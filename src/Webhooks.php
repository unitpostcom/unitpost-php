<?php

declare(strict_types=1);

namespace Unitpost;

/**
 * Webhook signature verification (svix-compatible).
 *
 * Signed content: "<svix-id>.<svix-timestamp>.<raw-body>"
 * Signature:      base64( HMAC_SHA256(secret_bytes, signed_content) )
 * Header:         svix-signature = "v1,<base64>" (space-separated list allowed)
 * Secret:         "whsec_<base64url>" — bytes after the prefix are the key.
 *
 * Verify the RAW request body string (not a re-serialized array).
 */
final class WebhookVerificationError extends \RuntimeException
{
}

function verifyWebhook(string $payload, string $secret, array $headers, int $toleranceSeconds = 300): mixed
{
    $id = headerValue($headers, 'svix-id');
    $timestamp = headerValue($headers, 'svix-timestamp');
    $signature = headerValue($headers, 'svix-signature');
    if ($id === null || $timestamp === null || $signature === null) {
        throw new WebhookVerificationError('Missing svix-id, svix-timestamp, or svix-signature header.');
    }
    if (!is_numeric($timestamp)) {
        throw new WebhookVerificationError('Invalid svix-timestamp header.');
    }
    $ts = (int) $timestamp;
    $now = time();
    if (abs($now - $ts) > $toleranceSeconds) {
        throw new WebhookVerificationError('Webhook timestamp is outside the tolerance window (possible replay).');
    }

    $signed = $id . '.' . $ts . '.' . $payload;
    $expected = base64_encode(hash_hmac('sha256', $signed, secretKeyBytes($secret), true));

    $matched = false;
    foreach (preg_split('/\s+/', $signature) ?: [] as $part) {
        $bits = explode(',', $part, 2);
        $sig = $bits[1] ?? '';
        if ($sig !== '' && hash_equals($expected, $sig)) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        throw new WebhookVerificationError('Webhook signature verification failed.');
    }

    try {
        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        throw new WebhookVerificationError('Webhook payload is not valid JSON.');
    }
}

/** @param array<string, string|list<string>|null> $headers */
function headerValue(array $headers, string $name): ?string
{
    foreach ($headers as $k => $v) {
        if (strcasecmp((string) $k, $name) === 0) {
            return is_array($v) ? ($v[0] ?? null) : $v;
        }
    }
    return null;
}

function secretKeyBytes(string $secret): string
{
    $raw = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
    $pad = (4 - (strlen($raw) % 4)) % 4;
    $decoded = base64_decode(strtr($raw, '-_', '+/') . str_repeat('=', $pad), true);
    if ($decoded === false) {
        throw new WebhookVerificationError('Invalid webhook secret.');
    }
    return $decoded;
}
