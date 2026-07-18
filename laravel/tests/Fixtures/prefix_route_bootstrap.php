<?php

use App\Models\EntityApproval;
use App\Models\McpToken;
use App\Models\OcrReview;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Services\Paperless\PaperlessDocumentPermissions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Executed by UxConsistencyTest in a fresh PHP process so configuration,
// routes, middleware, controllers, and URL generation all use this prefix
// from initial bootstrap.
$prefix = $argv[1] ?? '';
$environment = [
    'APP_ENV' => 'testing',
    'APP_PATH_PREFIX' => $prefix,
    'PAPERLESS_URL' => 'http://paperless.test',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'database',
    'QUEUE_WORKER_TIMEOUT' => '30',
    'DB_QUEUE_RETRY_AFTER' => '90',
    'SESSION_DRIVER' => 'array',
];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'archibot.testing_setup_complete' => true,
    'archibot.paperless_url' => 'http://paperless.test',
]);
Artisan::call('migrate', ['--force' => true]);
Queue::fake();

$user = User::factory()->create([
    'is_admin' => true,
    'email_verified_at' => now(),
    'paperless_token' => 'safe-prefix-test-token',
]);
Auth::login($user);

$app->instance(
    PaperlessDocumentPermissions::class,
    new class extends PaperlessDocumentPermissions
    {
        public function canViewDocument(User $user, int $paperlessDocumentId): bool
        {
            return true;
        }

        public function canChangeDocument(User $user, int $paperlessDocumentId): bool
        {
            return true;
        }

        public function assertCanViewDocument(User $user, int $paperlessDocumentId): void {}

        public function assertCanChangeDocument(User $user, int $paperlessDocumentId): void {}
    },
);

Http::fake(function (Illuminate\Http\Client\Request $request) {
    $url = $request->url();

    if (str_contains($url, 'ollama.test/api/tags')) {
        return Http::response([
            'models' => [['name' => 'safe-model']],
        ]);
    }
    if (str_contains($url, 'ollama.test/api/chat')) {
        return Http::response([
            'message' => ['content' => 'ARCHIBOT_OK'],
        ]);
    }
    if (str_contains($url, '/preview/')) {
        return Http::response('%PDF-prefix-matrix', 200, [
            'Content-Type' => 'application/pdf',
        ]);
    }
    if (str_contains($url, '/api/tags/')) {
        return Http::response(['results' => []]);
    }
    if (preg_match('#/api/documents/\d+/?#', $url)) {
        return Http::response([
            'id' => 501,
            'content' => 'Original safe fixture content',
        ]);
    }

    return Http::response([], 404);
});

$acceptSuggestion = ReviewSuggestion::factory()->create([
    'paperless_document_id' => 501,
]);
$rejectSuggestion = ReviewSuggestion::factory()->create([
    'paperless_document_id' => 502,
]);
$previewSuggestion = ReviewSuggestion::factory()->create([
    'paperless_document_id' => 503,
]);
$ocrReview = OcrReview::query()->create([
    'paperless_document_id' => 504,
    'dedupe_key' => 'prefix-existing-ocr',
    'original_content' => 'old',
    'ocr_content' => 'corrected',
    'status' => OcrReview::STATUS_PENDING,
    'created_by_user_id' => $user->id,
]);
$entity = EntityApproval::factory()->create([
    'type' => EntityApproval::TYPE_TAG,
    'name' => 'Prefix matrix tag',
]);
$mcpToken = McpToken::factory()->create(['user_id' => $user->id]);

$routeParameters = [
    'review.show' => [$previewSuggestion],
    'review.preview' => [$previewSuggestion],
    'ocr-reviews.show' => [$ocrReview],
    'ocr-reviews.approve' => [$ocrReview],
    'ocr-reviews.reject' => [$ocrReview],
    'entities.index' => ['segment' => 'tags'],
    'entities.reject' => ['segment' => 'tags', 'entityApproval' => $entity],
    'mcp-tokens.destroy' => [$mcpToken],
    'admin.settings.edit' => ['section' => 'ai-provider'],
];
$names = [
    'home', 'dashboard', 'admin.settings.edit', 'admin.settings.update',
    'admin.settings.ai-models', 'admin.settings.ai-models.validate',
    'review.index', 'review.bulk.accept', 'review.bulk.reject', 'review.show',
    'review.preview', 'ocr-reviews.index', 'ocr-reviews.store',
    'ocr-reviews.show', 'ocr-reviews.approve', 'ocr-reviews.reject',
    'entities.index', 'entities.reject', 'mcp-tokens.index',
    'mcp-tokens.store', 'mcp-tokens.destroy',
];
$routes = [];
foreach ($names as $name) {
    $route = $app['router']->getRoutes()->getByName($name);
    if ($route === null) {
        fwrite(STDERR, "Missing route: {$name}\n");
        exit(2);
    }
    $routes[$name] = [
        'uri' => '/'.ltrim($route->uri(), '/'),
        'methods' => $route->methods(),
        'generated' => route($name, $routeParameters[$name] ?? [], false),
    ];
}

$httpKernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$base = $prefix === '' ? '' : '/'.$prefix;
$dispatch = static function (
    string $method,
    string $uri,
    array $parameters = [],
    array $headers = [],
) use ($httpKernel, $base): array {
    $server = [
        'HTTP_ACCEPT' => $headers['Accept'] ?? 'text/html,application/xhtml+xml',
        'HTTP_REFERER' => $headers['Referer'] ?? 'http://localhost'.$base.'/dashboard',
    ];
    if (($headers['X-Inertia'] ?? false) === true) {
        $server['HTTP_X_INERTIA'] = 'true';
        $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    }
    $request = Request::create($uri, $method, $parameters, [], [], $server);
    $response = $httpKernel->handle($request);
    $body = $response->getContent() ?: '';
    $urls = [];
    $decoded = json_decode($body, true);
    $collectUrls = static function (mixed $value) use (&$collectUrls, &$urls): void {
        if (is_array($value)) {
            foreach ($value as $item) {
                $collectUrls($item);
            }
        } elseif (is_string($value)
            && (str_starts_with($value, '/') || str_starts_with($value, 'http://localhost/'))) {
            $urls[] = $value;
        }
    };
    if (is_array($decoded)) {
        $collectUrls($decoded);
    }
    $result = [
        'status' => $response->getStatusCode(),
        'location' => $response->headers->get('Location'),
        'content_type' => $response->headers->get('Content-Type'),
        'urls' => array_values(array_unique($urls)),
        'body' => substr($body, 0, 1000),
    ];
    $httpKernel->terminate($request, $response);

    return $result;
};

$inertia = ['X-Inertia' => true];
$json = ['Accept' => 'application/json'];
$flows = [
    'settings_get' => $dispatch('GET', $base.'/admin/settings/ai-provider', [], $inertia),
    'settings_post' => $dispatch('POST', $base.'/admin/settings', [
        '_method' => 'PATCH',
        '__settings_keys' => [],
    ], ['Referer' => 'http://localhost'.$base.'/admin/settings/ai-provider']),
    'document_preview_get' => $dispatch('GET', $base.'/review/'.$previewSuggestion->id.'/preview'),
    'model_discovery_post' => $dispatch('POST', $base.'/admin/settings/ai-models', [
        'llm_provider' => 'ollama',
        'ollama_url' => 'http://ollama.test',
    ], $json),
    'model_validation_post' => $dispatch('POST', $base.'/admin/settings/ai-models/validate', [
        'model_id' => 'safe-model',
        'role' => 'classification',
        'llm_provider' => 'ollama',
        'ollama_url' => 'http://ollama.test',
    ], $json),
    'review_bulk_accept_post' => $dispatch('POST', $base.'/review/bulk/accept', [
        'suggestion_ids' => [$acceptSuggestion->id],
    ], ['Referer' => 'http://localhost'.$base.'/review']),
    'review_bulk_reject_post' => $dispatch('POST', $base.'/review/bulk/reject', [
        'suggestion_ids' => [$rejectSuggestion->id],
    ], ['Referer' => 'http://localhost'.$base.'/review']),
    'ocr_index_get' => $dispatch('GET', $base.'/ocr-reviews', [], $inertia),
    'ocr_show_get' => $dispatch('GET', $base.'/ocr-reviews/'.$ocrReview->id, [], $inertia),
    'ocr_store_post' => $dispatch('POST', $base.'/ocr-reviews', [
        'paperless_document_id' => 505,
        'ocr_content' => 'New safe correction',
    ], ['Referer' => 'http://localhost'.$base.'/ocr-reviews']),
    'ocr_reject_post' => $dispatch('POST', $base.'/ocr-reviews/'.$ocrReview->id.'/reject', [], [
        'Referer' => 'http://localhost'.$base.'/ocr-reviews/'.$ocrReview->id,
    ]),
    'entity_reject_post' => $dispatch('POST', $base.'/tags/entity-approvals/'.$entity->id.'/reject', [], [
        'Referer' => 'http://localhost'.$base.'/tags',
    ]),
    'mcp_index_get' => $dispatch('GET', $base.'/settings/mcp-tokens', [], $inertia),
    'mcp_store_post' => $dispatch('POST', $base.'/settings/mcp-tokens', [
        'name' => 'Prefix matrix client',
    ], ['Referer' => 'http://localhost'.$base.'/settings/mcp-tokens']),
    'mcp_destroy_post' => $dispatch('POST', $base.'/settings/mcp-tokens/'.$mcpToken->id, [
        '_method' => 'DELETE',
    ], ['Referer' => 'http://localhost'.$base.'/settings/mcp-tokens']),
];

$redirectFlows = [
    'settings_post', 'review_bulk_accept_post', 'review_bulk_reject_post',
    'ocr_store_post', 'ocr_reject_post', 'entity_reject_post',
    'mcp_store_post', 'mcp_destroy_post',
];
foreach ($redirectFlows as $source) {
    $location = $flows[$source]['location'];
    $flows[$source.'_follow'] = is_string($location)
        ? $dispatch('GET', parse_url($location, PHP_URL_PATH) ?: $location, [], $inertia)
        : ['status' => 0, 'location' => null, 'content_type' => null, 'urls' => [], 'body' => ''];
}

echo json_encode([
    'configured_prefix' => config('archibot.path_prefix'),
    'routes' => $routes,
    'flows' => $flows,
], JSON_THROW_ON_ERROR);
