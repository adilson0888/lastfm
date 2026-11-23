<?php

declare(strict_types=1);

namespace Provider;

use Config;
use Http\JsonClient;
use ProviderInterface;

/**
 * Builds the configured library provider plus the shared tag source.
 */
final class Factory
{
    public static function create(Config $config): ProviderInterface
    {
        $http = new JsonClient();

        return match ($config->get('source')) {
            'maloja' => self::maloja($config, $http),
            default  => self::listenBrainz($config, $http),
        };
    }

    public static function tags(Config $config): MusicBrainzTags
    {
        return new MusicBrainzTags(
            new JsonClient(userAgent: $config->userAgent()),
            $config->musicBrainzBaseUrl(),
            $config->cacheDir(),
        );
    }

    private static function listenBrainz(Config $config, JsonClient $http): ProviderInterface
    {
        $user = $config->get('lb_user');
        if ($user === '') {
            throw new \RuntimeException('LB_USER must be set when SOURCE=listenbrainz.');
        }

        return new ListenBrainzProvider(
            $http,
            $user,
            $config->get('lb_baseurl'),
            $config->get('lb_token') ?: null,
        );
    }

    private static function maloja(Config $config, JsonClient $http): ProviderInterface
    {
        $baseUrl = $config->get('maloja_baseurl');
        if ($baseUrl === '') {
            throw new \RuntimeException('MALOJA_BASEURL must be set when SOURCE=maloja.');
        }

        return new MalojaProvider(
            $http,
            $baseUrl,
            $config->get('maloja_apikey'),
        );
    }
}
