<?php

declare(strict_types=1);

namespace Unitpost;

/**
 * Resource surface — the hand-written, ergonomic layer.
 *
 * Method names are curated for DX (email.send, email.templates.list, …). The
 * codegen surface-coverage check fails CI if a spec operation has no method
 * here. Every method returns a Result and never throws for an API error.
 */
function enc(string $segment): string
{
    return rawurlencode($segment);
}

abstract class Resource
{
    public function __construct(protected HttpClient $http)
    {
    }

    /**
     * Yield every row of a cursor-paginated list, following `has_more` with the
     * last row's `id` as the next `after` cursor. Filters in $params ride along
     * on every page; only the cursor moves. A failed page request throws
     * {@see UnitpostException} (a generator has no Result to put it in).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    protected function paginate(string $path, array $params): \Generator
    {
        // Forward only, refused up front (this method has no `yield`, so it
        // runs at the listAll() call): a `before` cursor used to ride along
        // with the moving `after` and 422 on the second page.
        if (!empty($params['before'])) {
            throw new \InvalidArgumentException(
                'listAll pages forward only: `before` is not supported (use list() to page backward).'
            );
        }
        return $this->pages($path, $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    private function pages(string $path, array $params): \Generator
    {
        $after = $params['after'] ?? null;
        while (true) {
            $query = $params;
            if ($after === null) {
                unset($query['after']);
            } else {
                $query['after'] = $after;
            }
            $page = $this->http->request("GET", $path, $query);
            if ($page->error !== null) {
                throw new UnitpostException($page->error);
            }
            $rows = is_array($page->data) ? ($page->data['data'] ?? []) : [];
            foreach ($rows as $row) {
                yield $row;
            }
            $last = $rows === [] ? null : $rows[array_key_last($rows)];
            if (empty($page->data['has_more']) || !is_array($last) || !isset($last['id'])) {
                return;
            }
            $after = $last['id'];
        }
    }
}

final class Sms extends Resource
{
    public SmsTemplates $templates;
    public SmsCampaigns $campaigns;
    public SmsTopics $topics;
    // SMS setup. Carrier purchases go through $quotes (a price, then a
    // confirm with the opt-in `sms:purchase` scope); releasing a number,
    // identity documents and toll-free verification stay in the dashboard.
    public SmsMessagingPrograms $messagingPrograms;
    public SmsTestDestinations $testDestinations;
    public SmsConsentSetups $consentSetups;
    public SmsBilling $billing;
    // Registrations are read-only.
    public SmsRegistrations $registrations;
    // Buy carrier resources: price an order, then confirm it.
    public SmsQuotes $quotes;

    public function __construct(HttpClient $http)
    {
        parent::__construct($http);
        $this->templates = new SmsTemplates($http);
        $this->campaigns = new SmsCampaigns($http);
        $this->topics = new SmsTopics($http);
        $this->messagingPrograms = new SmsMessagingPrograms($http);
        $this->testDestinations = new SmsTestDestinations($http);
        $this->consentSetups = new SmsConsentSetups($http);
        $this->billing = new SmsBilling($http);
        $this->registrations = new SmsRegistrations($http);
        $this->quotes = new SmsQuotes($http);
    }

    /**
     * Pass $idempotencyKey to make a retried send safe across your own process
     * restarts (the API replays the first result); omitted, the SDK mints one.
     *
     * @param array<string, mixed> $body
     */
    public function send(array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/sms", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/sms/" . enc($id));
    }

    /**
     * Cancel a queued/scheduled SMS (`['cancel' => true]`, credit refunded) or
     * move a scheduled one (`['scheduled_at' => '<ISO 8601>']`).
     *
     * @param array<string, mixed> $body
     */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/sms/" . enc($id), null, $body);
    }

    /**
     * List SMS messages (cursor-paginated, newest first). `q` searches the
     * message text (3 or more digits also match either number; at most 200
     * characters) and `type` is `transactional` or `marketing`. A `q` or
     * `type` value the list can't apply is ignored and named in the
     * `Unitpost-Warning` response header; `Unitpost-Strict: true` refuses it
     * instead.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`, `direction`, `status`, `from`, `to`, `participant`, `contact_id`, `campaign_id`, `created_after`, `created_before`, `q`, `type`, `sender_id` (a number's `snum_…` id: its texts, both directions).
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/sms", $params);
    }

    /**
     * Iterate every row of /sms across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/sms", $params);
    }

    /**
     * List SMS brands and their setup status.
     *
     * A brand is the business identity carriers register before they approve a
     * phone number, so this is what to poll to answer "is SMS ready yet?":
     * `status` says whether you or the carrier is the blocker, and `missing`
     * lists exactly what is still required.
     *
     * Create and edit a brand with `createBrand` / `updateBrand` (free).
     * Registering it with carriers is a paid order: `$sms->quotes->create`
     * prices it, `$sms->quotes->confirm` places it. Needs `sms:read`.
     *
     * @param array<string, mixed> $params
     */
    public function brands(array $params = []): Result
    {
        return $this->http->request("GET", "/sms/brands", $params);
    }

    /**
     * Create a brand (201): the business identity carriers register before
     * they approve a number. A draft may be partial (only `name` is required);
     * `missing` says what is still needed. Free, with no carrier call; one
     * brand per website domain (409). The tax id is write-only: stored
     * encrypted, read back as `tax_id_on_file`. Registering the brand with
     * carriers is a paid order through `$sms->quotes`. Needs `sms:manage`. Pass $idempotencyKey so a retry never makes a second
     * brand; omitted, the SDK mints one.
     *
     * @param array<string, mixed> $body
     */
    public function createBrand(array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/sms/brands", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    /** One brand with every field you can edit, its `status` and `missing` list. Needs `sms:read`. */
    public function getBrand(string $id): Result
    {
        return $this->http->request("GET", "/sms/brands/" . enc($id));
    }

    /**
     * Edit a brand: only the fields you send change, `null` clears an optional
     * one; send `['archived' => ...]` alone to archive or restore it. The tax
     * id is write-only. A locked brand (`editable: false`: carriers are
     * reviewing or approved it, or it has numbers) refuses carrier-field edits
     * with `409 brand_locked`, `details` naming each field, except those a
     * correction request opened. Needs `sms:manage`.
     *
     * @param array<string, mixed> $body
     */
    public function updateBrand(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/sms/brands/" . enc($id), null, $body);
    }

    /**
     * List SMS phone numbers.
     *
     * Only numbers with `status: "active"` can send. A `ten_dlc` number has no
     * `phone_number` until carriers approve it, and a `simulator` number only
     * reaches verified test destinations.
     *
     * Order a number through `$sms->quotes`; releasing one stays in the
     * dashboard.
     *
     * @param array<string, mixed> $params
     */
    public function numbers(array $params = []): Result
    {
        return $this->http->request("GET", "/sms/numbers", $params);
    }

    /**
     * SMS delivery stats over a rolling window (`days`: 1–365, default 30):
     * queued, sent, delivered, failed, suppressed, canceled, inbound and
     * segments billed, with delivery, failure and suppression rates. No opens
     * or clicks on SMS.
     *
     * @param array<string, mixed> $params
     */
    public function stats(array $params = []): Result
    {
        return $this->http->request("GET", "/sms/stats", $params);
    }

    /** Read a contact's SMS consent state and immutable consent history. */
    public function smsConsent(string $contactId): Result
    {
        return $this->http->request("GET", "/contacts/" . enc($contactId) . "/sms-consent");
    }

    /**
     * Record an SMS consent change (opt-in or opt-out) for a contact.
     *
     * @param array<string, mixed> $body
     */
    public function recordSmsConsent(string $contactId, array $body): Result
    {
        return $this->http->request("POST", "/contacts/" . enc($contactId) . "/sms-consent", null, $body);
    }

    /**
     * Text the contact a request to reply YES to confirm marketing texts. It
     * sends a real text, so pass $idempotencyKey to make a retry safe; omitted,
     * the SDK mints one.
     *
     * @param array<string, mixed> $body Optional `phone`: which of the contact's numbers.
     */
    public function requestSmsOptIn(string $contactId, array $body = [], ?string $idempotencyKey = null): Result
    {
        // `(object)` so the default is sent as `{}`, not `[]`.
        return $this->http->request("POST", "/contacts/" . enc($contactId) . "/sms-opt-in-request", null, (object) $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }
}

/** SMS templates: plain-text bodies with {{variable}} placeholders. */
final class SmsTemplates extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/sms/templates", $params);
    }

    /**
     * Iterate every row of /sms/templates across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/sms/templates", $params);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/sms/templates", null, $body);
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/sms/templates/" . enc($id));
    }

    /** @param array<string, mixed> $body */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/sms/templates/" . enc($id), null, $body);
    }

    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/sms/templates/" . enc($id));
    }

    /**
     * What uses the template, and whether a change would be accepted now: its
     * live campaigns and automations, queued texts, and `can_edit_content` /
     * `can_unpublish` with the reason when not. Read it before an edit or a
     * delete. Needs `templates:read`.
     */
    public function usage(string $id): Result
    {
        return $this->http->request("GET", "/sms/templates/" . enc($id) . "/usage");
    }

    /**
     * Send ONE test text of the template to `to`, which must be a simulator
     * number (`+14254147755` delivers, `+14254147167` fails) or a verified
     * number from `$client->sms->testDestinations->list()` (any other is a
     * 403). Every `{{variable}}` needs a value in `variables` (422). It goes
     * from `sender_id`, or is routed by the destination's country. It is a
     * real text, billed like any. Needs `sms:send`. Pass $idempotencyKey so a retry never texts twice;
     * omitted, the SDK mints one.
     *
     * @param array<string, mixed> $body `to`, and an optional `variables` and `sender_id`.
     */
    public function testSend(string $id, array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/sms/templates/" . enc($id) . "/test-send", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }
}

/** SMS subscription topics (independent from email topics); delete archives. */
/** SMS campaigns: a published SMS template sent to a segment; delete archives. */
final class SmsCampaigns extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/sms/campaigns", $params);
    }

    /**
     * Iterate every row of /sms/campaigns across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/sms/campaigns", $params);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/sms/campaigns", null, $body);
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/sms/campaigns/" . enc($id));
    }

    /** @param array<string, mixed> $body */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/sms/campaigns/" . enc($id), null, $body);
    }

    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/sms/campaigns/" . enc($id));
    }

    /** @param array<string, mixed> $body Optional `scheduled_at`; omit to arm the draft's saved send time (or send now). */
    public function send(string $id, array $body = []): Result
    {
        // Always a JSON object: json_encode([]) is `[]`, which the endpoint
        // rejects (422). `(object)` turns the default into `{}`.
        return $this->http->request("POST", "/sms/campaigns/" . enc($id) . "/send", null, (object) $body);
    }

    public function cancel(string $id): Result
    {
        return $this->http->request("POST", "/sms/campaigns/" . enc($id) . "/cancel");
    }

    public function pause(string $id): Result
    {
        return $this->http->request("POST", "/sms/campaigns/" . enc($id) . "/pause");
    }

    public function resume(string $id): Result
    {
        return $this->http->request("POST", "/sms/campaigns/" . enc($id) . "/resume");
    }

    /** Move a scheduled campaign back to draft so it can be edited; clears its send time. */
    public function unschedule(string $id): Result
    {
        return $this->http->request("POST", "/sms/campaigns/" . enc($id) . "/unschedule");
    }

    /**
     * Copy a campaign into a new draft (201). Pass $idempotencyKey so a retry
     * returns the same copy; omitted, the SDK mints one.
     *
     * @param array<string, mixed> $body Optional `name` for the copy.
     */
    public function duplicate(string $id, array $body = [], ?string $idempotencyKey = null): Result
    {
        // `(object)` so the default is sent as `{}`, not `[]`.
        return $this->http->request("POST", "/sms/campaigns/" . enc($id) . "/duplicate", null, (object) $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    /** @param array<string, mixed> $body */
    public function reschedule(string $id, array $body): Result
    {
        return $this->http->request("POST", "/sms/campaigns/" . enc($id) . "/reschedule", null, $body);
    }

    public function validate(string $id): Result
    {
        return $this->http->request("GET", "/sms/campaigns/" . enc($id) . "/validate");
    }

    /**
     * How each recipient fared: the carrier's answer for a text that went out
     * (`delivered`, `sent`, `failed`, …), or why none was sent (`skipped`, with
     * `reason_code`). Newest first, cursor-paginated on `id`; `q` matches
     * digits of the number. `skipped_by_reason` counts skipped recipients over
     * the whole campaign.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`, `q`.
     */
    public function listRecipients(string $id, array $params = []): Result
    {
        return $this->http->request("GET", "/sms/campaigns/" . enc($id) . "/recipients", $params);
    }
}

final class SmsTopics extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/sms/topics", $params);
    }

    /**
     * Iterate every row of /sms/topics across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/sms/topics", $params);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/sms/topics", null, $body);
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/sms/topics/" . enc($id));
    }

    /** @param array<string, mixed> $body */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/sms/topics/" . enc($id), null, $body);
    }

    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/sms/topics/" . enc($id));
    }

    /**
     * Opt many contacts out of this SMS topic as a background job (202, the
     * job): `['contact_ids' => [...]]` or `['emails' => [...]]` (up to 1,000)
     * or `['segment_id' => ...]` (every member). Follow it with
     * `jobs->get($id)`. Pass an `$idempotencyKey` so a retry answers the same
     * job; one is generated when you don't. There is no bulk opt-in.
     *
     * @param array<string, mixed> $body
     */
    public function optOut(string $id, array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/sms/topics/" . enc($id) . "/opt-out", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }
}

/**
 * Messaging programs (`/sms/messaging-programs`): what a sender registration
 * says your texts are for, how people opt in, the help and stop replies and
 * sample messages. A registration copies the program when it is submitted, so
 * a later edit never changes one. Reads need `sms:read`; the writes need
 * `sms:manage`, which FULL keys have and SENDING keys don't. Submitting a
 * registration is a paid order through `$sms->quotes`.
 */
final class SmsMessagingPrograms extends Resource
{
    /**
     * Your programs, newest first, cursor-paginated (`limit`, `after`,
     * `before`); `['archived' => true]` includes archived ones. A boolean
     * `archived` is sent as `true` / `false`.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`, `archived`.
     */
    public function list(array $params = []): Result
    {
        if (is_bool($params['archived'] ?? null)) {
            $params['archived'] = $params['archived'] ? 'true' : 'false';
        }
        return $this->http->request("GET", "/sms/messaging-programs", $params);
    }

    /**
     * Create a program (201; `name` is required). Each field is checked by the
     * carriers' rules: lengths, help and stop replies that are a sentence and
     * not just a keyword, links to a real web address. Pass $idempotencyKey so
     * a retry never makes a second one; omitted, the SDK mints one.
     *
     * @param array<string, mixed> $body
     */
    public function create(array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/sms/messaging-programs", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    /** One program, archived ones too. */
    public function get(string $id): Result
    {
        return $this->http->request("GET", "/sms/messaging-programs/" . enc($id));
    }

    /**
     * Edit a program: only the fields you send change, `null` clears one, and
     * the result must still pass the carriers' rules. `revision` moves on a
     * material change. An archived program is a 409: `duplicate` it to keep
     * editing.
     *
     * @param array<string, mixed> $body
     */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/sms/messaging-programs/" . enc($id), null, $body);
    }

    /** Archive a program. Registrations that copied it keep their copy. */
    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/sms/messaging-programs/" . enc($id));
    }

    /**
     * Copy a program (an archived one too) into a new editable one named
     * "… (copy)" at revision 1 (201). It sends no body. Pass $idempotencyKey
     * so a retry returns the same copy; omitted, the SDK mints one.
     */
    public function duplicate(string $id, ?string $idempotencyKey = null): Result
    {
        // No body: the route takes none, and null sends nothing. It is passed
        // as $body like every idempotent send, which `sdk:check` reads.
        $body = null;
        return $this->http->request("POST", "/sms/messaging-programs/" . enc($id) . "/duplicate", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }
}

/**
 * Test numbers (`/sms/test-destinations`): the numbers a template test may
 * text besides the simulator, `pending` until the code texted to one is
 * entered, then `verified`. Adding and verifying one stays in the dashboard
 * (Phone numbers → Testing), because it proves the phone is yours. `list`
 * needs `sms:read`; `delete` needs `sms:manage`.
 */
final class SmsTestDestinations extends Resource
{
    /**
     * Your test numbers, newest first, cursor-paginated.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`.
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/sms/test-destinations", $params);
    }

    /** Remove a test number; template tests can no longer text it. */
    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/sms/test-destinations/" . enc($id));
    }
}

/**
 * Consent setups (`/sms/consent-setups`): where and how people opt in to your
 * texts, the form or page and the brand and purpose it discloses. A messaging
 * program points at one with `consent_setup_id`. Needs `sms:read`.
 */
final class SmsConsentSetups extends Resource
{
    /**
     * Your consent setups except archived ones, newest first, cursor-paginated.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`.
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/sms/consent-setups", $params);
    }
}

/** SMS billing (`/sms/billing`). Needs `sms:read`; buying goes through `$sms->quotes`. */
final class SmsBilling extends Resource
{
    /**
     * What the workspace pays for SMS every month: each number and 10DLC
     * campaign, its monthly price, status and next charge, and for a past-due
     * number the date it is released (`grace_until`). Newest first,
     * cursor-paginated.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`.
     */
    public function listResources(array $params = []): Result
    {
        return $this->http->request("GET", "/sms/billing/resources", $params);
    }
}

/**
 * Carrier purchases (`/sms/quotes`): register a 10DLC brand, vet it, register
 * a campaign, order a number. Each is two calls: `create` prices the order
 * and binds what it buys, `confirm` places it at the price you showed.
 */
final class SmsQuotes extends Resource
{
    /**
     * Price an order (201). The quote binds the action and what it buys, and
     * returns `due_now_cents`, `monthly_cents`, `line_items` and a single-use
     * `confirm_token` (only in this response). It lasts 10 minutes and
     * charges nothing. Needs `sms:manage`.
     *
     * @param array<string, mixed> $body
     */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/sms/quotes", null, $body);
    }

    /** One quote: its price, status and, once confirmed, what the order made. Needs `sms:read`. */
    public function get(string $id): Result
    {
        return $this->http->request("GET", "/sms/quotes/" . enc($id));
    }

    /**
     * Place the quoted order and charge it. Show the person the quote's price
     * and get their yes first. Needs `sms:purchase`, which a key or app has
     * only when a member who holds it ticked it, and the owner's monthly cap for
     * SMS purchases by API. A changed price or content answers 409: quote
     * again. Omit $idempotencyKey and the SDK mints one so a retry can't buy
     * twice; pass your own to replay the same answer later.
     *
     * @param array<string, mixed> $body `['confirm_token' => 'sqct_…']`
     */
    public function confirm(string $id, array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/sms/quotes/" . enc($id) . "/confirm", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }
}

/**
 * Carrier registrations (`/sms/registrations`): 10DLC brand and campaign
 * registrations and toll-free verifications, with their review state and the
 * carrier's feedback. Submitting one is a paid order through `$sms->quotes`;
 * toll-free verification and removing one stay in the dashboard. Needs
 * `sms:read`.
 */
final class SmsRegistrations extends Resource
{
    /**
     * Your registrations, newest first, cursor-paginated. A 10DLC campaign
     * also carries the number it backs (`sender`) and one `setup_state`.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`.
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/sms/registrations", $params);
    }
}

final class Email extends Resource
{
    public Topics $topics;
    public Campaigns $campaigns;
    public Templates $templates;
    public Domains $domains;
    /** Inbox conversations: list, read, delete and reply. */
    public EmailThreads $threads;

    public function __construct(HttpClient $http)
    {
        parent::__construct($http);
        $this->topics = new Topics($http);
        $this->campaigns = new Campaigns($http);
        $this->templates = new Templates($http);
        $this->domains = new Domains($http);
        $this->threads = new EmailThreads($http);
    }

    /**
     * Pass $idempotencyKey to make a retried send safe across your own process
     * restarts (the API replays the first result); omitted, the SDK mints one.
     *
     * @param array<string, mixed> $body
     */
    public function send(array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/email", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    /** @param array<string, mixed> $body */
    public function batch(array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/email/batch", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    public function getBatch(string $id): Result
    {
        return $this->http->request("GET", "/email/batches/" . enc($id));
    }

    public function cancelBatch(string $id): Result
    {
        return $this->http->request("POST", "/email/batches/" . enc($id) . "/cancel");
    }

    /**
     * Your batch sends, newest first (cursor-paginated), as the dashboard's
     * Batches tab lists them. Each row is the same live rollup `getBatch`
     * returns: how many emails it holds, when it's scheduled, a count per
     * delivery status and how many are still cancelable.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`.
     */
    public function listBatches(array $params = []): Result
    {
        return $this->http->request("GET", "/email/batches", $params);
    }

    /**
     * List sent and scheduled emails (cursor-paginated, newest first). `q` is
     * an exact recipient address, or part of the subject or the sender (at
     * most 200 characters); `source` is `api`, `smtp`, `test`, `batch`,
     * `campaign` or `reply`; `outcome` is `delivered`, `opened`, `clicked`,
     * `unsubscribed`, `bounced`, `complained`, `failed` or `pending` (with
     * `campaign_id`: who opened or clicked that campaign). A `q`, `source` or
     * `outcome` value the list can't apply is ignored and named in the
     * `Unitpost-Warning` response header; `Unitpost-Strict: true` refuses it
     * instead.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`, `status`, `to`, `contact_id`, `campaign_id`, `batch_id`, `created_after`, `created_before`, `q`, `source`, `outcome`.
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/email", $params);
    }

    /**
     * Iterate every row of /email across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/email", $params);
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/email/" . enc($id));
    }

    /**
     * One sent email's delivery events, oldest first, as the dashboard's email
     * detail shows the timeline. Each event carries its detail in `data` under
     * the webhook's names (`data.bounce.diagnostic_code`, `data.click.link`,
     * `data.complaint.feedback_type`, ...). Cursor-paginated: `after` /
     * `before` take an `emev_` id. Needs `emails:read`.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`.
     */
    public function listEvents(string $id, array $params = []): Result
    {
        return $this->http->request("GET", "/email/" . enc($id) . "/events", $params);
    }

    /** @param array<string, mixed> $body */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/email/" . enc($id), null, $body);
    }

    /**
     * Send a `failed` email again on the same `id`, as the dashboard's Retry
     * does. Only a one-off send whose failure was temporary is retried: a
     * campaign recipient, a template test, a suppressed or rejected send, or a
     * content error is a 409 saying why (send a new email instead). It passes
     * the same checks as a new send: the sending domain, your plan and quota.
     * It sends no body; follow the email with `get`.
     *
     * It sends real email: pass $idempotencyKey so a retried call can't send
     * it twice; omitted, the SDK mints one.
     */
    public function retry(string $id, ?string $idempotencyKey = null): Result
    {
        // No body: the route takes none, and null sends nothing. It is passed
        // as $body like every idempotent send, which `sdk:check` reads.
        $body = null;
        return $this->http->request("POST", "/email/" . enc($id) . "/retry", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    /**
     * Deliverability/engagement aggregates over a rolling window (`days`:
     * 1–365, defaults to 30 server-side) or a period: `from` and `to`, ISO
     * 8601 with `Z` or an offset. Add `grain` (`5m`, `1h` or `1d`, with
     * `from`/`to`) and `tz` (an IANA zone) for `series`, the send volume per
     * bucket. `domain_id` (`dom_…`) counts one sending domain. A value the
     * API can't apply is ignored and named in the `Unitpost-Warning`
     * response header; `Unitpost-Strict: true` refuses it instead.
     *
     * @param array<string, mixed> $params `days`, `from`, `to`, `grain`, `tz`, `domain_id`.
     */
    public function stats(array $params = []): Result
    {
        return $this->http->request("GET", "/email/stats", $params);
    }

    /** @param array<string, mixed> $params */
    public function receivedList(array $params = []): Result
    {
        return $this->http->request("GET", "/email/received", $params);
    }

    public function receivedGet(string $id): Result
    {
        return $this->http->request("GET", "/email/received/" . enc($id));
    }

    public function receivedAttachmentUrl(string $id, string $attachmentId): Result
    {
        return $this->http->request("GET", "/email/received/" . enc($id) . "/attachments/" . enc($attachmentId));
    }

    /**
     * A signed URL to download the received message's original MIME (`.eml`),
     * valid for about 5 minutes (`expires_at`), with its `size_bytes`. A body
     * over 256 KB isn't inlined on `receivedGet` (its `text` and `html` are
     * null), so this is how to read it. A copy of one of your own sends
     * (`sent_by_us`) is a 403. Needs `emails:read`.
     */
    public function receivedRaw(string $id): Result
    {
        return $this->http->request("GET", "/email/received/" . enc($id) . "/raw");
    }
}

final class Contacts extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/contacts", $params);
    }

    /**
     * Iterate every row of /contacts across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/contacts", $params);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/contacts", null, $body);
    }

    public function get(string $idOrEmail): Result
    {
        return $this->http->request("GET", "/contacts/" . enc($idOrEmail));
    }

    /** @param array<string, mixed> $body */
    public function update(string $idOrEmail, array $body): Result
    {
        return $this->http->request("PATCH", "/contacts/" . enc($idOrEmail), null, $body);
    }

    public function delete(string $idOrEmail): Result
    {
        return $this->http->request("DELETE", "/contacts/" . enc($idOrEmail));
    }

    /** @param array<string, mixed> $body */
    public function import(array $body): Result
    {
        return $this->http->request("POST", "/contacts/imports", null, $body);
    }

    /** @param array<string, mixed> $params */
    public function listImports(array $params = []): Result
    {
        return $this->http->request("GET", "/contacts/imports", $params);
    }

    public function getImport(string $id): Result
    {
        return $this->http->request("GET", "/contacts/imports/" . enc($id));
    }

    public function cancelImport(string $id): Result
    {
        return $this->http->request("POST", "/contacts/imports/" . enc($id) . "/cancel");
    }

    /** Re-run a failed import from its stored rows (202); rows already added are skipped. */
    public function retryImport(string $id): Result
    {
        return $this->http->request("POST", "/contacts/imports/" . enc($id) . "/retry");
    }

    /** The contact's email addresses and phone numbers, with each one's status (one page, never `has_more`). */
    public function addresses(string $idOrEmail): Result
    {
        return $this->http->request("GET", "/contacts/" . enc($idOrEmail) . "/addresses");
    }

    /**
     * The contact's most recent emails and texts, newest first (`limit` 1–100, default 25; no cursor).
     *
     * @param array<string, mixed> $params
     */
    public function activity(string $idOrEmail, array $params = []): Result
    {
        return $this->http->request("GET", "/contacts/" . enc($idOrEmail) . "/activity", $params);
    }

    /**
     * The segments the contact is in, with its per-segment opt-out (not
     * paginated). To add the contact to a static segment or remove it, use
     * `$client->segments->addMember()` / `removeMember()`.
     */
    public function listSegments(string $idOrEmail): Result
    {
        return $this->http->request("GET", "/contacts/" . enc($idOrEmail) . "/segments");
    }

    /**
     * Export contacts to a CSV file (202), as the Contacts page's Export does:
     * every contact, or the ones the filters match (`q`, `status`, `phone`,
     * `segment_id`, `segment_subscription`, as on `list`). It runs as a
     * background job: follow it with `jobs->get($job['id'])`, then fetch the
     * file with `jobs->download($job['id'])`. One export runs at a time:
     * another while it runs is a 409 `job_in_progress`. `segment_id` also
     * needs `segments:read`.
     *
     * @param array<string, mixed> $body Optional filters.
     */
    public function export(array $body = []): Result
    {
        // `(object)` so the default is sent as `{}`, not `[]`.
        return $this->http->request("POST", "/contacts/export", null, (object) $body);
    }
}

final class ContactFields extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/contact-fields", $params);
    }

    /**
     * Iterate every row of /contact-fields across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/contact-fields", $params);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/contact-fields", null, $body);
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/contact-fields/" . enc($id));
    }

    /** @param array<string, mixed> $body */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/contact-fields/" . enc($id), null, $body);
    }

    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/contact-fields/" . enc($id));
    }

    /** @param array<string, mixed> $body */
    public function rename(string $id, array $body): Result
    {
        return $this->http->request("POST", "/contact-fields/" . enc($id) . "/rename", null, $body);
    }
}

final class Jobs extends Resource
{
    /**
     * Your background jobs, newest first (cursor-paginated), as Settings →
     * Bulk operations lists them: the kinds an API operation starts that your
     * key may read. Filter with `status` (`QUEUED`, `RUNNING`, `COMPLETED`,
     * `FAILED`, `CANCELED`) or `kind` (`contacts.export`,
     * `contact_field.delete`, …).
     *
     * @param array<string, mixed> $params `status`, `kind`, `limit`, `after`, `before`.
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/jobs", $params);
    }

    /** A background job (the 202 answer of a large operation); poll until it finishes. */
    public function get(string $id): Result
    {
        return $this->http->request("GET", "/jobs/" . enc($id));
    }

    /**
     * Stop a queued or running job. A running job stops after the part in
     * flight; the work already done stays. A kind that can't stop half-way (a
     * custom-field rename) is a 409 saying why. It sends no body.
     */
    public function cancel(string $id): Result
    {
        return $this->http->request("POST", "/jobs/" . enc($id) . "/cancel");
    }

    /**
     * Resume a `FAILED` job from where it stopped (202): nothing it already
     * processed runs again. Any other status is a 409. It sends no body.
     */
    public function retry(string $id): Result
    {
        return $this->http->request("POST", "/jobs/" . enc($id) . "/retry");
    }

    /**
     * A finished job's file (a contacts export's CSV) as a signed `url` that
     * expires in 5 minutes: fetch it right away, and call `download` again for
     * a fresh one. A job that isn't `COMPLETED`, or a kind with no file, is a
     * 409.
     */
    public function download(string $id): Result
    {
        return $this->http->request("GET", "/jobs/" . enc($id) . "/download");
    }
}

final class Segments extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/segments", $params);
    }

    /**
     * Iterate every row of /segments across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/segments", $params);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/segments", null, $body);
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/segments/" . enc($id));
    }

    /** @param array<string, mixed> $body */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/segments/" . enc($id), null, $body);
    }

    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/segments/" . enc($id));
    }

    /** @param array<string, mixed> $params */
    public function listMembers(string $id, array $params = []): Result
    {
        return $this->http->request("GET", "/segments/" . enc($id) . "/contacts", $params);
    }

    /** @param array<string, mixed> $body */
    public function addMember(string $id, array $body): Result
    {
        return $this->http->request("POST", "/segments/" . enc($id) . "/contacts", null, $body);
    }

    public function removeMember(string $id, string $contact): Result
    {
        return $this->http->request("DELETE", "/segments/" . enc($id) . "/contacts/" . enc($contact));
    }

    /**
     * Opt a member (by id or email) out of this segment's sends without
     * removing it (`['subscribed' => false]`), or back in, as the members
     * table's toggle does. It stays a member. A dynamic segment has no
     * per-segment opt-out (422).
     *
     * @param array<string, mixed> $body `subscribed`.
     */
    public function updateMember(string $id, string $contact, array $body): Result
    {
        return $this->http->request("PATCH", "/segments/" . enc($id) . "/contacts/" . enc($contact), null, $body);
    }

    /**
     * How many contacts a saved segment reaches: its members, the subscribed
     * set a campaign would mail (`0` means a campaign would send nothing), and
     * a sample (which also needs `contacts:read`).
     */
    public function preview(string $id): Result
    {
        return $this->http->request("GET", "/segments/" . enc($id) . "/preview");
    }

    /**
     * Count an unsaved dynamic filter before you create the segment, as the
     * segment dialog's live count does: how many contacts match, how many of
     * them a campaign would mail, and a sample (which also needs
     * `contacts:read`). Nothing is saved.
     *
     * @param array<string, mixed> $body `filter`.
     */
    public function previewFilter(array $body): Result
    {
        return $this->http->request("POST", "/segments/preview", null, $body);
    }

    /**
     * The workspace's managed "All subscribed contacts" segment, the
     * dashboard's "Everyone subscribed" campaign target: made the first time
     * you ask (201), the same segment after that (200). It sends no body.
     */
    public function allContacts(): Result
    {
        return $this->http->request("POST", "/segments/all-contacts");
    }
}

final class Topics extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/email/topics", $params);
    }

    /**
     * Iterate every row of /email/topics across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/email/topics", $params);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/email/topics", null, $body);
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/email/topics/" . enc($id));
    }

    /** @param array<string, mixed> $body */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/email/topics/" . enc($id), null, $body);
    }

    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/email/topics/" . enc($id));
    }

    /**
     * Opt many contacts out of this topic as a background job (202, the job):
     * `['contact_ids' => [...]]` or `['emails' => [...]]` (up to 1,000, each a
     * contact of this workspace) or `['segment_id' => ...]` (every member,
     * however many). Each is opted out as `setTopic` would, with its webhook
     * only when it was subscribed. Follow it with `jobs->get($id)`. Pass an
     * `$idempotencyKey` so a retry answers the same job; one is generated when
     * you don't. There is no bulk opt-in.
     *
     * @param array<string, mixed> $body
     */
    public function optOut(string $id, array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/email/topics/" . enc($id) . "/opt-out", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    /** @param array<string, mixed> $params */
    public function listTopics(string $contactId, array $params = []): Result
    {
        return $this->http->request("GET", "/contacts/" . enc($contactId) . "/topics", $params);
    }

    /** @param array<string, mixed> $body */
    public function setTopic(string $contactId, array $body): Result
    {
        return $this->http->request("POST", "/contacts/" . enc($contactId) . "/topics", null, $body);
    }

    /**
     * Change a contact's preferences for several topics in one call
     * (`['topics' => [['topic_id' => ..., 'subscribed' => ...]]]`, up to 50,
     * each topic once); topics left out keep theirs. Every topic is checked
     * before any is set (an unknown one is a 404 and nothing changes).
     * Answers the resolved preferences in the order sent (`object` `list`,
     * `has_more` false, `data`). Idempotent.
     *
     * @param array<string, mixed> $body
     */
    public function updateTopics(string $contactId, array $body): Result
    {
        return $this->http->request("PATCH", "/contacts/" . enc($contactId) . "/topics", null, $body);
    }
}

final class Campaigns extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/email/campaigns", $params);
    }

    /**
     * Iterate every row of /email/campaigns across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/email/campaigns", $params);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/email/campaigns", null, $body);
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/email/campaigns/" . enc($id));
    }

    /** @param array<string, mixed> $body */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/email/campaigns/" . enc($id), null, $body);
    }

    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/email/campaigns/" . enc($id));
    }

    /**
     * @param array<string, mixed> $body `scheduled_at` (ISO 8601) schedules the draft, `null` sends now;
     *                                   omit to arm its saved send time while still ahead (otherwise it sends now).
     */
    public function send(string $id, array $body = []): Result
    {
        // Always a JSON object: json_encode([]) is `[]`, which the endpoint
        // rejects (422). `(object)` turns the default into `{}`.
        return $this->http->request("POST", "/email/campaigns/" . enc($id) . "/send", null, (object) $body);
    }

    public function cancel(string $id): Result
    {
        return $this->http->request("POST", "/email/campaigns/" . enc($id) . "/cancel");
    }

    public function pause(string $id): Result
    {
        return $this->http->request("POST", "/email/campaigns/" . enc($id) . "/pause");
    }

    public function resume(string $id): Result
    {
        return $this->http->request("POST", "/email/campaigns/" . enc($id) . "/resume");
    }

    /** Move a scheduled campaign back to draft so it can be edited; clears its send time. */
    public function unschedule(string $id): Result
    {
        return $this->http->request("POST", "/email/campaigns/" . enc($id) . "/unschedule");
    }

    /**
     * Copy a campaign into a new draft (201). Pass $idempotencyKey so a retry
     * returns the same copy; omitted, the SDK mints one.
     *
     * @param array<string, mixed> $body Optional `name` for the copy.
     */
    public function duplicate(string $id, array $body = [], ?string $idempotencyKey = null): Result
    {
        // `(object)` so the default is sent as `{}`, not `[]`.
        return $this->http->request("POST", "/email/campaigns/" . enc($id) . "/duplicate", null, (object) $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    /** @param array<string, mixed> $body */
    public function reschedule(string $id, array $body): Result
    {
        return $this->http->request("POST", "/email/campaigns/" . enc($id) . "/reschedule", null, $body);
    }

    public function validate(string $id): Result
    {
        return $this->http->request("GET", "/email/campaigns/" . enc($id) . "/validate");
    }

    /**
     * The campaign's results: delivered, unique opens and clicks, unsubscribes,
     * bounces, complaints and their rates. Every rate is `null` until something
     * was sent; open and click rates are `null` when that tracking was off.
     */
    public function stats(string $id): Result
    {
        return $this->http->request("GET", "/email/campaigns/" . enc($id) . "/stats");
    }

    /**
     * `stats` for up to 100 campaigns in one read. An id that isn't one of this
     * workspace's email campaigns is left out of `data`, never an error;
     * `has_more` is always false.
     *
     * @param list<string> $ids 1–100 campaign ids.
     */
    public function listStats(array $ids): Result
    {
        return $this->http->request("GET", "/email/campaigns/stats", ["ids" => implode(",", $ids)]);
    }

    /**
     * Who the campaign would reach if sent now, member by member: `sendable`,
     * `missing_data`, `suppressed` or `excluded`, with the reason. Pass the
     * page's `next_cursor` as `after` for the next page (rows carry no `id`).
     * A page can hold fewer rows than `limit` while `has_more` is true.
     *
     * @param array<string, mixed> $params `status` (default `all`), `limit` (1–100, default 50), `after`.
     */
    public function listRecipients(string $id, array $params = []): Result
    {
        return $this->http->request("GET", "/email/campaigns/" . enc($id) . "/recipients", $params);
    }

    /**
     * After an automatic quality pause: the recipients whose message
     * hard-bounced or drew a spam complaint. They're already suppressed; this
     * is for cleaning the source list. Up to 10,000; `has_more` says it was
     * cut there.
     */
    public function listAffected(string $id): Result
    {
        return $this->http->request("GET", "/email/campaigns/" . enc($id) . "/quality/affected");
    }

    /**
     * After an automatic quality pause: take the flagged contacts out of the
     * campaign's static segment (needs `segments:write` too; a filter segment
     * is a 409). A repeat removes nothing. It doesn't resume the campaign.
     */
    public function removeAffected(string $id): Result
    {
        return $this->http->request("POST", "/email/campaigns/" . enc($id) . "/quality/remove-affected");
    }

    /**
     * Ask for the review a campaign needs before it can resume after a repeat
     * quality pause (`resume` answers 409 `review_required` until then). A
     * repeat answers `already_requested: true` and notifies no one.
     */
    public function requestReview(string $id): Result
    {
        return $this->http->request("POST", "/email/campaigns/" . enc($id) . "/quality/request-review");
    }
}

final class Templates extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/email/templates", $params);
    }

    /**
     * Iterate every row of /email/templates across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/email/templates", $params);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/email/templates", null, $body);
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/email/templates/" . enc($id));
    }

    /** @param array<string, mixed> $body */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/email/templates/" . enc($id), null, $body);
    }

    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/email/templates/" . enc($id));
    }

    /**
     * Send ONE test of the template to $body['to'] with sample variables.
     * Once a sending domain is verified it may go to anyone; until then only
     * to a member of your workspace.
     *
     * @param array<string, mixed> $body
     */
    public function testSend(string $id, array $body): Result
    {
        return $this->http->request("POST", "/email/templates/" . enc($id) . "/test-send", null, $body);
    }

    /**
     * The `subject`, `html` and `text` a send of this template would carry,
     * with your footer and branding, filled from `variables` (and from a
     * contact's fields with `contact_id`). The body is optional: `[]` renders
     * with no variables. It sends and stores nothing, so it takes no
     * idempotency key. `missing_variables` lists the variables left without a
     * value: they render as a literal `{{token}}`, and a real send would
     * refuse them. Needs `templates:read`, and has its own per-workspace rate
     * limit.
     *
     * @param array<string, mixed> $body Optional `variables`, `subject`, `contact_id`.
     */
    public function render(string $id, array $body = []): Result
    {
        // `(object)` so the default is sent as `{}`, not `[]`.
        return $this->http->request("POST", "/email/templates/" . enc($id) . "/render", null, (object) $body);
    }

    /**
     * Copy a template into a new draft (201): its name (with " (copy)", or
     * your `name`), subject, editor and body carry over; its status, versions
     * and usage don't. Pass $idempotencyKey so a retry returns the same copy;
     * omitted, the SDK mints one.
     *
     * @param array<string, mixed> $body Optional `name` for the copy.
     */
    public function duplicate(string $id, array $body = [], ?string $idempotencyKey = null): Result
    {
        // `(object)` so the default is sent as `{}`, not `[]`.
        return $this->http->request("POST", "/email/templates/" . enc($id) . "/duplicate", null, (object) $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    /**
     * Add one of the gallery's ready-made emails (unitpost.com/templates) to
     * your workspace as a draft Design template (201). `slug` names it; the
     * ones marked for paid plans answer 403 `limit_reached` on the free plan.
     * Pass $idempotencyKey so a retry returns the same template; omitted, the
     * SDK mints one.
     *
     * @param array<string, mixed> $body `slug` and an optional `name`.
     */
    public function fromLibrary(array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/email/templates/from-library", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    /**
     * What uses the template, and whether a change would be accepted now: the
     * campaigns that reference it, its live campaigns and automations, queued
     * sends, and `can_edit_content` / `can_unpublish` with the reason when not.
     * Read it before an edit or a delete: a content edit is refused while a
     * live campaign renders the template, and a delete while any campaign uses
     * it.
     */
    public function usage(string $id): Result
    {
        return $this->http->request("GET", "/email/templates/" . enc($id) . "/usage");
    }

    /**
     * The template's history, newest first: a version is added on every
     * publish and on every content save while it's published. It pages by
     * version number: pass the last row's `version` (an integer, not an id) as
     * `after`. There is no `before`.
     *
     * @param array<string, mixed> $params `limit` (1–100, default 20), `after`.
     */
    public function listVersions(string $id, array $params = []): Result
    {
        return $this->http->request("GET", "/email/templates/" . enc($id) . "/versions", $params);
    }

    /**
     * Put a version's subject and body back, as the editor's History → Restore
     * does. It is an update, so a system template, or one a live campaign
     * renders, is refused with a 409 naming why (`usage` says so up front). On
     * a published template the restore adds a new version; history is never
     * rewritten. It sends no body.
     */
    public function restoreVersion(string $id, int $version): Result
    {
        $v = (string) $version;
        return $this->http->request("POST", "/email/templates/" . enc($id) . "/versions/" . enc($v) . "/restore");
    }
}

/**
 * Inbox conversations (`/email/threads`, `thr_…`): each received message and
 * the replies and forwards sent from it are one conversation, as Emails →
 * Received shows them.
 */
final class EmailThreads extends Resource
{
    /**
     * Every conversation (Spam included), newest activity first. `q` searches
     * the subject, the sender and the opening words. Pass the page's
     * `next_cursor` as `after` for the next page (not the last row's `id`).
     *
     * @param array<string, mixed> $params `q`, `limit` (1–100, default 50), `after`.
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/email/threads", $params);
    }

    /**
     * One conversation, oldest message first: what you received (with bodies)
     * and what you sent from it (status and recipients, no body).
     */
    public function get(string $id): Result
    {
        return $this->http->request("GET", "/email/threads/" . enc($id));
    }

    /**
     * Delete a conversation for good: its received messages are erased;
     * replies and forwards sent from it stay on your sent emails. It can't be
     * undone.
     */
    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/email/threads/" . enc($id));
    }

    /**
     * Reply, reply all or forward one received message of the conversation
     * (`received_email_id`): your `text`/`html` go on top and the original is
     * quoted under them. It sends now and marks the conversation read. It
     * sends real email, so pass $idempotencyKey to make a retry safe; omitted,
     * the SDK mints one.
     *
     * @param array<string, mixed> $body
     */
    public function reply(string $id, array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/email/threads/" . enc($id) . "/reply", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }
}

/**
 * Brand Kit profiles (voice, colors, pinned `/img/{id}` graphics), and the
 * imports that fill them from a website or the brand's own words. The reads
 * (`list`, `get`, `getImport`) need `templates:read`; the writes and
 * starting, accepting or canceling an import need `branding:write`, which no
 * key preset grants: tick it on the key.
 */
final class BrandKits extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/brand-kits", $params);
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/brand-kits/" . enc($id));
    }

    /**
     * Create an empty profile (201); `name` is unique in the workspace. The
     * first profile, or one with `make_default`, becomes the default. At most
     * five profiles (403 `limit_reached`). Fill it with `update` or `import`.
     *
     * @param array<string, mixed> $body `name`, and an optional `make_default`.
     */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/brand-kits", null, $body);
    }

    /**
     * Edit a profile: only the fields you send change, a list replaces the
     * list, and `null` or `""` clears a text field. `graphics` pins library
     * images by `image_id` (`img_…`). `sync_visual` on the default profile
     * also sets the email branding's accent from its colors.
     *
     * @param array<string, mixed> $body
     */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/brand-kits/" . enc($id), null, $body);
    }

    /**
     * Delete a profile. When it was the default, the oldest remaining profile
     * becomes the default. The last profile can't be deleted (409): `reset`
     * it instead. Its pinned images stay in the library.
     */
    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/brand-kits/" . enc($id));
    }

    /**
     * Clear a profile's content (voice, colors, graphics, pages), keeping its
     * name and whether it's the default. It sends no body.
     */
    public function reset(string $id): Result
    {
        return $this->http->request("POST", "/brand-kits/" . enc($id) . "/reset");
    }

    /** Make a profile the default: the one agents write with unless told otherwise. It sends no body. */
    public function setDefault(string $id): Result
    {
        return $this->http->request("POST", "/brand-kits/" . enc($id) . "/set-default");
    }

    /**
     * Add a graphic (201) from a public https image `url` (fetched once, no
     * redirects): it goes into the image library, counted against its
     * storage, and is pinned with its `role` (default `logo`). To pin an image
     * already in the library, `update` the profile's `graphics`.
     *
     * @param array<string, mixed> $body `url`, and an optional `role` and `label`.
     */
    public function addGraphic(string $id, array $body): Result
    {
        return $this->http->request("POST", "/brand-kits/" . enc($id) . "/graphics", null, $body);
    }

    /**
     * Build a profile from a website (`url`, or a bare domain) or the brand's
     * own words (`text`). It runs in the background, usually under a minute
     * (202): follow it with `getImport`. By default the result is applied when
     * it lands, to `brand_kit_id`, else the default profile, or a new one when
     * the default already has content. With `'review' => true` it stops at a
     * proposal (`preview_ready`) for `acceptImport`; one review import is open
     * at a time, and a new one replaces it.
     *
     * @param array<string, mixed> $body `url` or `text`, and optional `website_url`, `brand_kit_id`, `review`, `sync_visual`.
     */
    public function import(array $body): Result
    {
        return $this->http->request("POST", "/brand-kits/imports", null, $body);
    }

    /** One import: its status, the proposal once it's ready, warnings and any error. */
    public function getImport(string $id): Result
    {
        return $this->http->request("GET", "/brand-kits/imports/" . enc($id));
    }

    /**
     * Apply a review import's proposal (`preview_ready`) to a profile: voice,
     * colors and pages, and its logos and backgrounds brought into the image
     * library and pinned. The body is optional: `brand_kit_id` applies it to
     * another profile, `name` names it, `sync_visual` also sets the email
     * branding's accent. To change other fields, accept and then `update`.
     *
     * @param array<string, mixed> $body Optional `brand_kit_id`, `name`, `sync_visual`.
     */
    public function acceptImport(string $id, array $body = []): Result
    {
        // `(object)` so the default is sent as `{}`, not `[]`.
        return $this->http->request("POST", "/brand-kits/imports/" . enc($id) . "/accept", null, (object) $body);
    }

    /** Discard a review import's proposal or stop its crawl. It sends no body. */
    public function cancelImport(string $id): Result
    {
        return $this->http->request("POST", "/brand-kits/imports/" . enc($id) . "/cancel");
    }
}

final class Domains extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/email/domains", $params);
    }

    /**
     * Iterate every row of /email/domains across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/email/domains", $params);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/email/domains", null, $body);
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/email/domains/" . enc($id));
    }

    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/email/domains/" . enc($id));
    }

    /** @param array<string, mixed> $body */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/email/domains/" . enc($id), null, $body);
    }

    public function verify(string $id): Result
    {
        return $this->http->request("POST", "/email/domains/" . enc($id) . "/verify");
    }

    /** Read-only DNS diagnosis: per-record answers and ranked findings with fixes. */
    public function diagnose(string $id): Result
    {
        return $this->http->request("GET", "/email/domains/" . enc($id) . "/diagnose");
    }

    // Sender addresses (`/email/senders`, `sndr_…`): saved From addresses.

    /**
     * Your saved From addresses, as the domain page lists them and the
     * dashboard's From pickers offer them: each a mailbox on one of your
     * domains, with its display name and `from`, the value to send with.
     * `domain_id` keeps one domain's. A sender can send once its domain can.
     *
     * @param array<string, mixed> $params `domain_id`, `limit`, `after`, `before`.
     */
    public function listSenders(array $params = []): Result
    {
        return $this->http->request("GET", "/email/senders", $params);
    }

    /**
     * Save a From address on one of your domains (201). The domain may still be
     * verifying. A mailbox already saved on that domain is a 409, so a retry
     * never saves it twice.
     *
     * @param array<string, mixed> $body `address` and an optional `display_name`.
     */
    public function createSender(array $body): Result
    {
        return $this->http->request("POST", "/email/senders", null, $body);
    }

    /** One saved From address. */
    public function getSender(string $id): Result
    {
        return $this->http->request("GET", "/email/senders/" . enc($id));
    }

    /**
     * Change a sender's display name (`null` or `""` clears it), the only
     * field that changes: a different mailbox is a new sender.
     *
     * @param array<string, mixed> $body `display_name`.
     */
    public function updateSender(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/email/senders/" . enc($id), null, $body);
    }

    /**
     * Remove a saved From address from the pickers. Templates and campaigns
     * that send from it keep their From.
     */
    public function deleteSender(string $id): Result
    {
        return $this->http->request("DELETE", "/email/senders/" . enc($id));
    }
}

final class Webhooks extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/webhooks", $params);
    }

    /**
     * Iterate every row of /webhooks across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/webhooks", $params);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/webhooks", null, $body);
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/webhooks/" . enc($id));
    }

    /** @param array<string, mixed> $body */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/webhooks/" . enc($id), null, $body);
    }

    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/webhooks/" . enc($id));
    }

    public function test(string $id): Result
    {
        return $this->http->request("POST", "/webhooks/" . enc($id) . "/test");
    }

    /** New signing secret, returned once; the old one co-signs for 24 h. */
    public function rotateSecret(string $id): Result
    {
        return $this->http->request("POST", "/webhooks/" . enc($id) . "/rotate-secret");
    }

    /**
     * The endpoint's delivery log, newest first (cursor-paginated). Narrow it
     * with `status` (one or more of `pending`, `queued`, `retrying`,
     * `succeeded`, `failed`, `exhausted`, comma-separated, e.g.
     * `'failed,exhausted'`) and `event_type` (e.g. `'email.bounced'`). The
     * log ignored them before, so a value it can't apply is ignored and named
     * in the `Unitpost-Warning` response header; `Unitpost-Strict: true`
     * refuses it instead.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`, `status`, `event_type`.
     */
    public function listDeliveries(string $id, array $params = []): Result
    {
        return $this->http->request("GET", "/webhooks/" . enc($id) . "/deliveries", $params);
    }

    /**
     * Iterate every delivery of the endpoint (lazily, as a Generator), with
     * the same filters as `listDeliveries`.
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAllDeliveries(string $id, array $params = []): \Generator
    {
        return $this->paginate("/webhooks/" . enc($id) . "/deliveries", $params);
    }

    /**
     * Deliver one event to the endpoint again (202), as the dashboard's Replay
     * does. The same event goes out with the same id, so a receiver that
     * dedupes on it treats the replay as the delivery it already has. The
     * webhook must be enabled: a disabled or suspended one is a 409 (re-enable
     * it with `update` first). Find deliveries with `listDeliveries`. It sends
     * no body.
     */
    public function replayDelivery(string $id, string $deliveryId): Result
    {
        return $this->http->request("POST", "/webhooks/" . enc($id) . "/deliveries/" . enc($deliveryId) . "/replay");
    }

    /**
     * Deliver the backlog (202): the deliveries held while the webhook was
     * suspended (`queued`), as the dashboard's "Retry queued" does. With
     * `['include_failed' => true]`, also the ones that failed for good
     * (`failed`, `exhausted`). The webhook must be enabled: a disabled or
     * suspended one is a 409 (re-enable it with `update` first); re-enabling
     * never delivers the backlog on its own. `flushed` counts the deliveries
     * handed back.
     *
     * @param array<string, mixed> $body Optional `include_failed`.
     */
    public function flush(string $id, array $body = []): Result
    {
        // `(object)` so the default is sent as `{}`, not `[]`.
        return $this->http->request("POST", "/webhooks/" . enc($id) . "/flush", null, (object) $body);
    }

    /** @param array<string, string> $headers */
    public function verify(string $payload, string $secret, array $headers, int $toleranceSeconds = 300): mixed
    {
        return verifyWebhook($payload, $secret, $headers, $toleranceSeconds);
    }
}

final class ApiKeys extends Resource
{
    /**
     * The workspace's API keys (prefix, scope, capabilities, last use; never
     * the secret). With an API key or an MCP sign-in it needs `apikeys:read`,
     * which no key preset includes.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/api-keys", $params);
    }

    /**
     * Iterate every row of /api-keys across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/api-keys", $params);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/api-keys", null, $body);
    }

    public function delete(string $id): Result
    {
        return $this->http->request("DELETE", "/api-keys/" . enc($id));
    }

    /**
     * One key's last 24 hours: how many requests it made, how many were
     * answered 4xx, 5xx or 429, and when it was last used. Check it before you
     * revoke a key (is anything still calling with it?) or when an
     * integration's calls fail. Needs `apikeys:read`, which no key preset
     * includes.
     */
    public function usage(string $id): Result
    {
        return $this->http->request("GET", "/api-keys/" . enc($id) . "/usage");
    }
}

/**
 * The workspace's API request log: one row per call. Needs `apikeys:read`,
 * which no key preset includes.
 */
final class Logs extends Resource
{
    /**
     * Your workspace's API request log, newest first (cursor-paginated,
     * `rlog_` ids): each call's `method`, `path`, `status`, time taken
     * (`duration_ms`), the API key that made it (`api_key_id`) and its
     * `x-request-id` (`request_id`: quote it to support). Never the caller's
     * IP and never a body. Narrow it with `status` (a code such as `429`, or a
     * class: `2xx`, `3xx`, `4xx` or `5xx`), `api_key_id` (`key_…`), `method`
     * (`GET`, `POST`, `PUT`, `PATCH` or `DELETE`), `path_prefix` (e.g.
     * `/email/campaigns`, at most 200 characters), `request_id` (the
     * `x-request-id` a response carried) and `created_after` /
     * `created_before` (ISO 8601 with `Z` or an offset); an unknown key is a
     * 422. Rows are kept 30 days. Needs `apikeys:read`, which no key preset
     * includes, and has its own per-workspace rate limit.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`, `status`, `api_key_id`, `method`, `path_prefix`, `request_id`, `created_after`, `created_before`.
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/logs", $params);
    }
}

final class Suppressions extends Resource
{
    /**
     * Your workspace's suppressed addresses, newest first (cursor-paginated).
     * Narrow it with `q` (part of the address, at most 200 characters),
     * `reason` (`bounce`, `complaint`, `unsubscribe`, `manual`, `import` or
     * `api`) and `created_after` / `created_before` (ISO 8601 with `Z` or an
     * offset). `scope` is deprecated and ignored. The list ignored these
     * filters before, so a value it can't apply is ignored and named in the
     * `Unitpost-Warning` response header; `Unitpost-Strict: true` refuses it
     * instead.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`, `q`, `reason`, `created_after`, `created_before`.
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/suppressions", $params);
    }

    /**
     * Iterate every row of /suppressions across pages (lazily, as a
     * Generator), with the same filters as `list`.
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/suppressions", $params);
    }

    /** @param array<string, mixed> $body */
    public function create(array $body): Result
    {
        return $this->http->request("POST", "/suppressions", null, $body);
    }

    public function get(string $idOrEmail): Result
    {
        return $this->http->request("GET", "/suppressions/" . enc($idOrEmail));
    }

    public function delete(string $idOrEmail): Result
    {
        return $this->http->request("DELETE", "/suppressions/" . enc($idOrEmail));
    }
}

/**
 * The workspace's image library: the images a template embeds. Each image's
 * `path` (`/img/…`) is what a template's `<Image src>` (or `<img src>`) takes;
 * `url` is its public address. Reads need `templates:read`, writes
 * `templates:write`.
 */
final class LibraryImages extends Resource
{
    /**
     * The library, newest first (cursor-paginated).
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`.
     */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/library-images", $params);
    }

    /**
     * Iterate every row of /library-images across pages (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(array $params = []): \Generator
    {
        return $this->paginate("/library-images", $params);
    }

    /**
     * Add an image (201) from a public https `url` (fetched once, no
     * redirects, no private addresses) or base64 `data` with its
     * `content_type`: PNG, JPEG, GIF or WebP up to 5 MB, counted against the
     * plan's image storage (403 `limit_reached`). Pass $idempotencyKey so a
     * retried upload stores one image; omitted, the SDK mints one.
     *
     * @param array<string, mixed> $body `url` or `data` + `content_type`, and an optional `filename` and `alt`.
     */
    public function create(array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/library-images", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/library-images/" . enc($id));
    }

    /**
     * Change the alt text the image suggests when it's inserted.
     *
     * @param array<string, mixed> $body `alt` (`null` or `""` clears it).
     */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/library-images/" . enc($id), null, $body);
    }

    /**
     * Remove an image from the library. While a template, a campaign or sent
     * mail uses it, the answer is a 409 `resource_in_use` naming them
     * (`details`). $force (sent as `?force=true`) deletes it anyway: those
     * emails then show a broken image wherever it isn't cached yet.
     */
    public function delete(string $id, bool $force = false): Result
    {
        return $this->http->request("DELETE", "/library-images/" . enc($id), $force ? ['force' => 'true'] : null);
    }
}

/**
 * The look marketing emails' footer and the hosted unsubscribe page share, as
 * Settings → Brand shows it. Reading it needs `templates:read`; the writes
 * need `branding:write`, which no key preset grants: tick it on the key.
 */
final class Branding extends Resource
{
    /** Colors, font, company name and postal address, the unsubscribe page, the footer, the logo; `null` means the default. */
    public function get(): Result
    {
        return $this->http->request("GET", "/branding");
    }

    /**
     * Change branding: only the fields you send change, and `null` restores a
     * default. Hiding the "Sent with Unitpost" mark needs a paid plan
     * (403 `limit_reached`).
     *
     * @param array<string, mixed> $body
     */
    public function update(array $body): Result
    {
        return $this->http->request("PATCH", "/branding", null, $body);
    }

    /**
     * Replace the logo, from a public https `url` or base64 `data` (PNG, JPEG,
     * GIF or WebP, up to 5 MB).
     *
     * @param array<string, mixed> $body `url`, or `data` + `content_type`; optional `filename`.
     */
    public function setLogo(array $body): Result
    {
        return $this->http->request("PUT", "/branding/logo", null, $body);
    }

    /** Clear the brand logo (the workspace image shows instead, else none). It sends no body and answers the branding. */
    public function removeLogo(): Result
    {
        return $this->http->request("DELETE", "/branding/logo");
    }
}

/**
 * The calling credential (`whoami`, which any valid credential may read), the
 * activity log (`listActivity`, which needs `activity:read`), and the
 * workspace's name and image. `update`, `setImage` and `removeImage` need
 * `branding:write`. No key preset grants either capability: tick it on the
 * key. Deleting the workspace stays in the dashboard.
 */
final class Workspace extends Resource
{
    /**
     * Who is calling: the workspace, the credential (an API key or an OAuth
     * sign-in, never its secret), its `capabilities` and the workspace's rate
     * limit. Call it first to see what the credential may do: a call that
     * needs a capability it lacks answers 403 `insufficient_scope`. It needs no
     * capability: any valid credential may read it.
     */
    public function whoami(): Result
    {
        return $this->http->request("GET", "/whoami");
    }

    /**
     * The workspace activity log, newest first (cursor-paginated, `act_` ids):
     * what changed, when, who changed it (`actor.name`: a teammate, `System`,
     * `Ask Unit`) and, for a change made over the API, MCP or Ask Unit, how
     * (`actor.via`, with the API key). The rows the dashboard's Activity page
     * and each record's timeline show. Narrow it with `subject` (`contact`,
     * `segment`, `topic`, `field`, `template`, `component`, `campaign`,
     * `email`, `domain`, `sender_email`, `library_image`, `api_key`,
     * `webhook`, `suppression`, `message`, `handle` or `workspace`) and
     * `subject_id` (one record's changes), `contact_id`, `campaign_id`, `q`
     * (part of the record's name or the actor's, at most 200 characters) and
     * `channel` (`'email'`: without the SMS rows); an unknown key is a 422.
     * Needs `activity:read`, which no key preset includes: the log names your
     * teammates.
     *
     * @param array<string, mixed> $params `limit`, `after`, `before`, `subject`, `subject_id`, `contact_id`, `campaign_id`, `q`, `channel`.
     */
    public function listActivity(array $params = []): Result
    {
        return $this->http->request("GET", "/activity", $params);
    }

    /**
     * Iterate every activity row (lazily, as a Generator), with the same
     * filters as `listActivity`.
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAllActivity(array $params = []): \Generator
    {
        return $this->paginate("/activity", $params);
    }

    /**
     * Rename the workspace.
     *
     * @param array<string, mixed> $body `name`.
     */
    public function update(array $body): Result
    {
        return $this->http->request("PATCH", "/workspace", null, $body);
    }

    /**
     * Replace the workspace image (its avatar in the dashboard, and the email
     * logo when there's no brand logo), from a public https `url` or base64
     * `data` (PNG, JPEG, GIF or WebP, up to 5 MB).
     *
     * @param array<string, mixed> $body `url`, or `data` + `content_type`; optional `filename`.
     */
    public function setImage(array $body): Result
    {
        return $this->http->request("PUT", "/workspace/image", null, $body);
    }

    /** Clear the workspace image. It sends no body and answers the workspace. */
    public function removeImage(): Result
    {
        return $this->http->request("DELETE", "/workspace/image");
    }
}

final class Usage extends Resource
{
    public function get(): Result
    {
        return $this->http->request("GET", "/usage");
    }
}
