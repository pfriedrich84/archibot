<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\SetupState;
use App\Models\User;
use App\Services\Paperless\CanonicalPaperlessOrigin;
use App\Services\Paperless\PaperlessClient;
use App\Services\Paperless\PaperlessUnavailableException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use RuntimeException;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        // Local user creation/password-reset actions are intentionally not
        // registered: ArchiBot users authenticate through Paperless-NGX.
    }

    /**
     * Configure Paperless-backed authentication.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            if (! SetupState::current()->is_complete) {
                return null;
            }

            $username = (string) $request->input('username');
            $password = (string) $request->input('password');
            if ($username === '' || $password === '') {
                return null;
            }

            try {
                $client = new PaperlessClient;
                $token = $client->createToken($username, $password);
                $paperlessUser = $client->currentUser($token, $username);
            } catch (PaperlessUnavailableException) {
                throw ValidationException::withMessages([
                    'username' => 'Paperless server is not reachable.',
                ]);
            } catch (RuntimeException) {
                return null;
            }

            $email = $paperlessUser->email ?: $paperlessUser->username.'@paperless.local';

            $user = User::query()->updateOrCreate(
                ['paperless_username' => $paperlessUser->username],
                [
                    'name' => $paperlessUser->displayName,
                    'email' => $email,
                    'paperless_user_id' => $paperlessUser->id,
                    'is_admin' => $paperlessUser->isSuperuser,
                    'paperless_token' => $token,
                    'paperless_profile_refreshed_at' => now(),
                    'password' => Hash::make(Str::random(64)),
                    'email_verified_at' => now(),
                ],
            );

            AuditLog::query()->create([
                'actor_user_id' => $user->id,
                'event' => 'auth.login',
                'target_type' => 'user',
                'target_id' => (string) $user->id,
                'metadata' => [
                    'paperless_username' => $paperlessUser->username,
                    'paperless_user_id' => $paperlessUser->id,
                    'is_admin' => $paperlessUser->isSuperuser,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => false,
            'canRegister' => false,
            'paperlessUrl' => app(CanonicalPaperlessOrigin::class)->url(),
            'status' => $request->session()->get('status'),
        ]));

    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('setup-paperless', function (Request $request) {
            $identityHash = hash('sha256', Str::lower((string) $request->input('username', '')));

            return Limit::perMinute(max(1, (int) config('archibot.setup_rate_limit_per_minute', 5)))
                ->by($identityHash.'|'.$request->ip());
        });

        RateLimiter::for('model-discovery', fn (Request $request) => Limit::perMinute(
            max(1, (int) config('archibot.model_discovery_rate_limit_per_minute', 10)),
        )->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
