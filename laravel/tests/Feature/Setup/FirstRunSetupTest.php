<?php

namespace Tests\Feature\Setup;

use App\Models\AppSetting;
use App\Models\SetupState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    protected bool $completeSetupByDefault = false;

    public function test_setup_page_is_available_before_setup_is_complete(): void
    {
        $this->get('/setup')->assertOk();
    }

    public function test_setup_page_prefills_paperless_url_from_environment(): void
    {
        config(['archibot_settings.import_paths' => [storage_path('framework/testing/missing-config.env')]]);

        $previousEnvValue = $_ENV['PAPERLESS_URL'] ?? null;
        $previousServerValue = $_SERVER['PAPERLESS_URL'] ?? null;

        putenv('PAPERLESS_URL=https://paperless-env.test');
        $_ENV['PAPERLESS_URL'] = 'https://paperless-env.test';
        $_SERVER['PAPERLESS_URL'] = 'https://paperless-env.test';

        try {
            $this->get('/setup')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Setup/Index')
                    ->where('paperlessUrl', 'https://paperless-env.test')
                );
        } finally {
            if ($previousEnvValue === null) {
                unset($_ENV['PAPERLESS_URL']);
            } else {
                $_ENV['PAPERLESS_URL'] = $previousEnvValue;
            }

            if ($previousServerValue === null) {
                unset($_SERVER['PAPERLESS_URL']);
            } else {
                $_SERVER['PAPERLESS_URL'] = $previousServerValue;
            }

            putenv($previousEnvValue === null ? 'PAPERLESS_URL' : "PAPERLESS_URL={$previousEnvValue}");
        }
    }

    public function test_composer_copied_environment_example_keeps_webhook_secret_empty(): void
    {
        $environmentExample = file_get_contents(base_path('.env.example'));
        $composerConfig = file_get_contents(base_path('composer.json'));

        $this->assertMatchesRegularExpression('/^PAPERLESS_WEBHOOK_SECRET=$/m', $environmentExample);
        $this->assertStringNotContainsString('PAPERLESS_WEBHOOK_SECRET=<generate', $environmentExample);
        $this->assertStringContainsString("copy('.env.example', '.env')", $composerConfig);
    }

    public function test_setup_requires_webhook_secret_when_deployment_has_none(): void
    {
        config(['archibot.paperless_webhook_secret' => '']);

        $response = $this->post('/setup', $this->setupPayload(['webhook_secret' => '']));

        $response->assertSessionHasErrors('webhook_secret');
        $this->assertFalse(SetupState::current()->is_complete);
    }

    public function test_setup_treats_known_deployment_and_submitted_placeholders_as_missing(): void
    {
        config(['archibot.paperless_webhook_secret' => '<generate-a-unique-random-secret>']);

        $this->get('/setup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Setup/Index')
            ->where('deploymentWebhookSecretConfigured', false)
        );

        $response = $this->post('/setup', $this->setupPayload([
            'webhook_secret' => '<generate-a-unique-random-secret>',
        ]));

        $response->assertSessionHasErrors('webhook_secret');
        $this->assertFalse(SetupState::current()->is_complete);
    }

    public function test_setup_reports_when_deployment_webhook_secret_is_configured(): void
    {
        config(['archibot.paperless_webhook_secret' => 'deployment-secret-with-at-least-32-chars']);

        $this->get('/setup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Setup/Index')
            ->where('deploymentWebhookSecretConfigured', true)
        );
    }

    public function test_setup_requires_paperless_admin_verification(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/users/me/' => Http::response([
                'id' => 42,
                'username' => 'regular-user',
                'email' => 'regular@example.test',
                'is_superuser' => false,
            ]),
        ]);

        $response = $this->post('/setup', $this->setupPayload([
            'username' => 'regular-user',
        ]));

        $response->assertSessionHasErrors('paperless_url');
        $this->assertFalse(SetupState::current()->is_complete);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_paperless_admin_can_complete_first_run_setup(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/users/me/' => Http::response([
                'id' => 7,
                'username' => 'admin',
                'name' => 'Paperless Admin',
                'email' => 'admin@example.test',
                'is_superuser' => true,
            ]),
        ]);

        $runtimeConfigPath = storage_path('framework/testing/config.env');
        config(['archibot_settings.import_paths' => [$runtimeConfigPath]]);

        $response = $this->post('/setup', $this->setupPayload([
            'paperless_url' => 'https://paperless.test/',
        ]));

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertTrue(SetupState::current()->is_complete);
        $this->assertSame('https://paperless.test', AppSetting::getValue('paperless.url'));
        $this->assertSame('synthetic-setup-webhook-secret-12345', AppSetting::getValue('webhook.secret'));
        $storedWebhookSecret = AppSetting::query()->where('key', 'webhook.secret')->firstOrFail();
        $this->assertTrue($storedWebhookSecret->encrypted);
        $this->assertStringNotContainsString('synthetic-setup-webhook-secret-12345', (string) $storedWebhookSecret->value);
        $this->assertSame('1', AppSetting::getValue('paperless.inbox_tag_id'));
        $this->assertSame('2', AppSetting::getValue('paperless.processed_tag_id'));
        $this->assertSame('ollama', AppSetting::getValue('llm.provider'));
        $this->assertSame('http://ollama.test:11434', AppSetting::getValue('ollama.url'));
        $this->assertSame('llama3.2:latest', AppSetting::getValue('classification.model'));
        $this->assertSame('nomic-embed-text:latest', AppSetting::getValue('embedding.model'));

        $user = User::query()->firstOrFail();
        $this->assertSame('admin', $user->paperless_username);
        $this->assertSame(7, $user->paperless_user_id);
        $this->assertTrue($user->is_admin);
        $this->assertSame('paperless-token', $user->paperless_token);
        $runtimeConfig = file_get_contents($runtimeConfigPath);
        $this->assertStringContainsString('PAPERLESS_TOKEN=paperless-token', $runtimeConfig);
        $this->assertStringContainsString('PAPERLESS_URL=https://paperless.test', $runtimeConfig);
        $this->assertStringContainsString('CLASSIFICATION_MODEL=llama3.2:latest', $runtimeConfig);
        $this->assertStringContainsString('EMBEDDING_MODEL=nomic-embed-text:latest', $runtimeConfig);
        $this->assertStringContainsString('OLLAMA_MODEL=llama3.2:latest', $runtimeConfig);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'setup.completed',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_setup_accepts_admin_from_paperless_ui_settings_profile(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::response([
                'user' => [
                    'id' => 7,
                    'username' => 'admin',
                    'first_name' => 'Paperless',
                    'last_name' => 'Admin',
                    'is_staff' => true,
                    'is_superuser' => true,
                    'groups' => [],
                ],
                'permissions' => ['view_document', 'change_document'],
            ]),
        ]);

        $response = $this->post('/setup', $this->setupPayload([
            'paperless_url' => 'https://paperless.test/',
        ]));

        $response->assertRedirect('/dashboard');

        $user = User::query()->firstOrFail();
        $this->assertTrue($user->is_admin);
        $this->assertSame('Paperless Admin', $user->name);
    }

    public function test_setup_accepts_is_admin_flag_from_paperless_ui_settings_profile(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::response([
                'user' => [
                    'id' => 7,
                    'username' => 'admin',
                    'name' => 'Paperless Admin',
                    'is_admin' => true,
                    'groups' => [],
                ],
                'permissions' => [],
            ]),
        ]);

        $response = $this->post('/setup', $this->setupPayload([
            'paperless_url' => 'https://paperless.test/',
        ]));

        $response->assertRedirect('/dashboard');
        $this->assertTrue(User::query()->firstOrFail()->is_admin);
    }

    public function test_setup_accepts_admin_when_users_me_omits_admin_flags_but_users_endpoint_has_them(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::response([], 404),
            'https://paperless.test/api/users/me/' => Http::response([
                'id' => 7,
                'username' => 'admin',
                'name' => 'Paperless Admin',
                'email' => 'admin@example.test',
            ]),
            'https://paperless.test/api/users/*' => Http::response([
                'results' => [[
                    'id' => 7,
                    'username' => 'admin',
                    'name' => 'Paperless Admin',
                    'email' => 'admin@example.test',
                    'is_superuser' => true,
                ]],
            ]),
        ]);

        $response = $this->post('/setup', $this->setupPayload([
            'paperless_url' => 'https://paperless.test/',
        ]));

        $response->assertRedirect('/dashboard');
        $this->assertTrue(User::query()->firstOrFail()->is_admin);
    }

    public function test_setup_can_load_paperless_tags_with_admin_credentials(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::response([
                'user' => [
                    'id' => 7,
                    'username' => 'admin',
                    'is_admin' => true,
                ],
            ]),
            'https://paperless.test/api/tags/*' => Http::response([
                'results' => [
                    ['id' => 2, 'name' => 'Archiviert'],
                    ['id' => 1, 'name' => 'Posteingang'],
                ],
            ]),
        ]);

        $this->postJson('/setup/paperless-tags', [
            'paperless_url' => 'https://paperless.test',
            'username' => 'admin',
            'password' => 'secret',
        ])->assertOk()
            ->assertJsonPath('items.0.name', 'Archiviert')
            ->assertJsonPath('items.1.name', 'Posteingang');
    }

    public function test_setup_can_load_ollama_models(): void
    {
        Http::fake([
            '*ollama.test:11434/api/tags' => Http::response([
                'models' => [
                    ['name' => 'qwen3:4b'],
                    ['name' => 'nomic-embed-text:latest'],
                ],
            ]),
        ]);

        $this->postJson('/setup/ollama-models', [
            'llm_provider' => 'ollama',
            'ollama_url' => 'http://ollama.test:11434',
        ])->assertOk()
            ->assertJsonPath('items.0', 'nomic-embed-text:latest')
            ->assertJsonPath('items.1', 'qwen3:4b');
    }

    public function test_setup_can_load_openai_compatible_models(): void
    {
        Http::fake([
            '*openai.test/v1/models' => Http::response([
                'data' => [
                    ['id' => 'local-chat'],
                    ['id' => 'local-embed'],
                ],
            ]),
        ]);

        $this->postJson('/setup/ollama-models', [
            'llm_provider' => 'openai_compatible',
            'ollama_url' => 'http://openai.test/v1',
            'openai_api_key' => 'local-token',
        ])->assertOk()
            ->assertJsonPath('items.0', 'local-chat')
            ->assertJsonPath('items.1', 'local-embed');
    }

    public function test_setup_routes_are_disabled_after_setup_is_complete(): void
    {
        SetupState::current()->forceFill([
            'is_complete' => true,
            'completed_at' => now(),
        ])->save();

        $this->get('/setup')->assertNotFound();
        $this->post('/setup', [])->assertNotFound();
        $this->postJson('/setup/paperless-tags', [])->assertNotFound();
        $this->postJson('/setup/ollama-models', [])->assertNotFound();
    }

    public function test_setup_reset_command_reopens_setup_with_ten_minute_token(): void
    {
        SetupState::current()->forceFill([
            'is_complete' => true,
            'completed_at' => now(),
        ])->save();

        $this->artisan('archibot:setup-reset')
            ->expectsOutputToContain('Setup has been reset')
            ->assertSuccessful();

        $state = SetupState::current()->refresh();
        $this->assertFalse($state->is_complete);
        $this->assertNotNull($state->reset_token_hash);
        $this->assertTrue($state->reset_token_expires_at->between(now()->addMinutes(9), now()->addMinutes(10)->addSecond()));
        $this->assertDatabaseHas('audit_logs', ['event' => 'setup.reset']);
        $this->get('/setup')->assertOk();
    }

    public function test_reset_token_is_required_when_setup_was_reset(): void
    {
        $state = SetupState::current();
        $state->forceFill([
            'is_complete' => false,
            'reset_token_hash' => Hash::make('expected-token'),
            'reset_token_expires_at' => now()->addMinutes(10),
        ])->save();

        $response = $this->post('/setup', $this->setupPayload([
            'setup_token' => 'wrong-token',
        ]));

        $response->assertSessionHasErrors('setup_token');
    }

    public function test_expired_reset_token_does_not_allow_setup_without_token(): void
    {
        Http::fake();

        $state = SetupState::current();
        $state->forceFill([
            'is_complete' => false,
            'reset_token_hash' => Hash::make('expired-token'),
            'reset_token_expires_at' => now()->subMinute(),
        ])->save();

        $response = $this->post('/setup', $this->setupPayload());

        $response->assertSessionHasErrors('setup_token');
        $this->assertFalse(SetupState::current()->is_complete);
        Http::assertNothingSent();
    }

    public function test_expired_reset_token_does_not_allow_setup_with_old_token(): void
    {
        Http::fake();

        $state = SetupState::current();
        $state->forceFill([
            'is_complete' => false,
            'reset_token_hash' => Hash::make('expired-token'),
            'reset_token_expires_at' => now()->subMinute(),
        ])->save();

        $response = $this->post('/setup', $this->setupPayload([
            'setup_token' => 'expired-token',
        ]));

        $response->assertSessionHasErrors('setup_token');
        $this->assertFalse(SetupState::current()->is_complete);
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function setupPayload(array $overrides = []): array
    {
        return array_merge([
            'paperless_url' => 'https://paperless.test',
            'username' => 'admin',
            'password' => 'secret',
            'webhook_secret' => 'synthetic-setup-webhook-secret-12345',
            'paperless_inbox_tag_id' => 1,
            'paperless_processed_tag_id' => 2,
            'ocr_requested_tag_id' => 3,
            'llm_provider' => 'ollama',
            'ollama_url' => 'http://ollama.test:11434',
            'classification_model' => 'llama3.2:latest',
            'embedding_model' => 'nomic-embed-text:latest',
            'ocr_text_model' => 'qwen3:4b',
            'classification_judge_model' => 'qwen3:4b',
            'ocr_mode' => 'off',
        ], $overrides);
    }
}
