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

Docs: https://www.unitpost.com/docs
