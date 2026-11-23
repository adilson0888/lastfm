<?php

declare(strict_types=1);

/*
 * Central configuration, loaded from environment variables so the same image
 * can run against either ListenBrainz or a self-hosted Maloja instance.
 *
 * Required:
 *   SOURCE        listenbrainz | maloja   (default: listenbrainz)
 *
 * ListenBrainz source:
 *   LB_USER       ListenBrainz username
 *   LB_TOKEN      (optional) user token, only needed for authenticated endpoints
 *   LB_BASEURL    (optional) default https://api.listenbrainz.org
 *
 * Maloja source:
 *   MALOJA_BASEURL   e.g. https://maloja.example.com
 *   MALOJA_APIKEY    (optional) API key for read access if the instance requires it
 *
 * Shared:
 *   ARTIST_PAGELIMIT   how many artists per upstream request (default 100)
 *   TASKER_URL_SCHEME  scheme used for the per-artist deep links (default "tasker")
 *   CONTACT_URL        (optional) your site/email, used in the MusicBrainz UA
 *   CACHE_DIR          (optional) writable dir for the MusicBrainz tag cache
 */

final class Config
{
    /** @var array<string,string> */
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromEnv(): self
    {
        return new self([
            'source'           => strtolower(trim(self::env('SOURCE', 'listenbrainz'))),
            'lb_user'          => trim(self::env('LB_USER', '')),
            'lb_token'         => trim(self::env('LB_TOKEN', '')),
            'lb_baseurl'       => rtrim(self::env('LB_BASEURL', 'https://api.listenbrainz.org'), '/'),
            'maloja_baseurl'   => rtrim(self::env('MALOJA_BASEURL', ''), '/'),
            'maloja_apikey'    => trim(self::env('MALOJA_APIKEY', '')),
            'artist_pagelimit' => max(1, (int) self::env('ARTIST_PAGELIMIT', '100')),
            'tasker_scheme'    => self::env('TASKER_URL_SCHEME', 'tasker'),
            'contact_url'      => self::env('CONTACT_URL', 'https://github.com/adilson0888/lastfm'),
            'cache_dir'        => self::env('CACHE_DIR', __DIR__ . '/../cache'),
        ]);
    }

    public function get(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    public function pageLimit(): int
    {
        return (int) $this->values['artist_pagelimit'];
    }

    public function taskerScheme(): string
    {
        return $this->values['tasker_scheme'];
    }

    public function userAgent(): string
    {
        // MusicBrainz requires a descriptive, contactable User-Agent.
        return 'lastfm-random-artists/2.0 ( ' . $this->values['contact_url'] . ' )';
    }

    public function musicBrainzBaseUrl(): string
    {
        return 'https://musicbrainz.org/ws/2';
    }

    public function cacheDir(): string
    {
        return $this->values['cache_dir'];
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        return (string) $value;
    }
}
