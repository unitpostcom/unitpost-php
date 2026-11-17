<?php

declare(strict_types=1);

namespace Unitpost;

/**
 * Thrown by the organization client's constructor when its key or organization
 * id is missing. An InvalidArgumentException like the workspace client's
 * missing-key error; `$errorCode` is `missing_api_key` or
 * `missing_organization_id`.
 */
final class OrganizationConfigException extends \InvalidArgumentException
{
    public string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}

/**
 * The organization client (ADR-0171): the second client, for an ORGANIZATION
 * key (`ok_live_…`). A platform that gives each of its customers a Unitpost
 * workspace uses it to create, close and restore those workspaces, mint their
 * keys, manage the organization's webhook endpoints, read usage per workspace,
 * change a workspace's plan and manage which workspaces share the
 * organization's shared plan.
 *
 *   $org = new \Unitpost\OrganizationClient(); // UNITPOST_ORG_KEY + UNITPOST_ORGANIZATION_ID
 *   $result = $org->workspaces->create(['name' => 'Acme', 'external_id' => 'cust_42']);
 *
 * One credential per client: the workspace client (`Client`) never calls an
 * organization route, and this one never calls a workspace route. To work
 * inside one of the workspaces, mint its key with `workspaceKeys->create` and
 * make a `Client` with it. Same transport, retries and errors as `Client`.
 */
final class OrganizationClient
{
    /** The organization every call acts on (`org_…`). */
    public readonly string $organizationId;
    /** The organization's workspaces: list, create, get, update, close, restore. */
    public OrganizationWorkspaces $workspaces;
    /** Keys for one of the organization's workspaces: `create` mints one (the secret is in that answer only). */
    public OrganizationWorkspaceKeys $workspaceKeys;
    /** The organization's webhook endpoints: events from every workspace it owns, each with `workspace_id` and `external_id`. */
    public OrganizationWebhooks $webhooks;
    /** Usage per workspace for a billing period (`list`). */
    public OrganizationUsage $usage;
    /** A plan change for one workspace the organization pays for: quote, read, confirm. */
    public OrganizationPlanQuotes $planQuotes;
    /** The organization's shared plan: read it, and add, cap or remove the workspaces on it. */
    public OrganizationSharedPlan $sharedPlan;

    /**
     * @param array<string, mixed> $options `apiKey` (the organization key, default
     *        UNITPOST_ORG_KEY), `organizationId` (`org_…`, default
     *        UNITPOST_ORGANIZATION_ID), plus the workspace client's `baseUrl`,
     *        `timeout`, `maxRetries`, `retryBaseDelay`, `maxRetryDelay`, `handler`.
     *        The key is never read from UNITPOST_API_KEY.
     */
    public function __construct(array $options = [])
    {
        $apiKey = $options['apiKey'] ?? (getenv('UNITPOST_ORG_KEY') ?: null);
        if ($apiKey === null || $apiKey === '') {
            throw new OrganizationConfigException(
                'missing_api_key',
                'No organization key provided. Pass apiKey or set the UNITPOST_ORG_KEY environment variable. An Owner or Admin makes one in the dashboard under Organization → API.',
            );
        }
        $organizationId = $options['organizationId'] ?? (getenv('UNITPOST_ORGANIZATION_ID') ?: null);
        if ($organizationId === null || $organizationId === '') {
            throw new OrganizationConfigException(
                'missing_organization_id',
                'No organization id provided. Pass organizationId (`org_…`) or set the UNITPOST_ORGANIZATION_ID environment variable.',
            );
        }
        unset($options['organizationId']);
        $options['apiKey'] = (string) $apiKey;
        $http = new HttpClient($options);
        $this->organizationId = (string) $organizationId;
        $this->workspaces = new OrganizationWorkspaces($http, $this->organizationId);
        $this->workspaceKeys = new OrganizationWorkspaceKeys($http, $this->organizationId);
        $this->webhooks = new OrganizationWebhooks($http, $this->organizationId);
        $this->usage = new OrganizationUsage($http, $this->organizationId);
        $this->planQuotes = new OrganizationPlanQuotes($http, $this->organizationId);
        $this->sharedPlan = new OrganizationSharedPlan($http, $this->organizationId);
    }
}

/**
 * Base of the organization resources. Extends `Resource` (src/Resources.php),
 * so that file, with the `enc()` helper and the pager, is always loaded first.
 */
abstract class OrganizationResource extends Resource
{
    public function __construct(HttpClient $http, protected string $organizationId)
    {
        parent::__construct($http);
    }
}

final class OrganizationWorkspaces extends OrganizationResource
{
    /**
     * The organization's workspaces, newest first (cursor-paginated). Needs `organizations:read`.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`, `external_id`.
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/organizations/" . enc($this->organizationId) . "/workspaces", $params);
    }

    /**
     * Iterate every workspace of the organization (lazily, as a Generator), with the same filters as `list`.
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/organizations/" . enc($this->organizationId) . "/workspaces", $params);
    }

    /**
     * Create a workspace (201). Its Owner is the key's creator. An
     * Idempotency-Key is minted when you don't pass one, so a retry can't make
     * two. Needs `organizations:write`.
     *
     * @param array<string, mixed> $body `name`, `external_id`, …
     */
    public function create(array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/organizations/" . enc($this->organizationId) . "/workspaces", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    /** One workspace (`ws_…`). Needs `organizations:read`. */
    public function get(string $workspaceId): Result
    {
        return $this->http->request("GET", "/organizations/" . enc($this->organizationId) . "/workspaces/" . enc($workspaceId));
    }

    /**
     * Rename it, set its `external_id` or its SMS purchases cap. Needs `organizations:write`.
     *
     * @param array<string, mixed> $body
     */
    public function update(string $workspaceId, array $body): Result
    {
        return $this->http->request("PATCH", "/organizations/" . enc($this->organizationId) . "/workspaces/" . enc($workspaceId), null, $body);
    }

    /**
     * Close it: it stops sending at once. It can be restored for 30 days
     * (`restorable_until`); unless restored, it is deleted once those 30 days
     * are over and 7 days have passed since the end of the billing period it
     * closed in, whichever is later (`deletes_at`). Closing twice changes
     * nothing. Needs `organizations:write`.
     */
    public function close(string $workspaceId): Result
    {
        return $this->http->request("POST", "/organizations/" . enc($this->organizationId) . "/workspaces/" . enc($workspaceId) . "/close");
    }

    /** Restore a closed workspace within its 30 days. Needs `organizations:write`. */
    public function restore(string $workspaceId): Result
    {
        return $this->http->request("POST", "/organizations/" . enc($this->organizationId) . "/workspaces/" . enc($workspaceId) . "/restore");
    }
}

final class OrganizationWorkspaceKeys extends OrganizationResource
{
    /**
     * Mint a workspace key (201) for one of the organization's workspaces. The
     * answer holds the secret (`key`) once; use it with `new Client(['apiKey' => $key])`.
     * Needs `organizations:write`.
     *
     * @param array<string, mixed> $body
     */
    public function create(string $workspaceId, array $body): Result
    {
        return $this->http->request("POST", "/organizations/" . enc($this->organizationId) . "/workspaces/" . enc($workspaceId) . "/api-keys", null, $body);
    }
}

final class OrganizationWebhooks extends OrganizationResource
{
    /**
     * The organization's webhook endpoints. Needs `organizations:read`.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`.
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/organizations/" . enc($this->organizationId) . "/webhooks", $params);
    }

    /**
     * Iterate every webhook endpoint of the organization (lazily, as a Generator). Needs `organizations:read`.
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/organizations/" . enc($this->organizationId) . "/webhooks", $params);
    }

    /**
     * Create an endpoint. The answer has `signing_secret` exactly once. Needs `organizations:write`.
     *
     * @param array<string, mixed> $body
     */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/organizations/" . enc($this->organizationId) . "/webhooks", null, $body);
    }

    /** One endpoint (`wh_…`). Needs `organizations:read`. */
    public function get(string $webhookId): Result
    {
        return $this->http->request("GET", "/organizations/" . enc($this->organizationId) . "/webhooks/" . enc($webhookId));
    }

    /**
     * Change an endpoint. Needs `organizations:write`.
     *
     * @param array<string, mixed> $body
     */
    public function update(string $webhookId, array $body): Result
    {
        return $this->http->request("PATCH", "/organizations/" . enc($this->organizationId) . "/webhooks/" . enc($webhookId), null, $body);
    }

    /** Delete an endpoint. Needs `organizations:write`. */
    public function delete(string $webhookId): Result
    {
        return $this->http->request("DELETE", "/organizations/" . enc($this->organizationId) . "/webhooks/" . enc($webhookId));
    }

    /** Mint a new signing secret; the answer carries it exactly once. Needs `organizations:write`. */
    public function rotateSecret(string $webhookId): Result
    {
        return $this->http->request("POST", "/organizations/" . enc($this->organizationId) . "/webhooks/" . enc($webhookId) . "/rotate-secret");
    }

    /** Fire a sample event at the endpoint. Needs `organizations:write`. */
    public function test(string $webhookId): Result
    {
        return $this->http->request("POST", "/organizations/" . enc($this->organizationId) . "/webhooks/" . enc($webhookId) . "/test");
    }

    /**
     * The endpoint's delivery log, newest first; each row names its
     * `workspace_id` and `external_id`. Needs `organizations:write` (the rows
     * carry each event's data, so `organizations:read` alone is a 403).
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`, `status`, `event_type`, `workspace_id`, `external_id`.
     */
    public function listDeliveries(string $webhookId, array $params = []): Result
    {
        return $this->http->request("GET", "/organizations/" . enc($this->organizationId) . "/webhooks/" . enc($webhookId) . "/deliveries", $params);
    }

    /**
     * Iterate the endpoint's whole delivery log (lazily, as a Generator), with
     * the same filters as `listDeliveries`. Needs `organizations:write`.
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAllDeliveries(string $webhookId, array $params = []): \Generator
    {
        return $this->paginate("/organizations/" . enc($this->organizationId) . "/webhooks/" . enc($webhookId) . "/deliveries", $params);
    }

    /** Deliver one event to the endpoint again (202). Needs `organizations:write`. */
    public function replayDelivery(string $webhookId, string $deliveryId): Result
    {
        return $this->http->request("POST", "/organizations/" . enc($this->organizationId) . "/webhooks/" . enc($webhookId) . "/deliveries/" . enc($deliveryId) . "/replay");
    }

    /**
     * Deliver the backlog (202); `['include_failed' => true]` adds the ones
     * that failed for good. Needs `organizations:write`.
     *
     * @param array<string, mixed> $body Optional `include_failed`.
     */
    public function flush(string $webhookId, array $body = []): Result
    {
        // `(object)` so the default is sent as `{}`, not `[]`.
        return $this->http->request("POST", "/organizations/" . enc($this->organizationId) . "/webhooks/" . enc($webhookId) . "/flush", null, (object) $body);
    }

    /** @param array<string, string> $headers */
    public function verify(string $payload, string $secret, array $headers, int $toleranceSeconds = 300): mixed
    {
        return verifyWebhook($payload, $secret, $headers, $toleranceSeconds);
    }
}

final class OrganizationUsage extends OrganizationResource
{
    /**
     * Usage per workspace (`workspace_usage` rows), each for that workspace's
     * own billing period containing `period`. Paged by workspace. Needs
     * `organizations:read`.
     *
     * @param array<string, mixed> $params `period`, `external_id`, `limit`, `after`, `before`.
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/organizations/" . enc($this->organizationId) . "/usage", $params);
    }

    /**
     * Iterate the usage row of every workspace (lazily, as a Generator), with
     * the same filters as `list`, paging by the last row's `workspace_id`.
     * Needs `organizations:read`.
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/organizations/" . enc($this->organizationId) . "/usage", $params, 'workspace_id');
    }
}

final class OrganizationPlanQuotes extends OrganizationResource
{
    /**
     * Price a plan change for one workspace the organization pays for (201).
     * Charges nothing; the quote lasts 10 minutes and holds a single-use
     * `confirm_token` (only in this answer). Its `pending_change` (`plan`,
     * `tier_index`, `effective_at`, or null) is a downgrade already scheduled
     * for the period end; with one, a quote for the current plan and tier is a
     * `noop` whose confirm cancels it. Needs `organizations:billing`.
     *
     * @param array<string, mixed> $body
     */
    public function create(string $workspaceId, array $body): Result
    {
        return $this->http->request("POST", "/organizations/" . enc($this->organizationId) . "/workspaces/" . enc($workspaceId) . "/plan-quotes", null, $body);
    }

    /** One quote (`pq_…`). Needs `organizations:read`. */
    public function get(string $workspaceId, string $quoteId): Result
    {
        return $this->http->request("GET", "/organizations/" . enc($this->organizationId) . "/workspaces/" . enc($workspaceId) . "/plan-quotes/" . enc($quoteId));
    }

    /**
     * Apply the quoted change and charge it. An Idempotency-Key is minted when
     * you don't pass one, so a retry can't charge twice. Needs `organizations:billing`.
     *
     * @param array<string, mixed> $body
     */
    public function confirm(string $workspaceId, string $quoteId, array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/organizations/" . enc($this->organizationId) . "/workspaces/" . enc($workspaceId) . "/plan-quotes/" . enc($quoteId) . "/confirm", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }
}

final class OrganizationSharedPlan extends OrganizationResource
{
    /**
     * The organization's shared plan (one plan whose allowance its workspaces
     * share): whether it's turned on (`approved`), its `status` (`none` until
     * one is bought), plan, price and period, and this period's `used_cents`,
     * `overage_cents`, `paused`. Buying, upgrading and ending it are done in
     * the dashboard. Needs `organizations:read`.
     */
    public function get(): Result
    {
        return $this->http->request("GET", "/organizations/" . enc($this->organizationId) . "/shared-plan");
    }

    /** Every workspace on the shared plan, with its cap and what it drew this period (one page). Needs `organizations:read`. */
    public function listWorkspaces(): Result
    {
        return $this->http->request("GET", "/organizations/" . enc($this->organizationId) . "/shared-plan/workspaces");
    }

    /**
     * Put a workspace on the shared plan (it moves onto the organization's
     * card), or set its cap when it's already on it. Refusals carry the reason
     * in `error->details[0]['code']` (`own_plan`, `limit_reached`, …). An
     * Idempotency-Key is minted when you don't pass one, so a retry answers
     * what the first call did. Needs `organizations:billing`.
     *
     * @param array<string, mixed> $body Optional `cap_cents` (int or null).
     */
    public function addWorkspace(string $workspaceId, array $body = [], ?string $idempotencyKey = null): Result
    {
        // `(object)` so the default is sent as `{}`, not `[]`.
        return $this->http->request("PUT", "/organizations/" . enc($this->organizationId) . "/shared-plan/workspaces/" . enc($workspaceId), null, (object) $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    /**
     * Set a workspace's cap (`cap_cents`; null removes it). Needs `organizations:billing`.
     *
     * @param array<string, mixed> $body `cap_cents` (int or null).
     */
    public function updateWorkspace(string $workspaceId, array $body): Result
    {
        return $this->http->request("PATCH", "/organizations/" . enc($this->organizationId) . "/shared-plan/workspaces/" . enc($workspaceId), null, $body);
    }

    /**
     * Take a workspace off the shared plan: `drops_to` is `free` or
     * `needs_plan`; one not on it answers `left: false`. An Idempotency-Key is
     * minted when you don't pass one. Needs `organizations:billing`.
     */
    public function removeWorkspace(string $workspaceId, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("DELETE", "/organizations/" . enc($this->organizationId) . "/shared-plan/workspaces/" . enc($workspaceId), null, null, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }
}
