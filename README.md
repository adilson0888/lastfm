# lastfm random artists' fetcher

Web application to fetch a random list of artists from a listening-history
library, weighted across five "adventure" tiers (classics → insane).

Originally built against the Last.fm API. Last.fm has announced that it will
start **charging for API access**, so this version drops Last.fm entirely and
reads from either **[ListenBrainz](https://listenbrainz.org)** or a
self-hosted **[Maloja](https://github.com/krateng/maloja)** instance.

## Why these two backends?

The app only ever needed three things from Last.fm:

1. **the ranked artist library** (artists ordered by playcount),
2. **the total number of artists**, and
3. **per-artist style tags**.

Here is how the alternatives compare against those needs:

| Need | Last.fm (old) | ListenBrainz | Maloja |
| --- | --- | --- | --- |
| Ranked artist library | `library.getArtists` (paged) | `GET /1/stats/user/{user}/artists` (`count`, `offset`) — ranked, paginated | `GET /apis/mlj_1/charts/artists` — ranked, whole list |
| Total artist count | `total` field | `total_artist_count` | length of the chart |
| Artist style tags | `artist.getInfo` `toptags` | ✗ (metadata API needs a token) | ✗ (native API has no tags) |
| Auth | API key required | public for read endpoints | optional API key |
| Cost | **Now paid** | free | free / self-hosted |
| Images | removed upstream | none | via Fanart.tv (needs a key) |

**Recommendation: ListenBrainz as the primary source, Maloja as the alternative.**

- ListenBrainz's `/1/stats/user/{user}/artists` endpoint is a near drop-in for
  `library.getArtists`, and its read endpoints need no API key. If you already
  scrobble to ListenBrainz, you can switch with a single env var.
- Maloja is the better choice if you want everything **self-hosted** and
  independent of any third party. It exposes a ranked artist chart, but no tags.
- The two compose nicely: Maloja can itself forward scrobbles to ListenBrainz
  (`scrobble_listenbrainz`), so you can run Maloja for storage and
  ListenBrainz for reach.

> **Note on large libraries:** ListenBrainz only computes statistics for the
> **top 1000 artists**; requests beyond that return nothing. The app clamps the
> section math to that window, so on very large libraries the "insane" tier
> means the bottom of the top 1000 rather than of the whole library. Maloja has
> no such limit.

### Where do the tags come from now?

Neither ListenBrainz (anonymously) nor Maloja expose artist styles, so tags are
resolved from the **MusicBrainz** public API
(`/ws/2/artist/{mbid}?inc=genres tags`), which is free, keyless and returns
curated *genres* before community tags — an upgrade over Last.fm's `toptags`.

MusicBrainz throttles anonymous clients to about **one request per second** and
replies `503` when exceeded. The app handles this: it spaces and serialises
requests across processes, honours `Retry-After`, retries a few times, and
caches successful lookups (including "no tags") on disk for a day. Failures are
never cached.

## Configuring

Set environment variables in `docker-compose.yml` (or your orchestrator):

| Variable | Used by | Description |
| --- | --- | --- |
| `SOURCE` | both | `listenbrainz` (default) or `maloja` |
| `LB_USER` | listenbrainz | your ListenBrainz username |
| `LB_TOKEN` | listenbrainz | optional user token (only for authenticated calls) |
| `LB_BASEURL` | listenbrainz | default `https://api.listenbrainz.org` |
| `MALOJA_BASEURL` | maloja | e.g. `https://maloja.example.com` |
| `MALOJA_APIKEY` | maloja | optional read API key, if your instance needs one |
| `ARTIST_PAGELIMIT` | both | artists per upstream request (default `100`) |
| `TASKER_URL_SCHEME` | both | deep-link scheme for the Tasker integration (default `tasker`) |
| `CONTACT_URL` | both | contact URL/email used in the MusicBrainz User-Agent |

### Example: ListenBrainz

```yaml
services:
  lastfm-selector:
    image: adilson0888/lastfm:latest
    ports: [ "8530:80" ]
    environment:
      - SOURCE=listenbrainz
      - LB_USER=your_username
```

### Example: Maloja

```yaml
services:
  lastfm-selector:
    image: adilson0888/lastfm:latest
    ports: [ "8530:80" ]
    environment:
      - SOURCE=maloja
      - MALOJA_BASEURL=https://maloja.example.com
      # - MALOJA_APIKEY=...
```

## Using the web app

![setup](https://imgshare.io/images/2020/05/25/Capture01.png)

### 01. How many artists

Slide for the number of artists to retrieve.

### 02. Adventure's Tunning

Adjust which part of the library to draw from. The library is ranked by
playcount (most-played first) and split into five sections. Each section covers
a fraction of the library:

- classics: 5%
- known: 15%
- adventurous: 25%
- wild: 25%
- insane: 30%

Each vertical slider sets how many random artists to take **from that section**.
The horizontal slider at the top sets the total; the per-section numbers are
proportional to it.

### 03. Reviewing

A summary of the configuration above. Click **Fetch**.

### 04. Results

![results](https://imgshare.io/images/2020/05/25/Capture02.png)

Each artist shows:

- Artist name;
- A deep link (default `tasker://…`) to open the artist in your own music app;
- Style tags, resolved live from MusicBrainz;
- The number of times you have listened to that artist.

## Using the Tasker project

The artist links point at an internal URL such as `tasker://Iron-Maiden`, which
Tasker intercepts to open the artist in your preferred app. To use it, import
`tasker/LastFm.prj.xml` into Tasker. You will need:

- [Tasker](https://play.google.com/store/apps/details?id=net.dinglisch.android.taskerm) (required)
- [AutoApps](https://play.google.com/store/apps/details?id=com.joaoapps.auto) (required)
- [AutoTools](https://play.google.com/store/apps/details?id=com.joaoapps.autotools) (optional)

> Enable **Allow Phone Access** inside the scene, otherwise the webview cannot
> load JavaScript resources or reach the app host.

## Architecture

```
public/                 -> the only directory served by the web server
  index.php             -> redirects to the UI
  api.php               -> AJAX endpoint: renders the artist list and tag links
  music_list.html       -> the single-page UI (sliders + AJAX)
  img/artist.svg        -> placeholder image
src/                    -> application code (outside the web root)
  config.php            -> environment-driven configuration
  Security.php          -> security headers + self-URL helper
  Artist.php            -> normalized artist value object
  ProviderInterface.php
  Library.php           -> section math + random selection
  Text.php              -> accent stripping + deep links
  Http/JsonClient.php   -> small cURL JSON client (retries, status, Retry-After)
  Provider/
    Factory.php          -> builds the configured provider + tag source
    ListenBrainzProvider.php
    MalojaProvider.php
    MusicBrainzTags.php  -> rate-limited, cached tag lookups
docker/                 -> hardened Apache configuration
tests/                  -> dependency-free test scripts
```

Run the tests with PHP 8.4:

```sh
php tests/logic_test.php
php tests/provider_test.php
```

## Security

- **The web root is `public/` only.** `src/`, `cache/`, `tests/`, the
  Dockerfile and the compose file live outside it and are additionally denied by
  Apache, so application code and cached data can never be downloaded.
- **No secrets in the repository or the image.** Credentials are read from the
  environment at runtime; nothing is hard-coded or baked into a layer.
- **All output is HTML-escaped**, so upstream artist names, MBIDs and tags
  cannot inject markup.
- **Hardened HTTP responses:** `Content-Security-Policy`,
  `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and
  `Permissions-Policy`, with `ServerTokens Prod` (no version banner) and
  directory listing disabled.
- **Errors are never shown to clients**; they go to the container log, and the
  UI shows generic messages.
- **Cache files use validated UUID or hashed names**, so no request value can
  influence a path on disk.
- **Front-end libraries are pinned with Subresource Integrity (SRI).** Note the
  vendored jQuery/Bootstrap versions are old; upgrading them is a sensible
  follow-up but out of scope for this migration.

## Requirements

- Docker (the image is built on **PHP 8.4** with Apache and the `curl` extension;
  the previous image used the long-out-of-support PHP 8.0).
- Outbound HTTPS access to the chosen backend and to `musicbrainz.org`.
