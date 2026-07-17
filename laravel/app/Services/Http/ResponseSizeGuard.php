<?php

namespace App\Services\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A response sink that rejects writes before retained, decoded bytes exceed the limit.
 *
 * Guzzle writes the entity body to the sink after transfer/content decoding, so this
 * bounds responses even when Content-Length is absent or describes compressed bytes.
 */
final class ResponseSizeGuard implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $retainedBytes = 0;

    public function __construct(
        private readonly int $maxBytes,
        private readonly string $service,
    ) {
        if ($maxBytes < 1) {
            throw new RuntimeException('HTTP response size limit must be positive.');
        }

        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new RuntimeException('Could not create bounded HTTP response buffer.');
        }

        $this->stream = Utils::streamFor($resource);
    }

    public function write(string $string): int
    {
        $incomingBytes = strlen($string);
        if ($incomingBytes > $this->maxBytes - $this->retainedBytes) {
            throw new RuntimeException("{$this->service} response exceeded the configured size limit.");
        }

        $written = $this->stream->write($string);
        $this->retainedBytes += $written;

        return $written;
    }
}
