<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\McpToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class McpTokenController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/McpTokens', [
            'tokens' => $request->user()->mcpTokens()
                ->latest()
                ->get()
                ->map(fn (McpToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'last_used_at' => $token->last_used_at?->toISOString(),
                    'revoked_at' => $token->revoked_at?->toISOString(),
                    'created_at' => $token->created_at?->toISOString(),
                ]),
            'createdToken' => session('created_mcp_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $plainTextToken = McpToken::generatePlainTextToken();
        $token = $request->user()->mcpTokens()->create([
            'name' => $validated['name'],
            'token_hash' => McpToken::hashToken($plainTextToken),
        ]);

        $this->audit($request, $token, 'mcp_token.created');

        return redirect()
            ->route('mcp-tokens.index')
            ->with('created_mcp_token', $plainTextToken)
            ->with('status', "MCP token '{$token->name}' created. Copy it now; it will not be shown again.");
    }

    public function destroy(Request $request, McpToken $mcpToken): RedirectResponse
    {
        abort_unless($mcpToken->user_id === $request->user()->id, 404);
        abort_if($mcpToken->revoked_at !== null, 409);

        $mcpToken->revoke();
        $this->audit($request, $mcpToken, 'mcp_token.revoked');

        return redirect()->route('mcp-tokens.index')
            ->with('status', "MCP token '{$mcpToken->name}' revoked.");
    }

    private function audit(Request $request, McpToken $token, string $event): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => $event,
            'target_type' => 'mcp_token',
            'target_id' => (string) $token->id,
            'metadata' => ['name' => $token->name],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
