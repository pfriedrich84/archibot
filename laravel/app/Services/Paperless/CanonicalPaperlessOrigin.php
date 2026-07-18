<?php

namespace App\Services\Paperless;

use InvalidArgumentException;
use RuntimeException;

/**
 * The deployment-owned Paperless trust anchor.
 *
 * PAPERLESS_URL is intentionally an origin (scheme + host + optional port), not
 * a user-editable base URL. Stored settings are legacy migration data only.
 */
class CanonicalPaperlessOrigin
{
    public function url(): string
    {
        $configured = config('archibot.paperless_url');

        if (! is_string($configured) || trim($configured) === '') {
            throw new RuntimeException('PAPERLESS_URL must be configured before ArchiBot setup can run.');
        }

        try {
            return $this->normalize($configured);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('PAPERLESS_URL must be an http(s) origin without credentials, path, query, or fragment.', previous: $exception);
        }
    }

    public function assertMatches(?string $submitted): void
    {
        if ($submitted === null || trim($submitted) === '') {
            return;
        }

        try {
            $normalized = $this->normalize($submitted);
        } catch (InvalidArgumentException) {
            throw new RuntimeException('The Paperless destination is fixed by PAPERLESS_URL and cannot be overridden.');
        }

        if (! hash_equals($this->url(), $normalized)) {
            throw new RuntimeException('The Paperless destination is fixed by PAPERLESS_URL and cannot be overridden.');
        }
    }

    public function isSameOriginUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        try {
            $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

            return hash_equals($this->url(), $this->normalize($origin));
        } catch (InvalidArgumentException|RuntimeException) {
            return false;
        }
    }

    private function normalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')
        ) {
            throw new InvalidArgumentException('Invalid origin.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === '' || preg_match('/[\s\x00-\x1f\x7f]/', $host)) {
            throw new InvalidArgumentException('Invalid host.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $host = '['.$host.']';
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('Invalid port.');
        }
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        return $scheme.'://'.$host.($port !== null ? ':'.$port : '');
    }
}
