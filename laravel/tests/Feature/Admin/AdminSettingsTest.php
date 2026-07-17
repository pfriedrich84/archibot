<?php

namespace Tests\Feature\Admin;

use App\Models\AppSetting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Settings\PythonRuntimeConfigExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_view_admin_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.settings.edit'))->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Settings')
                ->has('groups')
                ->has('sections')
                ->where('activeSection', 'paperless')
                ->where('sections.0.name', 'Paperless')
                ->where('groups.0.name', 'Paperless')
                ->where('groups.0.settings.0.key', 'paperless.url')
                ->where('groups.0.settings.0.value', 'https://paperless.test')
            );
    }

    public function test_local_development_webhook_bypass_has_startup_log_and_ui_warning(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Config::set('archibot.paperless_webhook_development_bypass', true);
        $this->app->detectEnvironment(fn (): string => 'local');
        Log::spy();

        (new AppServiceProvider($this->app))->boot();

        Log::shouldHaveReceived('warning')->once()->with(
            'Paperless webhook authentication development bypass is active.',
            ['environment' => 'local'],
        );
        $this->actingAs($admin)
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('webhookDevelopmentBypassActive', true)
            );
    }

    public function test_admin_settings_can_show_a_single_section(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.settings.edit', ['section' => 'embedding']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Settings')
                ->where('activeSection', 'embedding')
                ->has('groups', 1)
                ->where('groups.0.name', 'Embedding')
                ->where('groups.0.settings.0.key', 'embedding.model')
                ->where('prompts', [])
            );
    }

    public function test_admin_can_update_global_settings_and_write_audit_log(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->patch(route('admin.settings.update'), [
            'embedding_hybrid_search_weight' => '0.7',
            'audit_retention_days' => 14,
        ]);

        $response->assertRedirect();
        $this->assertSame('https://paperless.test', AppSetting::getValue('paperless.url'));
        $this->assertSame('0.7', AppSetting::getValue('embedding.hybrid_search_weight'));
        $this->assertSame('14', AppSetting::getValue('audit.retention_days'));
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'admin_settings.updated',
            'target_type' => 'app_settings',
        ]);
    }

    public function test_admin_paperless_url_override_is_rejected_and_stored_value_is_unchanged(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        AppSetting::put('paperless.url', 'https://legacy-stored.test');

        $this->actingAs($admin)->patch(route('admin.settings.update'), [
            'paperless_url' => 'https://attacker.test',
        ])->assertSessionHasErrors('paperless_url');

        $this->assertSame('https://legacy-stored.test', AppSetting::getValue('paperless.url'));
        $this->actingAs($admin)
            ->get(route('admin.settings.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groups.0.settings.0.value', 'https://paperless.test')
                ->where('groups.0.settings.0.read_only', true)
            );
    }

    public function test_admin_can_clear_boolean_settings_when_checkbox_is_unchecked(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        AppSetting::put('classification.enable_judge_verification', '1');

        $this->actingAs($admin)->patch(route('admin.settings.update'), [
            '__settings_keys' => ['classification.enable_judge_verification'],
        ])->assertRedirect();

        $this->assertSame('0', AppSetting::getValue('classification.enable_judge_verification'));
    }

    public function test_admin_subpage_update_preserves_settings_from_other_sections(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        AppSetting::put('paperless.url', 'https://paperless-kept.test');
        AppSetting::put('embedding.hybrid_search_weight', '0.4');

        $this->actingAs($admin)->patch(route('admin.settings.update'), [
            '__settings_keys' => ['embedding.hybrid_search_weight'],
            'embedding_hybrid_search_weight' => '0.8',
        ])->assertRedirect();

        $this->assertSame('https://paperless-kept.test', AppSetting::getValue('paperless.url'));
        $this->assertSame('0.8', AppSetting::getValue('embedding.hybrid_search_weight'));
    }

    public function test_confidence_auto_commit_setting_is_read_only_and_explains_suspension(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        AppSetting::put('classification.auto_commit_confidence', '100');

        $this->actingAs($admin)
            ->get(route('admin.settings.edit', ['section' => 'classification-review']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('groups.0.settings', fn ($settings): bool => collect($settings)->contains(
                    fn (array $setting): bool => $setting['key'] === 'classification.auto_commit_confidence'
                        && $setting['read_only'] === true
                        && $setting['value'] === '0'
                        && str_contains($setting['help'], 'ADR-0018')
                ))
            );
    }

    public function test_admin_can_update_mcp_runtime_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        AppSetting::put('classification.auto_commit_confidence', '100');
        $response = $this->actingAs($admin)->patch(route('admin.settings.update'), [
            'gui_base_url' => 'https://archibot.example',
            'classification_auto_commit_confidence' => '95',
            'classification_enable_judge_verification' => '1',
            'classification_judge_confidence_threshold' => '101',
            'mcp_api_key' => 'legacy-mcp-secret',
            'mcp_laravel_auth_enabled' => '1',
            'mcp_laravel_path' => '/app/laravel',
            'mcp_laravel_php_binary' => 'php8.3',
            'mcp_classify_rate_limit' => '25',
        ]);

        $response->assertRedirect();
        $this->assertSame('legacy-mcp-secret', AppSetting::getValue('mcp.api_key'));
        $this->assertSame('100', AppSetting::getValue('classification.auto_commit_confidence'));

        $runtimeConfig = file_get_contents(config('archibot_settings.import_paths')[0]);
        $this->assertStringContainsString('GUI_BASE_URL=https://archibot.example', $runtimeConfig);
        $this->assertStringContainsString('AUTO_COMMIT_CONFIDENCE=0', $runtimeConfig);
        $this->assertStringNotContainsString('AUTO_COMMIT_CONFIDENCE=95', $runtimeConfig);
        $this->assertStringContainsString('ENABLE_JUDGE_VERIFICATION=1', $runtimeConfig);
        $this->assertStringContainsString('JUDGE_CONFIDENCE_THRESHOLD=101', $runtimeConfig);
        $this->assertStringContainsString('MCP_API_KEY=legacy-mcp-secret', $runtimeConfig);
        $this->assertStringContainsString('MCP_LARAVEL_AUTH_ENABLED=1', $runtimeConfig);
        $this->assertStringContainsString('MCP_LARAVEL_PATH=/app/laravel', $runtimeConfig);
        $this->assertStringContainsString('MCP_LARAVEL_PHP_BINARY=php8.3', $runtimeConfig);
        $this->assertStringContainsString('MCP_CLASSIFY_RATE_LIMIT=25', $runtimeConfig);

        foreach (config('archibot_settings.definitions') as $definition) {
            if (! is_array($definition) || ! isset($definition['legacy'])) {
                continue;
            }
            $this->assertStringContainsString(strtoupper($definition['legacy']).'=', $runtimeConfig);
        }
    }

    public function test_runtime_export_forces_auto_commit_off_for_existing_file_database_and_override(): void
    {
        $path = config('archibot_settings.import_paths')[0];
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "AUTO_COMMIT_CONFIDENCE=80\n");
        AppSetting::put('classification.auto_commit_confidence', '100');

        app(PythonRuntimeConfigExporter::class)->export([
            'AUTO_COMMIT_CONFIDENCE' => '95',
        ]);

        $runtimeConfig = File::get($path);
        $this->assertSame(1, substr_count($runtimeConfig, 'AUTO_COMMIT_CONFIDENCE='));
        $this->assertStringContainsString('AUTO_COMMIT_CONFIDENCE=0', $runtimeConfig);
        $this->assertStringNotContainsString('AUTO_COMMIT_CONFIDENCE=80', $runtimeConfig);
        $this->assertStringNotContainsString('AUTO_COMMIT_CONFIDENCE=95', $runtimeConfig);
        $this->assertStringNotContainsString('AUTO_COMMIT_CONFIDENCE=100', $runtimeConfig);
    }

    public function test_admin_can_load_ai_models_for_default_provider(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Http::fake([
            '*ollama.test:11434/api/tags' => Http::response([
                'models' => [
                    ['name' => 'qwen3:4b'],
                    ['name' => 'nomic-embed-text:latest'],
                ],
            ]),
        ]);

        $this->actingAs($admin)->postJson(route('admin.settings.ai-models'), [
            'provider_id' => 'default',
            'llm_provider' => 'ollama',
            'ollama_url' => 'http://ollama.test:11434',
        ])->assertOk()
            ->assertJsonPath('provider.id', 'default')
            ->assertJsonPath('items.0', 'nomic-embed-text:latest')
            ->assertJsonPath('items.1', 'qwen3:4b');
    }

    public function test_admin_can_load_ai_models_for_named_openai_provider(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Http::fake([
            '*openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    ['id' => 'openai/gpt-4o-mini'],
                    ['id' => 'anthropic/claude-3.5-sonnet'],
                ],
            ]),
        ]);

        $this->actingAs($admin)->postJson(route('admin.settings.ai-models'), [
            'provider_id' => 'openrouter',
            'ai_provider_profiles' => json_encode([
                [
                    'id' => 'openrouter',
                    'type' => 'openai_compatible',
                    'base_url' => 'https://openrouter.ai/api/v1',
                    'api_key' => 'test-token',
                    'is_cloud' => true,
                ],
            ]),
        ])->assertOk()
            ->assertJsonPath('provider.id', 'openrouter')
            ->assertJsonPath('provider.is_cloud', true)
            ->assertJsonPath('items.0', 'anthropic/claude-3.5-sonnet')
            ->assertJsonPath('items.1', 'openai/gpt-4o-mini');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_ai_model_discovery_does_not_follow_redirects_to_another_origin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Http::fake([
            'http://ollama.test:11434/api/tags' => Http::response('', 302, [
                'Location' => 'https://attacker.test/models',
            ]),
            'https://attacker.test/*' => Http::response(['models' => [['name' => 'escaped']]]),
        ]);

        $this->actingAs($admin)->postJson(route('admin.settings.ai-models'), [
            'llm_provider' => 'ollama',
            'ollama_url' => 'http://ollama.test:11434',
        ])->assertUnprocessable();

        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://attacker.test/'));
    }

    public function test_repeated_ai_model_discovery_attempts_are_throttled(): void
    {
        config(['archibot.model_discovery_rate_limit_per_minute' => 2]);
        $admin = User::factory()->create(['is_admin' => true]);
        Http::fake(['*ollama.test:11434/api/tags' => Http::response([], 503)]);
        $payload = [
            'llm_provider' => 'ollama',
            'ollama_url' => 'http://ollama.test:11434',
        ];

        $this->actingAs($admin)->postJson(route('admin.settings.ai-models'), $payload)->assertUnprocessable();
        $this->actingAs($admin)->postJson(route('admin.settings.ai-models'), $payload)->assertUnprocessable();
        $this->actingAs($admin)->postJson(route('admin.settings.ai-models'), $payload)->assertTooManyRequests();
    }

    public function test_admin_can_update_and_reset_active_prompt_overrides(): void
    {
        config(['archibot.data_dir' => storage_path('framework/testing/prompts')]);
        File::deleteDirectory(config('archibot.data_dir'));
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.settings.prompts.update', 'classify'), [
                'content' => 'Custom classification prompt',
            ])
            ->assertRedirect();

        $this->assertSame('Custom classification prompt', File::get(config('archibot.data_dir').'/classify_system.txt'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin_prompt.updated',
            'target_id' => 'classify',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.settings.prompts.reset', 'classify'))
            ->assertRedirect();

        $this->assertFalse(File::exists(config('archibot.data_dir').'/classify_system.txt'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin_prompt.reset',
            'target_id' => 'classify',
        ]);
    }

    public function test_chat_prompt_is_not_exposed_by_admin_settings(): void
    {
        config(['archibot.data_dir' => storage_path('framework/testing/prompts')]);
        File::deleteDirectory(config('archibot.data_dir'));
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.settings.edit', ['section' => 'prompts']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('prompts', fn ($prompts) => collect($prompts)->doesntContain('key', 'chat'))
            );

        $this->actingAs($admin)
            ->patch(route('admin.settings.prompts.update', 'chat'), ['content' => 'Disabled'])
            ->assertNotFound();
        $this->actingAs($admin)
            ->delete(route('admin.settings.prompts.reset', 'chat'))
            ->assertNotFound();

        $this->assertFalse(File::exists(config('archibot.data_dir').'/chat_system.txt'));
    }

    public function test_hybrid_search_weight_must_be_between_zero_and_one(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.settings.update'), [
            'embedding_hybrid_search_weight' => '1.1',
        ])->assertSessionHasErrors('embedding_hybrid_search_weight');
    }

    public function test_non_admin_can_not_update_global_settings(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->patch(route('admin.settings.update'), [
            'paperless_url' => 'https://blocked.test',
            'audit_retention_days' => 30,
        ])->assertForbidden();

        $this->assertSame('https://paperless.test', AppSetting::getValue('paperless.url'));
    }
}
