<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Models\WorkerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summarizes_laravel_app_status(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        AppSetting::put('paperless.inbox_tag_id', '7');
        Http::fake(['paperless.example/api/ui_settings/' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);
        ReviewSuggestion::factory()->count(2)->create();
        ReviewSuggestion::factory()->create(['status' => ReviewSuggestion::STATUS_REJECTED]);
        WorkerJob::factory()->create(['status' => WorkerJob::STATUS_RUNNING]);
        WorkerJob::factory()->create(['status' => WorkerJob::STATUS_FAILED]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('status.paperless_url_configured', true)
                ->where('status.paperless_available', true)
                ->where('status.inbox_tag_id', 7)
                ->where('counts.pending_reviews', 2)
                ->where('counts.queued_or_running_workers', 1)
                ->where('counts.failed_workers', 1)
                ->has('recentWorkerJobs', 2)
            );
    }

    public function test_dashboard_handles_paperless_unavailable(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::fake(['paperless.example/api/ui_settings/' => Http::response([], 500)]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('status.paperless_available', false)
            );
    }
}
