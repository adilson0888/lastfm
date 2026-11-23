<?php

declare(strict_types=1);

namespace Provider;

use Http\JsonClient;

/**
 * Artist tag lookup backed by MusicBrainz.
 *
 * Neither ListenBrainz (whose metadata endpoints now require a token) nor
 * Maloja expose music styles, but both give us what MusicBrainz needs: a name,
 * and usually an MBID. MusicBrainz's public API is free, keyless and returns
 * curated genres.
 *
 * MusicBrainz throttles anonymous clients to roughly one request per second and
 * replies 503 when exceeded. Because the UI loads tags for many rows at once,
 * this class:
 *   - serialises requests across processes with a lock, spacing them out;
 *   - honours Retry-After and retries a few times on 503/429;
 *   - caches successful lookups (including "no tags") on disk for a day, and
 *     never caches a failure.
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
        // Bound the inputs; artist names and MBIDs are short. This avoids
        // unbounded upstream queries and cache keys from oversized requests.
        $name = substr(trim($name), 0, 256);
        $mbid = substr(trim($mbid), 0, 64);

        if ($mbid !== '') {
            $cached = $this->readCache($mbid);
            if ($cached !== null) {
                return $cached;
            }
        }

        $tags = null;
        if ($mbid !== '') {
            $tags = $this->lookupById($mbid);
        }

        if ($tags === null && $name !== '') {
            $tags = $this->lookupByName($name);
        }

        return $tags ?? [];
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

    /** @return string[]|null */
    private function lookupByName(string $name): ?array
    {
        $decoded = $this->request(
            $this->baseUrl . '/artist',
            ['query' => 'artist:"' . $name . '"', 'fmt' => 'json', 'limit' => 1],
        );

        if ($decoded === null) {
            return null;
        }

        $mbid = $decoded['artists'][0]['id'] ?? '';
        if (!is_string($mbid) || $mbid === '') {
            // Genuine miss: search succeeded but matched nothing.
            return [];
        }

        return $this->lookupById($mbid);
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

            $wait = $retryAfter !== null && ctype_digit($retryAfter)
                ? (int) $retryAfter
                : $attempt;
            $this->sleep((int) ceil($wait * $this->sleepScale));
        }

        return null;
    }

    /**
     * Serialise MusicBrainz traffic across processes and keep requests at
     * least MIN_INTERVAL_US apart, so a burst of concurrent AJAX calls from
     * the browser does not trip the anonymous rate limit.
     */
    private function throttle(): void
    {
        $lockFile = rtrim($this->cacheDir, '/') . '/.mb-lock';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            return; // No lock available; best effort.
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }

            $stampFile = rtrim($this->cacheDir, '/') . '/.mb-last';
            $last = is_file($stampFile) ? (float) file_get_contents($stampFile) : 0.0;
            $elapsed = (microtime(true) - $last) * 1_000_000;
            if ($elapsed < self::MIN_INTERVAL_US) {
                $this->sleep((int) ceil((self::MIN_INTERVAL_US - $elapsed) / 1_000_000));
            }

            @file_put_contents($stampFile, (string) microtime(true), LOCK_EX);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
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

    /** @return string[]|null */
    private function readCache(string $mbid): ?array
    {
        $file = $this->cacheFile($mbid);
        if ($file === null || !is_file($file)) {
            return null;
        }
        if (time() - (int) filemtime($file) > self::CACHE_TTL) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : null;
    }

    /** @param string[] $tags */
    private function writeCache(string $mbid, array $tags): void
    {
        $file = $this->cacheFile($mbid);
        if ($file === null) {
            return;
        }
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        @file_put_contents($file, json_encode($tags), LOCK_EX);
    }

    private function cacheFile(string $mbid): ?string
    {
        $mbid = trim($mbid);
        if ($mbid === '') {
            return null;
        }

        // MusicBrainz IDs are UUIDs. Only cache under a strictly validated name;
        // anything else (e.g. a name-derived key) is hashed so no crafted value
        // can influence the path on disk.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $mbid)) {
            $key = strtolower($mbid);
        } else {
            $key = 'h' . hash('sha256', $mbid);
        }

        return rtrim($this->cacheDir, '/') . '/' . $key . '.json';
    }
}
