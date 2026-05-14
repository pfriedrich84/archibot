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

        return array_merge([
            'PAPERLESS_URL' => AppSetting::getValue('paperless.url'),
            'PAPERLESS_TOKEN' => $admin?->paperless_token,
            'PAPERLESS_INBOX_TAG_ID' => AppSetting::getValue('paperless.inbox_tag_id'),
            'PAPERLESS_PROCESSED_TAG_ID' => AppSetting::getValue('paperless.processed_tag_id'),
            'KEEP_INBOX_TAG' => AppSetting::getValue('paperless.keep_inbox_tag'),
            'GUI_BASE_URL' => AppSetting::getValue('gui.base_url'),
            'ENABLE_TELEGRAM' => AppSetting::getValue('telegram.enable'),
            'TELEGRAM_BOT_TOKEN' => AppSetting::getValue('telegram.bot_token'),
            'TELEGRAM_CHAT_ID' => AppSetting::getValue('telegram.chat_id'),
            'TELEGRAM_POLL_INTERVAL' => AppSetting::getValue('telegram.poll_interval'),
            'OCR_REQUESTED_TAG_ID' => AppSetting::getValue('ocr.requested_tag_id'),
            'OCR_MODE' => AppSetting::getValue('ocr.mode'),
            'LLM_PROVIDER' => AppSetting::getValue('llm.provider'),
            'OLLAMA_URL' => AppSetting::getValue('ollama.url'),
            'OPENAI_API_KEY' => AppSetting::getValue('llm.openai_api_key'),
            'AI_PROVIDER_PROFILES' => AppSetting::getValue('llm.provider_profiles'),
            'CLASSIFICATION_PROVIDER' => AppSetting::getValue('llm.classification_provider'),
            'EMBEDDING_PROVIDER' => AppSetting::getValue('llm.embedding_provider'),
            'OCR_PROVIDER' => AppSetting::getValue('llm.ocr_provider'),
            'JUDGE_PROVIDER' => AppSetting::getValue('llm.judge_provider'),
            'CHAT_PROVIDER' => AppSetting::getValue('llm.chat_provider'),
            'CLASSIFICATION_MODEL' => AppSetting::getValue('classification.model'),
            'EMBEDDING_MODEL' => AppSetting::getValue('embedding.model'),
            'OCR_TEXT_MODEL' => AppSetting::getValue('ocr.text_model'),
            'JUDGE_MODEL' => AppSetting::getValue('classification.judge_model'),
            'OLLAMA_MODEL' => AppSetting::getValue('classification.model'),
            'OLLAMA_EMBED_MODEL' => AppSetting::getValue('embedding.model'),
            'OLLAMA_OCR_MODEL' => AppSetting::getValue('ocr.text_model'),
            'OLLAMA_JUDGE_MODEL' => AppSetting::getValue('classification.judge_model'),
            'MCP_TRANSPORT' => AppSetting::getValue('mcp.transport'),
            'MCP_PORT' => AppSetting::getValue('mcp.port'),
            'MCP_HOST' => AppSetting::getValue('mcp.host'),
            'MCP_ENABLE_WRITE' => AppSetting::getValue('mcp.enable_write'),
            'MCP_API_KEY' => AppSetting::getValue('mcp.api_key'),
            'MCP_LARAVEL_AUTH_ENABLED' => AppSetting::getValue('mcp.laravel_auth_enabled'),
            'MCP_LARAVEL_PATH' => AppSetting::getValue('mcp.laravel_path'),
            'MCP_LARAVEL_PHP_BINARY' => AppSetting::getValue('mcp.laravel_php_binary'),
            'MCP_CLASSIFY_RATE_LIMIT' => AppSetting::getValue('mcp.classify_rate_limit'),
        ], $overrides);
    }

    private function sanitize(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}
