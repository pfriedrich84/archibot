<?php

use App\Http\Middleware\EnsureSetupIsComplete;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ValidatePaperlessWebhookRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Run webhook raw-body/auth checks before Laravel's input-normalization middleware can parse the payload.
        $middleware->prepend(ValidatePaperlessWebhookRequest::class);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureUserIsAdmin::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->validateCsrfTokens(except: [
            'webhook',
            'webhook/*',
            '*/webhook',
            '*/webhook/*',
            'api/webhooks/*',
            '*/api/webhooks/*',
        ]);

        $middleware->web(append: [
            EnsureSetupIsComplete::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
