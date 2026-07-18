<?php

namespace App\Services\Pipeline;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class PipelineContentStateNormalizer
{
    public const VERSION = 'v1';

    public function modified(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid Paperless modified timestamp.');
        }

        try {
            return CarbonImmutable::parse($value)
                ->utc()
                ->format('Y-m-d\TH:i:s.u\Z');
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Invalid Paperless modified timestamp.', previous: $exception);
        }
    }

    public function contentHash(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return strtolower(trim($value));
    }

    public function state(int $documentId, ?string $modified, ?string $contentHash): string
    {
        return hash('sha256', implode(':', [
            (string) $documentId,
            $modified ?? 'unknown_modified',
            $contentHash ?? 'unknown_content',
            self::VERSION,
        ]));
    }
}
