<?php

// The recapi client for PHP sites, in one file to copy and require: PHP 8.2
// with the curl extension, no Composer package.

declare(strict_types=1);

namespace Recapi;

/**
 * A failed call. $status is 0 when no HTTP response was received.
 *
 * $errorCode is the API's error code (invalid_request, rate_limited,
 * quota_exceeded...) or a client one: network, timeout, invalid_response.
 */
final class RecapiError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 0,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /** Whether trying again later can succeed (quota_exceeded can't until it resets). */
    public function retryable(): bool
    {
        return $this->status === 429 ? $this->errorCode !== 'quota_exceeded' : $this->outage();
    }

    /**
     * Whether recapi couldn't answer in time or failed on its side. A 429 is
     * the tenant's own limit and a 4xx an integration bug: a fallback would hide both.
     */
    public function outage(): bool
    {
        return $this->errorCode === 'network' || $this->errorCode === 'timeout' || $this->status >= 500;
    }
}

/**
 * One recommended or popular item. $metadata is the object stored with the
 * item (metadata of PUT /v1/items: name, picture, link, price...) as an
 * associative array, null when the item has none. $why says why it is
 * listed, a reason code to turn into a label for visitors: 'for_you',
 * 'similar', 'next', 'bought_together', 'trending', 'popular', 'new',
 * 'explore' or 'more' (docs/api.md "Reason codes"), null when the API sent
 * none. New codes may be added: show nothing for an unknown one.
 */
final readonly class Item
{
    /** @param array<string, mixed>|null $metadata */
    public function __construct(public string $itemId, public float $score, public string $slot, public ?array $metadata = null,
        public ?string $why = null)
    {
    }
}

/** A recommendation list. Fallback lists are popular items with no id. */
final readonly class Recommendations
{
    /** @param list<Item> $items */
    public function __construct(public ?string $recommendationId, public bool $fallback, public array $items)
    {
    }
}

/**
 * Where per-user state lives between requests (history and shown items).
 *
 * Values are strings; get() returns null for a missing key. Wrap Redis,
 * APCu or your framework's cache in these three methods.
 */
interface Store
{
    public function get(string $key): ?string;

    public function set(string $key, string $value): void;

    public function delete(string $key): void;
}

/** This request only: the default, so nothing outlives the request unless you choose a store. */
final class MemoryStore implements Store
{
    private array $data = [];

    public function get(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->data[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }
}

/**
 * The visitor's PHP session: their history and clicks follow them from page
 * to page with no server to run. Call session_start() first; without an
 * active session it keeps nothing.
 */
final class SessionStore implements Store
{
    public function get(string $key): ?string
    {
        $value = $_SESSION['recapi'][$key] ?? null;
        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['recapi'][$key] = $value;
        }
    }

    public function delete(string $key): void
    {
        unset($_SESSION['recapi'][$key]);
    }
}

/**
 * Decayed user history, exactly as docs/history-spec.md (times in unix ms).
 *
 * A port of sdk/python/recapi/history.py; spec/history_vectors.json keeps
 * them equal. PHP turns an array key such as "42" into the integer 42, so
 * every key read back goes through (string).
 */
final class History
{
    public const HALF_LIFE_SECONDS = 604800;
    public const TOP_K = 50;
    public const RECENT_SIZE = 20;
    public const REJECTED_SIZE = 200;
    public const MAX_ID_BYTES = 256;

    /** @var array<string, array{float, int|float}> item => [score, t] */
    public array $top = [];
    /** @var list<array{string, int|float}> [item, t], oldest first */
    public array $recent = [];
    /** @var array<string, int|float> item => time of the newest rejection */
    public array $rejected = [];
    /** Newest event applied to the recent list, null when unknown. */
    public int|float|null $lastT = null;

    /** Whether the API accepts $value as an id: 1-256 bytes of UTF-8, no controls. */
    public static function isId(mixed $value): bool
    {
        // Go's unicode.IsControl, which the API applies to every id: C0, DEL and C1.
        // On invalid UTF-8 a /u pattern returns false, never 0.
        return is_string($value) && $value !== '' && strlen($value) <= self::MAX_ID_BYTES
            && preg_match('/[\x{0}-\x{1f}\x{7f}-\x{9f}]/u', $value) === 0;
    }

    /**
     * Whether there is nothing to send, so the server uses its stored history. Rejections
     * alone don't count: the server still has the user's positive events, and excludes both sets.
     */
    public function isEmpty(): bool
    {
        return $this->top === [] && $this->recent === [];
    }

    /** Apply one event: level 0 changes nothing, a negative level is a rejection. */
    public function apply(string $item, int $level, int|float $t): void
    {
        if ($level === 0) {
            return;
        }
        if ($level < 0) {
            $this->reject($item, $t);
            return;
        }
        if (isset($this->rejected[$item])) {
            if ($t < $this->rejected[$item]) {
                return;
            }
            unset($this->rejected[$item]);
        }
        $current = $this->top[$item] ?? null;
        if ($current === null) {
            $this->top[$item] = [(float) $level, $t];
        } elseif ($t >= $current[1]) {
            $this->top[$item] = [$current[0] * self::decay($t - $current[1]) + $level, $t];
        } else {
            $this->top[$item] = [$current[0] + $level * self::decay($current[1] - $t), $current[1]];
        }
        if ($this->lastT === null || $t >= $this->lastT) {
            $this->recent = self::without($this->recent, $item);
            $this->recent[] = [$item, $t];
            $this->recent = array_slice($this->recent, -self::RECENT_SIZE);
            $this->lastT = $t;
        }
        $this->prune($t);
    }

    private function reject(string $item, int|float $t): void
    {
        // Dropped, not lowered: a lowered score would decay back towards 0.
        $current = $this->top[$item] ?? null;
        if ($current !== null && $current[1] > $t) {
            return;
        }
        unset($this->top[$item]);
        $this->recent = self::without($this->recent, $item);
        $this->rejected[$item] = max($t, $this->rejected[$item] ?? $t);
        $this->pruneRejected();
    }

    /** @return list<array{string, float, int|float}> [item, weight at $now, t] by weight desc, ties by item id */
    public function ranked(int|float $now): array
    {
        $entries = [];
        foreach ($this->top as $item => [$s, $t]) {
            $entries[] = [(string) $item, $s * self::decay($now - $t), $t];
        }
        usort($entries, fn ($a, $b) => $b[1] <=> $a[1] ?: strcmp($a[0], $b[0]));
        return $entries;
    }

    /** @return list<string> the rejected items, oldest mark first, ties by item id */
    public function rejectedOrder(): array
    {
        $items = array_map('strval', array_keys($this->rejected));
        usort($items, fn ($a, $b) => $this->rejected[$a] <=> $this->rejected[$b] ?: strcmp($a, $b));
        return $items;
    }

    private function prune(int|float $now): void
    {
        if (count($this->top) > self::TOP_K) {
            $kept = [];
            foreach (array_slice($this->ranked($now), 0, self::TOP_K) as [$item]) {
                $kept[$item] = $this->top[$item];
            }
            $this->top = $kept;
        }
    }

    private function pruneRejected(): void
    {
        if (count($this->rejected) > self::REJECTED_SIZE) {
            $kept = [];
            foreach (array_slice($this->rejectedOrder(), -self::REJECTED_SIZE) as $item) {
                $kept[$item] = $this->rejected[$item];
            }
            $this->rejected = $kept;
        }
    }

    /**
     * The request's history and recent, and the rejected items. Weights are decayed to $now
     * and rounded half up to 4 decimals like JS Math.round; ages are whole seconds, never negative.
     *
     * @return array{list<array{string, float, int}>, list<string>, list<string>}
     */
    public function wire(int|float $now): array
    {
        $history = [];
        foreach (array_slice($this->ranked($now), 0, self::TOP_K) as [$item, $weight, $t]) {
            $history[] = [$item, floor($weight * 1e4 + 0.5) / 1e4, max(0, (int) floor(($now - $t) / 1000))];
        }
        $recent = array_map(fn ($e) => (string) $e[0], array_slice($this->recent, -self::RECENT_SIZE));
        return [$history, $recent, $this->rejectedOrder()];
    }

    /**
     * A history from the server's snapshot, skipping invalid entries. Each top entry is a
     * score at as_of, each rejected item a mark at as_of.
     * lastT stays unknown: as_of is the server clock, and a local clock
     * behind it would see every new event as out of order.
     */
    public static function fromSnapshot(mixed $snapshot): self
    {
        $state = new self();
        if (!is_array($snapshot) || !self::isTime($snapshot['as_of'] ?? null)) {
            return $state;
        }
        $asOf = $snapshot['as_of'];
        foreach (self::listOf($snapshot['top'] ?? null) as $e) {
            if (is_array($e) && array_is_list($e) && count($e) >= 2 && self::isId($e[0]) && self::isWeight($e[1])) {
                $state->top[$e[0]] = [(float) $e[1], $asOf];
            }
        }
        $seen = [];
        foreach (self::listOf($snapshot['recent'] ?? null) as $item) {
            if (self::isId($item) && !isset($seen[$item])) {
                $seen[$item] = true;
                $state->recent[] = [$item, $asOf];
            }
        }
        $state->recent = array_slice($state->recent, -self::RECENT_SIZE);
        foreach (self::listOf($snapshot['rejected'] ?? null) as $item) {
            if (self::isId($item)) {
                $state->rejected[$item] = $asOf;
            }
        }
        $state->prune($asOf);
        $state->pruneRejected();
        return $state;
    }

    /**
     * The union of two histories built from disjoint events. Top scores add up, expressed at the later of the two times; the recent
     * lists are interleaved by time ($this first on ties); rejection marks are
     * united, and a mark older than the item's merged entry is dropped,
     * otherwise the item leaves the top and the recent list.
     */
    public function merge(self $other, int|float $now): self
    {
        $out = new self();
        $out->top = $this->top;
        foreach ($other->top as $item => [$s, $t]) {
            $mine = $out->top[$item] ?? null;
            if ($mine === null) {
                $out->top[$item] = [$s, $t];
                continue;
            }
            $later = max($t, $mine[1]);
            $out->top[$item] = [$s * self::decay($later - $t) + $mine[0] * self::decay($later - $mine[1]), $later];
        }

        $out->rejected = $this->rejected;
        foreach ($other->rejected as $item => $t) {
            $out->rejected[$item] = max($t, $out->rejected[$item] ?? $t);
        }
        foreach ($out->rejected as $item => $t) {
            if (isset($out->top[$item]) && $out->top[$item][1] > $t) {
                unset($out->rejected[$item]);
            } else {
                unset($out->top[$item]);
            }
        }

        $byItem = [];
        foreach ([...$this->recent, ...$other->recent] as $order => [$item, $t]) {
            if (!isset($byItem[$item]) || $t > $byItem[$item][0]) {
                $byItem[$item] = [$t, $order];
            }
        }
        // Rejected items go before the trim, so they don't cost the list its places.
        $kept = [];
        foreach ($byItem as $item => [$t, $order]) {
            if (!isset($out->rejected[$item])) {
                $kept[] = [$t, $order, (string) $item];
            }
        }
        usort($kept, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out->recent = array_map(fn ($e) => [$e[2], $e[0]], array_slice($kept, -self::RECENT_SIZE));

        $times = array_filter([$this->lastT, $other->lastT], fn ($t) => $t !== null);
        $out->lastT = $times ? max($times) : null;
        $out->prune($now);
        $out->pruneRejected();
        return $out;
    }

    /**
     * Merge a server snapshot, keeping the items rejected here rejected. The snapshot dates its scores as_of, which only bounds when its events
     * happened; a local rejection is usually one the server hasn't stored
     * yet, so it wins over the snapshot's score.
     */
    public function mergeSnapshot(self $snapshot, int|float $now): self
    {
        $trimmed = clone $snapshot;
        $trimmed->top = array_diff_key($snapshot->top, $this->rejected);
        return $this->merge($trimmed, $now);
    }

    /** The JSON-safe stored form (raw scores), shaped like JS encodeState. */
    public function encode(): array
    {
        $pairs = fn (array $map) => array_map(fn ($k, $v) => [(string) $k, ...(array) $v], array_keys($map), $map);
        return [
            'top' => $pairs($this->top),
            'recent' => $this->recent,
            'rejected' => $pairs($this->rejected),
            'lastT' => $this->lastT,
        ];
    }

    /** Inverse of encode(); null for any malformed field. A state stored before rejections has none. */
    public static function decode(mixed $raw): ?self
    {
        if (!is_array($raw) || !array_key_exists('lastT', $raw)) {
            return null;
        }
        $lists = [$raw['top'] ?? null, $raw['recent'] ?? null, $raw['rejected'] ?? []];
        if (array_filter($lists, fn ($l) => !is_array($l) || !array_is_list($l))
            || ($raw['lastT'] !== null && !self::isTime($raw['lastT']))) {
            return null;
        }
        [$top, $recent, $rejected] = $lists;
        $bad = array_filter($top, fn ($e) => !self::pair($e, 3) || !self::isWeight($e[1]) || !self::isTime($e[2]))
            ?: array_filter([...$recent, ...$rejected], fn ($e) => !self::pair($e, 2) || !self::isTime($e[1]));
        if ($bad) {
            return null;
        }
        $state = new self();
        foreach ($top as [$item, $s, $t]) {
            $state->top[$item] = [(float) $s, $t];
        }
        $state->recent = $recent;
        foreach ($rejected as [$item, $t]) {
            $state->rejected[$item] = $t;
        }
        $state->lastT = $raw['lastT'];
        $state->pruneRejected();
        return $state;
    }

    private static function decay(int|float $dtMs): float
    {
        return exp(-(log(2) / self::HALF_LIFE_SECONDS) * ($dtMs / 1000));
    }

    private static function isTime(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    private static function isWeight(mixed $value): bool
    {
        return self::isTime($value) && $value >= 0;
    }

    private static function pair(mixed $entry, int $size): bool
    {
        return is_array($entry) && array_is_list($entry) && count($entry) === $size && self::isId($entry[0]);
    }

    private static function listOf(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /** @param list<array{string, int|float}> $recent */
    private static function without(array $recent, string $item): array
    {
        return array_values(array_filter($recent, fn ($e) => (string) $e[0] !== $item));
    }
}

/**
 * What the store keeps per user: the history and the shown items, in the
 * same JSON as the Python library's.
 *
 * $shown maps item => [recommendation id, shown at, clicked at?], oldest first.
 * $restored is set once the history holds recapi's stored one.
 *
 * @internal
 */
final class UserState
{
    public function __construct(
        public History $history = new History(), public array $shown = [], public bool $restored = false,
    ) {
    }

    public static function attributable(array $entry, int $t): bool
    {
        if (count($entry) < 3) {
            return $t - $entry[1] <= Client::DISPLAY_WINDOW_MS;
        }
        return $t - $entry[2] <= Client::CLICK_WINDOW_MS;
    }

    public function encode(int $now): string
    {
        $shown = [];
        foreach ($this->shown as $item => $entry) {
            if (self::attributable($entry, $now)) {
                $shown[] = [(string) $item, ...$entry];
            }
        }
        $state = ['history' => $this->history->encode(), 'shown' => array_slice($shown, -Client::MAX_SHOWN_ITEMS)];
        if ($this->restored) {
            $state['restored'] = true;
        }
        return json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    /** The stored state, an empty one for nothing stored, null when malformed. */
    public static function decode(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return new self();
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['shown'] ?? null)) {
            return null;
        }
        $history = History::decode($data['history'] ?? null);
        if ($history === null) {
            return null;
        }
        $shown = [];
        foreach ($data['shown'] as $e) {
            if (is_array($e) && array_is_list($e) && count($e) >= 3 && count($e) <= 4 && History::isId($e[0])
                && is_string($e[1]) && is_int($e[2]) && is_int($e[3] ?? 0)) {
                $shown[$e[0]] = array_slice($e, 1);
            }
        }
        return new self($history, $shown, ($data['restored'] ?? false) === true);
    }
}

/**
 * The recapi client: behaves like sdk/python (spec/client_vectors.json pins what every library
 * agrees on). PHP serves each request in a fresh process, so events are queued for the request
 * and sent once its response is out (flushAtShutdown). Create one per request or script; in a
 * worker runtime (Octane, RoadRunner), one per worker, and call flush() after each request.
 */
class Client
{
    public const VERSION = '0.4.1';
    // API limits (docs/api.md).
    public const MAX_EVENTS_PER_REQUEST = 1000;
    public const MAX_ITEMS_PER_REQUEST = 1000;
    public const MAX_EXCLUDE = 500;
    public const DEFAULT_COUNT = 10;
    public const MAX_COUNT = 100;
    // A widget still empty after 1.5 s has lost the user: recommend() gives up
    // then and falls back, whatever the general timeout (which suits events).
    public const RECOMMEND_TIMEOUT = 1.5;
    // The end-of-request send holds the PHP worker: a recapi that hangs must
    // not keep it from the next visitor longer than this.
    public const SHUTDOWN_TIMEOUT = 2.0;
    // The recommended level scale (docs/api.md "Interaction levels"), by name.
    public const LEVEL_NAMES = ['view' => 3, 'cart' => 5, 'purchase' => 10, 'reject' => -10];
    // A long import in a CLI script queues at most this many events.
    public const MAX_QUEUE = 10000;
    public const RETRIES = 3;
    public const RETRY_BASE = 0.5;
    public const RETRY_MAX = 8.0;
    // Attribution windows, as in the other libraries: an event on a shown item
    // within 30 minutes of the display is the click; after a click, that
    // item's events are attributed for 24 hours.
    public const DISPLAY_WINDOW_MS = 30 * 60 * 1000;
    public const CLICK_WINDOW_MS = 24 * 60 * 60 * 1000;
    public const MAX_SHOWN_ITEMS = 100;
    public const PUBLIC_KEY_PREFIX = 'rk_pub_';

    private const CODE_BY_STATUS = [
        400 => 'invalid_request', 401 => 'unauthorized', 403 => 'forbidden',
        404 => 'not_found', 413 => 'too_large', 429 => 'rate_limited',
    ];

    private readonly string $base;
    private readonly Store $store;
    private readonly int $maxBatch;
    private readonly string $prefix;
    private array $queue = [];
    private bool $failed = false;
    private ?\CurlHandle $curl = null;

    /**
     * @param string $apiKey Secret key (rk_sec_...): recapi then restores a
     *     user's history when the store lacks it.
     * @param string $baseUrl Your recapi API origin, e.g. https://api.example.com.
     * @param Store|null $store Per-user history and attribution state; this
     *     request only by default. SessionStore keeps it in the visitor's session.
     * @param float $timeout Per-request timeout in seconds (recommend() uses at most 1.5,
     *     the end-of-request send at most 2).
     * @param int $maxBatch Events per request, at most the API's 1,000.
     * @param object|null $logger A PSR-3 logger (any object with log($level, $message));
     *     error_log() by default.
     */
    public function __construct(
        private readonly string $apiKey,
        string $baseUrl,
        ?Store $store = null,
        private readonly float $timeout = 5.0,
        int $maxBatch = self::MAX_EVENTS_PER_REQUEST,
        private readonly ?object $logger = null,
    ) {
        if ($apiKey === '' || $baseUrl === '') {
            throw new \InvalidArgumentException('apiKey and baseUrl are required');
        }
        // curl reads a timeout of 0 as none at all.
        if (!($timeout > 0) || !is_finite($timeout)) {
            throw new \InvalidArgumentException('timeout must be a positive number of seconds');
        }
        $this->base = rtrim($baseUrl, '/');
        $this->store = $store ?? new MemoryStore();
        $this->maxBatch = min(max(1, $maxBatch), self::MAX_EVENTS_PER_REQUEST);
        // Two environments' keys may share one store: their users must not mix.
        $this->prefix = 'recapi:' . substr(hash('sha256', $apiKey), 0, 12) . ':';
        // Registered from a shutdown function, so it runs after the app's own.
        register_shutdown_function(function (): void {
            register_shutdown_function([$this, 'flushAtShutdown']);
        });
    }

    // --- events -------------------------------------------------------------

    /**
     * Record an interaction: the user's history changes at once, the event is queued. $level is an integer -10 to 10 or 'view' (3), 'cart' (5), 'purchase'
     * (10), 'reject' (-10: never recommend the item to this user again until a
     * positive event on it). $recommendationId overrides the automatic attribution.
     */
    public function track(string $itemId, mixed $level, ?string $userId = null, ?string $anonId = null, ?string $recommendationId = null): void
    {
        $level = self::level($level);
        self::checkIds(['itemId' => $itemId, 'userId' => $userId, 'anonId' => $anonId, 'recommendationId' => $recommendationId]);
        $t = $this->nowMs();
        $attributed = $recommendationId;
        $key = $this->key($userId, $anonId);
        if ($key !== null) {
            $state = $this->load($key);
            $state->history->apply($itemId, $level, $t);
            $entry = $state->shown[$itemId] ?? null;
            // An explicit id of the recommendation that showed the item is
            // still the click on it; another id leaves the entry alone.
            if ($entry !== null && ($recommendationId === null || $entry[0] === $recommendationId)
                && UserState::attributable($entry, $t)) {
                if ($level > 0 && count($entry) < 3) {
                    $state->shown[$itemId][] = $t;
                }
                $attributed = $entry[0];
            }
            $this->save($key, $state, $t);
        }
        $this->queue[] = self::compact([
            'item_id' => $itemId, 'level' => $level, 'user_id' => $userId, 'anon_id' => $anonId,
            'recommendation_id' => $attributed, 'timestamp' => self::iso($t), 'event_id' => self::uuid4(),
        ]);
        if (count($this->queue) > self::MAX_QUEUE) {
            $this->dropOverflow();
        }
        // A full batch goes at once (a CLI import), unless a send failed:
        // then flush() or the end of the request tries again.
        if (count($this->queue) >= $this->maxBatch && !$this->failed) {
            $this->drain(false);
        }
    }

    /**
     * Send every queued event now, with retries (end of a job in a worker). Never throws: failures are logged, and retryable batches stay queued.
     */
    public function flush(): void
    {
        $this->drain(true);
    }

    /**
     * Send what is queued once the response is out, one try of at most 2 s per batch.
     * Registered by the constructor. No retries: a PHP-FPM worker would wait on a
     * recapi outage instead of serving the next visitor.
     */
    public function flushAtShutdown(): void
    {
        if ($this->queue === []) {
            return;
        }
        // The visitor's next page would otherwise wait on the session lock
        // while the events are sent.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        // PHP flushes the page's output only after the shutdown functions, so
        // elsewhere (Apache mod_php, CGI) the end of the page waits on the send.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        $this->drain(false, min($this->timeout, self::SHUTDOWN_TIMEOUT));
        foreach (array_chunk($this->queue, $this->maxBatch) as $batch) {
            $this->log('error', sprintf('recapi: dropped %d events at the end of the request', count($batch)));
        }
        $this->queue = [];
    }

    private function dropOverflow(): void
    {
        $dropped = count($this->queue) - self::MAX_QUEUE;
        array_splice($this->queue, 0, $dropped);
        $this->log('warning', sprintf('recapi: event queue full, dropped the %d oldest events', $dropped));
    }

    private function drain(bool $retry, ?float $timeout = null): void
    {
        while ($this->queue !== []) {
            $batch = array_splice($this->queue, 0, $this->maxBatch);
            try {
                $this->sendEvents($batch, $retry, $timeout);
            } catch (\Throwable $error) {
                if ($error instanceof RecapiError && !$error->retryable()) {
                    $this->log('error', sprintf('recapi: dropped %d events: %s', count($batch), $error->getMessage()));
                    continue;
                }
                // Anything else keeps the batch, a bug of ours included:
                // event ids make a resend harmless.
                array_splice($this->queue, 0, 0, $batch);
                if (count($this->queue) > self::MAX_QUEUE) {
                    $this->dropOverflow();
                }
                $this->failed = true;
                $this->log('warning', sprintf('recapi: sending %d events failed, kept: %s', count($batch), $error->getMessage()));
                return;
            }
            $this->failed = false;
        }
    }

    private function sendEvents(array $events, bool $retry, ?float $timeout): void
    {
        $send = fn () => $this->request('POST', '/v1/events', body: ['events' => $events], timeout: $timeout);
        $response = $retry ? $this->withRetry($send) : $send();
        $rejected = is_array($response) ? ($response['rejected'] ?? null) : null;
        if (is_array($rejected) && $rejected !== []) {
            // Invalid events are reported, never retried: they would fail again.
            $detail = array_map(
                fn ($r) => is_array($r) && isset($events[$r['index'] ?? null]) ? $r + ['event' => $events[$r['index']]] : $r,
                array_slice($rejected, 0, 5),
            );
            $this->log('error', sprintf('recapi: %d event(s) rejected: %s', count($rejected), json_encode($detail, JSON_UNESCAPED_UNICODE)));
        }
    }

    // --- recommendations ----------------------------------------------------

    /**
     * Personalised recommendations for a user (or a visitor's $anonId).
     *
     * Sends the history from the store; without one, recapi uses its stored
     * history for $userId and returns it for the store. A history the store
     * started without it (a track() or identify() came first) gets recapi's
     * fetched once, within the same deadline, and merged in. No retry, at most 1.5 s: on a
     * network error, timeout or 5xx it returns popular items (fallback true,
     * no id) and logs the error. If those fail too (recapi is down), the list
     * is empty: hide the slot. A 4xx or 429 throws: a fallback would hide an
     * integration bug or a plan limit.
     *
     * @param list<string>|null $tagsAny
     * @param list<string>|null $tagsAll
     * @param list<string>|null $exclude
     */
    public function recommend(
        ?string $userId = null, ?string $anonId = null, int $count = 0, ?string $placement = null,
        ?array $tagsAny = null, ?array $tagsAll = null, ?array $exclude = null,
        ?string $contextItemId = null, ?float $discovery = null,
    ): Recommendations {
        $key = $this->key($userId, $anonId);
        $t = $this->nowMs();
        $timeout = min($this->timeout, self::RECOMMEND_TIMEOUT);
        $deadline = hrtime(true) / 1e9 + $timeout;
        $state = $key !== null ? $this->load($key) : new UserState();
        if ($userId !== null && $userId !== '' && !$state->restored && !$state->history->isEmpty()
            && !str_starts_with($this->apiKey, self::PUBLIC_KEY_PREFIX)) {
            // Half the time left, so the recommendation itself still fits.
            $state = $this->restore($key, $userId, $state, ($deadline - hrtime(true) / 1e9) / 2, $t);
        }
        [$history, $recent, $rejected] = $state->history->wire($t);
        $excluded = self::withRejected($exclude, $rejected);
        try {
            $response = $this->request('POST', '/v1/recommendations', timeout: $deadline - hrtime(true) / 1e9, body: self::compact([
                'user_id' => $userId, 'anon_id' => $anonId, 'placement' => $placement, 'count' => $count ?: null,
                'tags_any' => $tagsAny ?: null, 'tags_all' => $tagsAll ?: null, 'exclude' => $excluded,
                'context_item_id' => $contextItemId, 'history' => $history, 'recent' => $recent, 'discovery' => $discovery,
            ]));
            [$recId, $items] = self::parseRecommendations($response);
        } catch (RecapiError $error) {
            if (!$error->outage()) {
                throw $error;
            }
            // The requested length without the excluded items, so a few more
            // are asked for. 0 is the recommendations default; popular refuses it.
            $n = $count ?: self::DEFAULT_COUNT;
            $skip = array_fill_keys($excluded ?? [], true);
            if ($contextItemId !== null) {
                $skip[$contextItemId] = true;
            }
            try {
                $popular = $this->fetchPopular(min($n + count($skip), self::MAX_COUNT), $tagsAny, $tagsAll, $timeout);
            } catch (RecapiError $fallbackError) {
                // recapi is down altogether. The README promises no try/catch,
                // so an empty list the page can hide beats an exception that
                // fails the whole page.
                $this->log('warning', sprintf('recapi: recommendations failed (%s), popular too, served no items: %s',
                    $error->getMessage(), $fallbackError->getMessage()));
                return new Recommendations(null, true, []);
            }
            $this->log('warning', 'recapi: recommendations failed, served popular items: ' . $error->getMessage());
            $items = array_filter($popular, fn (Item $item) => !isset($skip[$item->itemId]));
            return new Recommendations(null, true, array_slice(array_values($items), 0, $n));
        }

        if ($key !== null) {
            $state = $this->load($key);
            foreach ($items as $item) {
                unset($state->shown[$item->itemId]);
                $state->shown[$item->itemId] = [$recId, $t];
            }
            $state->shown = array_slice($state->shown, -self::MAX_SHOWN_ITEMS, null, true);
            // Only trusted when we sent nothing; merged, never replacing what
            // was tracked meanwhile, and only once: another request may have
            // merged it already.
            $snapshot = $response['history'] ?? null;
            $done = $state->restored && !$state->history->isEmpty();
            if ($history === [] && $recent === [] && is_array($snapshot) && !$done) {
                $state->history = $state->history->mergeSnapshot(History::fromSnapshot($snapshot), $t);
                $state->restored = true;
            }
            $this->save($key, $state, $t);
        }
        return new Recommendations($recId, false, $items);
    }

    /**
     * Merge recapi's stored history into a local one that lacks it (a fresh store). The
     * recommendation's reply carries it only when the request sends no history, which would drop
     * this one. So it is asked for apart, once: a failure leaves the state as it was, to be tried
     * on the next call.
     */
    private function restore(string $key, string $userId, UserState $state, float $timeout, int $t): UserState
    {
        try {
            $snapshot = $this->request('GET', '/v1/users/' . rawurlencode($userId) . '/history', timeout: $timeout);
            if (!is_array($snapshot) || array_is_list($snapshot)) {
                throw new RecapiError('invalid_response', 'malformed history response');
            }
        } catch (RecapiError $error) {
            $this->log('warning', 'recapi: could not restore the user\'s stored history: ' . $error->getMessage());
            return $state;
        }
        $state = $this->load($key);
        if (!$state->restored) {
            $state->history = $state->history->mergeSnapshot(History::fromSnapshot($snapshot), $t);
            $state->restored = true;
            $this->save($key, $state, $t);
        }
        return $state;
    }

    /**
     * Call at login: the visitor's history and shown items become the user's. Merged into what the
     * store has for $userId (scores add up, as in the history spec), then the visitor's entry is
     * deleted. Without it, a visitor's clicks stop counting once they log in.
     */
    public function identify(string $anonId, string $userId): void
    {
        self::checkIds(['anonId' => $anonId, 'userId' => $userId]);
        $visitor = $this->key(null, $anonId);
        $user = $this->key($userId, null);
        $t = $this->nowMs();
        $guest = $this->load($visitor);
        if ($guest->history->isEmpty() && $guest->history->rejected === [] && $guest->shown === []) {
            return;
        }
        $state = $this->load($user);
        $state->history = $state->history->merge($guest->history, $t);
        // Oldest display first, as recommend() keeps them; an item's later display wins.
        $shown = [];
        foreach ([$state->shown, $guest->shown] as $entries) {
            foreach ($entries as $item => $entry) {
                $shown[] = [(string) $item, $entry];
            }
        }
        usort($shown, fn ($a, $b) => $a[1][1] <=> $b[1][1]);
        $state->shown = [];
        foreach ($shown as [$item, $entry]) {
            unset($state->shown[$item]);
            $state->shown[$item] = $entry;
        }
        $this->save($user, $state, $t);
        try {
            $this->store->delete($visitor);
        } catch (\Throwable $error) {
            $this->log('error', 'recapi: could not delete the visitor\'s stored state: ' . $error->getMessage());
        }
    }

    /**
     * Non-personalised popular items, for pages without a user (no retry).
     * @return list<Item>
     */
    public function popular(int $count = self::DEFAULT_COUNT, ?array $tagsAny = null, ?array $tagsAll = null): array
    {
        return $this->fetchPopular($count, $tagsAny, $tagsAll, null);
    }

    private function fetchPopular(int $count, ?array $tagsAny, ?array $tagsAll, ?float $timeout): array
    {
        $query = [];
        foreach (['tags_any' => $tagsAny, 'tags_all' => $tagsAll] as $name => $tags) {
            if ($tags) {
                $query[$name] = implode(',', $tags);
            }
        }
        $query['count'] = (string) $count;
        $response = $this->request('GET', '/v1/popular', query: $query, timeout: $timeout);
        $raw = is_array($response) ? ($response['items'] ?? null) : null;
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new RecapiError('invalid_response', 'malformed popular response');
        }
        return array_map(fn ($entry) => self::item($entry, 'backfill'), $raw);
    }

    // --- catalog and users --------------------------------------------------

    /**
     * Create or replace items (arrays of the API's item fields), 1,000 per request. Retried like events. Returns how many were stored; an invalid item
     * throws, and nothing of its batch of 1,000 is stored.
     *
     * @param iterable<array<string, mixed>> $items
     */
    public function upsertItems(iterable $items): int
    {
        $upserted = 0;
        $batch = [];
        $send = function () use (&$batch, &$upserted): void {
            $response = $this->withRetry(fn () => $this->request('POST', '/v1/items:batch', body: ['items' => $batch]));
            $upserted += is_array($response) ? (int) ($response['upserted'] ?? 0) : 0;
            $batch = [];
        };
        foreach ($items as $item) {
            $batch[] = $item;
            if (count($batch) === self::MAX_ITEMS_PER_REQUEST) {
                $send();
            }
        }
        if ($batch !== []) {
            $send();
        }
        return $upserted;
    }

    /** Erase a user's events in recapi (GDPR) and their state in the store. */
    public function deleteUser(string $userId): void
    {
        self::checkIds(['userId' => $userId]);
        $this->withRetry(fn () => $this->request('DELETE', '/v1/users/' . rawurlencode($userId)));
        try {
            $this->store->delete($this->key($userId, null));
        } catch (\Throwable $error) {
            $this->log('error', 'recapi: could not delete the user\'s stored state: ' . $error->getMessage());
        }
    }

    // --- hooks the tests replace (clock, backoff sleep, jitter, transport) ---

    protected function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    protected function sleep(float $seconds): void
    {
        usleep((int) round($seconds * 1e6));
    }

    protected function random(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    /**
     * One HTTP exchange: [status, lower-cased headers, body]; RecapiError 'network' or 'timeout'
     * when no complete response arrived. One curl handle per client reuses its connection.
     */
    protected function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $this->curl ??= curl_init();
        $curl = $this->curl;
        curl_reset($curl);
        $received = [];
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $text = curl_exec($curl);
        $errno = curl_errno($curl);
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new RecapiError('timeout', sprintf('request timed out after %s s', $timeout));
        }
        if ($errno !== 0 || !is_string($text)) {
            // A reply cut mid-body (CURLE_PARTIAL_FILE) is a network error too.
            throw new RecapiError('network', curl_error($curl) ?: 'curl error ' . $errno);
        }
        return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $received, $text];
    }

    // --- plumbing -----------------------------------------------------------

    private function request(string $method, string $path, mixed $body = null, array $query = [], ?float $timeout = null): mixed
    {
        $headers = ['Accept: application/json', 'User-Agent: recapi-php/' . self::VERSION];
        // A public key travels in the URL only on GET /v1/popular (CDN
        // caching); the API refuses a secret key there.
        if ($method === 'GET' && str_starts_with($this->apiKey, self::PUBLIC_KEY_PREFIX)) {
            $query['key'] = $this->apiKey;
        } else {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        $url = $this->base . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $data = null;
        if ($body !== null) {
            // Raw UTF-8: escaped, non-Latin ids would take up to 3 times the
            // bytes, and a batch of 1,000 could pass the API's 2 MiB limit.
            $data = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            // curl waits for a 100 Continue (up to 1 s) before a body over 1 MB,
            // such as items with long texts: an empty Expect skips it.
            array_push($headers, 'Content-Type: application/json', 'Expect:');
        }
        $timeout ??= $this->timeout;
        // curl reads a timeout of 0 as none at all.
        if ($timeout <= 0) {
            throw new RecapiError('timeout', 'the deadline passed before the request was sent');
        }
        [$status, $received, $text] = $this->send($method, $url, $headers, $data, $timeout);
        $payload = $text === '' ? null : json_decode($text, true);
        // curl follows no redirect: a 3xx fails like a 4xx.
        if ($status >= 200 && $status < 300) {
            return $payload;
        }
        $error = is_array($payload) && is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $code = $error['code'] ?? null;
        if (!is_string($code) || $code === '') {
            $code = self::CODE_BY_STATUS[$status] ?? ($status >= 500 ? 'internal' : 'http_error');
        }
        $message = is_string($error['message'] ?? null) && $error['message'] !== '' ? $error['message'] : 'HTTP ' . $status;
        throw new RecapiError($code, $message, $status, self::retryAfter($received['retry-after'] ?? null));
    }

    /** Call $fn, retrying retryable errors with jittered exponential backoff; Retry-After wins, both capped at 8 s. */
    private function withRetry(callable $fn): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $fn();
            } catch (RecapiError $error) {
                if ($attempt >= self::RETRIES || !$error->retryable()) {
                    throw $error;
                }
                $backoff = self::RETRY_BASE * 2 ** $attempt * (0.5 + $this->random() / 2);
                $this->sleep(min(self::RETRY_MAX, $error->retryAfter ?? $backoff));
            }
        }
    }

    private function key(?string $userId, ?string $anonId): ?string
    {
        if ($userId !== null && $userId !== '') {
            return $this->prefix . 'u:' . $userId;
        }
        if ($anonId !== null && $anonId !== '') {
            return $this->prefix . 'a:' . $anonId;
        }
        return null;
    }

    private function load(string $key): UserState
    {
        // The store is a cache: when it fails, recapi's stored history is
        // still there, so the call goes on without it.
        try {
            $raw = $this->store->get($key);
        } catch (\Throwable $error) {
            $this->log('error', 'recapi: store read failed: ' . $error->getMessage());
            return new UserState();
        }
        $state = UserState::decode($raw);
        if ($state === null) {
            $this->log('warning', 'recapi: discarding a malformed stored user state');
            return new UserState();
        }
        return $state;
    }

    private function save(string $key, UserState $state, int $now): void
    {
        try {
            $this->store->set($key, $state->encode($now));
        } catch (\Throwable $error) {
            $this->log('error', 'recapi: store write failed: ' . $error->getMessage());
        }
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger !== null && method_exists($this->logger, 'log')) {
            $this->logger->log($level, $message);
        } else {
            error_log($message);
        }
    }

    private static function retryAfter(?string $header): ?float
    {
        if ($header === null || !is_numeric(trim($header))) {
            return null;
        }
        $seconds = (float) trim($header);
        return $seconds >= 0 && is_finite($seconds) ? $seconds : null;
    }

    private static function parseRecommendations(mixed $response): array
    {
        if (!is_array($response) || !is_string($response['recommendation_id'] ?? null)
            || !is_array($response['items'] ?? null) || !array_is_list($response['items'])) {
            throw new RecapiError('invalid_response', 'malformed recommendations response');
        }
        return [$response['recommendation_id'], array_map(fn ($e) => self::item($e, 'exploit'), $response['items'])];
    }

    private static function item(mixed $entry, string $slot): Item
    {
        if (!is_array($entry) || !is_string($entry['item_id'] ?? null)) {
            throw new RecapiError('invalid_response', 'malformed item in response');
        }
        $score = $entry['score'] ?? 0;
        $metadata = $entry['metadata'] ?? null;
        $why = $entry['why'] ?? null;
        return new Item($entry['item_id'], is_int($score) || is_float($score) ? (float) $score : 0.0, (string) ($entry['slot'] ?? '') ?: $slot,
            is_array($metadata) && $metadata !== [] ? $metadata : null, is_string($why) && $why !== '' ? $why : null);
    }

    /** The caller's exclude plus the rejected items, newest first, up to the API limit. */
    private static function withRejected(?array $exclude, array $rejected): ?array
    {
        if ($rejected === []) {
            return $exclude === null ? null : array_values($exclude);
        }
        $out = [];
        foreach ([...($exclude ?? []), ...array_reverse($rejected)] as $i => $item) {
            if ($i >= count($exclude ?? []) && count($out) >= self::MAX_EXCLUDE) {
                break;
            }
            $out[$item] ??= (string) $item;
        }
        return array_values($out);
    }

    private static function level(mixed $level): int
    {
        if (is_string($level) && isset(self::LEVEL_NAMES[$level])) {
            return self::LEVEL_NAMES[$level];
        }
        if (is_int($level) && $level >= -10 && $level <= 10) {
            return $level;
        }
        throw new \InvalidArgumentException('level must be an integer from -10 to 10 or one of ' . implode(', ', array_keys(self::LEVEL_NAMES)));
    }

    private static function checkIds(array $ids): void
    {
        foreach ($ids as $name => $value) {
            if ($value !== null && !History::isId($value)) {
                throw new \InvalidArgumentException("$name must be 1-256 bytes of UTF-8 without control characters");
            }
        }
    }

    private static function compact(array $fields): array
    {
        return array_filter($fields, fn ($v) => $v !== null);
    }

    private static function iso(int $tMs): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($tMs, 1000)) . sprintf('.%03dZ', $tMs % 1000);
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
