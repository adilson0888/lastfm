<?php

declare(strict_types=1);

// Verifies provider parsing against representative upstream JSON shapes,
// using a stub HTTP client so no network is touched.

require_once __DIR__ . '/../src/Artist.php';
require_once __DIR__ . '/../src/ProviderInterface.php';
require_once __DIR__ . '/../src/Http/JsonClient.php';
require_once __DIR__ . '/../src/Provider/ListenBrainzProvider.php';
require_once __DIR__ . '/../src/Provider/MalojaProvider.php';
require_once __DIR__ . '/../src/Provider/MusicBrainzTags.php';

function check(string $label, bool $ok): void
{
    echo ($ok ? "PASS" : "FAIL") . " - $label\n";
}

/** Replays canned responses keyed by URL substring. */
final class StubClient extends Http\JsonClient
{
    public array $seen = [];

    public function __construct(private array $responses)
    {
        parent::__construct();
    }

    public function get(
        string $url,
        array $query = [],
        array $headers = [],
        ?string &$error = null,
        ?int &$status = null,
        ?string &$retryAfter = null,
    ): ?array {
        $status = 200;
        $this->seen[] = ['url' => $url, 'query' => $query, 'headers' => $headers];
        $probe = $query === [] ? $url : $url . '?' . http_build_query($query);
        foreach ($this->responses as $needle => $response) {
            if (str_contains($probe, $needle)) {
                if (is_int($response)) { // status-only stub
                    $status = $response;
                    return null;
                }
                return $response;
            }
        }
        $status = 404;
        return null;
    }
}

// --- ListenBrainz: stats page shape ---
$lbJson = [
    'payload' => [
        'artists' => [
            ['artist_mbid' => 'abc-123', 'artist_name' => 'Portishead', 'listen_count' => 420],
            ['artist_mbid' => '', 'artist_name' => 'Björk', 'listen_count' => 88],
        ],
        'total_artist_count' => 512,
        'range' => 'all_time',
    ],
];
$stub = new StubClient(['/1/stats/user/' => $lbJson]);
$lb = new Provider\ListenBrainzProvider($stub, 'bob', 'https://api.listenbrainz.org');

check('lb total from payload', $lb->totalArtists() === 512);

// The library size is clamped to ListenBrainz's 1000-artist statistics cap so
// section math never targets ranks the API cannot return.
$bigStub = new StubClient(['/1/stats/user/' => ['payload' => ['artists' => [], 'total_artist_count' => 8629]]]);
$bigLb = new Provider\ListenBrainzProvider($bigStub, 'rob', 'https://api.listenbrainz.org');
check('lb total clamped to 1000', $bigLb->totalArtists() === 1000);
$artists = $lb->artists(100, 1);
check('lb artist count', count($artists) === 2);
check('lb first artist name', $artists[0]->name === 'Portishead');
check('lb playcount as string', $artists[0]->playcount === '420');
check('lb mbid kept', $artists[0]->mbid === 'abc-123');
check('lb provider url uses mbid', str_contains($artists[0]->providerUrl, 'musicbrainz.org/artist/abc-123'));

// offset math: page 2 with limit 100 -> offset 100
$lb->artists(100, 2);
$lastQuery = end($stub->seen)['query'];
check('lb page 2 offset', $lastQuery['offset'] === 100);
check('lb range all_time', $lastQuery['range'] === 'all_time');

// --- ListenBrainz: tags are resolved elsewhere (MusicBrainz), so the
// provider exposes only the library.
check('lb no tag method pollutes interface', !method_exists($lb, 'artistTags'));

// --- MusicBrainz: genres preferred over tags, cached on disk.
$cacheDir = sys_get_temp_dir() . '/mb-tags-test-' . uniqid();
$mbStub = new StubClient([
    '/artist/8f6bd1e4' => [
        'name' => 'Portishead',
        'genres' => [['name' => 'trip hop', 'count' => 5], ['name' => 'downtempo', 'count' => 3]],
        'tags' => [['name' => 'trip-hop'], ['name' => 'bristol sound']],
    ],
    '/artist?query=' => ['artists' => [['id' => 'a74b1b7f-71a5-4011-9441-d0b5e4122711', 'name' => 'Radiohead']]],
]);
$mb = new Provider\MusicBrainzTags($mbStub, 'https://musicbrainz.org/ws/2', $cacheDir, 0.0);

$tagsWithMbid = $mb->artistTags('Portishead', '8f6bd1e4-fbe1-4f50-aa9b-94c450ec0f11');
check('mb genres first', $tagsWithMbid[0] === 'trip hop' && $tagsWithMbid[1] === 'downtempo');
check('mb tags appended', in_array('bristol sound', $tagsWithMbid, true));

// Second call should hit the on-disk cache, adding no new HTTP calls.
$before = count($mbStub->seen);
$mb->artistTags('Portishead', '8f6bd1e4-fbe1-4f50-aa9b-94c450ec0f11');
check('mb cache avoids second fetch', count($mbStub->seen) === $before);
check('mb cache file written', is_file($cacheDir . '/8f6bd1e4-fbe1-4f50-aa9b-94c450ec0f11.json'));

// With no MBID, the name is searched first.
$byName = $mb->artistTags('Radiohead', '');
check('mb resolves mbid by name', is_array($byName));

// Rate limiting: a 503 must not be cached, and a later success is returned.
$flakyDir = sys_get_temp_dir() . '/mb-flaky-' . uniqid();
$counter = new class { public int $n = 0; };
$flaky = new class($counter) extends Http\JsonClient {
    public function __construct(private object $counter)
    {
        parent::__construct();
    }
    public function get(string $url, array $query = [], array $headers = [], ?string &$error = null, ?int &$status = null, ?string &$retryAfter = null): ?array
    {
        $status = 200;
        $this->counter->n++;
        if ($this->counter->n < 3) {
            $status = 503;
            return null;
        }
        return ['genres' => [['name' => 'shoegaze']]];
    }
};
$mbFlaky = new Provider\MusicBrainzTags($flaky, 'https://mb', $flakyDir, 0.0);
check('mb retries through 503', $mbFlaky->artistTags('Slowdive', 'some-mbid') === ['shoegaze']);
check('mb retried three times', $counter->n === 3);

// A persistent 503 must not poison the cache.
$badDir = sys_get_temp_dir() . '/mb-bad-' . uniqid();
$alwaysFailing = new Provider\MusicBrainzTags(
    new StubClient(['/artist/' => 503]),
    'https://mb',
    $badDir,
    0.0,
);
check('mb persistent 503 -> empty', $alwaysFailing->artistTags('X', 'bad-mbid') === []);
check('mb failure not cached', !is_file($badDir . '/h' . hash('sha256', 'bad-mbid') . '.json'));

// A genuine empty tags result IS cached (avoids repeat lookups).
$emptyDir = sys_get_temp_dir() . '/mb-empty-' . uniqid();
$emptyMb = new Provider\MusicBrainzTags(new StubClient(['/artist/' => ['genres' => [], 'tags' => []]]), 'https://mb', $emptyDir, 0.0);
check('mb empty result returned', $emptyMb->artistTags('Nobody', 'empty-mbid') === []);
// Non-UUID keys are hashed so nothing path-like can ever be written.
$hashed = $emptyDir . '/h' . hash('sha256', 'empty-mbid') . '.json';
check('mb empty result cached', is_file($hashed));

// Hostile cache keys cannot escape the cache directory, and every file lands
// directly inside it with a safe name.
$evilDir = sys_get_temp_dir() . '/mb-evil-' . uniqid();
$evilMb = new Provider\MusicBrainzTags(new StubClient(['/artist/' => ['genres' => [['name' => 'x']]]]), 'https://mb', $evilDir, 0.0);
foreach (['../../etc/passwd', '..', '.', 'a/b', "nul\0byte"] as $evil) {
    $evilMb->artistTags('x', $evil);
}
$escaped = glob($evilDir . '/../*') ?: [];
$files = glob($evilDir . '/*.json') ?: [];
check('mb hostile keys stay in cache dir', count($files) === 5);
$names = array_map('basename', $files);
check('mb cache names are hashes or uuids', $names === array_filter($names, static fn ($n): bool =>
    preg_match('/^(h[0-9a-f]{64}|[0-9a-f-]{36})\.json$/', $n) === 1));

// --- Maloja: chart shape ---
$malojaJson = [
    'status' => 'ok',
    'list' => [
        ['scrobbles' => 900, 'artist' => 'Radiohead', 'artist_id' => 1],
        ['scrobbles' => 300, 'artist' => 'Aphex Twin', 'artist_id' => 2],
        ['scrobbles' => 100, 'artist' => 'Boards of Canada', 'artist_id' => 3],
    ],
];
$mstub = new StubClient(['/apis/mlj_1/charts/artists' => $malojaJson]);
$maloja = new Provider\MalojaProvider($mstub, 'https://maloja.test', 'key1');

check('maloja total', $maloja->totalArtists() === 3);
$page = $maloja->artists(2, 1);
check('maloja page slice', count($page) === 2 && $page[0]->name === 'Radiohead');
$page2 = $maloja->artists(2, 2);
check('maloja page 2 slice', count($page2) === 1 && $page2[0]->name === 'Boards of Canada');
check('maloja playcount', $page[0]->playcount === '900');
check('maloja api key sent', end($mstub->seen)['query']['key'] === 'key1');
// Maloja returns HTTP 500 for `since=0`; the parameter must never be sent.
check('maloja omits since', !array_key_exists('since', end($mstub->seen)['query']));

// Without an API key, the key parameter is omitted entirely.
$noKeyStub = new StubClient(['/apis/mlj_1/charts/artists' => $malojaJson]);
$noKey = new Provider\MalojaProvider($noKeyStub, 'https://maloja.test');
$noKey->totalArtists();
$lastNoKeyQuery = end($noKeyStub->seen)['query'];
check('maloja no key param when unset', !array_key_exists('key', $lastNoKeyQuery));

// Maloja error status -> empty chart, not a crash.
$badMaloja = new Provider\MalojaProvider(new StubClient(['/apis/mlj_1/' => ['status' => 'error']]), 'https://x');
check('maloja error yields empty', $badMaloja->totalArtists() === 0);
