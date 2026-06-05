<?php

namespace App\Services\Settings;

use App\Models\AppSetting;
use App\Models\User;
use RuntimeException;

class PythonRuntimeConfigExporter
{
    /**
     * Persist Laravel-managed settings to the legacy Python runtime config file.
     *
     * Python CLI/workers still read pydantic-settings environment plus
     * /data/config.env. The setup wizard stores the Paperless API token in
     * Laravel, so export the effective runtime values until Python reads Laravel
     * settings directly.
     *
     * @param  array<string, string|null>  $overrides
     */
    public function export(array $overrides = []): void
    {
        $path = $this->configPath();
        $values = $this->readExisting($path);

        foreach ($this->runtimeValues($overrides) as $key => $value) {
            if ($value === null) {
                continue;
            }
            $values[$key] = $this->sanitize($value);
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create runtime config directory [{$directory}].");
        }

        $tmp = $path.'.tmp';
        $contents = '';
        foreach ($values as $key => $value) {
            $contents .= $key.'='.$value."\n";
        }

        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Could not write runtime config [{$tmp}].");
        }

        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Could not replace runtime config [{$path}].");
        }
    }

    private function configPath(): string
    {
        $paths = config('archibot_settings.import_paths', []);
        $path = is_array($paths) ? ($paths[0] ?? null) : null;

        if (is_string($path) && $path !== '') {
            return $path;
        }

        return rtrim((string) config('archibot.data_dir', '/data'), '/').'/config.env';
    }

    /**
     * @return array<string, string>
     */
    private function readExisting(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = strtoupper(trim($key));
            if ($key !== '') {
                $values[$key] = trim($value);
            }
        }

        return $values;
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @return array<string, string|null>
     */
    private function runtimeValues(array $overrides): array
    {
        $admin = User::query()
            ->where('is_admin', true)
            ->whereNotNull('paperless_token')
            ->oldest('id')
            ->first();

        return array_merge(
            $this->managedRuntimeValues(),
            [
                'PAPERLESS_TOKEN' => $admin?->paperless_token,
                // Preferred role-based aliases. Legacy OLLAMA_* keys are
                // exported automatically from archibot_settings definitions.
                'CLASSIFICATION_MODEL' => AppSetting::getValue('classification.model'),
                'EMBEDDING_MODEL' => AppSetting::getValue('embedding.model'),
                'OCR_TEXT_MODEL' => AppSetting::getValue('ocr.text_model'),
                'JUDGE_MODEL' => AppSetting::getValue('classification.judge_model'),
            ],
            $overrides,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function managedRuntimeValues(): array
    {
        $values = [];
        $definitions = config('archibot_settings.definitions', []);
        if (! is_array($definitions)) {
            return $values;
        }

        foreach ($definitions as $settingKey => $definition) {
            if (! is_array($definition) || ! isset($definition['legacy']) || ! is_string($definition['legacy'])) {
                continue;
            }

            $legacyKey = strtoupper($definition['legacy']);
            if ($legacyKey === '') {
                continue;
            }
            $value = AppSetting::getValue((string) $settingKey);
            if ($value === null && array_key_exists('default', $definition)) {
                $value = (string) $definition['default'];
            }
            $values[$legacyKey] = $value;
        }

        return $values;
    }

    private function sanitize(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}
