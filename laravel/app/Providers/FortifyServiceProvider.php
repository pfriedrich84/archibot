<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\SetupState;
use App\Models\User;
use App\Services\Paperless\PaperlessClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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
        //
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
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
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
            $paperlessUrl = AppSetting::getValue('paperless.url');

            if ($username === '' || $password === '' || ! $paperlessUrl) {
                return null;
            }

            try {
                $client = new PaperlessClient($paperlessUrl);
                $token = $client->createToken($username, $password);
                $paperlessUser = $client->currentUser($token, $username);
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
                    'is_admin' => $paperlessUser->isAdmin,
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
                    'is_admin' => $paperlessUser->isAdmin,
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
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register'));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
