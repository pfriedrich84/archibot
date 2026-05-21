<?php

namespace Tests\Feature\Chat;

use App\Models\ChatSession;
use App\Models\User;
use App\Services\Chat\ChatRagResult;
use App\Services\Chat\PythonChatRag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_open_chat_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('chat.page'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('chat/Index')
                ->has('initialPayload.sessions')
                ->has('initialPayload.recent_activity')
            );
    }

    public function test_asking_question_persists_laravel_session_and_messages(): void
    {
        $user = User::factory()->create();
        $this->app->instance(PythonChatRag::class, new class extends PythonChatRag
        {
            public array $history = [];

            public function ask(string $question, array $history): ChatRagResult
            {
                $this->history = $history;

                return new ChatRagResult('Die Antwort.', [
                    ['id' => 42, 'title' => 'Invoice May', 'distance' => 0.123],
                ]);
            }
        });

        $response = $this->actingAs($user)
            ->postJson(route('chat.ask'), ['question' => 'Was steht in der Rechnung?'])
            ->assertOk()
            ->assertJsonPath('answer', 'Die Antwort.')
            ->assertJsonPath('sources.0.title', 'Invoice May');

        $sessionId = $response->json('session_id');
        $this->assertNotEmpty($sessionId);

        $this->assertDatabaseHas('chat_sessions', [
            'id' => $sessionId,
            'user_id' => $user->id,
            'origin' => 'web',
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'chat_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Was steht in der Rechnung?',
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'chat_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Die Antwort.',
        ]);
    }

    public function test_chat_session_list_show_and_delete_are_scoped_to_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $session = ChatSession::query()->create([
            'id' => 'session123456789',
            'user_id' => $user->id,
            'origin' => 'web',
            'title' => 'Question',
            'preview' => 'Answer',
            'last_active_at' => now(),
        ]);
        $session->messages()->create(['role' => 'user', 'content' => 'Question']);
        $session->messages()->create(['role' => 'assistant', 'content' => 'Answer', 'sources' => []]);

        $this->actingAs($user)
            ->getJson(route('chat.index'))
            ->assertOk()
            ->assertJsonPath('sessions.0.id', $session->id)
            ->assertJsonPath('sessions.0.message_count', 2);

        $this->actingAs($user)
            ->getJson(route('chat.show', $session->id))
            ->assertOk()
            ->assertJsonPath('messages.1.content', 'Answer');

        $this->actingAs($otherUser)
            ->getJson(route('chat.show', $session->id))
            ->assertNotFound();

        $this->actingAs($user)
            ->deleteJson(route('chat.destroy', $session->id))
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('chat_sessions', ['id' => $session->id]);
    }

    public function test_chat_session_can_be_deleted_with_method_spoofed_post(): void
    {
        $user = User::factory()->create();
        $session = ChatSession::query()->create([
            'id' => 'session123456789',
            'user_id' => $user->id,
            'origin' => 'web',
            'title' => 'Question',
            'preview' => 'Answer',
            'last_active_at' => now(),
        ]);
        $session->messages()->create(['role' => 'assistant', 'content' => 'Answer', 'sources' => []]);

        $this->actingAs($user)
            ->postJson(route('chat.destroy', $session->id), ['_method' => 'DELETE'])
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('chat_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('chat_messages', ['chat_session_id' => $session->id]);
    }

    public function test_chat_backend_failure_returns_error_without_fake_assistant_message(): void
    {
        $user = User::factory()->create();
        $this->app->instance(PythonChatRag::class, new class extends PythonChatRag
        {
            public function ask(string $question, array $history): ChatRagResult
            {
                throw new \RuntimeException('connection refused');
            }
        });

        $response = $this->actingAs($user)
            ->postJson(route('chat.ask'), ['question' => 'Was steht in der Rechnung?'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Chat backend failed. Please check the AI provider and Paperless configuration.');

        $sessionId = $response->json('session_id');
        $this->assertNotEmpty($sessionId);
        $this->assertDatabaseHas('chat_messages', [
            'chat_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Was steht in der Rechnung?',
        ]);
        $this->assertDatabaseMissing('chat_messages', [
            'chat_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Fehler bei der Verarbeitung. Bitte später erneut versuchen.',
        ]);
    }

    public function test_empty_question_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('chat.ask'), ['question' => '   '])
            ->assertStatus(422);
    }
}
