<?php

declare(strict_types=1);

/**
 * Front controller for the AJAX endpoints.
 *
 *   GET  api.php?tunning[classics]=3&tunning[known]=...   -> rendered artist list
 *   GET  api.php?oper=tags&name=<artist>&mbid=<mbid>      -> rendered tag links
 *
 * This is the only server-side endpoint exposed to the web; everything under
 * /src lives outside the document root.
 */

require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/Artist.php';
require_once __DIR__ . '/../src/ProviderInterface.php';
require_once __DIR__ . '/../src/Http/JsonClient.php';
require_once __DIR__ . '/../src/Provider/ListenBrainzProvider.php';
require_once __DIR__ . '/../src/Provider/MalojaProvider.php';
require_once __DIR__ . '/../src/Provider/MusicBrainzTags.php';
require_once __DIR__ . '/../src/Provider/Factory.php';
require_once __DIR__ . '/../src/Library.php';
require_once __DIR__ . '/../src/Text.php';

header('Content-Type: text/html; charset=utf-8');
Security::headers();

$config = Config::fromEnv();

try {
    $provider = Provider\Factory::create($config);
} catch (\Throwable) {
    // Never surface configuration or upstream details to the client.
    http_response_code(500);
    echo '<div class="alert alert-danger">The service is not configured correctly.</div>';
    exit;
}

$operation = is_string($_GET['oper'] ?? null) ? $_GET['oper'] : '';

if ($operation === 'tags') {
    renderTags(
        Provider\Factory::tags($config),
        scalarString($_GET['name'] ?? ''),
        scalarString($_GET['mbid'] ?? ''),
    );
    exit;
}

if ($operation === 'tags-batch') {
    renderTagsBatch(Provider\Factory::tags($config));
    exit;
}

renderList($provider, $config);


/**
 * Coerce a request value to a string, rejecting arrays and other types.
 */
function scalarString(mixed $value): string
{
    return is_string($value) ? $value : '';
}

/**
 * Render the randomly selected artist rows for the requested tuning.
 */
function renderList(ProviderInterface $provider, Config $config): void
{
    $tuning = $_REQUEST['tunning'] ?? [];
    if (!is_array($tuning)) {
        $tuning = [];
    }

    try {
        $totalArtists = $provider->totalArtists();
    } catch (\Throwable) {
        echo '<div class="alert alert-danger">Could not reach the music service.</div>';
        return;
    }

    if ($totalArtists <= 0) {
        echo '<div class="alert alert-warning">No listening history found for this account.</div>';
        return;
    }

    $sections = Library::sections($totalArtists);
    $pageLimit = $config->pageLimit();

    // Collect every rank we need, then fetch each upstream page only once.
    $wantedRanks = [];
    foreach (Library::sectionNames() as $name) {
        $quantity = (int) ($tuning[$name] ?? 0);
        if ($quantity <= 0 || !isset($sections[$name])) {
            continue;
        }
        if ($quantity > $pageLimit) {
            $quantity = $pageLimit;
        }
        [$start, $end] = $sections[$name];
        foreach (Library::randomRanks($start, $end, $quantity) as $rank) {
            $wantedRanks[] = $rank;
        }
    }

    if ($wantedRanks === []) {
        echo '<div class="alert alert-info">Adjust the sliders and fetch again.</div>';
        return;
    }

    try {
        $pageCache = fetchPages($provider, $wantedRanks, $pageLimit);
    } catch (\Throwable) {
        echo '<div class="alert alert-danger">Could not reach the music service.</div>';
        return;
    }

    ?>
    <div class="panel panel-default">
      <div class="panel-heading">
        <h3 class="panel-title">
          <img src="<?= htmlspecialchars(Security::selfDir(), ENT_QUOTES) ?>/img/icon-music.svg" class="icon" alt="" aria-hidden="true">
          04 - Results
        </h3>
      </div>
      <div class="panel-body">
        <table class="table table-striped">
          <tbody>
          <?php foreach ($wantedRanks as $rank) : ?>
            <?php if (!isset($pageCache[$rank])) { continue; } ?>
            <?php $artist = $pageCache[$rank]; ?>
            <?php $url = $config->taskerScheme() . '://' . rawurlencode(Text::stripAccents($artist->name)); ?>
            <?php $image = $artist->imageUrl !== '' ? $artist->imageUrl : Security::selfDir() . '/img/artist.svg'; ?>
            <tr>
              <td>
                <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>">
                  <img src="<?= htmlspecialchars($image, ENT_QUOTES) ?>" alt="" width="34" height="34">
                </a>
              </td>
              <td>
                <a class="artistTitle"
                   href="<?= htmlspecialchars($url, ENT_QUOTES) ?>"><?= htmlspecialchars($artist->name, ENT_QUOTES) ?></a><br/>
                <img src="<?= htmlspecialchars(Security::selfDir(), ENT_QUOTES) ?>/img/icon-fire.svg" class="icon" alt="" aria-hidden="true">&nbsp;
                <span style="font-size:15px;"><?= htmlspecialchars($artist->playcount, ENT_QUOTES) ?></span><br/>
                <div class="tags-area"
                     data-name="<?= htmlspecialchars($artist->name, ENT_QUOTES) ?>"
                     data-mbid="<?= htmlspecialchars($artist->mbid, ENT_QUOTES) ?>"></div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
}

/**
 * Fetch the upstream pages covering all requested 1-based ranks.
 *
 * @param int[] $ranks
 * @return array<int,Artist> rank => Artist
 */
function fetchPages(ProviderInterface $provider, array $ranks, int $pageLimit): array
{
    $pagesNeeded = [];
    foreach ($ranks as $rank) {
        $pagesNeeded[(int) ceil($rank / $pageLimit)] = true;
    }

    // Guard against pathological page counts from a huge (untrusted) library size.
    if (count($pagesNeeded) > 50) {
        $pagesNeeded = array_slice($pagesNeeded, 0, 50, true);
    }

    $byRank = [];
    foreach (array_keys($pagesNeeded) as $page) {
        $artists = $provider->artists($pageLimit, $page);
        $baseRank = ($page - 1) * $pageLimit + 1;
        foreach ($artists as $offset => $artist) {
            $byRank[$baseRank + $offset] = $artist;
        }
    }

    return $byRank;
}

/**
 * Render the tag links for a single artist, resolved from MusicBrainz.
 */
function renderTags(Provider\MusicBrainzTags $tags, string $name, string $mbid): void
{
    if ($name === '' && $mbid === '') {
        return;
    }

    try {
        $names = $tags->artistTags($name, $mbid);
    } catch (\Throwable) {
        return;
    }

    if ($names === []) {
        return;
    }

    echo '<span style="font-size:11px; line-height:normal">';
    foreach ($names as $tag) {
        $url = 'https://musicbrainz.org/tag/' . rawurlencode($tag);
        echo '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
            . htmlspecialchars($tag, ENT_QUOTES) . '</a> &middot; ';
    }
    echo '</span>';
}


/**
 * Resolve tags for every artist in one page in a single request.
 *
 * The browser posts JSON: [{"name":"Artist","mbid":"..."}]. The response is a
 * JSON object mapping the artist name to rendered tag HTML, so the UI fills
 * every row from one call instead of one request per row (which burst against
 * MusicBrainz's per-IP rate limit).
 */
function renderTagsBatch(Provider\MusicBrainzTags $tags): void
{
    header('Content-Type: application/json; charset=utf-8');

    $raw = file_get_contents('php://input');
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'expected a JSON array']);
        return;
    }

    // Bound the batch: a page has at most ARTIST_PAGELIMIT rows.
    $payload = array_slice($payload, 0, 100);

    $out = [];
    foreach ($payload as $entry) {
        $name = is_array($entry) ? (string) ($entry['name'] ?? '') : '';
        $mbid = is_array($entry) ? (string) ($entry['mbid'] ?? '') : '';
        if ($name === '' && $mbid === '') {
            continue;
        }

        try {
            $names = $tags->artistTags($name, $mbid);
        } catch (\Throwable) {
            $names = [];
        }

        $out[$name] = renderTagLinks($names);
    }

    echo json_encode($out);
}

/**
 * Render tag names as inline links (empty string when there are none).
 *
 * @param string[] $tags
 */
function renderTagLinks(array $tags): string
{
    if ($tags === []) {
        return '';
    }

    $html = '<span style="font-size:11px; line-height:normal">';
    foreach ($tags as $tag) {
        $url = 'https://musicbrainz.org/tag/' . rawurlencode($tag);
        $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
            . htmlspecialchars($tag, ENT_QUOTES) . '</a> &middot; ';
    }

    return $html . '</span>';
}
