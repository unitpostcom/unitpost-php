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

    public function __construct(HttpClient $http)
    {
        parent::__construct($http);
        $this->templates = new SmsTemplates($http);
        $this->campaigns = new SmsCampaigns($http);
        $this->topics = new SmsTopics($http);
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

    /** @param array<string, mixed> $params */
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
     * Read-only — creating a brand is a paid carrier submission and is only
     * available in the dashboard.
     *
     * @param array<string, mixed> $params
     */
    public function brands(array $params = []): Result
    {
        return $this->http->request("GET", "/sms/brands", $params);
    }

    /**
     * List SMS phone numbers.
     *
     * Only numbers with `status: "active"` can send. A `ten_dlc` number has no
     * `phone_number` until carriers approve it, and a `simulator` number only
     * reaches verified test destinations.
     *
     * Read-only — requesting or releasing a number is only available in the
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

    /** @param array<string, mixed> $body */
    public function reschedule(string $id, array $body): Result
    {
        return $this->http->request("POST", "/sms/campaigns/" . enc($id) . "/reschedule", null, $body);
    }

    public function validate(string $id): Result
    {
        return $this->http->request("GET", "/sms/campaigns/" . enc($id) . "/validate");
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
}

final class Email extends Resource
{
    public Topics $topics;
    public Campaigns $campaigns;
    public Templates $templates;
    public Domains $domains;

    public function __construct(HttpClient $http)
    {
        parent::__construct($http);
        $this->topics = new Topics($http);
        $this->campaigns = new Campaigns($http);
        $this->templates = new Templates($http);
        $this->domains = new Domains($http);
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

    /** @param array<string, mixed> $params */
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

    /** @param array<string, mixed> $body */
    public function update(string $id, array $body): Result
    {
        return $this->http->request("PATCH", "/email/" . enc($id), null, $body);
    }

    /** @param array<string, mixed> $params */
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
    /** A background job (the 202 answer of a large operation); poll until it finishes. */
    public function get(string $id): Result
    {
        return $this->http->request("GET", "/jobs/" . enc($id));
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

    /** @param array<string, mixed> $body */
    public function reschedule(string $id, array $body): Result
    {
        return $this->http->request("POST", "/email/campaigns/" . enc($id) . "/reschedule", null, $body);
    }

    public function validate(string $id): Result
    {
        return $this->http->request("GET", "/email/campaigns/" . enc($id) . "/validate");
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
     *
     * @param array<string, mixed> $body
     */
    public function testSend(string $id, array $body): Result
    {
        return $this->http->request("POST", "/email/templates/" . enc($id) . "/test-send", null, $body);
    }
}

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

    /** @param array<string, mixed> $params */
    public function listDeliveries(string $id, array $params = []): Result
    {
        return $this->http->request("GET", "/webhooks/" . enc($id) . "/deliveries", $params);
    }

    /**
     * Iterate every delivery of the endpoint (lazily, as a Generator).
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAllDeliveries(string $id, array $params = []): \Generator
    {
        return $this->paginate("/webhooks/" . enc($id) . "/deliveries", $params);
    }

    /** @param array<string, string> $headers */
    public function verify(string $payload, string $secret, array $headers, int $toleranceSeconds = 300): mixed
    {
        return verifyWebhook($payload, $secret, $headers, $toleranceSeconds);
    }
}

final class ApiKeys extends Resource
{
    /** @param array<string, mixed> $params */
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
}

final class Suppressions extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/suppressions", $params);
    }

    /**
     * Iterate every row of /suppressions across pages (lazily, as a Generator).
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

final class Usage extends Resource
{
    public function get(): Result
    {
        return $this->http->request("GET", "/usage");
    }
}
