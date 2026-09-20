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

`$unitpost->sms` is the SMS channel: **beta, behind the launch gate**. It is wired in its GA shape (send, get, list, brands, numbers, contact SMS consent); while your workspace's gate is off every call returns `404`.

Docs: https://www.unitpost.com/docs
