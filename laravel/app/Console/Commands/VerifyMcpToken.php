<?php

namespace App\Console\Commands;

use App\Models\McpToken;
use Illuminate\Console\Command;

class VerifyMcpToken extends Command
{
    protected $signature = 'archibot:mcp-token-verify {token : Raw MCP token to verify}';

    protected $description = 'Verify a raw MCP token and return linked user context as JSON for the Python MCP runtime.';

    public function handle(): int
    {
        $rawToken = (string) $this->argument('token');
        $hash = McpToken::hashToken($rawToken);

        $token = McpToken::query()
            ->with('user')
            ->where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->first();

        if (! $token || ! $token->user) {
            $this->line(json_encode(['ok' => false, 'error' => 'invalid_token'], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        $this->line(json_encode([
            'ok' => true,
            'user' => [
                'id' => $token->user->id,
                'paperless_user_id' => $token->user->paperless_user_id,
                'paperless_username' => $token->user->paperless_username,
                'is_admin' => (bool) $token->user->is_admin,
            ],
            'token' => [
                'id' => $token->id,
                'name' => $token->name,
            ],
            'permissions' => [
                'mcp_write_enabled' => filter_var(config('archibot.mcp_write_enabled', env('MCP_ENABLE_WRITE', false)), FILTER_VALIDATE_BOOL),
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
