<?php

namespace App\Http\Middleware;

use App\Models\SetupState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupIsComplete
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request) || SetupState::current()->is_complete) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'ArchiBot setup is not complete.',
            ], 503);
        }

        return redirect()->route('setup.show');
    }

    private function shouldSkip(Request $request): bool
    {
        if ($request->routeIs('setup.*') || $request->routeIs('healthz')) {
            return true;
        }

        if (app()->environment('testing') && (bool) config('archibot.testing_setup_complete', false)) {
            return true;
        }

        return $request->is('up')
            || $request->is('build/*')
            || $request->is('favicon.ico')
            || $request->is('favicon.svg')
            || $request->is('apple-touch-icon.png')
            || $request->is('robots.txt');
    }
}
