<?php

declare(strict_types=1);

namespace Provider;

use Artist;
use Http\JsonClient;
use ProviderInterface;

/**
 * ListenBrainz provider.
 *
 * Uses the public statistics API:
 *   GET /1/stats/user/{user}/artists?count=&offset=&range=all_time
 * which returns artists ranked by listen count, mirroring Last.fm's
 * library.getArtists.
 */
final class ListenBrainzProvider implements ProviderInterface
{
    /** ListenBrainz caps a single stats page at 100 items. */
    private const MAX_PAGE = 100;

    /**
     * ListenBrainz only computes/caches the top 1000 artists per range; offsets
     * beyond this return nothing. We therefore treat 1000 as the usable library
     * size so the section math never targets ranks that cannot exist.
     */
    private const MAX_LIBRARY = 1000;

    public function __construct(
        private readonly JsonClient $http,
        private readonly string $user,
        private readonly string $baseUrl,
        private readonly ?string $token = null,
    ) {
    }

    public function totalArtists(): int
    {
        $page = $this->statsPage(1, 1);
        $total = (int) ($page['payload']['total_artist_count'] ?? 0);

        return min($total, self::MAX_LIBRARY);
    }

    public function artists(int $limit, int $page): array
    {
        $limit = min($limit, self::MAX_PAGE);
        $payload = $this->statsPage($limit, $page);
        $rows = $payload['payload']['artists'] ?? [];

        $artists = [];
        foreach ($rows as $row) {
            $name = (string) ($row['artist_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $mbid = (string) ($row['artist_mbid'] ?? '');
            $artists[] = new Artist(
                name: $name,
                playcount: (string) (int) ($row['listen_count'] ?? 0),
                mbid: $mbid,
                imageUrl: '',
                providerUrl: $mbid !== ''
                    ? 'https://musicbrainz.org/artist/' . rawurlencode($mbid)
                    : 'https://listenbrainz.org/artist/' . rawurlencode($name),
            );
        }

        return $artists;
    }

    /**
     * @return array<string,mixed>
     */
    private function statsPage(int $count, int $page): array
    {
        $count = min(max(1, $count), self::MAX_PAGE);
        $offset = max(0, $page - 1) * $count;

        $decoded = $this->http->get(
            $this->baseUrl . '/1/stats/user/' . rawurlencode($this->user) . '/artists',
            [
                'count'  => $count,
                'offset' => $offset,
                'range'  => 'all_time',
            ],
            $this->authHeaders(),
            $error,
        );

        return $decoded ?? [];
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders(): array
    {
        if ($this->token === null || $this->token === '') {
            return [];
        }

        return ['Authorization' => 'Token ' . $this->token];
    }
}
