<?php

namespace Tests\Feature\Settings;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class McpTokenVerifyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_verifies_active_token_and_returns_user_context_without_secrets(): void
    {
        Config::set('archibot.mcp_write_enabled', true);
        $plainTextToken = McpToken::generatePlainTextToken();
        $user = User::factory()->create([
            'paperless_user_id' => 42,
            'paperless_username' => 'ada',
            'paperless_token' => 'paperless-secret-token',
            'is_admin' => true,
        ]);
        $token = McpToken::factory()->create([
            'user_id' => $user->id,
            'name' => 'Claude Desktop',
            'token_hash' => McpToken::hashToken($plainTextToken),
        ]);

        $exitCode = Artisan::call('archibot:mcp-token-verify', ['token' => $plainTextToken]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'ok' => true,
            'user' => [
                'id' => $user->id,
                'paperless_user_id' => 42,
                'paperless_username' => 'ada',
                'is_admin' => true,
            ],
            'token' => [
                'id' => $token->id,
                'name' => 'Claude Desktop',
            ],
            'permissions' => [
                'mcp_write_enabled' => true,
            ],
        ], $payload);
        $this->assertNotNull($token->refresh()->last_used_at);
        $this->assertStringNotContainsString($plainTextToken, Artisan::output());
        $this->assertStringNotContainsString('paperless-secret-token', Artisan::output());
    }

    public function test_command_rejects_revoked_or_unknown_tokens(): void
    {
        $plainTextToken = McpToken::generatePlainTextToken();
        McpToken::factory()->create([
            'token_hash' => McpToken::hashToken($plainTextToken),
            'revoked_at' => now(),
        ]);

        $exitCode = Artisan::call('archibot:mcp-token-verify', ['token' => $plainTextToken]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(['ok' => false, 'error' => 'invalid_token'], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }
}
