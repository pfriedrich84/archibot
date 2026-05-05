<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\SetupState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResetSetup extends Command
{
    protected $signature = 'archibot:setup-reset';

    protected $description = 'Reset first-run setup and print a temporary setup token valid for 10 minutes.';

    public function handle(): int
    {
        $token = Str::random(48);
        $state = SetupState::current();

        $state->forceFill([
            'is_complete' => false,
            'reset_token_hash' => Hash::make($token),
            'reset_token_expires_at' => now()->addMinutes(10),
            'completed_at' => null,
        ])->save();

        AuditLog::query()->create([
            'event' => 'setup.reset',
            'target_type' => 'setup_state',
            'target_id' => (string) $state->id,
            'metadata' => ['expires_at' => $state->reset_token_expires_at?->toIso8601String()],
        ]);

        $this->line('Setup has been reset. Use this token within 10 minutes:');
        $this->line($token);

        return self::SUCCESS;
    }
}
