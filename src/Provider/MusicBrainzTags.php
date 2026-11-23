<?php

declare(strict_types=1);

namespace Provider;

use Http\JsonClient;

/**
 * Artist tag lookup backed by MusicBrainz.
 *
 * Neither ListenBrainz (whose metadata endpoints now require a token) nor
 * Maloja expose music styles. Maloja in particular gives us only an artist
 * *name*, no MBID, so a naive implementation would hit MusicBrainz twice per
 * artist on every page load (name search, then MBID lookup) and cache neither.
 *
 * This class therefore:
 *   - caches results by name AND by MBID, remembering the MBID resolved for a
 *     name so later lookups go straight to the (cached) MBID path;
 *   - spaces requests to MusicBrainz (~1 req/s, it replies 503 when exceeded),
 *     releasing the cross-process lock before sleeping so concurrent requests
 *     queue by time slot instead of blocking each other;
 *   - honours Retry-After and retries a few times on 503/429;
 *   - caches successful lookups (including "no tags") for a day, and never
 *     caches a failure.
 */
final class MusicBrainzTags
{
    private const CACHE_TTL = 86400;

    private const MAX_ATTEMPTS = 4;

    /** Minimum spacing between MusicBrainz requests, in microseconds. */
    private const MIN_INTERVAL_US = 1_100_000;

    public function __construct(
        private readonly JsonClient $http,
        private readonly string $baseUrl = 'https://musicbrainz.org/ws/2',
        private readonly string $cacheDir = __DIR__ . '/../../cache',
        private readonly float $sleepScale = 1.0,
    ) {
    }

    /**
     * @return string[] Human-readable genre/tag names, most relevant first.
     */
    public function artistTags(string $name, string $mbid = ''): array
    {
        // Bound the inputs; artist names and MBIDs are short.
        $name = substr(trim($name), 0, 256);
        $mbid = substr(trim($mbid), 0, 64);

        // 1. Direct MBID cache hit.
        if ($mbid !== '') {
            $cached = $this->readCache($mbid);
            if ($cached !== null) {
                return $cached;
            }
        }

        // 2. Name cache hit (covers Maloja, which never supplies an MBID).
        if ($name !== '') {
            $cached = $this->readCache($name);
            if ($cached !== null) {
                return $cached;
            }
        }

        // 3. Resolve tags, preferring an MBID lookup when we have one.
        $tags = null;
        if ($mbid !== '') {
            $tags = $this->lookupById($mbid);
        }

        if ($tags === null && $name !== '') {
            [$tags, $resolvedMbid] = $this->lookupByName($name);
            // Remember the name→MBID mapping under the name key so the next
            // request short-circuits to the MBID path.
            if ($resolvedMbid !== '' && $tags !== null) {
                $this->writeCache($name, $tags, $resolvedMbid);
            }
        }

        return $tags ?? [];
    }

    /**
     * Resolve tags for many artists in one pass.
     *
     * The browser issues a single request per page instead of one per row.
     * Cached artists cost nothing; the rest are fetched in order under the
     * shared throttle, so the pacing is controlled in one place rather than by
     * a burst of concurrent requests that trip MusicBrainz's rate limit.
     *
     * @param array<int,array{name:string,mbid?:string}> $artists
     * @return array<int,array{name:string,mbid:string,tags:string[],cached:bool}>
     */
    public function artistTagsBatch(array $artists): array
    {
        $out = [];
        foreach ($artists as $artist) {
            $name = is_array($artist) ? (string) ($artist['name'] ?? '') : (string) $artist;
            $mbid = is_array($artist) ? (string) ($artist['mbid'] ?? '') : '';
            $name = substr(trim($name), 0, 256);
            $mbid = substr(trim($mbid), 0, 64);

            if ($name === '' && $mbid === '') {
                continue;
            }

            $cached = false;
            if ($mbid !== '' && $this->readCache($mbid) !== null) {
                $cached = true;
            } elseif ($name !== '' && $this->readCache($name) !== null) {
                $cached = true;
            }

            $tags = $this->artistTags($name, $mbid);
            $out[] = ['name' => $name, 'mbid' => $mbid, 'tags' => $tags, 'cached' => $cached];
        }

        return $out;
    }

    /** @return string[]|null null means "lookup failed"; [] means "no tags". */
    private function lookupById(string $mbid): ?array
    {
        $decoded = $this->request(
            $this->baseUrl . '/artist/' . rawurlencode($mbid),
            ['inc' => 'genres tags', 'fmt' => 'json'],
        );

        if ($decoded === null) {
            return null;
        }

        $tags = $this->extract($decoded);
        $this->writeCache($mbid, $tags);

        return $tags;
    }

    /**
     * @return array{0:string[]|null,1:string} Tags and the resolved MBID
     *                                            (empty when not resolved).
     */
    private function lookupByName(string $name): array
    {
        $decoded = $this->request(
            $this->baseUrl . '/artist',
            ['query' => 'artist:"' . $name . '"', 'fmt' => 'json', 'limit' => 1],
        );

        if ($decoded === null) {
            return [null, ''];
        }

        $mbid = $decoded['artists'][0]['id'] ?? '';
        if (!is_string($mbid) || $mbid === '') {
            // Genuine miss: search succeeded but matched nothing. Cache the
            // empty result under the name so we do not search again today.
            $this->writeCache($name, []);

            return [[], ''];
        }

        return [$this->lookupById($mbid), $mbid];
    }

    /**
     * Perform a rate-limited MusicBrainz GET, retrying on throttling.
     *
     * @param array<string,string|int> $query
     * @return array<string,mixed>|null
     */
    private function request(string $url, array $query): ?array
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $this->throttle();

            $status = 0;
            $retryAfter = null;
            $decoded = $this->http->get($url, $query, [], $error, $status, $retryAfter);

            if ($decoded !== null) {
                return $decoded;
            }

            if (!in_array($status, [429, 503], true)) {
                return null; // Real error (404, transport failure, ...).
            }

            if ($attempt === self::MAX_ATTEMPTS) {
                return null;
            }

            // Honour Retry-After exactly. MusicBrainz sends a small value
            // (usually 1s); a long escalating backoff just keeps the shared
            // per-IP bucket drained and makes the next request fail too.
            $wait = ($retryAfter !== null && ctype_digit($retryAfter))
                ? (int) $retryAfter
                : 1;
            $this->sleep((int) ceil($wait * $this->sleepScale));
        }

        return null;
    }

    /**
     * Space MusicBrainz requests across processes.
     *
     * The lock is held only long enough to reserve the next time slot; the
     * caller then sleeps *outside* the lock. This means N concurrent requests
     * queue by timestamp instead of serialising on the lock itself, which
     * previously made a batch of tag lookups take N seconds each.
     */
    private function throttle(): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        $lockFile = rtrim($this->cacheDir, '/') . '/.mb-lock';
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            return; // No lock available; best effort.
        }

        $waitUntil = 0.0;
        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }

            $stampFile = rtrim($this->cacheDir, '/') . '/.mb-last';
            $last = is_file($stampFile) ? (float) file_get_contents($stampFile) : 0.0;
            $now = microtime(true);
            $earliest = max($now, $last + self::MIN_INTERVAL_US / 1_000_000);
            $waitUntil = $earliest;

            // Claim the slot immediately so the next worker spaces after us.
            @file_put_contents($stampFile, (string) $earliest, LOCK_EX);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $delta = $waitUntil - microtime(true);
        if ($delta > 0) {
            $this->sleep((int) ceil($delta * $this->sleepScale));
        }
    }

    private function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    /**
     * @param array<string,mixed> $decoded
     * @return string[]
     */
    private function extract(array $decoded): array
    {
        // Prefer curated genres; append distinct community tags.
        $names = [];
        foreach (['genres', 'tags'] as $key) {
            foreach ($decoded[$key] ?? [] as $entry) {
                $tag = trim((string) ($entry['name'] ?? ''));
                if ($tag !== '' && !in_array($tag, $names, true)) {
                    $names[] = $tag;
                }
            }
        }

        return array_slice($names, 0, 8);
    }

    /**
     * @return array{tags:string[],mbid:string}|null Null on a cache miss.
     */
    private function readCacheEntry(string $key): ?array
    {
        $file = $this->cacheFile($key);
        if ($file === null || !is_file($file)) {
            return null;
        }
        if (time() - (int) filemtime($file) > self::CACHE_TTL) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            return null;
        }

        // New format: {"tags": [...], "mbid": "..."}; tolerate plain arrays
        // written by earlier versions.
        if (isset($decoded['tags']) && is_array($decoded['tags'])) {
            $tags = array_values(array_filter($decoded['tags'], 'is_string'));
            $mbid = is_string($decoded['mbid'] ?? null) ? $decoded['mbid'] : '';

            return ['tags' => $tags, 'mbid' => $mbid];
        }

        return ['tags' => array_values(array_filter($decoded, 'is_string')), 'mbid' => ''];
    }

    /** @return string[]|null */
    private function readCache(string $key): ?array
    {
        return $this->readCacheEntry($key)['tags'] ?? null;
    }

    /** @param string[] $tags */
    private function writeCache(string $key, array $tags, string $mbid = ''): void
    {
        $file = $this->cacheFile($key);
        if ($file === null) {
            return;
        }
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        $payload = json_encode(['tags' => array_values($tags), 'mbid' => $mbid]);
        if ($payload !== false) {
            @file_put_contents($file, $payload, LOCK_EX);
        }
    }

    private function cacheFile(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        // MusicBrainz IDs are UUIDs. Only cache under a strictly validated name;
        // anything else (e.g. a name-derived key) is hashed so no crafted value
        // can influence the path on disk.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
            $safe = strtolower($key);
        } else {
            $safe = 'h' . hash('sha256', $key);
        }

        return rtrim($this->cacheDir, '/') . '/' . $safe . '.json';
    }
}
