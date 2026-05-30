<?php

namespace Tests\Feature\Auth;

use App\Models\SetupState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->where('paperlessUrl', 'https://paperless.test')
            );
    }

    public function test_users_authenticate_against_paperless_using_the_login_screen(): void
    {
        $this->markSetupComplete();

        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['token' => 'fresh-paperless-token']),
            'https://paperless.test/api/ui_settings/' => Http::response([
                'user' => [
                    'id' => 42,
                    'username' => 'paperless-admin',
                    'first_name' => 'Paperless',
                    'last_name' => 'Admin',
                    'is_staff' => true,
                    'is_superuser' => true,
                    'groups' => [],
                ],
                'permissions' => ['view_document'],
            ]),
        ]);

        $response = $this->post(route('login.store'), [
            'username' => 'paperless-admin',
            'password' => 'paperless-password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->firstOrFail();
        $this->assertSame('paperless-admin', $user->paperless_username);
        $this->assertSame('fresh-paperless-token', $user->paperless_token);
        $this->assertTrue($user->is_admin);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'event' => 'auth.login',
        ]);
    }

    public function test_users_can_not_authenticate_before_setup_is_complete(): void
    {
        SetupState::current()->forceFill([
            'is_complete' => false,
            'completed_at' => null,
        ])->save();

        $this->post(route('login.store'), [
            'username' => 'paperless-admin',
            'password' => 'paperless-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_paperless_password(): void
    {
        $this->markSetupComplete();

        Http::fake([
            'https://paperless.test/api/token/' => Http::response(['detail' => 'invalid'], 400),
        ]);

        $this->post(route('login.store'), [
            'username' => 'paperless-admin',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_users_see_paperless_unreachable_message_when_login_server_is_unavailable(): void
    {
        $this->markSetupComplete();

        Http::fake([
            'https://paperless.test/api/token/' => Http::response([], 503),
        ]);

        $response = $this->from(route('login'))->post(route('login.store'), [
            'username' => 'paperless-admin',
            'password' => 'paperless-password',
        ]);

        $response->assertRedirect(route('login', absolute: false));
        $response->assertSessionHasErrors([
            'username' => 'Paperless server is not reachable.',
        ]);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $this->assertGuest();
        $response->assertRedirect(route('home'));
    }

    public function test_users_are_rate_limited(): void
    {
        $this->markSetupComplete();

        RateLimiter::increment(md5('login'.implode('|', ['paperless-admin', '127.0.0.1'])), amount: 5);

        $response = $this->post(route('login.store'), [
            'username' => 'paperless-admin',
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }

    private function markSetupComplete(): void
    {
        $this->markArchiBotSetupComplete();
    }
}
