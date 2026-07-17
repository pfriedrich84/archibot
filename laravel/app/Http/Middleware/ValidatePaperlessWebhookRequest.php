<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ValidatePaperlessWebhookRequest
{
    /**
     * Reject oversized or unauthenticated webhook requests before controller parsing or persistence.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isPaperlessWebhookIngress($request)) {
            return $next($request);
        }

        $maximumBytes = max(1, (int) config('archibot.paperless_webhook_max_bytes', 262144));
        $declaredLength = $request->headers->get('Content-Length');

        if (is_string($declaredLength) && ctype_digit($declaredLength) && (int) $declaredLength > $maximumBytes) {
            return response()->json(['detail' => 'Webhook payload too large'], 413);
        }

        // Content-Length is advisory and may be missing or false. Always inspect at most max + 1 bytes.
        if ($this->bodyExceedsLimitOrCannotBeRewound($request, $maximumBytes)) {
            return response()->json(['detail' => 'Webhook payload too large'], 413);
        }

        $rateLimitKey = self::rateLimitKey((string) $request->ip());
        $requestsPerMinute = max(1, (int) config('archibot.paperless_webhook_rate_limit_per_minute', 60));
        if (RateLimiter::tooManyAttempts($rateLimitKey, $requestsPerMinute)) {
            $retryAfter = max(1, RateLimiter::availableIn($rateLimitKey));

            return response()->json(['detail' => 'Webhook rate limit exceeded'], 429, [
                'Retry-After' => (string) $retryAfter,
            ]);
        }
        RateLimiter::hit($rateLimitKey, 60);

        if ($this->developmentBypassIsActive()) {
            return $next($request);
        }

        $storedSecret = AppSetting::getValue('webhook.secret');
        $configuredSecret = is_string($storedSecret) && trim($storedSecret) !== ''
            ? $storedSecret
            : (string) config('archibot.paperless_webhook_secret', '');
        $providedSecret = (string) $request->headers->get('X-Webhook-Secret', '');

        if (! self::secretIsUsable($configuredSecret)
            || $providedSecret === ''
            || ! hash_equals($configuredSecret, $providedSecret)
        ) {
            return response()->json(['detail' => 'Invalid webhook secret'], 403);
        }

        return $next($request);
    }

    private function bodyExceedsLimitOrCannotBeRewound(Request $request, int $maximumBytes): bool
    {
        try {
            $stream = $request->getContent(true);
        } catch (Throwable) {
            return true;
        }

        if (! is_resource($stream) || ! @rewind($stream)) {
            return true;
        }

        $content = stream_get_contents($stream, $maximumBytes + 1);
        $rewound = @rewind($stream);

        return $content === false || ! $rewound || strlen($content) > $maximumBytes;
    }

    public static function rateLimitKey(string $clientIp): string
    {
        return 'paperless-webhook:'.hash('sha256', $clientIp);
    }

    public static function secretIsUsable(mixed $secret): bool
    {
        if (! is_string($secret)) {
            return false;
        }

        $normalized = strtolower(trim($secret));

        return $normalized !== ''
            && ! in_array($normalized, [
                '<generate-a-random-secret>',
                '<generate-a-unique-random-secret>',
                'change-me',
                'changeme',
                'replace-me',
                'your-webhook-secret',
            ], true);
    }

    private function isPaperlessWebhookIngress(Request $request): bool
    {
        if (! $request->isMethod('POST')) {
            return false;
        }

        $prefix = trim((string) config('archibot.path_prefix', ''), '/');
        $path = trim($request->path(), '/');

        return in_array($path, array_map(
            fn (string $suffix): string => trim($prefix.'/'.$suffix, '/'),
            ['webhook', 'api/webhooks/paperless'],
        ), true);
    }

    public static function developmentBypassIsActive(): bool
    {
        return (bool) config('archibot.paperless_webhook_development_bypass', false)
            && app()->environment(['local', 'development']);
    }
}
