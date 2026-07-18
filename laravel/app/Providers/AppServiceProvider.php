<?php

namespace App\Providers;

use App\Http\Middleware\ValidatePaperlessWebhookRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use LogicException;
use PHPUnit\Framework\TestCase;

class AppServiceProvider extends ServiceProvider
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
        $this->configureDefaults();
        $this->configurePaperlessWebhookSecurity();
    }

    private function configurePaperlessWebhookSecurity(): void
    {
        if (ValidatePaperlessWebhookRequest::developmentBypassIsActive()) {
            Log::warning('Paperless webhook authentication development bypass is active.', [
                'environment' => app()->environment(),
            ]);
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        $testDatabaseAdapterActive = app()->environment('testing')
            && class_exists(TestCase::class);
        if (config('database.default') !== 'pgsql' && ! $testDatabaseAdapterActive) {
            throw new LogicException('ArchiBot product startup requires PostgreSQL.');
        }

        if (config('queue.default') !== 'database') {
            throw new LogicException('Archibot requires QUEUE_CONNECTION=database for atomic durable dispatch.');
        }
        if ((int) config('queue.connections.database.retry_after')
            <= (int) config('archibot_workers.queue_worker_timeout')) {
            throw new LogicException('DB_QUEUE_RETRY_AFTER must exceed QUEUE_WORKER_TIMEOUT.');
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
