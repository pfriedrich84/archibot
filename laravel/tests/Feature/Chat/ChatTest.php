<?php

namespace Tests\Feature\Chat;

use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_routes_are_not_registered_for_non_admins_and_execute_nothing(): void
    {
        $this->assertChatIsUnavailableFor(User::factory()->create(['is_admin' => false]));
    }

    public function test_chat_routes_are_not_registered_for_admins_and_execute_nothing(): void
    {
        $this->assertChatIsUnavailableFor(User::factory()->create(['is_admin' => true]));
    }

    public function test_existing_chat_rows_are_preserved_but_not_exposed(): void
    {
        $owner = User::factory()->create();
        $session = ChatSession::query()->create([
            'id' => 'preserved-session',
            'user_id' => $owner->id,
            'origin' => 'web',
            'title' => 'Preserved title',
            'preview' => 'Preserved preview',
            'last_active_at' => now(),
        ]);
        $message = $session->messages()->create([
            'role' => 'user',
            'content' => 'Synthetic retained chat content',
            'sources' => [],
        ]);

        $this->actingAs($owner)->get('/chat')->assertNotFound();
        $this->actingAs($owner)->getJson("/api/v1/chat/sessions/{$session->id}")
            ->assertNotFound()
            ->assertDontSee('Synthetic retained chat content');

        $this->assertDatabaseHas('chat_sessions', ['id' => $session->id]);
        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'chat_session_id' => $session->id,
            'content' => 'Synthetic retained chat content',
        ]);
    }

    private function assertChatIsUnavailableFor(User $user): void
    {
        Process::fake();
        Http::fake();

        foreach (['chat.page', 'chat.index', 'chat.ask', 'chat.show', 'chat.destroy'] as $name) {
            $this->assertFalse(Route::has($name), "Route {$name} must not be registered.");
        }

        $requests = [
            fn (): TestResponse => $this->actingAs($user)->get('/chat'),
            fn (): TestResponse => $this->actingAs($user)->getJson('/api/v1/chat'),
            fn (): TestResponse => $this->actingAs($user)->postJson('/api/v1/chat/ask', [
                'question' => 'Synthetic question',
            ]),
            fn (): TestResponse => $this->actingAs($user)->getJson('/api/v1/chat/sessions/missing'),
            fn (): TestResponse => $this->actingAs($user)->deleteJson('/api/v1/chat/sessions/missing'),
        ];

        foreach ($requests as $request) {
            $request()->assertNotFound();
        }

        Process::assertNothingRan();
        Http::assertNothingSent();
    }
}
