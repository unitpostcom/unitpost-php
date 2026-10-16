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
    public Webhooks $webhooks;
    public ApiKeys $apiKeys;
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
        $this->webhooks = new Webhooks($http);
        $this->apiKeys = new ApiKeys($http);
        $this->suppressions = new Suppressions($http);
        $this->jobs = new Jobs($http);
        $this->usage = new Usage($http);
    }
}
