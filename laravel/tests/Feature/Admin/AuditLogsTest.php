<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_view_audit_logs(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'paperless_username' => 'admin']);
        $user = User::factory()->create(['is_admin' => false]);

        AuditLog::query()->create([
            'actor_user_id' => $admin->id,
            'event' => 'setup.completed',
            'target_type' => 'setup',
            'metadata' => ['paperless_url' => 'https://paperless.test'],
        ]);

        $this->actingAs($user)->get(route('admin.audit-logs.index'))->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/AuditLogs')
                ->has('logs', 1)
                ->where('logs.0.event', 'setup.completed')
                ->where('logs.0.actor.paperless_username', 'admin')
            );
    }
}
