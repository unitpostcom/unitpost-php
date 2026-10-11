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

Docs: https://www.unitpost.com/docs
