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
}

final class Sms extends Resource
{
    /** @param array<string, mixed> $body */
    public function send(array $body, ?string $idempotencyKey = null): Result
    {
        return $this->http->request("POST", "/sms", null, $body, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    public function get(string $id): Result
    {
        return $this->http->request("GET", "/sms/" . enc($id));
    }

    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/sms", $params);
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

    /** @param array<string, mixed> $body */
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
}

final class ContactFields extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/contact-fields", $params);
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

final class Segments extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/segments", $params);
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

    public function send(string $id): Result
    {
        return $this->http->request("POST", "/email/campaigns/" . enc($id) . "/send");
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
}

final class Webhooks extends Resource
{
    /** @param array<string, mixed> $params */
    public function list(array $params = []): Result
    {
        return $this->http->request("GET", "/webhooks", $params);
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
