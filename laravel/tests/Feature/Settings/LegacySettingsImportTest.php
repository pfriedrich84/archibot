<?php

namespace Tests\Feature\Settings;

use App\Models\AppSetting;
use App\Services\Settings\LegacySettingsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class LegacySettingsImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_legacy_config_env_without_overwriting_existing_settings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'archibot-config-');
        file_put_contents($path, "OLLAMA_URL=https://ollama-config.test\nWEBHOOK_SECRET=secret-from-file\n");

        Config::set('archibot_settings.import_paths', [$path]);
        AppSetting::put('ollama.url', 'https://already-set.test');

        $imported = app(LegacySettingsImporter::class)->importMissing();

        $this->assertNotContains('ollama.url', $imported);
        $this->assertContains('webhook.secret', $imported);
        $this->assertSame('https://already-set.test', AppSetting::getValue('ollama.url'));
        $this->assertSame('secret-from-file', AppSetting::getValue('webhook.secret'));

        @unlink($path);
    }
}
