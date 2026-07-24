<?php

namespace Tests\Feature\Entities;

use App\Models\PaperlessMasterDataCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaperlessMasterDataCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_view_master_data_case_page(): void
    {
        $user = User::factory()->create();
        $pending = PaperlessMasterDataCase::query()->create([
            'entity_type' => 'tag',
            'normalized_name' => 'accounting',
            'canonical_name' => 'Accounting',
            'status' => PaperlessMasterDataCase::STATUS_PENDING,
        ]);
        PaperlessMasterDataCase::query()->create([
            'entity_type' => 'correspondent',
            'normalized_name' => 'acme',
            'canonical_name' => 'ACME',
            'status' => PaperlessMasterDataCase::STATUS_PENDING,
        ]);

        $this->actingAs($user)
            ->get('/tags')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('entities/Index')
                ->where('segment', 'tags')
                ->where('title', 'Tags')
                ->where('pending.0.id', $pending->id)
                ->where('pending.0.name', 'Accounting')
                ->has('pending', 1)
            );
    }

    public function test_admin_can_approve_pending_master_data_case(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $entity = PaperlessMasterDataCase::query()->create([
            'entity_type' => 'tag',
            'normalized_name' => 'accounting',
            'canonical_name' => 'Accounting',
            'status' => PaperlessMasterDataCase::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('entities.approve', ['segment' => 'tags', 'paperlessMasterDataCase' => $entity]))
            ->assertRedirect()
            ->assertSessionHas('status', "Approval for 'Accounting' was queued.");

        $entity->refresh();
        $this->assertSame(PaperlessMasterDataCase::STATUS_APPROVED, $entity->status);
        $this->assertSame(PaperlessMasterDataCase::SYNC_STATUS_QUEUED, $entity->sync_status);
        $this->assertSame($admin->id, $entity->reviewed_by_user_id);
    }

    public function test_admin_can_reject_pending_master_data_case(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $entity = PaperlessMasterDataCase::query()->create([
            'entity_type' => 'tag',
            'normalized_name' => 'accounting',
            'canonical_name' => 'Accounting',
            'status' => PaperlessMasterDataCase::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('entities.reject', ['segment' => 'tags', 'paperlessMasterDataCase' => $entity]))
            ->assertRedirect()
            ->assertSessionHas('status', "Rejection of 'Accounting' was queued.");

        $entity->refresh();
        $this->assertSame(PaperlessMasterDataCase::STATUS_REJECTED, $entity->status);
        $this->assertSame(PaperlessMasterDataCase::SYNC_STATUS_SYNCED, $entity->sync_status);
    }
}
