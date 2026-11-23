<?php

declare(strict_types=1);

namespace Http;

/**
 * Tiny JSON HTTP client built on cURL.
 *
 * Centralizes timeout, redirect and error handling so providers only deal with
 * decoded arrays. Every request is read-only.
 */
class JsonClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 15,
        private readonly string $userAgent = 'lastfm-random-artists/2.0 (+https://github.com/adilson0888/lastfm)',
    ) {
    }

    /**
     * GET a JSON document. Returns null on any failure (never throws).
     *
     * @param array<string,string|int> $query
     * @param array<string,string>     $headers
     * @param int|null    $status     Out: HTTP status code (0 when the transport failed).
     * @param string|null $retryAfter Out: value of a Retry-After header, if any.
     * @return array<mixed>|null
     */
    public function get(
        string $url,
        array $query = [],
        array $headers = [],
        ?string &$error = null,
        ?int &$status = null,
        ?string &$retryAfter = null,
    ): ?array {
        $status = 0;
        $retryAfter = null;

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $error = 'Unable to initialise HTTP client.';
            return null;
        }

        $headerLines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        if (isset($responseHeaders['retry-after'])) {
            $retryAfter = $responseHeaders['retry-after'];
        }

        if ($status === 204) {
            // "No content" is a valid, empty result set for the stats APIs.
            return [];
        }
        if ($status < 200 || $status >= 300) {
            $error = sprintf('Upstream returned HTTP %d', $status);
            return null;
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            $error = 'Upstream returned a non-JSON response.';
            return null;
        }

        return $decoded;
    }
}
