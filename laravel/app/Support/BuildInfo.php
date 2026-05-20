<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

class BuildInfo
{
    /** @return array{commit: ?string, commit_short: ?string, ref: ?string} */
    public static function current(): array
    {
        $commit = self::valueFromEnv(['ARCHIBOT_GIT_SHA', 'GIT_COMMIT', 'SOURCE_COMMIT', 'RENDER_GIT_COMMIT']);
        $ref = self::valueFromEnv(['ARCHIBOT_GIT_REF', 'GIT_REF', 'SOURCE_BRANCH', 'RENDER_GIT_BRANCH']);

        if (! $commit) {
            $commit = self::git(['rev-parse', 'HEAD']);
        }

        if (! $ref) {
            $ref = self::git(['rev-parse', '--abbrev-ref', 'HEAD']);
        }

        $commit = $commit ? trim($commit) : null;
        $ref = $ref ? trim($ref) : null;

        return [
            'commit' => $commit,
            'commit_short' => $commit ? substr($commit, 0, 7) : null,
            'ref' => $ref,
        ];
    }

    /** @param array<int, string> $keys */
    private static function valueFromEnv(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = env($key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @param array<int, string> $arguments */
    private static function git(array $arguments): ?string
    {
        if (! is_dir(base_path('../.git'))) {
            return null;
        }

        try {
            $result = Process::path(base_path('..'))->run(array_merge(['git'], $arguments));
        } catch (\Throwable) {
            return null;
        }

        return $result->successful() ? trim($result->output()) : null;
    }
}
