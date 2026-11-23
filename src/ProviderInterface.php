<?php

declare(strict_types=1);

/**
 * A music-stats backend (ListenBrainz or Maloja).
 *
 * Providers expose the ranked artist library, which is all this application
 * needs. Implementations paginate themselves so callers can ask for "page N of
 * the ranked library" without knowing the upstream protocol.
 *
 * Artist tags/styles are deliberately NOT part of this interface: neither
 * ListenBrainz (anonymously) nor Maloja expose them. They are resolved
 * separately, from MusicBrainz, by {@see \Provider\MusicBrainzTags}.
 */
interface ProviderInterface
{
    /**
     * Total number of distinct artists in the user's library.
     */
    public function totalArtists(): int;

    /**
     * Return one page of artists ordered by playcount, most-played first.
     *
     * @param int $limit  Number of artists to return.
     * @param int $page   1-based page number.
     * @return Artist[]
     */
    public function artists(int $limit, int $page): array;
}
