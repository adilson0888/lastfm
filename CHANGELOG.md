# Changelog

All notable changes to this project are documented here.
This project follows [Semantic Versioning](https://semver.org/).

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
