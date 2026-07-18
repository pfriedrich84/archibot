<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\OcrReview;
use App\Models\PipelineRun;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Paperless\PaperlessDocumentPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UxConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_core_paginated_list_reaches_items_beyond_twenty_five_and_preserves_query(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        ReviewSuggestion::factory()->count(26)->sequence(
            fn ($sequence) => ['paperless_document_id' => 1000 + $sequence->index],
        )->create();
        PipelineRun::query()->insert(collect(range(1, 26))->map(fn (int $id) => [
            'type' => 'document', 'status' => PipelineRun::STATUS_SUCCEEDED,
            'trigger_source' => 'manual', 'paperless_document_id' => $id,
            'created_at' => now(), 'updated_at' => now(),
        ])->all());
        OcrReview::query()->insert(collect(range(1, 26))->map(fn (int $id) => [
            'paperless_document_id' => 2000 + $id, 'dedupe_key' => "ocr-{$id}",
            'original_content' => 'original', 'ocr_content' => 'corrected',
            'status' => OcrReview::STATUS_PENDING, 'created_at' => now(), 'updated_at' => now(),
        ])->all());
        WebhookDelivery::query()->insert(collect(range(1, 26))->map(fn (int $id) => [
            'source' => 'paperless', 'event_type' => 'document.updated',
            'dedupe_key' => "webhook-{$id}", 'payload_hash' => hash('sha256', "payload-{$id}"),
            'request_id' => "request-{$id}", 'raw_payload' => '{}',
            'status' => WebhookDelivery::STATUS_FAILED, 'received_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ])->all());

        $permissions = $this->mock(PaperlessDocumentPermissions::class);
        $permissions->shouldReceive('canViewDocument')->andReturnTrue();

        $cases = [
            [route('review.index', ['page' => 2, 'per_page' => 25, 'sort' => 'created_desc']), 'suggestions', 'sort=created_desc'],
            [route('ocr-reviews.index', ['page' => 2, 'per_page' => 25]), 'reviews', 'per_page=25'],
            [route('pipeline-runs.index', ['page' => 2, 'per_page' => 25]), 'runs', 'per_page=25'],
            [route('webhook-deliveries.index', ['page' => 2, 'per_page' => 25]), 'deliveries', 'per_page=25'],
            [route('errors.index', ['webhook_page' => 2, 'per_page' => 25, 'status' => WebhookDelivery::STATUS_FAILED]), 'webhookErrors', 'status=failed'],
        ];

        foreach ($cases as [$url, $prop, $preserved]) {
            $this->actingAs($admin)->get($url)->assertOk()->assertInertia(
                fn (Assert $page) => $page
                    ->has("{$prop}.data", 1)
                    ->where("{$prop}.per_page", 25)
                    ->where("{$prop}.links", fn ($links): bool => collect($links)->contains(
                        fn (array $link): bool => is_string($link['url']) && str_contains($link['url'], $preserved),
                    )),
            );
        }
    }

    public function test_shared_path_prefix_is_normalized_for_empty_and_prefixed_deployments(): void
    {
        $middleware = app(HandleInertiaRequests::class);
        $request = Request::create('/');

        config(['archibot.path_prefix' => '']);
        $this->assertSame('', $middleware->share($request)['appPathPrefix']);

        config(['archibot.path_prefix' => '/archibot/']);
        $this->assertSame('/archibot', $middleware->share($request)['appPathPrefix']);

        config(['archibot.path_prefix' => '']);
    }

    public function test_fresh_processes_execute_prefix_sensitive_http_and_form_flows_for_both_prefixes(): void
    {
        $expectedStatuses = [
            'settings_get' => 200,
            'settings_post' => 302,
            'document_preview_get' => 200,
            'model_discovery_post' => 200,
            'model_validation_post' => 200,
            'review_bulk_accept_post' => 302,
            'review_bulk_reject_post' => 302,
            'ocr_index_get' => 200,
            'ocr_show_get' => 200,
            'ocr_store_post' => 302,
            'ocr_reject_post' => 302,
            'entity_reject_post' => 302,
            'mcp_index_get' => 200,
            'mcp_store_post' => 302,
            'mcp_destroy_post' => 302,
        ];
        $redirectFlows = [
            'settings_post', 'review_bulk_accept_post', 'review_bulk_reject_post',
            'ocr_store_post', 'ocr_reject_post', 'entity_reject_post',
            'mcp_store_post', 'mcp_destroy_post',
        ];
        $contracts = [];

        foreach (['', 'archibot'] as $prefix) {
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/prefix_route_bootstrap.php'), $prefix]);
            $process->setTimeout(60);
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $routes = $result['routes'];
            $flows = $result['flows'];
            $expectedPrefix = $prefix === '' ? '' : '/'.$prefix;
            $this->assertSame($prefix, $result['configured_prefix']);

            $assertPrefixedPath = function (string $url, string $context) use ($expectedPrefix): void {
                $path = parse_url($url, PHP_URL_PATH);
                $this->assertIsString($path, $context);
                $this->assertStringStartsWith($expectedPrefix.'/', $path, $context);
                $this->assertStringNotContainsString('/archibot/archibot/', $path, $context);
                if ($expectedPrefix === '') {
                    $this->assertStringNotContainsString('/archibot/', $path, $context);
                }
            };

            foreach ($routes as $name => $route) {
                $assertPrefixedPath($route['uri'], $name.' registered URI');
                $assertPrefixedPath($route['generated'], $name.' generated URL');
            }
            $this->assertContains('GET', $routes['admin.settings.edit']['methods']);
            $this->assertContains('PATCH', $routes['admin.settings.update']['methods']);
            $this->assertContains('POST', $routes['admin.settings.ai-models']['methods']);
            $this->assertContains('GET', $routes['review.preview']['methods']);
            $this->assertContains('POST', $routes['review.bulk.accept']['methods']);
            $this->assertContains('POST', $routes['review.bulk.reject']['methods']);
            $this->assertContains('DELETE', $routes['mcp-tokens.destroy']['methods']);

            foreach ($expectedStatuses as $flow => $status) {
                $this->assertSame($status, $flows[$flow]['status'], $flow.' status under '.$prefix);
            }
            $this->assertStringContainsString('application/pdf', $flows['document_preview_get']['content_type']);
            $this->assertStringContainsString('safe-model', $flows['model_discovery_post']['body']);
            $this->assertStringContainsString('validated for classification', $flows['model_validation_post']['body']);

            foreach (['settings_get', 'ocr_show_get', 'mcp_index_get'] as $flow) {
                $this->assertNotEmpty($flows[$flow]['urls'], $flow.' should emit controller-generated URLs');
            }
            foreach ($flows as $flow => $response) {
                foreach ($response['urls'] as $url) {
                    $assertPrefixedPath($url, $flow.' response URL');
                }
            }

            foreach ($redirectFlows as $flow) {
                $this->assertIsString($flows[$flow]['location'], $flow.' redirect');
                $assertPrefixedPath($flows[$flow]['location'], $flow.' redirect');
                $this->assertSame(200, $flows[$flow.'_follow']['status'], $flow.' followed navigation');
            }

            $contracts[$prefix] = [
                'statuses' => collect($flows)->map(fn (array $response): int => $response['status'])->all(),
                'redirects' => collect($redirectFlows)->mapWithKeys(function (string $flow) use ($flows, $expectedPrefix): array {
                    $path = (string) parse_url($flows[$flow]['location'], PHP_URL_PATH);

                    return [$flow => $expectedPrefix === '' ? $path : substr($path, strlen($expectedPrefix))];
                })->all(),
            ];
        }

        $this->assertSame($contracts['']['statuses'], $contracts['archibot']['statuses']);
        $this->assertSame($contracts['']['redirects'], $contracts['archibot']['redirects']);
    }

    public function test_setup_and_admin_api_actions_use_routes_from_the_active_prefix(): void
    {
        $prefix = trim((string) config('archibot.path_prefix'), '/');
        $expectedPath = fn (string $path): string => '/'.($prefix === '' ? '' : $prefix.'/').ltrim($path, '/');

        $this->get(route('setup.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('actions.store', url($expectedPath('setup')))
            ->where('actions.paperlessTags', url($expectedPath('setup/paperless-tags')))
        );

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.settings.edit', ['section' => 'ai-provider']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('aiModelActions.discover', url($expectedPath('admin/settings/ai-models')))
                ->where('aiModelActions.validate', url($expectedPath('admin/settings/ai-models/validate')))
            );
    }

    public function test_flash_success_and_failure_are_shared_accessibly_with_inertia_pages(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->withSession([
            'toast' => ['type' => 'info', 'message' => 'Background notice.'],
            'status' => 'Queued successfully.',
            'error' => 'Queue failed.',
        ])->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('flash.toast.type', 'info')
            ->where('flash.toast.message', 'Background notice.')
            ->where('flash.success', 'Queued successfully.')
            ->where('flash.error', 'Queue failed.')
        );
    }
}
