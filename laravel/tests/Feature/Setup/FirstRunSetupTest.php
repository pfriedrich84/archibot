<?php

namespace Tests\Feature\Setup;

use App\Models\AppSetting;
use App\Models\SetupState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    protected bool $completeSetupByDefault = false;

    public function test_setup_page_is_available_before_setup_is_complete(): void
    {
        $this->get('/setup')->assertOk();
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

        $response = $this->post('/setup', [
            'paperless_url' => 'https://paperless.test',
            'username' => 'regular-user',
            'password' => 'secret',
        ]);

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

        $response = $this->post('/setup', [
            'paperless_url' => 'https://paperless.test/',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertTrue(SetupState::current()->is_complete);
        $this->assertSame('https://paperless.test', AppSetting::getValue('paperless.url'));

        $user = User::query()->firstOrFail();
        $this->assertSame('admin', $user->paperless_username);
        $this->assertSame(7, $user->paperless_user_id);
        $this->assertTrue($user->is_admin);
        $this->assertSame('paperless-token', $user->paperless_token);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'setup.completed',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_setup_routes_are_disabled_after_setup_is_complete(): void
    {
        SetupState::current()->forceFill([
            'is_complete' => true,
            'completed_at' => now(),
        ])->save();

        $this->get('/setup')->assertNotFound();
        $this->post('/setup', [])->assertNotFound();
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

        $response = $this->post('/setup', [
            'paperless_url' => 'https://paperless.test',
            'username' => 'admin',
            'password' => 'secret',
            'setup_token' => 'wrong-token',
        ]);

        $response->assertSessionHasErrors('setup_token');
    }
}
