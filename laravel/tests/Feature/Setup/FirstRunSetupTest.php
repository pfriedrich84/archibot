<?php

namespace Tests\Feature\Setup;

use App\Models\AppSetting;
use App\Models\SetupState;
use App\Models\User;
use App\Services\Paperless\PaperlessClient;
use App\Services\Settings\PythonRuntimeConfigExporter;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    protected bool $completeSetupByDefault = false;

    public function test_setup_page_is_available_and_shows_deployment_pinned_origin(): void
    {
        config(['archibot.paperless_url' => 'https://paperless-env.test/']);
        AppSetting::put('paperless.url', 'https://stored-override.test');

        $this->get('/setup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Setup/Index')
            ->where('paperlessUrl', 'https://paperless-env.test')
        );
    }

    public function test_missing_or_non_origin_deployment_destination_fails_closed(): void
    {
        foreach ([null, '', 'ftp://paperless.test', 'https://paperless.test/path', 'https://user@paperless.test'] as $configured) {
            config(['archibot.paperless_url' => $configured]);
            $this->get('/setup')->assertServerError();
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

        $this->post('/setup', $this->setupPayload(['webhook_secret' => '']))
            ->assertSessionHasErrors('webhook_secret');
        $this->assertFalse(SetupState::current()->is_complete);
    }

    public function test_setup_treats_known_deployment_and_submitted_placeholders_as_missing(): void
    {
        config(['archibot.paperless_webhook_secret' => '<generate-a-unique-random-secret>']);

        $this->get('/setup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Setup/Index')
            ->where('deploymentWebhookSecretConfigured', false)
        );

        $this->post('/setup', $this->setupPayload([
            'webhook_secret' => '<generate-a-unique-random-secret>',
        ]))->assertSessionHasErrors('webhook_secret');
        $this->assertFalse(SetupState::current()->is_complete);
    }

    public function test_setup_reports_when_deployment_webhook_secret_is_configured(): void
    {
        config(['archibot.paperless_webhook_secret' => 'deployment-secret-with-at-least-32-chars']);

        $this->get('/setup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('deploymentWebhookSecretConfigured', true)
        );
    }

    public function test_paperless_superuser_can_complete_setup_at_pinned_origin(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::response([
                'user' => [
                    'id' => 7,
                    'username' => 'admin',
                    'first_name' => 'Paperless',
                    'last_name' => 'Admin',
                    'email' => 'admin@example.test',
                    'is_staff' => true,
                    'is_superuser' => true,
                ],
            ]),
        ]);

        $runtimeConfigPath = storage_path('framework/testing/config.env');
        config(['archibot_settings.import_paths' => [$runtimeConfigPath]]);

        $this->post('/setup', $this->setupPayload(['paperless_url' => 'https://paperless.test/']))
            ->assertRedirect('/admin/settings/ai-provider');

        $this->assertAuthenticated();
        $this->assertTrue(SetupState::current()->is_complete);
        $this->assertSame('https://paperless.test', AppSetting::getValue('paperless.url'));
        $this->assertSame('synthetic-setup-webhook-secret-12345', AppSetting::getValue('webhook.secret'));
        $storedWebhookSecret = AppSetting::query()->where('key', 'webhook.secret')->firstOrFail();
        $this->assertSame(1, (int) $storedWebhookSecret->encrypted);
        $this->assertStringNotContainsString('synthetic-setup-webhook-secret-12345', (string) $storedWebhookSecret->value);
        $this->assertSame('1', AppSetting::getValue('paperless.inbox_tag_id'));
        $this->assertSame('2', AppSetting::getValue('paperless.processed_tag_id'));
        $this->assertSame('1', AppSetting::getValue('paperless.ai_suggest_enabled'));
        $this->assertSame('0', AppSetting::getValue('paperless.ai_similar_documents_enabled'));
        $this->assertSame('1', AppSetting::getValue('paperless.ai_auto_manage_workflows'));
        $this->assertNull(AppSetting::getValue('ollama.url'));

        $user = User::query()->firstOrFail();
        $this->assertSame('admin', $user->paperless_username);
        $this->assertSame(7, $user->paperless_user_id);
        $this->assertTrue($user->is_admin);
        $this->assertSame('paperless-token', $user->paperless_token);
        $runtimeConfig = file_get_contents($runtimeConfigPath);
        $this->assertStringContainsString('PAPERLESS_URL=https://paperless.test', $runtimeConfig);
        $this->assertStringContainsString('PAPERLESS_TOKEN=paperless-token', $runtimeConfig);
        $this->assertStringContainsString('PAPERLESS_AI_SUGGEST_ENABLED=1', $runtimeConfig);
        $this->assertStringContainsString('PAPERLESS_AI_SIMILAR_DOCUMENTS_ENABLED=0', $runtimeConfig);
        $this->assertStringContainsString('PAPERLESS_AI_AUTO_MANAGE_WORKFLOWS=1', $runtimeConfig);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'setup.completed',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_superuser_can_be_verified_from_documented_users_endpoint_field(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::response([], 404),
            'https://paperless.test/api/users/me/' => Http::response([
                'id' => 7,
                'username' => 'admin',
            ]),
            'https://paperless.test/api/users/*' => Http::response([
                'results' => [[
                    'id' => 7,
                    'username' => 'admin',
                    'is_superuser' => true,
                ]],
            ]),
        ]);

        $this->post('/setup', $this->setupPayload())->assertRedirect('/admin/settings/ai-provider');
        $this->assertTrue(User::query()->firstOrFail()->is_admin);
    }

    public function test_staff_or_ui_admin_cannot_claim_without_is_superuser(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::response([
                'user' => [
                    'id' => 8,
                    'username' => 'staff',
                    'is_staff' => true,
                    'is_admin' => true,
                    'admin' => true,
                    'is_superuser' => false,
                ],
            ]),
            'https://paperless.test/api/users/me/' => Http::response([
                'id' => 8,
                'username' => 'staff',
                'is_staff' => true,
                'is_admin' => true,
            ]),
            'https://paperless.test/api/users/*' => Http::response(['results' => [[
                'id' => 8,
                'username' => 'staff',
                'is_staff' => true,
                'is_admin' => true,
                'admin' => true,
            ]]]),
        ]);

        $this->post('/setup', $this->setupPayload(['username' => 'staff']))
            ->assertSessionHasErrors('paperless_url');
        $this->assertDatabaseCount('users', 0);
        $this->assertFalse(SetupState::current()->is_complete);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/users/me/')
            || str_contains($request->url(), '/api/users/?'));
    }

    public function test_missing_superuser_field_across_fallbacks_fails_closed(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::response([
                'user' => ['id' => 8, 'username' => 'field-missing', 'is_staff' => true],
            ]),
            'https://paperless.test/api/users/me/' => Http::response([
                'id' => 8,
                'username' => 'field-missing',
                'is_admin' => true,
            ]),
            'https://paperless.test/api/users/*' => Http::response(['results' => [[
                'id' => 8,
                'username' => 'field-missing',
                'admin' => true,
            ]]]),
        ]);

        $this->post('/setup', $this->setupPayload(['username' => 'field-missing']))
            ->assertSessionHasErrors('paperless_url');

        $this->assertDatabaseCount('users', 0);
        $this->assertFalse(SetupState::current()->is_complete);
        Http::assertSentCount(4);
    }

    public function test_ui_settings_connection_failure_fails_closed_without_profile_fallback(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::failedConnection(),
            'https://paperless.test/api/users/*' => Http::response(['is_superuser' => true]),
        ]);

        $this->post('/setup', $this->setupPayload())
            ->assertSessionHasErrors('paperless_url');

        $this->assertDatabaseCount('users', 0);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/users/'));
    }

    public function test_ui_settings_server_error_fails_closed_without_profile_fallback(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::response([], 503),
            'https://paperless.test/api/users/*' => Http::response(['is_superuser' => true]),
        ]);

        $this->post('/setup', $this->setupPayload())
            ->assertSessionHasErrors('paperless_url');

        $this->assertDatabaseCount('users', 0);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/users/'));
    }

    public function test_users_endpoint_cannot_claim_with_another_users_superuser_record(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::response([], 404),
            'https://paperless.test/api/users/me/' => Http::response([
                'id' => 8,
                'username' => 'staff',
                'is_superuser' => false,
            ]),
            'https://paperless.test/api/users/*' => Http::response(['results' => [[
                'id' => 1,
                'username' => 'different-admin',
                'is_superuser' => true,
            ]]]),
        ]);

        $this->post('/setup', $this->setupPayload(['username' => 'staff']))
            ->assertSessionHasErrors('paperless_url');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_tag_loading_uses_pinned_origin_and_requires_superuser(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::response([
                'user' => ['id' => 7, 'username' => 'admin', 'is_superuser' => true],
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

    public function test_submitted_setup_and_tag_loading_overrides_are_rejected_without_network_request(): void
    {
        Http::fake();

        $this->post('/setup', $this->setupPayload(['paperless_url' => 'https://attacker.test']))
            ->assertSessionHasErrors('paperless_url');
        $this->postJson('/setup/paperless-tags', [
            'paperless_url' => 'https://attacker.test',
            'username' => 'admin',
            'password' => 'secret',
        ])->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_redirect_escape_from_pinned_origin_is_not_followed(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response('', 302, [
                'Location' => 'https://attacker.test/token',
            ]),
            'https://attacker.test/*' => Http::response(['token' => 'escaped']),
        ]);

        $this->postJson('/setup/paperless-tags', [
            'paperless_url' => 'https://paperless.test',
            'username' => 'admin',
            'password' => 'secret',
        ])->assertUnprocessable();

        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://attacker.test/'));
    }

    public function test_guzzle_transport_removes_chunk_framing_before_writing_to_bounded_sink(): void
    {
        config(['archibot.paperless_http_max_response_bytes' => 1024]);
        $entity = json_encode(['content' => str_repeat('c', 1000)], JSON_THROW_ON_ERROR);
        $wireBodyBytes = strlen($entity) + strlen(dechex(500)) + strlen(dechex(strlen($entity) - 500)) + 13;
        $this->assertLessThanOrEqual(1024, strlen($entity));
        $this->assertGreaterThan(1024, $wireBodyBytes);

        $content = $this->withRawHttpServer(
            'chunked-under-limit',
            fn () => app(PaperlessClient::class)->documentContent('token', 1),
        );

        $this->assertSame(str_repeat('c', 1000), $content);
    }

    public function test_guzzle_transport_enforces_limit_on_decoded_chunked_entity_bytes(): void
    {
        config(['archibot.paperless_http_max_response_bytes' => 1024]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Paperless response exceeded the configured size limit.');

        $this->withRawHttpServer('chunked', fn () => app(PaperlessClient::class)->ping('token'));
    }

    public function test_guzzle_transport_decompresses_gzip_before_enforcing_response_limit(): void
    {
        config(['archibot.paperless_http_max_response_bytes' => 1024]);
        $decoded = str_repeat('decoded-content-', 100);
        $this->assertLessThan(1024, strlen(gzencode($decoded)));
        $this->assertGreaterThan(1024, strlen($decoded));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Paperless response exceeded the configured size limit.');

        $this->withRawHttpServer('gzip', fn () => app(PaperlessClient::class)->ping('token'));
    }

    public function test_document_previews_use_a_separate_larger_bounded_sink(): void
    {
        config([
            'archibot.paperless_http_max_response_bytes' => 1024,
            'archibot.paperless_http_max_preview_bytes' => 3 * 1024 * 1024,
        ]);
        $preview = str_repeat('p', 2 * 1024 * 1024);

        $handler = function ($request, array $options) use ($preview) {
            $options['sink']->write($preview);
            $options['sink']->rewind();

            return Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'application/pdf'], $options['sink']));
        };
        Http::globalOptions(['handler' => $handler]);

        $response = app(PaperlessClient::class)->documentPreview('token', 123);

        $this->assertSame(strlen($preview), strlen($response->body()));
        $this->assertSame('application/pdf', $response->header('Content-Type'));
    }

    public function test_document_preview_bound_still_rejects_oversize_content(): void
    {
        config(['archibot.paperless_http_max_preview_bytes' => 1024]);
        $handler = function ($request, array $options) {
            $options['sink']->write(str_repeat('p', 1025));

            return Create::promiseFor(new PsrResponse(200, [], $options['sink']));
        };
        Http::globalOptions(['handler' => $handler]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Paperless response exceeded the configured size limit.');

        app(PaperlessClient::class)->documentPreview('token', 123);
    }

    public function test_cross_origin_pagination_is_rejected_and_not_fetched(): void
    {
        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::response([
                'user' => ['id' => 7, 'username' => 'admin', 'is_superuser' => true],
            ]),
            'https://paperless.test/api/tags/*' => Http::response([
                'results' => [['id' => 1, 'name' => 'Inbox']],
                'next' => 'https://attacker.test/api/tags/?page=2',
            ]),
            'https://attacker.test/*' => Http::response(['results' => []]),
        ]);

        $this->postJson('/setup/paperless-tags', [
            'username' => 'admin',
            'password' => 'secret',
        ])->assertUnprocessable();

        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://attacker.test/'));
    }

    public function test_public_setup_rejects_oversized_credentials_and_inputs_before_network_access(): void
    {
        config(['archibot.setup_rate_limit_per_minute' => 100]);
        Http::fake();

        $cases = [
            'paperless_url' => str_repeat('u', 2049),
            'username' => str_repeat('u', 151),
            'password' => str_repeat('p', 1025),
            'webhook_secret' => str_repeat('s', 1025),
            'setup_token' => str_repeat('t', 256),
            'paperless_inbox_tag_id' => 2147483648,
            'paperless_processed_tag_id' => 2147483648,
            'ocr_requested_tag_id' => 2147483648,
        ];

        foreach ($cases as $field => $value) {
            $this->post('/setup', $this->setupPayload([$field => $value]))
                ->assertSessionHasErrors($field);
        }

        Http::assertNothingSent();
    }

    public function test_public_tag_verification_rejects_oversized_credentials_before_network_access(): void
    {
        config(['archibot.setup_rate_limit_per_minute' => 100]);
        Http::fake();

        foreach ([
            'paperless_url' => str_repeat('u', 2049),
            'username' => str_repeat('u', 151),
            'password' => str_repeat('p', 1025),
        ] as $field => $value) {
            $payload = [
                'paperless_url' => 'https://paperless.test',
                'username' => 'admin',
                'password' => 'secret',
                $field => $value,
            ];

            $this->postJson('/setup/paperless-tags', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        Http::assertNothingSent();
    }

    public function test_repeated_invalid_setup_and_tag_authentication_attempts_are_throttled(): void
    {
        config(['archibot.setup_rate_limit_per_minute' => 2]);
        Http::fake(['https://paperless.test/api/token/' => Http::response([], 401)]);
        $payload = ['paperless_url' => 'https://paperless.test', 'username' => 'rate-limit-user', 'password' => 'wrong'];

        $this->postJson('/setup/paperless-tags', $payload)->assertUnprocessable();
        $this->post('/setup', $this->setupPayload([
            'username' => 'rate-limit-user',
            'password' => 'wrong',
        ]))->assertSessionHasErrors();
        $this->postJson('/setup/paperless-tags', $payload)->assertTooManyRequests();
    }

    public function test_ai_provider_discovery_is_not_exposed_before_setup(): void
    {
        Http::fake();

        $this->postJson('/setup/ollama-models', [])->assertNotFound();
        $this->postJson('/admin/settings/ai-models', [])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_runtime_export_replaces_stored_file_and_call_site_url_overrides(): void
    {
        $path = config('archibot_settings.import_paths')[0];
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "PAPERLESS_URL=https://file-override.test\n");
        AppSetting::put('paperless.url', 'https://database-override.test');

        app(PythonRuntimeConfigExporter::class)->export([
            'PAPERLESS_URL' => 'https://call-site-override.test',
        ]);

        $runtimeConfig = File::get($path);
        $this->assertSame(1, substr_count($runtimeConfig, 'PAPERLESS_URL='));
        $this->assertStringContainsString('PAPERLESS_URL=https://paperless.test', $runtimeConfig);
        $this->assertStringNotContainsString('override.test', $runtimeConfig);
    }

    public function test_setup_routes_are_disabled_after_completion(): void
    {
        SetupState::current()->forceFill(['is_complete' => true, 'completed_at' => now()])->save();

        $this->get('/setup')->assertNotFound();
        $this->post('/setup', [])->assertNotFound();
        $this->postJson('/setup/paperless-tags', [])->assertNotFound();
        $this->postJson('/setup/ollama-models', [])->assertNotFound();
    }

    public function test_setup_reset_command_reopens_setup_with_ten_minute_token(): void
    {
        SetupState::current()->forceFill(['is_complete' => true, 'completed_at' => now()])->save();

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

    public function test_reset_token_is_required_and_must_not_be_expired(): void
    {
        Http::fake();
        $state = SetupState::current();
        $state->forceFill([
            'is_complete' => false,
            'reset_token_hash' => Hash::make('expected-token'),
            'reset_token_expires_at' => now()->subMinute(),
        ])->save();

        $this->post('/setup', $this->setupPayload(['setup_token' => 'expected-token']))
            ->assertSessionHasErrors('setup_token');
        $this->assertFalse(SetupState::current()->is_complete);
        Http::assertNothingSent();
    }

    /** @param callable(): mixed $request */
    private function withRawHttpServer(string $scenario, callable $request): mixed
    {
        $readyFile = tempnam(storage_path('framework/testing'), 'raw-http-');
        if ($readyFile === false) {
            $this->fail('Could not allocate raw HTTP server ready file.');
        }
        @unlink($readyFile);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Fixtures/raw_http_server.php'), $scenario, $readyFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
        );
        if (! is_resource($process)) {
            $this->fail('Could not start raw HTTP test server.');
        }
        fclose($pipes[0]);

        try {
            $deadline = microtime(true) + 5;
            while ((! is_file($readyFile) || trim((string) file_get_contents($readyFile)) === '') && microtime(true) < $deadline) {
                usleep(10_000);
            }

            $address = is_file($readyFile) ? trim((string) file_get_contents($readyFile)) : '';
            if ($address === '') {
                $stderr = stream_get_contents($pipes[2]);
                $this->fail('Raw HTTP test server did not become ready: '.$stderr);
            }

            config(['archibot.paperless_url' => 'http://'.$address]);
            // These focused transport tests intentionally use a loopback socket
            // instead of Laravel's HTTP fake handler so Guzzle performs real
            // chunk decoding/decompression before the bounded sink is checked.
            Http::allowStrayRequests();

            return $request();
        } finally {
            Http::preventStrayRequests();
            @proc_terminate($process);
            foreach ([1, 2] as $pipe) {
                if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) {
                    fclose($pipes[$pipe]);
                }
            }
            proc_close($process);
            @unlink($readyFile);
        }
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
        ], $overrides);
    }
}
