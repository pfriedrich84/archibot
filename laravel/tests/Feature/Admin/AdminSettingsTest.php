<?php

namespace Tests\Feature\Admin;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                ->where('groups.0.name', 'Paperless')
                ->where('groups.0.settings.0.key', 'paperless.url')
                ->where('groups.0.settings.0.value', 'https://paperless.test')
            );
    }

    public function test_admin_can_update_global_settings_and_write_audit_log(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->patch(route('admin.settings.update'), [
            'paperless_url' => 'https://paperless-updated.test',
            'embedding_hybrid_search_weight' => '0.7',
            'audit_retention_days' => 14,
        ]);

        $response->assertRedirect();
        $this->assertSame('https://paperless-updated.test', AppSetting::getValue('paperless.url'));
        $this->assertSame('0.7', AppSetting::getValue('embedding.hybrid_search_weight'));
        $this->assertSame('14', AppSetting::getValue('audit.retention_days'));
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'admin_settings.updated',
            'target_type' => 'app_settings',
        ]);
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
