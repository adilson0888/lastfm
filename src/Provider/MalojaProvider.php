<?php

declare(strict_types=1);

namespace Provider;

use Artist;
use Http\JsonClient;
use ProviderInterface;

/**
 * Maloja provider (self-hosted scrobble database).
 *
 * Maloja's native API (mounted at /apis/mlj_1) exposes a pre-ranked artist
 * chart at GET /charts/artists, ordered by scrobble count descending, each
 * entry shaped like:
 *
 *   { "scrobbles": 120, "artist": "Radiohead", "artist_id": 3, ... }
 *
 * Maloja does not return music tags; those are resolved from MusicBrainz.
 */
final class MalojaProvider implements ProviderInterface
{
    public function __construct(
        private readonly JsonClient $http,
        private readonly string $baseUrl,
        private readonly string $apiKey = '',
    ) {
    }

    public function totalArtists(): int
    {
        return count($this->chart());
    }

    public function artists(int $limit, int $page): array
    {
        $chart = $this->chart();
        $offset = max(0, $page - 1) * $limit;
        $slice = array_slice($chart, $offset, $limit);

        $artists = [];
        foreach ($slice as $row) {
            $name = trim((string) ($row['artist'] ?? ''));
            if ($name === '') {
                continue;
            }
            $artists[] = new Artist(
                name: $name,
                playcount: (string) (int) ($row['scrobbles'] ?? 0),
                mbid: '',
                imageUrl: '',
                providerUrl: $this->baseUrl . '/artist?artist=' . rawurlencode($name),
            );
        }

        return $artists;
    }

    /**
     * Fetch the full ranked artist chart for the all-time range.
     *
     * Maloja returns all-time data by default; passing `since=0` makes its
     * timestamp parser raise an IndexError and return HTTP 500, so the
     * parameter is omitted. The API key is only sent when configured.
     *
     * @return array<int,array<string,mixed>>
     */
    private function chart(): array
    {
        $query = [];
        if ($this->apiKey !== '') {
            $query['key'] = $this->apiKey;
        }

        $decoded = $this->http->get(
            $this->baseUrl . '/apis/mlj_1/charts/artists',
            $query,
            [],
            $error,
        );

        if ($decoded === null || ($decoded['status'] ?? '') !== 'ok') {
            return [];
        }

        return array_values(array_filter(
            $decoded['list'] ?? [],
            static fn ($row): bool => is_array($row) && isset($row['artist']),
        ));
    }
}
