# Changelog

Notable changes to the Unitpost PHP SDK. Changes to the API itself (new
operations, new fields, behavior changes) are in the API changelog:
https://www.unitpost.com/docs/changelog.

To release, move the `Unreleased` items under a `## X.Y.Z` heading in the same
change that bumps the version (docs/sdk/RELEASING.md).

## Unreleased

- The SMS list takes `sender_id`, a number's `snum_…` id: that number's
  texts, both directions. A value it can't apply is ignored and named in the
  `Unitpost-Warning` response header; `Unitpost-Strict: true` refuses it.
- `sms->quotes` (new): `create($body)`, `get($id)` and `confirm($id,
  ['confirm_token' => ...], $idempotencyKey)` buy SMS carrier resources
  (`squote_` ids): register a 10DLC brand, vet it, register a campaign, order
  a number. `confirm` mints an Idempotency-Key when you don't pass one, so a
  retry can't buy twice. `create` prices the order and binds what it buys (the
  brand's and program's content, the price) and returns `due_now_cents`,
  `monthly_cents`, `line_items` and a single-use `confirm_token` (`sqct_`),
  only in that response; a quote lasts 10 minutes and charges nothing. The
  confirm places the order at that price, or answers `409` when the price or
  content changed. It needs `sms:purchase`, which no key preset includes, and
  the workspace owner's monthly cap for SMS purchases by API (no cap set
  refuses it). Show the person the price and get their yes before confirming.
- `logs->list($params)` (new `logs` resource): the workspace's API request
  log, newest first and cursor-paginated (`rlog_` ids). Each row is one call:
  `method`, `path`, `status`, `duration_ms`, `api_key_id` and its
  `request_id` (the `x-request-id`), never the caller's IP or a body; rows are
  kept 30 days. Filters: `status` (a code or `2xx`…`5xx`), `api_key_id`,
  `method`, `path_prefix`, `request_id`, `created_after`, `created_before`. It
  needs `apikeys:read`, which no key preset includes.
- Opt many contacts out of a topic at once: `email->topics->optOut($id,
  $body, $idempotencyKey)` and `sms->topics->optOut(...)` (exactly one of
  `contact_ids` or `emails`, up to 1,000, or `segment_id`). They answer the
  job (202); follow it with `jobs->get($id)`. The SDK mints an idempotency
  key when you pass none, so a retry answers the same job. There is no bulk
  opt-in. Needs `topics:write` (a segment also `segments:read`).
- `sms->registrations->list($params)`, `sms->testDestinations->list($params)`,
  `sms->consentSetups->list($params)`, `sms->billing->listResources($params)`
  and `sms->messagingPrograms->list($params)` take `limit`, `after` and
  `before`: the five lists are cursor-paginated, newest first.
- New response fields are optional in the models (the API always sends
  them): they come last in the `SmsBrand` constructor and default to `null`,
  so a positional call written against 0.4.0 still works.
- The activity log, an API key's usage, several topic preferences at once and
  list search. `workspace->listActivity($params)` and `listAllActivity`: the
  workspace's activity log, newest first (cursor-paginated on `act_` ids):
  what changed, when, who (`actor.name`) and, for a change over the API, MCP
  or Ask Unit, how (`actor.via`, with the API key). Narrow it with `subject`
  (and `subject_id`), `contact_id`, `campaign_id`, `q` and `channel` `'email'`
  (an unknown key is a `422`). It needs `activity:read`.
  `apiKeys->usage($id)`: a key's requests in the last 24 hours
  (`requests_24h`, `client_errors_24h`, `server_errors_24h`,
  `rate_limited_24h`) and `last_used_at`. `apiKeys->list()` now also works
  with an API key or an MCP sign-in (it was dashboard-session only); with one,
  `list` and `usage` need `apikeys:read`. Creating and revoking keys stay in
  the dashboard. No key preset includes `activity:read` or `apikeys:read`.
  `email->topics->updateTopics($contactId, ['topics' => [['topic_id' => ...,
  'subscribed' => ...]]])` (`PATCH /contacts/{id}/topics`, up to 50) changes
  several of a contact's preferences at once, leaves the others as they are,
  and answers the list (`object` `list`, `data`); `setTopic` is unchanged.
  `email->list($params)` and `listAll` take `q` (an exact recipient, or part
  of the subject or the sender), `source` (`api`, `smtp`, `test`, `batch`,
  `campaign` or `reply`) and `outcome` (`delivered`, `opened`, `clicked`,
  `unsubscribed`, `bounced`, `complained`, `failed` or `pending`; with
  `campaign_id`, who opened or clicked it). `sms->list($params)` and `listAll`
  take `q` (the message text, or 3 or more digits of either number) and `type`
  (`transactional` or `marketing`). A `q`, `source`, `outcome` or `type` value
  the API can't apply is ignored and named in the `Unitpost-Warning` response
  header; `Unitpost-Strict: true` refuses it.
- List filters and a stats period. `suppressions->list($params)` and `listAll`
  take `q` (part of the address), `reason`, `created_after` and
  `created_before` (`scope` stays, deprecated).
  `webhooks->listDeliveries($id, $params)` and `listAllDeliveries` take
  `status` (comma-separated, e.g. `'failed,exhausted'`) and `event_type`.
  `email->stats($params)` keeps `days` and takes a period instead: `from` /
  `to`, with `grain` (`5m`, `1h` or `1d`) and `tz` for `series`, the send
  volume per bucket, and `domain_id` for one sending domain. These lists
  ignored the filters before, so a value the API can't apply is ignored and
  named in the `Unitpost-Warning` response header; `Unitpost-Strict: true`
  refuses it. Responses: `webhooks->get()` adds `backlog` (`queued`, `failed`:
  what a flush would send); `email->stats()` adds the permanent and transient
  bounces, `suppressed`, `unsubscribed`, `rates.unsubscribe`, `domain_id`,
  `from`, `to` and `series`; topics add `subscriber_count` and
  `unsubscribed_count`; `usage->get()` adds the plan's `limits` and
  `features`.
- Who is calling, an email's events, a received email's original and
  template rendering. `workspace->whoami()`: the workspace, the credential
  (an API key or an OAuth sign-in, never its secret), its `capabilities` and
  the workspace's rate limit; any valid credential may call it, so call it
  first to see what a key may do.
  `email->listEvents($id, ['limit' => ..., 'after' => ..., 'before' => ...])`:
  one email's delivery events, oldest first, with each event's detail under
  the webhook's names (cursor-paginated on `emev_` ids; no `listAll`).
  `email->receivedRaw($id)`: a signed URL (about 5 minutes) to a received
  message's original `.eml`, the way to read a body over 256 KB; a copy of
  your own send is a `403`.
  `email->templates->render($id, ['variables' => ..., 'subject' => ..., 'contact_id' => ...])`:
  the subject, HTML and text a send would carry, and its
  `missing_variables`; it sends and stores nothing, so it takes no
  idempotency key, and the body is optional. `listEvents` and `receivedRaw`
  need `emails:read`; `render` needs `templates:read`.
- SMS brands and registrations. On `sms`:
  `createBrand([...], $idempotencyKey)`, `getBrand($id)` and
  `updateBrand($id, [...])` (send `['archived' => ...]` alone to archive or
  restore). A new resource on `sms`: `sms->registrations->list()`, which is
  cursor-paginated (no `listAll`). Creating a brand is free and makes no
  carrier call; its tax id is write-only (read back as
  `tax_id_on_file`), and a locked brand refuses carrier-field edits with
  `409 brand_locked`. The reads need `sms:read`; the brand writes need
  `sms:manage`. Registering a brand with carriers (a paid step), ordering
  numbers and a brand's documents stay in the dashboard.
- SMS setup. On `sms->templates`: `usage($id)` and
  `testSend($id, ['to' => ..., 'variables' => ..., 'sender_id' => ...], $idempotencyKey)`.
  Four new resources on `sms`:
  `sms->messagingPrograms->list(['archived' => true])`,
  `create([...], $idempotencyKey)`, `get($id)`, `update($id, [...])`,
  `delete($id)` (it archives) and `duplicate($id, $idempotencyKey)`;
  `sms->testDestinations->list()` and `delete($id)`;
  `sms->consentSetups->list()`; and `sms->billing->listResources()`. These
  four lists are cursor-paginated (no `listAll`). A boolean
  `archived` is sent as `true` / `false`. A test text goes only to the
  simulator or a verified test number, and is billed like any text. The reads
  need `sms:read` (`usage` needs `templates:read`); the test send needs
  `sms:send`; the program writes and removing a test number need
  `sms:manage` (FULL keys have it, SENDING keys don't). Ordering numbers,
  sender registrations and payments stay in the dashboard.
- Brand Kit writes and imports on `brandKits`:
  `brandKits->create(['name' => ..., 'make_default' => ...])`,
  `brandKits->update($id, [...])`, `brandKits->delete($id)`,
  `brandKits->reset($id)`, `brandKits->setDefault($id)` and
  `brandKits->addGraphic($id, ['url' => ..., 'role' => ..., 'label' => ...])`;
  `brandKits->import([...])`,
  `brandKits->getImport($id)`, `brandKits->acceptImport($id, [...])` (the body
  is optional) and `brandKits->cancelImport($id)`. An import builds a profile
  from a website (`url`) or the brand's own words (`text`) in the background
  (`202`; follow it with `getImport`). By default it is applied when it lands;
  with `'review' => true` it stops at a proposal for `acceptImport`. Deleting
  the last profile is a `409` (reset it instead). `addGraphic` brings an image
  from a public https URL into the image library and pins it. The writes, and
  starting, accepting or canceling an import, need the `branding:write`
  capability, which no key preset grants: tick it on the key. The reads stay
  `templates:read`.
- Three new resources. Image library:
  `libraryImages->list(['limit' => ..., 'after' => ..., 'before' => ...])`,
  `libraryImages->listAll()`,
  `libraryImages->create([...], $idempotencyKey)`,
  `libraryImages->get($id)`, `libraryImages->update($id, ['alt' => ...])`
  and `libraryImages->delete($id, $force)`. Branding: `branding->get()`,
  `branding->update([...])`, `branding->setLogo([...])` and
  `branding->removeLogo()`. Workspace:
  `workspace->update(['name' => ...])`, `workspace->setImage([...])` and
  `workspace->removeImage()`. The library holds the images a template
  embeds: an image's `path` (`/img/…`) goes in a template's `<Image src>`.
  `create` uploads from a public https `url` or base64 `data` (PNG, JPEG,
  GIF or WebP, up to 5 MB) and takes an `$idempotencyKey`, minted when you
  don't pass one, so a retried upload stores one image. Deleting an image
  that a template, a campaign or sent mail uses is a `409` `resource_in_use`
  unless you pass `$force = true` (sent as `?force=true`). `branding` is the
  look of marketing emails' footer and the hosted unsubscribe page, as
  Settings → Brand shows it (`null` restores a default). `workspace` renames
  the workspace and sets its image. `removeLogo` and `removeImage` answer the
  updated object. The branding writes and every `workspace` call need the
  `branding:write` capability, which no key preset grants: tick it on the
  key.
- Jobs: `jobs->list(['status' => ..., 'kind' => ..., 'limit' => ..., 'after' => ..., 'before' => ...])`,
  `jobs->cancel($id)`, `jobs->retry($id)` and `jobs->download($id)`.
  Contacts: `contacts->export(['q' => ..., 'status' => ..., 'segment_id' => ...])`
  (the filters of `contacts->list`). Segments: `segments->preview($id)`,
  `segments->previewFilter(['filter' => ...])`, `segments->allContacts()` and
  `segments->updateMember($id, $contact, ['subscribed' => ...])`.
  `export` starts a background job that writes a CSV of every contact, or
  of the ones the filters match (one export at a time: another while it runs
  is a `409`); follow it with `jobs->get`, then `jobs->download` gives a signed URL to
  the file that expires in 5 minutes. `jobs->list` lists your background jobs;
  `cancel` stops a queued or running one, and `retry` resumes a `FAILED`
  one from where it stopped. `preview` and `previewFilter`
  count who a saved segment or an unsaved filter reaches and how many of them
  a campaign would mail; nothing is saved. `allContacts` returns the
  managed "All subscribed contacts" segment, made the first time you ask.
  `updateMember` opts a member out of a static segment's sends, or back
  in, without removing it. None of these takes an idempotency key.
- Webhooks: `webhooks->replayDelivery($id, $deliveryId)` and
  `webhooks->flush($id, ['include_failed' => ...])`. Email:
  `email->retry($id, $idempotencyKey)` and
  `email->listBatches(['limit' => ..., 'after' => ..., 'before' => ...])`.
  Domains: sender addresses with
  `email->domains->listSenders(['domain_id' => ..., 'limit' => ..., 'after' => ..., 'before' => ...])`,
  `createSender(['address' => ..., 'display_name' => ...])`, `getSender($id)`,
  `updateSender($id, ['display_name' => ...])` and `deleteSender($id)`.
  `replayDelivery` delivers one event again with the same event id, and `flush`
  delivers the deliveries held while a webhook was suspended (with
  `include_failed`, also the ones that failed for good); both need the
  webhook enabled (`409` otherwise). `retry` sends a failed email again on
  the same id (an email that can't be retried is a `409` saying why); it
  sends real email, so it takes an idempotency key and generates one when you
  don't pass it. `listBatches` lists your batch sends with the same
  rollup as `getBatch`. A
  sender is a saved From address on one of your domains; saving a mailbox
  that is already saved there is a `409`.
- Email templates:
  `email->templates->duplicate($id, ['name' => ...], $idempotencyKey)`,
  `email->templates->fromLibrary(['slug' => ..., 'name' => ...], $idempotencyKey)`,
  `email->templates->usage($id)`,
  `email->templates->listVersions($id, ['limit' => ..., 'after' => ...])` and
  `email->templates->restoreVersion($id, $version)`.
  `duplicate` copies a template into a new draft, and `fromLibrary` adds a
  gallery email as a draft Design template (the ones marked for paid plans are
  `403 limit_reached` on the free plan); both take an idempotency key and
  generate one when you don't pass it. `usage` says what uses a template and
  whether an edit would be accepted now. `listVersions` pages by version
  number: pass the last row's `version` as `after`. `restoreVersion` puts a
  version back and is refused with `409` while a live campaign renders the
  template.
- Inbox conversations: a new `email->threads` resource with
  `list(['q' => ..., 'limit' => ..., 'after' => ...])`,
  `get($id)`, `delete($id)` and
  `reply($id, $body, $idempotencyKey)`. A conversation is a received message
  plus the replies and forwards sent from it, as Emails → Received shows them.
  `list` pages with `next_cursor` (pass it as `after`; it is not a row id);
  `delete` erases the received messages for good. `reply` replies, replies all or forwards one received
  message and sends real email, so it takes an idempotency key and generates
  one when you don't pass it.
- Email campaigns: `email->campaigns->stats($id)`,
  `email->campaigns->listStats($ids)`,
  `email->campaigns->listRecipients($id, ['status' => ..., 'limit' => ..., 'after' => ...])`,
  `email->campaigns->listAffected($id)`,
  `email->campaigns->removeAffected($id)` and
  `email->campaigns->requestReview($id)`. SMS campaigns:
  `sms->campaigns->listRecipients($id, ['limit' => ..., 'after' => ..., 'before' => ..., 'q' => ...])`.
  `stats` reads a campaign's results: delivered, unique opens and clicks,
  unsubscribes, bounces, complaints and their rates. `listStats` reads them
  for up to 100 campaigns at once. On an email campaign, `listRecipients`
  lists who a send now would reach and why any other member would be left out;
  pass the page's `next_cursor` as `after` for the next page. After an
  automatic quality pause, `listAffected` lists the hard-bounced and
  complained addresses, `removeAffected` takes them out of the campaign's
  static segment, and `requestReview` asks for the review a repeat pause needs
  before `resume`. Both are safe to repeat: a second call removes no one and
  notifies no one. On an SMS campaign, `listRecipients` lists how each
  recipient fared, with `skipped_by_reason` counts for the whole campaign.
- Contacts: `contacts->addresses($idOrEmail)`,
  `contacts->activity($idOrEmail, ['limit' => ...])`,
  `contacts->listSegments($idOrEmail)` and `contacts->retryImport($id)`. SMS:
  `sms->requestSmsOptIn($contactId, ['phone' => ...], $idempotencyKey)`.
  `requestSmsOptIn` texts the contact a request to reply
  YES to confirm marketing texts. It sends a real text, so it sends the
  idempotency key you pass, or one it makes up, and a retry returns the same
  text instead of sending another.
- Contacts carry `phone`, their SMS number in E.164, on create, update and
  read. `null` on update removes the number only with the `Unitpost-Strict: true` header; without it the number is kept and the response warns. Setting it records no SMS
  consent: record that with `sms->recordSmsConsent`.
- `contacts->list` / `contacts->listAll` take `q`, `status` (`subscribed` |
  `unsubscribed`), `phone` (`has` | `none`), `segment_id` and
  `segment_subscription` (`subscribed` | `opted_out`) in `$params`;
  `email->list` / `email->listAll` take `to` and `contact_id`.
- Email and SMS campaigns: `unschedule($id)` and `duplicate($id, ['name' => ...], $idempotencyKey)`. Unschedule moves a scheduled campaign
  back to a draft; duplicate copies a campaign into a new draft. Duplicate
  sends the idempotency key you pass, or one it makes up, so a retry returns
  the same copy instead of making a second one.
- The campaign update model takes `name`, `subject`, `from`, `preview_text`,
  `template_id` and `segment_id`: the API now edits these on a draft.
- The email campaign and segment lists take the optional `archived` query
  parameter (`archived=true` lists archived rows).
- The API now answers `422` for a misnamed send time (`send_at`,
  `scheduledAt` on SMS, …) on sends and campaign writes, instead of sending at
  once or dropping it. Use `scheduled_at`.
- Campaign create's `403` now says which refusal it is: `limit_reached` for
  the plan's contact limit, `domain_not_found` / `domain_blocked` /
  `domain_not_verified` for the sender (it was always `domain_not_verified`).
  Read `error.code` to tell them apart.
- Domains: the update model takes `capabilities` (`{ receiving: "enabled" |
  "disabled" }` turns receiving on or off), and domain reads carry
  `capabilities` and a `receiving` object (status, the MX record to publish,
  the last checks).

## 0.4.0

The release this changelog starts from. Earlier changes are in the git history
(tags `sdk-php-v*`).
