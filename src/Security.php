<?php

declare(strict_types=1);

/**
 * Security-relevant HTTP helpers shared by the public endpoints.
 */
final class Security
{
    /**
     * Emit hardening headers not already set globally by the web server.
     *
     * Apache sets X-Content-Type-Options, X-Frame-Options and Referrer-Policy
     * for every response; the CSP is endpoint-specific and applied here.
     */
    public static function headers(): void
    {
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header(
            "Content-Security-Policy: default-src 'none'; " .
            "img-src 'self'; style-src 'unsafe-inline' https://maxcdn.bootstrapcdn.com; " .
            "base-uri 'none'; frame-ancestors 'none'"
        );
    }

    /**
     * The relative public base URL of the app, used to build self-references.
     * Derived from the request path so the app works behind a reverse proxy
     * sub-path, without trusting proxy headers for anything security-sensitive.
     */
    public static function selfDir(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '/api.php';
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

        return $dir === '' || $dir === '.' ? '' : $dir;
    }
}
