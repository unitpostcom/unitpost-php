# unitpost (PHP)

Official Unitpost SDK for PHP. Send email, manage contacts, segments, campaigns, templates, domains, webhooks, and suppressions.

```bash
composer require unitpost/unitpost
```

```php
use Unitpost\Client;

$unitpost = new Client(); // reads UNITPOST_API_KEY
$result = $unitpost->email->send([
    'from' => 'Acme <team@acme.com>',
    'to' => 'user@example.com',
    'subject' => 'Hello',
    'html' => '<p>Hi</p>',
]);
if ($result->error) {
    fwrite(STDERR, $result->error->code . ': ' . $result->error->message . "\n");
}
```

`$unitpost->sms` is the SMS channel (send, get, list, brands, numbers, contact SMS consent). Available on paid plans: on the free plan `send` returns a `403` with code `limit_reached`, while the reads keep working.

## Organization keys

A platform that gives each of its customers a Unitpost workspace uses an organization key (`ok_live_…`, made by an Owner or Admin in the dashboard under Organization → API) with the second client. It reads `UNITPOST_ORG_KEY` and `UNITPOST_ORGANIZATION_ID` (`org_…`) when you don't pass them; the key is never read from `UNITPOST_API_KEY`. A missing one throws `Unitpost\OrganizationConfigException` (an `InvalidArgumentException`; `$e->errorCode` is `missing_api_key` or `missing_organization_id`).

```php
use Unitpost\Client;
use Unitpost\OrganizationClient;

$org = new OrganizationClient(); // or new OrganizationClient(['apiKey' => 'ok_live_…', 'organizationId' => 'org_…'])
$ws = $org->workspaces->create(['name' => 'Acme', 'external_id' => 'cust_42']); // mints an Idempotency-Key unless you pass one

// To work inside that workspace, mint its key and make a workspace client.
$key = $org->workspaceKeys->create($ws->data['id'], ['name' => 'backend']);
$acme = new Client(['apiKey' => $key->data['key']]); // the secret is in this answer only
```

| Accessor | Methods |
| --- | --- |
| `$org->workspaces` | `list`, `listAll`, `create`, `get`, `update`, `close`, `restore` |
| `$org->workspaceKeys` | `create($workspaceId, $body)` |
| `$org->webhooks` | `list`, `listAll`, `create`, `get`, `update`, `delete`, `rotateSecret`, `test`, `listDeliveries` (needs `organizations:write`), `listAllDeliveries`, `replayDelivery`, `flush`, `verify` |
| `$org->usage` | `list`, `listAll` |
| `$org->planQuotes` | `create`, `get`, `confirm` (mints an Idempotency-Key unless you pass one) |
| `$org->sharedPlan` | The organization's shared plan: `get`, `listWorkspaces`, `addWorkspace`, `updateWorkspace` (its cap), `removeWorkspace` (adding and removing mint an Idempotency-Key unless you pass one) |

The workspace `Client` has none of these and never calls an `/organizations/` route; the organization client calls no workspace route. Same transport, retries and errors as `Client`.

## Idempotency

`email->send`, `email->batch` and `sms->send` always carry an `Idempotency-Key`: the SDK mints one per call so its own automatic retries can never send twice. Pass your own key to dedupe across separate calls (for example a job retried after your process crashed): the API replays the first result for the same key for 24 hours instead of sending again.

```php
$client->email->send($body, 'order-1234-receipt');
$client->email->batch($emails, 'digest-2026-10-24');
$client->sms->send($sms, 'otp-5678');
```

## Retries

Transient failures are retried automatically: `429`, `5xx` and transport errors (timeouts, network failures) are retried up to `maxRetries` times (default `2`, so 3 attempts in all). When the error response carries a `Retry-After` header (a `429` always does), the client waits that long, read as seconds or an HTTP date and capped at `maxRetryDelay`. Otherwise it waits with exponential backoff and full jitter: a random time up to `retryBaseDelay` × 2^attempt, also capped at `maxRetryDelay`. Client errors (`401`/`403`/`404`/`409`/`422`) are never retried.

Only requests that are safe to replay are retried: `GET`s and writes that carry an idempotency key (`email->send`, `email->batch` and `sms->send` always do, see above). Any other write without a key is sent once.

```php
// Defaults shown, delays in milliseconds. 'maxRetries' => 0 turns retries off.
$unitpost = new Client(['maxRetries' => 2, 'retryBaseDelay' => 500, 'maxRetryDelay' => 20_000]);
```

## Pagination

List endpoints are cursor-paginated. `list` returns one page; the iterator below walks every page, following `has_more` with the last row's `id` as the next `after` cursor. Filters you pass ride along on every page. `listAll()` is a `Generator`; a failed page throws `Unitpost\UnitpostException` (its `->error` is the usual `UnitpostError`).

```php
foreach ($client->contacts->listAll(['limit' => 100]) as $contact) {
    echo $contact['email'], PHP_EOL;
}
```

## Receive a text and answer it

US toll-free, 10DLC and Canadian numbers take replies (`two_way` is true on `$unitpost->sms->numbers()`). Subscribe a webhook to `sms.received`, skip STOP and texts Unitpost already answered, read the conversation with `participant`, and answer without `from` so the reply leaves from the number they texted.

```php
use Unitpost\Client;

$unitpost = new Client(); // reads UNITPOST_API_KEY

// Your webhook endpoint, subscribed to sms.received. Pass the RAW body.
function onWebhook(Client $unitpost, string $rawBody, array $headers): void
{
    $event = $unitpost->webhooks->verify($rawBody, getenv('UNITPOST_WEBHOOK_SECRET'), $headers); // whsec_…
    if ($event['type'] !== 'sms.received') {
        return;
    }
    $reply = $event['data'];
    if ($reply['keyword'] === 'OPT_OUT' || $reply['auto_replied']) {
        return; // STOP, or already answered
    }

    // The whole conversation with this person, both directions, newest first.
    // Other filters: direction, status, from, to, contact_id, campaign_id,
    // created_after, created_before.
    $history = $unitpost->sms->list(['participant' => $reply['from'], 'limit' => 20]);
    $answer = yourAgent($history->data, $reply['body'] ?? '');

    // No 'from': the answer goes out from the number they texted.
    $unitpost->sms->send(['to' => $reply['from'], 'body' => $answer]);
}
```

Docs: https://www.unitpost.com/docs
