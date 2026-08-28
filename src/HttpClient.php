<?php

declare(strict_types=1);

namespace Unitpost;

use Unitpost\Generated\Operations;

/**
 * HTTP transport — the one place that talks to the Unitpost API.
 */
final class HttpClient
{
    public const SDK_VERSION = '0.3.0';
    public const DEFAULT_BASE_URL = 'https://www.unitpost.com';

    private string $apiKey;
    private string $baseUrl;
    private int $timeoutMs;
    private int $maxRetries;
    private int $retryBaseDelay;
    private int $maxRetryDelay;
    /** @var (callable(string, string, list<string>, ?string): array{status: int, headers: array<string, string>, body: string})|null */
    private $handler;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $apiKey = $options['apiKey'] ?? getenv('UNITPOST_API_KEY') ?: '';
        if ($apiKey === '') {
            throw new \InvalidArgumentException(
                'No API key provided. Pass apiKey or set UNITPOST_API_KEY. Create a key at https://www.unitpost.com → Settings → API keys.',
            );
        }
        $this->apiKey = $apiKey;
        $base = $options['baseUrl'] ?? getenv('UNITPOST_BASE_URL') ?: self::DEFAULT_BASE_URL;
        $this->baseUrl = rtrim((string) $base, '/');
        $this->timeoutMs = (int) ($options['timeout'] ?? 30_000);
        $this->maxRetries = max(0, (int) ($options['maxRetries'] ?? 2));
        $this->retryBaseDelay = (int) ($options['retryBaseDelay'] ?? 500);
        $this->maxRetryDelay = (int) ($options['maxRetryDelay'] ?? 20_000);
        $this->handler = $options['handler'] ?? null;
    }

    /**
     * @param array<string, mixed>|null $query
     * @param mixed $body
     * @return Result<mixed>
     */
    public function request(string $method, string $path, ?array $query = null, mixed $body = null, ?string $idempotencyKey = null): Result
    {
        $retrySafe = $method === 'GET' || $idempotencyKey !== null;
        $attempt = 0;
        while (true) {
            [$result, $retryAfterMs] = $this->attempt($method, $path, $query, $body, $idempotencyKey);
            $err = $result->error;
            $retryable = $err !== null && ($err->status === 0 || $err->status === 429 || ($err->status >= 500 && $err->status <= 599));
            if ($err === null || !$retryable || !$retrySafe || $attempt >= $this->maxRetries) {
                return $result;
            }
            $this->sleep($this->backoffDelay($attempt, $retryAfterMs));
            $attempt += 1;
        }
    }

    /**
     * @param array<string, mixed>|null $query
     * @return array{0: Result<mixed>, 1: ?int}
     */
    private function attempt(string $method, string $path, ?array $query, mixed $body, ?string $idempotencyKey): array
    {
        $url = $this->baseUrl . '/api/' . Operations::API_VERSION . $path;
        if ($query) {
            $qs = [];
            foreach ($query as $k => $v) {
                if ($v === null) {
                    continue;
                }
                $qs[$k] = $v;
            }
            if ($qs !== []) {
                $url .= '?' . http_build_query($qs);
            }
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: unitpost-php/' . self::SDK_VERSION,
            'Accept: application/json',
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $payload = null;
        if ($body !== null && $method !== 'GET') {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $requestId = null;
        $retryAfter = null;
        $status = 0;
        $bodyText = '';

        if ($this->handler) {
            $res = ($this->handler)($method, $url, $headers, $payload);
            $status = (int) $res['status'];
            $bodyText = (string) ($res['body'] ?? '');
            $resHeaders = $res['headers'] ?? [];
            $requestId = $resHeaders['x-request-id'] ?? $resHeaders['X-Request-Id'] ?? null;
            $retryAfterRaw = $resHeaders['retry-after'] ?? $resHeaders['Retry-After'] ?? null;
            $retryAfter = is_string($retryAfterRaw) ? $this->parseRetryAfter($retryAfterRaw) : null;
        } else {
            $ch = curl_init($url);
            if ($ch === false) {
                return [new Result(null, new UnitpostError('network_error', 'curl_init failed', 0, null)), null];
            }
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT_MS => $this->timeoutMs,
                CURLOPT_HEADER => true,
            ];
            if ($payload !== null) {
                $opts[CURLOPT_POSTFIELDS] = $payload;
            }
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($raw === false) {
                $code = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'network_error';
                return [new Result(null, new UnitpostError($code, 'HTTP request failed', 0, null)), null];
            }

            $raw = (string) $raw;
            $headerBlob = substr($raw, 0, $headerSize);
            $bodyText = substr($raw, $headerSize);
            foreach (preg_split("/\r\n|\n/", $headerBlob) ?: [] as $line) {
                if (stripos($line, 'x-request-id:') === 0) {
                    $requestId = trim(substr($line, strlen('x-request-id:')));
                }
                if (stripos($line, 'retry-after:') === 0) {
                    $retryAfter = $this->parseRetryAfter(trim(substr($line, strlen('retry-after:'))));
                }
            }
        }

        if ($status === 204) {
            return [new Result(null, null), null];
        }
        $parsed = $bodyText === '' ? null : json_decode($bodyText, true);

        if ($status < 200 || $status >= 300) {
            $err = is_array($parsed) ? ($parsed['error'] ?? []) : [];
            return [
                new Result(null, new UnitpostError(
                    is_array($err) ? (string) ($err['code'] ?? 'http_error') : 'http_error',
                    is_array($err) ? (string) ($err['message'] ?? "Request failed with status {$status}.") : "Request failed with status {$status}.",
                    $status,
                    $requestId,
                    is_array($err) && isset($err['details']) && is_array($err['details']) ? $err['details'] : [],
                )),
                $retryAfter,
            ];
        }
        return [new Result($parsed, null), null];
    }

    private function parseRetryAfter(string $value): ?int
    {
        if (is_numeric($value)) {
            return max(0, (int) ((float) $value * 1000));
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return max(0, ($ts - time()) * 1000);
    }

    private function backoffDelay(int $attempt, ?int $retryAfterMs): int
    {
        if ($retryAfterMs !== null) {
            return min($retryAfterMs, $this->maxRetryDelay);
        }
        $ceiling = min($this->retryBaseDelay * (2 ** $attempt), $this->maxRetryDelay);
        return (int) (mt_rand() / mt_getrandmax() * $ceiling);
    }

    private function sleep(int $ms): void
    {
        usleep($ms * 1000);
    }
}
