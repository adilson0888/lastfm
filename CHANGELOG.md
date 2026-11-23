# Changelog

All notable changes to this project are documented here.
This project follows [Semantic Versioning](https://semver.org/).

## [2.0.4] - 2026-11-23

### Changed

- **Tag lookups are now batched.** The UI previously issued one request per
  artist row; a page of 20 rows meant 20 concurrent MusicBrainz lookups, which
  tripped its shared per-IP rate limit and took ~40s. The page now posts all
  rows to a single `tags-batch` endpoint, so pacing happens in one place.

### Fixed

- Retry backoff now honours `Retry-After` exactly instead of escalating
  (1s, 2s, 3s…), which had been keeping MusicBrainz's shared bucket drained.

Measured: a cold batch of 6 artists went from ~43s to ~22s; a warm batch is a
single request returning in ~13ms.

## [2.0.3] - 2026-11-23

### Fixed

- **Blank icons.** The CSP sets `default-src 'none'`, which blocked the
  Bootstrap Glyphicons font, so the panel-header and play-count icons rendered
  as empty boxes.

### Changed

- Icons are now five small **self-hosted SVGs** (`public/img/icon-*.svg`,
  Bootstrap Icons shapes, MIT) served under the existing `img-src 'self'`.
  No `font-src` is needed, so both CSPs stay tight.
- Added `tests/config_test.php`, which asserts the CSPs permit exactly what the
  markup loads (CDN origins, self-hosted images) and runs in CI.

## [2.0.2] - 2026-11-23

### Fixed

- **Tag loading was very slow with Maloja.** Maloja returns artist names but
  no MBIDs, so every tag request re-resolved against MusicBrainz on each page
  load (a name search plus an MBID fetch, both rate-limited) and cached nothing
  under the name.

### Changed

- MusicBrainz results are now cached by artist **name** as well as by MBID, and
  the MBID resolved for a name is remembered so later lookups use the cached
  MBID path.
- The cross-process rate-limit lock is released before sleeping, so concurrent
  tag requests queue by time slot instead of serialising on the lock.

Measured against a live Maloja instance: a cached tag lookup dropped from about
4.3s to 7ms, and six concurrent cached lookups from about 25.7s to 39ms.

## [2.0.1] - 2026-11-23

### Fixed

- **Maloja backend returned no artists.** `/charts/artists` responds with
  HTTP 500 when a `since=0` parameter is sent (the chart is all-time by
  default), and the API key is now only included when configured. Verified
  against Maloja 3.2.10.

## [2.0.0] - 2026-11-23

Last.fm is dropping free API access, so this release replaces it entirely and
modernizes the runtime and security posture.

### Added

- **ListenBrainz backend** (`SOURCE=listenbrainz`) using the public
  `/1/stats/user/{user}/artists` endpoint, the closest match to the old
  `library.getArtists` call.
- **Maloja backend** (`SOURCE=maloja`) using the native
  `/apis/mlj_1/charts/artists` endpoint.
- **MusicBrainz tag lookup** with request spacing, `Retry-After` handling,
  retries on throttling, and a disk cache that never caches failures.
- **Test suite** (`tests/`) with no external dependencies, run in CI.
- Subresource Integrity (SRI) hashes for every front-end dependency.

### Changed

- **Runtime upgraded from PHP 8.0 (end of life) to PHP 8.4.**
- Application code moved outside the web root; only `public/` is served.
- Removal of `utf8_decode()` (removed in PHP 8.2) in favour of `iconv()`.
- Rewritten the request flow in typed, namespaced classes with
  `declare(strict_types=1)`.

### Removed

- All Last.fm API usage, and the `LASTFM_*` environment variables
  (`lastfm.php`, `functions.php`).

### Security

- Fixed reflected/stored **XSS**: artist names, MBIDs, playcounts and tags are
  now escaped on output (the old code echoed them verbatim).
- Source, tests and the writable cache are no longer web-accessible.
- Added security headers (CSP, `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy`), disabled directory listing and
  hidden the server version.
- Server errors are no longer returned to clients.
- Cache file names are validated UUIDs or hashes, preventing any path
  manipulation via request parameters.
- Front-end CDN assets pinned with SRI; protocol-relative URLs replaced with
  explicit HTTPS.

### Fixed

- ListenBrainz statistics only cover the top 1000 artists; the library size is
  clamped to that window so section selection never targets ranks the API
  cannot return (this previously left the "insane" tier empty on large
  libraries).
