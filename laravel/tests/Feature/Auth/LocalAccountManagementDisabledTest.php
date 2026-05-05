<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class LocalAccountManagementDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_starter_account_management_features_are_not_enabled(): void
    {
        $this->assertFalse(Features::enabled(Features::registration()));
        $this->assertFalse(Features::enabled(Features::resetPasswords()));
        $this->assertFalse(Features::enabled(Features::emailVerification()));
        $this->assertFalse(Features::enabled(Features::twoFactorAuthentication()));
    }

    public function test_guest_local_account_routes_are_not_registered(): void
    {
        foreach ([
            '/register',
            '/forgot-password',
            '/reset-password/local-token',
            '/email/verify',
            '/two-factor-challenge',
            '/user/confirm-password',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_authenticated_local_account_settings_routes_are_not_registered(): void
    {
        $user = User::factory()->create();

        foreach ([
            '/settings/profile',
            '/settings/security',
        ] as $path) {
            $this->actingAs($user)->get($path)->assertNotFound();
        }

        $this->actingAs($user)->patch('/settings/profile')->assertNotFound();
        $this->actingAs($user)->delete('/settings/profile')->assertNotFound();
        $this->actingAs($user)->put('/settings/password')->assertNotFound();
        $this->actingAs($user)->post('/user/two-factor-authentication')->assertNotFound();
    }
}
