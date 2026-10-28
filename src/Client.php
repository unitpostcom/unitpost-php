<?php

declare(strict_types=1);

namespace Unitpost;

/**
 * The Unitpost client. Construct once, reuse everywhere.
 *
 *   $unitpost = new \Unitpost\Client(); // reads UNITPOST_API_KEY
 *   $result = $unitpost->email->send(['from' => '...', 'to' => '...', 'html' => '<p>hi</p>']);
 *   if ($result->error) { fwrite(STDERR, $result->error->code . "\n"); }
 */
final class Client
{
    public Email $email;
    /**
     * SMS channel. Available on paid plans: on the free plan `send` returns a
     * 403 with code `limit_reached`; reads keep working.
     */
    public Sms $sms;
    public Contacts $contacts;
    public ContactFields $contactFields;
    public Segments $segments;
    public BrandKits $brandKits;
    /** The image library templates embed (`/img/…` paths). */
    public LibraryImages $libraryImages;
    /** The email footer and unsubscribe page look. Writes need `branding:write` (on no key preset). */
    public Branding $branding;
    /** Who is calling (`whoami`, any credential), the activity log (`listActivity`, needs `activity:read`, on no key preset), and the workspace's name and image; the writes need `branding:write` (on no key preset). */
    public Workspace $workspace;
    public Webhooks $webhooks;
    public ApiKeys $apiKeys;
    /** The API request log (`list()`, needs `apikeys:read`, on no key preset). */
    public Logs $logs;
    public Suppressions $suppressions;
    public Jobs $jobs;
    public Usage $usage;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $http = new HttpClient($options);
        $this->email = new Email($http);
        $this->sms = new Sms($http);
        $this->contacts = new Contacts($http);
        $this->contactFields = new ContactFields($http);
        $this->segments = new Segments($http);
        $this->brandKits = new BrandKits($http);
        $this->libraryImages = new LibraryImages($http);
        $this->branding = new Branding($http);
        $this->workspace = new Workspace($http);
        $this->webhooks = new Webhooks($http);
        $this->apiKeys = new ApiKeys($http);
        $this->logs = new Logs($http);
        $this->suppressions = new Suppressions($http);
        $this->jobs = new Jobs($http);
        $this->usage = new Usage($http);
    }
}
