<?php

declare(strict_types=1);

namespace Unitpost\Tests;

use PHPUnit\Framework\TestCase;
use Unitpost\Client;
use Unitpost\Generated\SmsBrand;
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

    public function testSmsCampaignSendWithoutOptionsSendsAJsonObject(): void
    {
        $bodies = [];
        $client = new Client([
            'apiKey' => 'test_key',
            'baseUrl' => 'https://api.test',
            'handler' => function (string $method, string $url, array $headers, ?string $body) use (&$bodies) {
                $bodies[] = $body;
                return ['status' => 200, 'headers' => [], 'body' => json_encode(['id' => 'smscmp_1'])];
            },
        ]);
        $this->assertNull($client->sms->campaigns->send('smscmp_1')->error);
        $this->assertNull($client->sms->campaigns->send('smscmp_1', ['scheduled_at' => '2026-11-01T09:00:00Z'])->error);
        $this->assertSame('{}', $bodies[0]);
        $this->assertSame('{"scheduled_at":"2026-11-01T09:00:00Z"}', $bodies[1]);
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

    public function testCallerSuppliedIdempotencyKey(): void
    {
        $keys = [];
        $client = new Client([
            'apiKey' => 'test_key',
            'baseUrl' => 'https://api.test',
            'handler' => function (string $method, string $url, array $headers, ?string $body) use (&$keys) {
                $key = '';
                foreach ($headers as $h) {
                    if (str_starts_with($h, 'Idempotency-Key: ')) {
                        $key = substr($h, strlen('Idempotency-Key: '));
                    }
                }
                $keys[] = $key;
                return ['status' => 200, 'headers' => [], 'body' => '{"id":"x"}'];
            },
        ]);
        $client->email->send(['to' => 'b@test.com'], 'order-1');
        $client->email->batch([['to' => 'b@test.com']], 'batch-1');
        $client->sms->send(['to' => '+15551230000'], 'sms-1');
        $client->email->send(['to' => 'b@test.com']);
        $this->assertSame(['order-1', 'batch-1', 'sms-1'], array_slice($keys, 0, 3));
        $this->assertNotSame('', $keys[3]);
        $this->assertNotSame('order-1', $keys[3]);
    }

    public function testListAllFollowsCursor(): void
    {
        $urls = [];
        $client = new Client([
            'apiKey' => 'test_key',
            'baseUrl' => 'https://api.test',
            'handler' => function (string $method, string $url) use (&$urls) {
                $urls[] = $url;
                if (!str_contains($url, 'after=')) {
                    return ['status' => 200, 'headers' => [], 'body' => '{"object":"list","has_more":true,"data":[{"id":"c1"},{"id":"c2"}]}'];
                }
                return ['status' => 200, 'headers' => [], 'body' => '{"object":"list","has_more":false,"data":[{"id":"c3"}]}'];
            },
        ]);
        $ids = [];
        foreach ($client->contacts->listAll(['status' => 'active']) as $row) {
            $ids[] = $row['id'];
        }
        $this->assertSame(['c1', 'c2', 'c3'], $ids);
        $this->assertCount(2, $urls);
        $this->assertStringContainsString('after=c2', $urls[1]);
        $this->assertStringContainsString('status=active', $urls[1]);
    }

    public function testListAllThrowsApiError(): void
    {
        $client = new Client([
            'apiKey' => 'test_key',
            'baseUrl' => 'https://api.test',
            'handler' => fn () => ['status' => 403, 'headers' => [], 'body' => '{"error":{"code":"forbidden","message":"no"}}'],
        ]);
        try {
            foreach ($client->webhooks->listAll() as $_) {
            }
            $this->fail('expected UnitpostException');
        } catch (\Unitpost\UnitpostException $e) {
            $this->assertSame('forbidden', $e->error->code);
        }
    }

    public function testListAllRefusesBeforeUpFront(): void
    {
        $client = new Client([
            'apiKey' => 'test_key',
            'baseUrl' => 'https://api.test',
            'handler' => function () {
                throw new \LogicException('no request may be sent');
            },
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/pages forward only/');
        $client->contacts->listAll(['before' => 'con_9']);
    }

    public function testSmsBrandKeepsItsEarlierConstructor(): void
    {
        // Positional, as code written against 0.4.0 calls it: the fields added
        // since default to null, and so they do on an older payload.
        $brand = new SmsBrand('sms_brand', 'sbrand_1', 'Acme', 'Acme Inc', null, null, null, 'ready', [], false, false, 0, true, '2026-10-01T00:00:00.000Z');
        $this->assertSame('2026-10-01T00:00:00.000Z', $brand->createdAt);
        $this->assertNull($brand->revision);
        $read = SmsBrand::fromArray([
            'object' => 'sms_brand', 'id' => 'sbrand_1', 'name' => 'Acme', 'legal_name' => 'Acme Inc',
            'status' => 'ready', 'missing' => [], 'ten_dlc_verified' => false, 'tax_id_on_file' => false,
            'number_count' => 0, 'editable' => true, 'created_at' => '2026-10-01T00:00:00.000Z',
        ]);
        $this->assertSame('Acme Inc', $read->legalName);
        $this->assertNull($read->contact);
    }
}
