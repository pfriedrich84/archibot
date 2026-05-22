<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Services\Chat\PythonChatRag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ChatController extends Controller
{
    public function page(Request $request): Response
    {
        return Inertia::render('chat/Index', [
            'initialPayload' => $this->snapshot($request),
            'endpoints' => [
                'index' => route('chat.index', [], false),
                'ask' => route('chat.ask', [], false),
                'session' => route('chat.show', ['session' => '__SESSION__'], false),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->snapshot($request));
    }

    public function show(Request $request, string $session): JsonResponse
    {
        $chatSession = $this->userSession($request, $session);
        abort_if($chatSession === null, 404);

        $chatSession->touchActivity();

        return response()->json($this->serializeSession($chatSession->load([
            'messages' => fn ($query) => $query->oldest('id'),
        ])));
    }

    public function ask(Request $request, PythonChatRag $rag): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:4000'],
            'session_id' => ['nullable', 'string', 'max:32'],
        ]);

        $question = trim($validated['question']);
        if ($question === '') {
            return response()->json(['message' => 'Question is required.'], 422);
        }

        $chatSession = null;
        if (! empty($validated['session_id'])) {
            $chatSession = $this->userSession($request, $validated['session_id']);
        }

        if (! $chatSession) {
            $chatSession = ChatSession::query()->create([
                'id' => Str::random(16),
                'user_id' => $request->user()->id,
                'origin' => 'web',
                'title' => $this->titleFromQuestion($question),
                'preview' => $question,
                'last_active_at' => now(),
            ]);
        }

        $history = $chatSession->messages()
            ->latest('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn ($message) => array_filter([
                'role' => $message->role,
                'content' => $message->content,
                'sources' => $message->sources,
            ], fn ($value) => $value !== null))
            ->values()
            ->all();

        $chatSession->messages()->create([
            'role' => 'user',
            'content' => $question,
        ]);

        try {
            $result = $rag->ask($question, $history);
            $answer = $result->answer;
            $sources = $result->sources;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Chat backend failed. Please check the AI provider and Paperless configuration.',
                'session_id' => $chatSession->id,
            ], 502);
        }

        $chatSession->messages()->create([
            'role' => 'assistant',
            'content' => $answer,
            'sources' => $sources,
        ]);

        $firstUserContent = (string) ($chatSession->messages()
            ->where('role', 'user')
            ->oldest('id')
            ->value('content') ?? $question);

        $chatSession->forceFill([
            'title' => $this->titleFromQuestion($firstUserContent),
            'preview' => Str::limit(preg_replace('/\s+/', ' ', $answer) ?: $question, 120),
            'last_active_at' => now(),
        ])->save();

        $this->trimHistory($chatSession);

        return response()->json([
            'session_id' => $chatSession->id,
            'answer' => $answer,
            'sources' => $sources,
        ]);
    }

    public function destroy(Request $request, string $session): JsonResponse
    {
        $chatSession = $this->userSession($request, $session);
        abort_if($chatSession === null, 404);

        $chatSession->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array{sessions:mixed,recent_activity:array<int, mixed>} */
    private function snapshot(Request $request): array
    {
        return [
            'sessions' => ChatSession::query()
                ->where('user_id', $request->user()->id)
                ->whereHas('messages', fn ($query) => $query->where('role', 'assistant'))
                ->withCount('messages')
                ->latest('last_active_at')
                ->limit((int) min(100, max(1, (int) $request->integer('limit', 8))))
                ->get()
                ->map(fn (ChatSession $session) => $this->serializeSummary($session))
                ->values(),
            'recent_activity' => [],
        ];
    }

    private function userSession(Request $request, string $session): ?ChatSession
    {
        return ChatSession::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($session)
            ->first();
    }

    /** @return array<string, mixed> */
    private function serializeSummary(ChatSession $session): array
    {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'preview' => $session->preview ?? '',
            'origin' => $session->origin,
            'last_active' => $session->last_active_at?->toISOString(),
            'message_count' => $session->messages_count ?? $session->messages()->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSession(ChatSession $session): array
    {
        return [
            ...$this->serializeSummary($session),
            'messages' => $session->messages->map(fn ($message) => [
                'role' => $message->role,
                'content' => $message->content,
                'sources' => $message->sources ?? [],
            ])->values(),
        ];
    }

    private function titleFromQuestion(string $question): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', trim($question)) ?: 'Neuer Chat', 80);
    }

    private function trimHistory(ChatSession $session): void
    {
        $overflow = max(0, $session->messages()->count() - 20);
        if ($overflow === 0) {
            return;
        }

        $idsToDelete = $session->messages()->oldest('id')->limit($overflow)->pluck('id');
        $session->messages()->whereIn('id', $idsToDelete)->delete();
    }
}
