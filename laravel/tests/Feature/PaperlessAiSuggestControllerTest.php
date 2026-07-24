<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaperlessAiSuggestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_configured_bearer_token(): void
    {
        AppSetting::put('paperless.ai_bearer_key', 'paperless-secret');

        $this->postJson(route('paperless-ai.suggest'), [
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ])->assertStatus(401);
    }

    public function test_proxies_to_openai_compatible_provider(): void
    {
        AppSetting::put('paperless.ai_bearer_key', 'paperless-secret');
        AppSetting::put('paperless.ai_suggest_enabled', '1');
        AppSetting::put('llm.provider', 'openai_compatible');
        AppSetting::put('llm.openai_base_url', 'http://openai.test/v1');
        AppSetting::put('llm.openai_api_key', 'provider-secret');
        AppSetting::put('classification.model', 'safe-model');

        Http::fake([
            'http://openai.test/v1/chat/completions' => Http::response([
                'id' => 'cmpl-1',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => '{"ok":true}'],
                    'finish_reason' => 'stop',
                ]],
            ]),
        ]);

        $this->withHeader('Authorization', 'Bearer paperless-secret')
            ->postJson(route('paperless-ai.suggest'), [
                'messages' => [['role' => 'user', 'content' => 'classify this']],
            ])
            ->assertOk()
            ->assertJsonPath('choices.0.message.content', '{"ok":true}');

        Http::assertSent(fn ($request) => $request->url() === 'http://openai.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer provider-secret')
            && $request['model'] === 'safe-model');
    }

    public function test_model_override_is_rejected(): void
    {
        AppSetting::put('paperless.ai_bearer_key', 'paperless-secret');
        AppSetting::put('paperless.ai_suggest_enabled', '1');
        AppSetting::put('classification.model', 'safe-model');

        $this->withHeader('Authorization', 'Bearer paperless-secret')
            ->postJson(route('paperless-ai.suggest'), [
                'model' => 'other-model',
                'messages' => [['role' => 'user', 'content' => 'classify this']],
            ])
            ->assertStatus(422);
    }

    public function test_disabled_suggest_fails_closed(): void
    {
        AppSetting::put('paperless.ai_bearer_key', 'paperless-secret');
        AppSetting::put('paperless.ai_suggest_enabled', '0');

        $this->withHeader('Authorization', 'Bearer paperless-secret')
            ->postJson(route('paperless-ai.suggest'), [
                'messages' => [['role' => 'user', 'content' => 'classify this']],
            ])
            ->assertStatus(409);
    }
}
