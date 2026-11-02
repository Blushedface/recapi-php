# recapi for PHP

PHP client for the recapi recommendation API, for WordPress, Laravel, Symfony
or plain PHP sites. PHP ≥ 8.2 with the curl extension (every common host has
it), no Composer package and no dependency.

Like the [Python library](../python/README.md), it keeps each user's
**history** and sends it with every recommendation request, **batches
events** (up to 1,000 per request, an idempotency key each), **falls back to
popular items** when recapi is slow or failing, and **attributes** events on
recommended items to the recommendation that showed them (the dashboard's
clicks and conversions).

## Install

The library is one file, and your recapi API serves it: download it into
your project, then require it. The dashboard's quick start shows this line
with your API's address.

```sh
curl -fLo recapi.php https://api.<your-domain>/sdk/php/recapi-0.4.1.php
```

```php
require_once __DIR__ . '/recapi.php';
```

Commit the file with your code, or run the line in your deploy script: a
version's URL always serves the same bytes. An older version's URL redirects
to the current file (hence `-L`; `-f` makes curl fail rather than save an
error page), and `Recapi\Client::VERSION` says which version you have.

## Quick start

```php
// Use a secret key: it stays on your server. SessionStore keeps each
// visitor's history and clicks in their PHP session, from page to page.
session_start();
$recapi = new Recapi\Client('rk_sec_…', 'https://api.example.com', store: new Recapi\SessionStore());

// Describe your items (a product, an article, a video…), 1,000 per request.
// metadata is yours (up to 2 KB) and comes back with the item in every list.
$recapi->upsertItems([['item_id' => 'item-42', 'tags' => ['sports'], 'title' => 'Cup final highlights',
    'metadata' => ['name' => 'Cup final highlights', 'url' => '/videos/42']]]);

// The user opened an item (level 3). Sent once the page has been served.
$recapi->track('item-42', 'view', userId: 'user-881');

// Recommendations for that user. If recapi is slow or failing, this returns
// popular items instead ($result->fallback is true), and an empty list if
// recapi is down altogether (hide the slot): no try/catch.
// $item->why says why it is listed, safe to show anyone (docs/api.md, "Reason
// codes"): pick your own words, no label for codes you don't map.
$labels = ['for_you' => 'Picked for you', 'similar' => 'Similar items', 'next' => 'Up next',
    'bought_together' => 'Often bought together', 'trending' => 'Trending now', 'popular' => 'Popular',
    'new' => 'New'];
$result = $recapi->recommend(userId: 'user-881', placement: 'homepage', count: 4);
foreach ($result->items as $item) {
    echo $item->itemId, ' ', $item->slot, ' ', $item->metadata['name'] ?? '', ' ', $labels[$item->why] ?? '', "\n";
}

// The user clicks a recommended item: track it as usual on the next page.
// The event carries the recommendation's id by itself (within 30 minutes of
// the display, then for 24 hours after the click).
$recapi->track('item-77', 'view', userId: 'user-881');
```

Levels follow the [recommended scale](../../docs/api.md#interaction-levels):
`'view'` (3), `'cart'` (5), `'purchase'` (10), or any integer from -10 to 10
(`track('article-9', 5, …)` for an article read to the end); 0 is an
impression. `'reject'` (-10) is an explicit "not interested": the item is never
recommended to that user again until a positive event on it. Visitors who are
not logged in: pass a stable `anonId` instead of `userId`, a random id kept in
their session (`$_SESSION['recapi_anon'] ??= bin2hex(random_bytes(16))`), never
the session id itself: recapi stores the `anonId` and the dashboard shows it.
Call `$recapi->identify($_SESSION['recapi_anon'], $userId)` when they log in:
their history and the items they were shown become the user's, so what they
did before logging in keeps counting.

## Where the history lives

PHP forgets everything between two requests, so the client keeps each user's
state in a store you choose. By default it lives for the request only.
`Recapi\SessionStore` keeps it in the visitor's session (call
`session_start()` first). Any other store is a class with three methods,
`get(string $key): ?string`, `set(string $key, string $value): void` and
`delete(string $key): void` (`Recapi\Store`): wrap Redis, APCu or your
framework's cache in it to share the state between devices and servers.

A user the store doesn't know yet (a new session, another server) still gets
personalised results: the request then has no history, recapi uses the
history it keeps from that user's events and returns it with the
recommendations, and the client stores it for the next calls. If a `track()`
or an `identify()` came first, as on an item page or at login, the first
`recommend()` fetches that history (`GET /v1/users/{id}/history`, unbilled)
with half of its 1.5 s and merges it with what was tracked; a failed fetch is
tried again on the next call. This works for `userId` only, and only with a
secret key. A store that fails is logged and skipped: tracking and
recommendations go on.

Each user's state is a small JSON value (at most 50 weighted items, 20
recent, 200 rejections and 100 shown items), the same as the Python
library's, under `recapi:<hash of the API key>:u:<userId>` (`:a:<anonId>`
for visitors), so a test and a production key never share entries.

## When events are sent

`track()` queues the event. The queue is sent at the end of the request,
after your own shutdown functions. Under PHP-FPM and LiteSpeed the response
has gone to the browser by then (`fastcgi_finish_request()`,
`litespeed_finish_request()`), so tracking never slows a page; under Apache's
mod_php or CGI the end of the page waits for the send. Each batch then gets
one try of at most 2 s, so a recapi outage holds a PHP worker 2 s at most:
once a batch fails, the events left are logged and dropped. An open session
is written and closed first, so the visitor's next page doesn't wait on its
lock (a destructor can no longer change it).

A long script (an import) sends each full batch of 1,000 at once and calls
`$recapi->flush()` at its end: it sends everything now, with retries. A
failed batch stays queued (up to 10,000 events) for the next `flush()`.

Worker runtimes (Laravel Octane, RoadRunner, queue workers) run shutdown
functions only when the process exits. Create one client per worker, not per
request (each client stays in memory, with its connection, until the process
exits), and call `flush()` after each request or job.

## Page caches

A page cache (WP Rocket, LiteSpeed Cache, your host's or a CDN's) stores a
page's HTML and serves it to every visitor. A list `recommend()` rendered
into that HTML is then one visitor's list, under one recommendation id,
shown to everyone: no personalisation, no new items tried, and clicks and
experiments counted against that one list. Render the recommendations in
the browser with the JS library (the script tag in the dashboard's quick
start), or from a fragment the page cache leaves out: an AJAX endpoint that
calls `recommend()`, or your cache plugin's exclusion for that block. Never
cache a `recommend()` result across visitors. The dashboard's Overview warns
when one recommendation id collects the events of many users ("Page
caches").

## Reference

| Method | What it does |
|---|---|
| `track($itemId, $level, userId: null, anonId: null, recommendationId: null)` | Updates the user's history at once and queues the event. `recommendationId` overrides the automatic attribution. Throws `InvalidArgumentException` for an invalid id or level |
| `recommend(userId: null, anonId: null, count: 0, placement: null, tagsAny: null, tagsAll: null, exclude: null, contextItemId: null, discovery: null)` | Returns `Recommendations` (`recommendationId`, `fallback`, `items`), each `Item` with `itemId`, `score`, `slot`, `metadata` (the array stored with the item, [Item metadata](../../docs/api.md#item-metadata), null when it has none) and `why` (its [reason code](../../docs/api.md#reason-codes)). The user's rejected items are added to `exclude` |
| `popular(count: 10, tagsAny: null, tagsAll: null)` | Popular items (`Item`, metadata and `why` included), for pages without a user |
| `upsertItems($items)` | Creates or replaces items (arrays with the [item fields](../../docs/api.md#put-v1itemsitem_id--secret-key)), 1,000 per request, with retries. Returns how many were stored |
| `identify($anonId, $userId)` | At login: merges the visitor's history and shown items into the user's, then deletes the visitor's entry |
| `deleteUser($userId)` | Erases the user's events in recapi (GDPR) and their state in the store |
| `flush()` | Sends queued events now, with retries |

`new Recapi\Client($apiKey, $baseUrl, store: null, timeout: 5.0, maxBatch: 1000,
logger: null)`: `timeout` per request in seconds (`recommend` waits at most
1.5 s, the end-of-request send 2 s), `logger` any PSR-3 logger (`error_log()` by default). Errors are
`Recapi\RecapiError` (`errorCode`, `status`, `retryAfter`, `retryable()`);
`recommend` throws only on a 3xx, 4xx or 429, or a reply that isn't the API's
(`invalid_response`, such as a base URL pointing at a web page): a fallback
would hide them. On a
network error, a timeout or a 5xx it returns popular items, or an empty list
when `/v1/popular` fails too, and logs the errors.

The API serves `recapi.php` as it is, and its URL names `Client::VERSION`:
a change to the file needs a new `VERSION` and a new entry in
`published.json` (the Go test `TestLibraryChangesNeedANewVersion` prints it).
An older version's URL then downloads the new one, so the library's public
methods and their arguments only grow.

Tests: `cd sdk/php && ../../scripts/php.sh tests/run.php` (a local `php`,
else Docker, else Debian's PHP 8.2 unpacked once under `~/.cache/recapi`).
They run the [history](../../docs/history-spec.md) and client
(`spec/client_vectors.json`) vectors through the real curl transport, against
a `php -S` server standing in for the API.
