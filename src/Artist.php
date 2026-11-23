<?php

declare(strict_types=1);

/**
 * Normalized artist record returned by every provider.
 *
 * The application never sees provider-specific payloads, only these objects.
 */
final class Artist implements JsonSerializable
{
    public function __construct(
        public readonly string $name,
        public readonly string $playcount,
        public readonly string $mbid = '',
        public readonly string $imageUrl = '',
        public readonly string $providerUrl = '',
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'name'        => $this->name,
            'playcount'   => $this->playcount,
            'mbid'        => $this->mbid,
            'image'       => $this->imageUrl,
            'providerUrl' => $this->providerUrl,
        ];
    }
}
