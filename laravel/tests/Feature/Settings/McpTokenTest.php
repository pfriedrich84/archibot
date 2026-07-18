<?php

namespace Tests\Feature\Settings;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class McpTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_own_mcp_tokens(): void
    {
        $user = User::factory()->create();
        $token = McpToken::factory()->create(['user_id' => $user->id, 'name' => 'Desktop']);
        McpToken::factory()->create(['name' => 'Other user token']);

        $this->actingAs($user)
            ->get(route('mcp-tokens.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/McpTokens')
                ->has('tokens', 1)
                ->where('tokens.0.id', $token->id)
                ->where('tokens.0.name', 'Desktop')
                ->where('createdToken', null)
            );
    }

    public function test_user_can_create_mcp_token_that_is_shown_once_and_stored_hashed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('mcp-tokens.store'), ['name' => 'Claude Desktop'])
            ->assertRedirect(route('mcp-tokens.index'))
            ->assertSessionHas('status', "MCP token 'Claude Desktop' created. Copy it now; it will not be shown again.");

        $plainTextToken = $response->getSession()->get('created_mcp_token');
        $this->assertIsString($plainTextToken);
        $this->assertStringStartsWith(McpToken::PREFIX, $plainTextToken);

        $token = McpToken::query()->firstOrFail();
        $this->assertSame($user->id, $token->user_id);
        $this->assertSame('Claude Desktop', $token->name);
        $this->assertSame(McpToken::hashToken($plainTextToken), $token->token_hash);
        $this->assertNotSame($plainTextToken, $token->token_hash);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'event' => 'mcp_token.created',
            'target_type' => 'mcp_token',
            'target_id' => (string) $token->id,
        ]);
    }

    public function test_user_can_revoke_own_mcp_token(): void
    {
        $user = User::factory()->create();
        $token = McpToken::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->delete(route('mcp-tokens.destroy', $token))
            ->assertRedirect(route('mcp-tokens.index'))
            ->assertSessionHas('status', "MCP token '{$token->name}' revoked.");

        $this->assertNotNull($token->refresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'mcp_token.revoked',
            'target_id' => (string) $token->id,
        ]);
    }

    public function test_user_can_not_revoke_another_users_mcp_token(): void
    {
        $user = User::factory()->create();
        $token = McpToken::factory()->create();

        $this->actingAs($user)
            ->delete(route('mcp-tokens.destroy', $token))
            ->assertNotFound();

        $this->assertNull($token->refresh()->revoked_at);
    }
}
