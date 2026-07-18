<?php

namespace Tests;

use App\Models\AppSetting;
use App\Models\SetupState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected bool $completeSetupByDefault = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        $runtimeConfigPath = storage_path('framework/testing/config.env');
        @unlink($runtimeConfigPath);
        config([
            'archibot.paperless_url' => 'https://paperless.test',
            'archibot.testing_setup_complete' => $this->completeSetupByDefault,
            'archibot_settings.import_paths' => [$runtimeConfigPath],
        ]);

        if (Schema::hasTable('app_settings') && Schema::hasTable('setup_states')) {
            if ($this->completeSetupByDefault) {
                $this->markArchiBotSetupComplete();
            } else {
                $this->markArchiBotSetupIncomplete();
            }
        }
    }

    protected function markArchiBotSetupComplete(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.test');
        SetupState::query()->updateOrCreate(['id' => 1], [
            'is_complete' => true,
            'reset_token_hash' => null,
            'reset_token_expires_at' => null,
            'completed_at' => now(),
        ]);
    }

    /**
     * Rebuild the Laravel container while retaining the same durable database
     * session. This keeps SQLite in-memory CI coverage meaningful without
     * pretending that a second, empty connection is persisted storage.
     */
    protected function restartApplicationPreservingDatabase(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $readPdo = $connection->getReadPdo();

        $this->app->flush();
        $this->refreshApplication();

        DB::connection()->setPdo($pdo)->setReadPdo($readPdo);
    }

    protected function markArchiBotSetupIncomplete(): void
    {
        AppSetting::query()->delete();
        SetupState::query()->updateOrCreate(['id' => 1], [
            'is_complete' => false,
            'reset_token_hash' => null,
            'reset_token_expires_at' => null,
            'completed_at' => null,
        ]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
