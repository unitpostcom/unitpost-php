<?php

declare(strict_types=1);

namespace Unitpost\Tests;

use PHPUnit\Framework\TestCase;
use Unitpost\Client;
use Unitpost\OrganizationClient;
use Unitpost\OrganizationConfigException;

final class OrganizationTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> */
    private array $calls = [];

    protected function tearDown(): void
    {
        putenv('UNITPOST_ORG_KEY');
        putenv('UNITPOST_ORGANIZATION_ID');
        putenv('UNITPOST_API_KEY');
    }

    /** @param list<array{status: int, body: mixed}> $responses */
    private function org(array $responses = [], string $organizationId = 'org_1'): OrganizationClient
    {
        $this->calls = [];
        $i = 0;
        return new OrganizationClient([
            'apiKey' => 'ok_live_test',
            'organizationId' => $organizationId,
            'baseUrl' => 'https://api.test',
            'handler' => function (string $method, string $url, array $headers, ?string $body) use (&$i, $responses) {
                $this->calls[] = compact('method', 'url', 'headers', 'body');
                $r = $responses[$i] ?? ['status' => 200, 'body' => ['data' => [], 'has_more' => false]];
                $i += 1;
                return ['status' => $r['status'], 'headers' => [], 'body' => json_encode($r['body'])];
            },
        ]);
    }

    private function lastHeaders(): string
    {
        return implode("\n", $this->calls[array_key_last($this->calls)]['headers']);
    }

    public function testSendsTheOrganizationKeyAndFillsTheOrganizationIdIntoThePath(): void
    {
        $org = $this->org();
        $this->assertSame('org_1', $org->organizationId);
        $org->workspaces->list(['limit' => 5]);
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertSame('https://api.test/api/v1/organizations/org_1/workspaces?limit=5', $this->calls[0]['url']);
        $this->assertStringContainsString('Authorization: Bearer ok_live_test', $this->lastHeaders());
    }

    public function testPercentEncodesTheOrganizationIdAndEveryPathParameter(): void
    {
        $org = $this->org([], 'org/1 x');
        $org->workspaces->get('ws/2');
        $this->assertStringContainsString('/organizations/org%2F1%20x/workspaces/ws%2F2', $this->calls[0]['url']);
    }

    public function testReadsTheKeyAndOrganizationIdFromTheEnvironment(): void
    {
        putenv('UNITPOST_ORG_KEY=ok_live_env');
        putenv('UNITPOST_ORGANIZATION_ID=org_env');
        $seen = [];
        $org = new OrganizationClient([
            'baseUrl' => 'https://api.test',
            'handler' => function (string $method, string $url, array $headers) use (&$seen) {
                $seen = compact('url', 'headers');
                return ['status' => 200, 'headers' => [], 'body' => '{"data":[],"has_more":false}'];
            },
        ]);
        $org->usage->list();
        $this->assertSame('org_env', $org->organizationId);
        $this->assertStringContainsString('/organizations/org_env/usage', $seen['url']);
        $this->assertStringContainsString('Authorization: Bearer ok_live_env', implode("\n", $seen['headers']));
    }

    public function testNeverReadsTheKeyFromUnitpostApiKey(): void
    {
        putenv('UNITPOST_API_KEY=pk_live_workspace');
        putenv('UNITPOST_ORGANIZATION_ID=org_env');
        try {
            new OrganizationClient();
            $this->fail('expected a missing key error');
        } catch (OrganizationConfigException $e) {
            $this->assertSame('missing_api_key', $e->errorCode);
            $this->assertStringContainsString('UNITPOST_ORG_KEY', $e->getMessage());
            $this->assertStringContainsString('Organization → API', $e->getMessage());
        }
    }

    public function testAMissingOrganizationIdIsAnError(): void
    {
        try {
            new OrganizationClient(['apiKey' => 'ok_live_test']);
            $this->fail('expected a missing organization id error');
        } catch (\InvalidArgumentException $e) {
            $this->assertInstanceOf(OrganizationConfigException::class, $e);
            $this->assertSame('missing_organization_id', $e->errorCode);
            $this->assertStringContainsString('UNITPOST_ORGANIZATION_ID', $e->getMessage());
            $this->assertStringContainsString('org_', $e->getMessage());
        }
    }

    public function testCreateSendsTheCallersIdempotencyKeyOrMintsOne(): void
    {
        $org = $this->org();
        $org->workspaces->create(['name' => 'Acme'], 'my-key-1');
        $this->assertStringContainsString('Idempotency-Key: my-key-1', $this->lastHeaders());
        $org->workspaces->create(['name' => 'Acme']);
        $this->assertMatchesRegularExpression('/Idempotency-Key: [0-9a-f]{32}/', $this->lastHeaders());
        $this->assertSame('{"name":"Acme"}', $this->calls[1]['body']);
        $org->planQuotes->confirm('ws_1', 'pq_1', ['confirm_token' => 't'], 'confirm-1');
        $this->assertStringContainsString('Idempotency-Key: confirm-1', $this->lastHeaders());
        $org->planQuotes->confirm('ws_1', 'pq_1', ['confirm_token' => 't']);
        $this->assertStringContainsString('Idempotency-Key: ', $this->lastHeaders());
    }

    public function testEveryResourceUsesTheRightMethodAndPath(): void
    {
        $org = $this->org();
        $org->workspaces->update('ws_1', ['name' => 'B']);
        $org->workspaces->close('ws_1');
        $org->workspaces->restore('ws_1');
        $org->workspaceKeys->create('ws_1', ['name' => 'k']);
        $org->webhooks->list();
        $org->webhooks->create(['url' => 'https://x.test/h', 'events' => ['email.sent']]);
        $org->webhooks->get('wh_1');
        $org->webhooks->update('wh_1', ['enabled' => false]);
        $org->webhooks->delete('wh_1');
        $org->webhooks->rotateSecret('wh_1');
        $org->webhooks->test('wh_1');
        $org->webhooks->listDeliveries('wh_1', ['status' => 'failed', 'workspace_id' => 'ws_1']);
        $org->webhooks->replayDelivery('wh_1', 'del_1');
        $org->webhooks->flush('wh_1');
        $org->usage->list(['period' => 'current']);
        $org->planQuotes->create('ws_1', ['plan' => 'pro']);
        $org->planQuotes->get('ws_1', 'pq_1');
        $org->planQuotes->confirm('ws_1', 'pq_1', ['confirm_token' => 't']);
        $org->sharedPlan->get();
        $org->sharedPlan->listWorkspaces();
        $org->sharedPlan->addWorkspace('ws_1');
        $org->sharedPlan->updateWorkspace('ws_1', ['cap_cents' => null]);
        $org->sharedPlan->removeWorkspace('ws_1');

        $base = 'https://api.test/api/v1/organizations/org_1';
        $expected = [
            ['PATCH', '/workspaces/ws_1'],
            ['POST', '/workspaces/ws_1/close'],
            ['POST', '/workspaces/ws_1/restore'],
            ['POST', '/workspaces/ws_1/api-keys'],
            ['GET', '/webhooks'],
            ['POST', '/webhooks'],
            ['GET', '/webhooks/wh_1'],
            ['PATCH', '/webhooks/wh_1'],
            ['DELETE', '/webhooks/wh_1'],
            ['POST', '/webhooks/wh_1/rotate-secret'],
            ['POST', '/webhooks/wh_1/test'],
            ['GET', '/webhooks/wh_1/deliveries?status=failed&workspace_id=ws_1'],
            ['POST', '/webhooks/wh_1/deliveries/del_1/replay'],
            ['POST', '/webhooks/wh_1/flush'],
            ['GET', '/usage?period=current'],
            ['POST', '/workspaces/ws_1/plan-quotes'],
            ['GET', '/workspaces/ws_1/plan-quotes/pq_1'],
            ['POST', '/workspaces/ws_1/plan-quotes/pq_1/confirm'],
            ['GET', '/shared-plan'],
            ['GET', '/shared-plan/workspaces'],
            ['PUT', '/shared-plan/workspaces/ws_1'],
            ['PATCH', '/shared-plan/workspaces/ws_1'],
            ['DELETE', '/shared-plan/workspaces/ws_1'],
        ];
        $this->assertCount(count($expected), $this->calls);
        foreach ($expected as $i => [$method, $path]) {
            $this->assertSame($method, $this->calls[$i]['method'], "call $i");
            $this->assertSame($base . $path, $this->calls[$i]['url'], "call $i");
        }
        $this->assertSame('{}', $this->calls[13]['body']);
        $this->assertSame('{}', $this->calls[20]['body']);
        $this->assertSame('{"cap_cents":null}', $this->calls[21]['body']);
    }

    public function testSharedPlanAddAndRemoveSendTheCallersIdempotencyKeyOrMintOne(): void
    {
        $org = $this->org();
        $org->sharedPlan->addWorkspace('ws_1', ['cap_cents' => 500], 'add-1');
        $this->assertStringContainsString('Idempotency-Key: add-1', $this->lastHeaders());
        $this->assertSame('{"cap_cents":500}', $this->calls[0]['body']);
        $org->sharedPlan->addWorkspace('ws_1');
        $this->assertMatchesRegularExpression('/Idempotency-Key: [0-9a-f]{32}/', $this->lastHeaders());
        $org->sharedPlan->updateWorkspace('ws_1', ['cap_cents' => 100]);
        $this->assertStringNotContainsString('Idempotency-Key', $this->lastHeaders());
        $org->sharedPlan->removeWorkspace('ws_1', 'remove-1');
        $this->assertStringContainsString('Idempotency-Key: remove-1', $this->lastHeaders());
        $org->sharedPlan->removeWorkspace('ws_1');
        $this->assertMatchesRegularExpression('/Idempotency-Key: [0-9a-f]{32}/', $this->lastHeaders());
    }

    public function testListAllFollowsTheCursor(): void
    {
        $org = $this->org([
            ['status' => 200, 'body' => ['data' => [['id' => 'ws_1'], ['id' => 'ws_2']], 'has_more' => true]],
            ['status' => 200, 'body' => ['data' => [['id' => 'ws_3']], 'has_more' => false]],
        ]);
        $ids = [];
        foreach ($org->workspaces->listAll(['limit' => 2, 'external_id' => 'cust_1']) as $row) {
            $ids[] = $row['id'];
        }
        $this->assertSame(['ws_1', 'ws_2', 'ws_3'], $ids);
        $this->assertCount(2, $this->calls);
        $this->assertStringContainsString('/organizations/org_1/workspaces?limit=2&external_id=cust_1', $this->calls[0]['url']);
        $this->assertStringContainsString('after=ws_2', $this->calls[1]['url']);
    }

    public function testListAllPagesForwardOnly(): void
    {
        $org = $this->org();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pages forward only');
        $org->workspaces->listAll(['before' => 'ws_9']);
    }

    public function testWebhooksVerifyDelegatesToTheTopLevelFunction(): void
    {
        $this->expectException(\Unitpost\WebhookVerificationError::class);
        $this->org()->webhooks->verify('{}', 'whsec_dGVzdA', []);
    }

    public function testTheWorkspaceClientHasNoOrganizationAccessors(): void
    {
        $client = new Client(['apiKey' => 'pk_live_x']);
        foreach (['workspaces', 'workspaceKeys', 'planQuotes', 'organizationId'] as $name) {
            $this->assertFalse(property_exists($client, $name), $name);
        }
        $this->assertFalse(property_exists($client->webhooks, 'organizationId'));
    }
}
