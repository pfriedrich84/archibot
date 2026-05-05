<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['is_complete', 'reset_token_hash', 'reset_token_expires_at', 'completed_at'])]
class SetupState extends Model
{
    protected function casts(): array
    {
        return [
            'is_complete' => 'boolean',
            'reset_token_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1]);
    }

    public function requiresResetToken(): bool
    {
        return $this->reset_token_hash !== null
            && $this->reset_token_expires_at !== null
            && $this->reset_token_expires_at->isFuture();
    }
}
