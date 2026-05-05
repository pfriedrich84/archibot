<?php

namespace App\Services\Settings;

use App\Models\AppSetting;
use Illuminate\Support\Arr;

class LegacySettingsImporter
{
    /**
     * Import environment and legacy /data/config.env settings once during setup.
     * Existing Laravel settings are not overwritten, so setup wizard values can
     * be applied afterwards and win conflicts.
     *
     * @return array<int, string> Imported Laravel setting keys.
     */
    public function importMissing(): array
    {
        $legacyValues = $this->legacyValues();
        $imported = [];

        foreach (config('archibot_settings.definitions', []) as $key => $definition) {
            $legacyKey = Arr::get($definition, 'legacy');

            if (! $legacyKey || AppSetting::query()->where('key', $key)->exists()) {
                continue;
            }

            $value = $legacyValues[$legacyKey] ?? null;

            if ($value === null) {
                continue;
            }

            AppSetting::put($key, $value, (bool) Arr::get($definition, 'sensitive', false));
            $imported[] = $key;
        }

        return $imported;
    }

    /**
     * @return array<string, string>
     */
    private function legacyValues(): array
    {
        $values = [];

        foreach (config('archibot_settings.definitions', []) as $definition) {
            $legacyKey = Arr::get($definition, 'legacy');

            if (! $legacyKey) {
                continue;
            }

            $envValue = env(strtoupper($legacyKey));

            if ($envValue !== null) {
                $values[$legacyKey] = (string) $envValue;
            }
        }

        foreach (config('archibot_settings.import_paths', []) as $path) {
            if (! is_string($path) || ! is_file($path)) {
                continue;
            }

            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $values[strtolower(trim($key))] = trim($value, " \t\n\r\0\x0B\"'");
            }
        }

        return $values;
    }
}
