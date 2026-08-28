<?php

declare(strict_types=1);

namespace Unitpost\Tests;

use PHPUnit\Framework\TestCase;
use Unitpost\Client;
use Unitpost\WebhookVerificationError;
use function Unitpost\verifyWebhook;

final class SdkTest extends TestCase
{
    private const SECRET = 'whsec_dGVzdC1zZWNyZXQtMTIzNDU2Nzg5MA';

    public function testSendReturnsDataAndSetsHeaders(): void
    {
        $seen = [];
        $client = new Client([
            'apiKey' => 'test_key',
            'baseUrl' => 'https://api.test',
            'handler' => function (string $method, string $url, array $headers, ?string $body) use (&$seen) {
                $seen = compact('method', 'url', 'headers', 'body');
                return ['status' => 200, 'headers' => [], 'body' => json_encode(['id' => 'em_1', 'status' => 'queued'])];
            },
        ]);
        $result = $client->email->send(['from' => 'a@test.com', 'to' => 'b@test.com', 'html' => '<p>hi</p>']);
        $this->assertNull($result->error);
        $this->assertSame('em_1', $result->data['id']);
        $this->assertSame('POST', $seen['method']);
        $this->assertStringContainsString('/api/v1/email', $seen['url']);
        $joined = implode("\n", $seen['headers']);
        $this->assertStringContainsString('Authorization: Bearer test_key', $joined);
        $this->assertStringContainsString('User-Agent: unitpost-php/', $joined);
    }

    public function testMapsNon2xxToTypedError(): void
    {
        $client = new Client([
            'apiKey' => 'test_key',
            'baseUrl' => 'https://api.test',
            'handler' => fn () => [
                'status' => 422,
                'headers' => [],
                'body' => json_encode(['error' => ['code' => 'validation_error', 'message' => 'bad from']]),
            ],
        ]);
        $result = $client->email->send(['from' => 'x', 'to' => 'y']);
        $this->assertNull($result->data);
        $this->assertSame('validation_error', $result->error->code);
        $this->assertSame(422, $result->error->status);
    }

    public function testVerifyWebhookGoldenVector(): void
    {
        $body = '{"type":"email.delivered","created_at":"2023-11-14T22:13:20.000Z","data":{"id":"em_golden_1"}}';
        $event = verifyWebhook(
            $body,
            self::SECRET,
            [
                'svix-id' => 'msg_2VeXh1EN7xExAmAmQ8gxxYz',
                'svix-timestamp' => '1700000000',
                'svix-signature' => 'v1,0+/GSmvt3iOjDLxc/D/1BZ4Da3gbNR4VyGP0fGMmE3I=',
            ],
            10_000_000_000,
        );
        $this->assertSame('email.delivered', $event['type']);
    }

    public function testClientWebhooksVerifyAlias(): void
    {
        $body = '{"type":"contact.created"}';
        $client = new Client([
            'apiKey' => 'test_key',
            'baseUrl' => 'https://api.test',
            'handler' => fn () => ['status' => 200, 'headers' => [], 'body' => '{}'],
        ]);
        $ts = (string) time();
        $event = $client->webhooks->verify(
            $body,
            self::SECRET,
            [
                'svix-id' => 'msg_1',
                'svix-timestamp' => $ts,
                'svix-signature' => $this->sign(self::SECRET, 'msg_1', (int) $ts, $body),
            ],
        );
        $this->assertSame('contact.created', $event['type']);
    }

    public function testRejectsTamperedBody(): void
    {
        $this->expectException(WebhookVerificationError::class);
        $ts = (string) time();
        $body = '{"type":"email.delivered"}';
        verifyWebhook(
            $body . 'x',
            self::SECRET,
            [
                'svix-id' => 'msg_1',
                'svix-timestamp' => $ts,
                'svix-signature' => $this->sign(self::SECRET, 'msg_1', (int) $ts, $body),
            ],
        );
    }

    private function sign(string $secret, string $id, int $ts, string $body): string
    {
        $raw = substr($secret, strlen('whsec_'));
        $pad = (4 - (strlen($raw) % 4)) % 4;
        $key = base64_decode(strtr($raw, '-_', '+/') . str_repeat('=', $pad), true);
        return 'v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $ts . '.' . $body, $key, true));
    }
}
