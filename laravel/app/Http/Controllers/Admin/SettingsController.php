<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Services\Ollama\OllamaClient;
use App\Services\Paperless\PaperlessClient;
use App\Services\Settings\PythonRuntimeConfigExporter;
use App\Services\Settings\SettingsCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(Request $request, SettingsCatalog $catalog, ?string $section = null): Response
    {
        $this->authorizeAdmin($request);

        $allGroups = $catalog->groupedForDisplay();
        $sections = collect($allGroups)
            ->map(fn (array $group): array => [
                'name' => $group['name'],
                'slug' => $group['slug'],
                'count' => count($group['settings']),
                'href' => route('admin.settings.edit', ['section' => $group['slug']]),
            ])
            ->push([
                'name' => 'System prompts',
                'slug' => 'prompts',
                'count' => count($this->promptSpecs()),
                'href' => route('admin.settings.edit', ['section' => 'prompts']),
            ])
            ->values()
            ->all();

        $activeSection = $section ?: ($sections[0]['slug'] ?? 'paperless');
        abort_unless(collect($sections)->contains('slug', $activeSection), 404);

        return Inertia::render('admin/Settings', [
            'groups' => $activeSection === 'prompts'
                ? []
                : collect($allGroups)->where('slug', $activeSection)->values()->all(),
            'sections' => $sections,
            'activeSection' => $activeSection,
            'prompts' => $activeSection === 'prompts' ? $this->promptPayloads() : [],
            'paperlessTagOptions' => $this->paperlessTagOptions($request),
        ]);
    }

    public function aiModels(Request $request): array
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'llm_provider' => ['nullable', Rule::in(['ollama', 'openai_compatible'])],
            'ollama_url' => ['nullable', 'url:http,https'],
            'openai_api_key' => ['nullable', 'string'],
        ]);

        $provider = $this->resolveAiProvider($validated);

        try {
            return [
                'items' => app(OllamaClient::class, [
                    'baseUrl' => $provider['base_url'],
                    'provider' => $provider['type'],
                    'apiKey' => $provider['api_key'] ?: null,
                ])->models(),
                'provider' => [
                    'id' => $provider['id'],
                    'label' => $provider['label'],
                    'type' => $provider['type'],
                    'base_url' => $provider['base_url'],
                    'is_cloud' => $provider['is_cloud'],
                ],
            ];
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'llm_provider' => $exception->getMessage(),
            ]);
        }
    }

    public function update(Request $request, SettingsCatalog $catalog): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $definitions = $catalog->definitions();
        $requestedKeys = collect($request->input('__settings_keys', []))
            ->filter(fn (mixed $key): bool => is_string($key) && array_key_exists($key, $definitions))
            ->values();

        if ($requestedKeys->isEmpty()) {
            $requestedKeys = collect(array_keys($definitions));
        }

        $rules = [];

        foreach ($requestedKeys as $key) {
            $definition = $definitions[$key];
            $inputName = $catalog->inputNameForKey($key);
            $type = $definition['type'] ?? 'text';
            $fieldRules = [(bool) ($definition['required'] ?? false) ? 'required' : 'nullable'];

            if ($type === 'url') {
                $fieldRules[] = 'url:http,https';
            } elseif ($type === 'number') {
                $fieldRules[] = 'numeric';

                if (array_key_exists('min', $definition)) {
                    $fieldRules[] = 'min:'.$definition['min'];
                }

                if (array_key_exists('max', $definition)) {
                    $fieldRules[] = 'max:'.$definition['max'];
                }
            } elseif ($type === 'bool') {
                $fieldRules[] = 'boolean';
            } elseif ($type === 'select') {
                $fieldRules[] = Rule::in($definition['options'] ?? []);
            } else {
                $fieldRules[] = 'string';
            }

            $rules[$inputName] = $fieldRules;
        }

        $validated = $request->validate($rules);
        $changes = [];

        foreach ($requestedKeys as $key) {
            $definition = $definitions[$key];
            $inputName = $catalog->inputNameForKey($key);
            $sensitive = (bool) Arr::get($definition, 'sensitive', false);
            $oldValue = AppSetting::getValue($key);
            $newValue = $this->normalizeValue($validated[$inputName] ?? null, (string) ($definition['type'] ?? 'text'));

            if ($sensitive && ($newValue === null || $newValue === '')) {
                continue;
            }

            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'old' => $sensitive ? '[masked]' : $oldValue,
                    'new' => $sensitive ? '[masked]' : $newValue,
                ];
                AppSetting::put($key, $newValue, $sensitive);
            }
        }

        if ($changes !== []) {
            app(PythonRuntimeConfigExporter::class)->export();

            AuditLog::query()->create([
                'actor_user_id' => $request->user()->id,
                'event' => 'admin_settings.updated',
                'target_type' => 'app_settings',
                'metadata' => [
                    'changed_keys' => array_keys($changes),
                    'changes' => $changes,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return back()->with('status', 'settings-updated');
    }

    public function updatePrompt(Request $request, string $prompt): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $spec = $this->promptSpec($prompt);
        abort_if($spec === null, 404);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:80000'],
        ]);

        File::ensureDirectoryExists($this->promptDirectory());
        File::put($this->promptPath($spec['filename']), $validated['content']);

        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => 'admin_prompt.updated',
            'target_type' => 'prompt',
            'target_id' => $prompt,
            'metadata' => ['prompt' => $prompt],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('status', 'prompt-updated');
    }

    public function resetPrompt(Request $request, string $prompt): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $spec = $this->promptSpec($prompt);
        abort_if($spec === null, 404);

        File::delete($this->promptPath($spec['filename']));

        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => 'admin_prompt.reset',
            'target_type' => 'prompt',
            'target_id' => $prompt,
            'metadata' => ['prompt' => $prompt],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('status', 'prompt-reset');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, label: string, type: string, base_url: string, api_key: string, is_cloud: bool}
     */
    private function resolveAiProvider(array $input): array
    {
        return [
            'id' => 'default',
            'label' => 'Configured AI provider',
            'type' => (string) ($input['llm_provider'] ?? AppSetting::getValue('llm.provider', 'ollama')),
            'base_url' => rtrim((string) ($input['ollama_url'] ?? AppSetting::getValue('ollama.url', 'http://ollama:11434')), '/'),
            'api_key' => (string) (($input['openai_api_key'] ?? '') ?: AppSetting::getValue('llm.openai_api_key', '')),
            'is_cloud' => false,
        ];
    }

    /** @return array<int, array{id: int, label: string}> */
    private function paperlessTagOptions(Request $request): array
    {
        $paperlessUrl = AppSetting::getValue('paperless.url');
        $token = $request->user()?->paperless_token;

        if (! $paperlessUrl || ! $token) {
            return [];
        }

        try {
            return collect(app(PaperlessClient::class, ['baseUrl' => $paperlessUrl])->tags($token))
                ->map(fn (array $tag): array => [
                    'id' => (int) $tag['id'],
                    'label' => sprintf('%s (#%s)', $tag['name'] ?? 'Unnamed tag', $tag['id']),
                ])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();
        } catch (\RuntimeException) {
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function promptPayloads(): array
    {
        return collect($this->promptSpecs())
            ->map(function (array $spec): array {
                $path = $this->promptPath($spec['filename']);
                $content = is_file($path) ? File::get($path) : '';

                return [
                    ...$spec,
                    'content' => $content,
                    'has_override' => is_file($path),
                    'update_url' => route('admin.settings.prompts.update', $spec['key']),
                    'reset_url' => route('admin.settings.prompts.reset', $spec['key']),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array{key: string, filename: string, label: string, description: string}> */
    private function promptSpecs(): array
    {
        return [
            ['key' => 'classify', 'filename' => 'classify_system.txt', 'label' => 'Klassifikation', 'description' => 'System prompt for document classification and JSON suggestions.'],
            ['key' => 'classify_judge', 'filename' => 'classify_judge_system.txt', 'label' => 'LLM-as-Judge', 'description' => 'System prompt for optional validation of uncertain classifications.'],
            ['key' => 'ocr_correction', 'filename' => 'ocr_correction_system.txt', 'label' => 'OCR text correction', 'description' => 'System prompt for text-only OCR correction.'],
            ['key' => 'ocr_vision_light', 'filename' => 'ocr_vision_light_system.txt', 'label' => 'OCR vision light', 'description' => 'System prompt for fast vision OCR checks.'],
            ['key' => 'ocr_vision_full', 'filename' => 'ocr_vision_full_system.txt', 'label' => 'OCR vision full', 'description' => 'System prompt for page-by-page vision OCR correction.'],
            ['key' => 'chat', 'filename' => 'chat_system.txt', 'label' => 'RAG Chat', 'description' => 'System prompt for questions about Paperless documents.'],
        ];
    }

    /** @return array{key: string, filename: string, label: string, description: string}|null */
    private function promptSpec(string $key): ?array
    {
        return collect($this->promptSpecs())->firstWhere('key', $key);
    }

    private function promptDirectory(): string
    {
        return (string) config('archibot.data_dir', '/data');
    }

    private function promptPath(string $filename): string
    {
        return $this->promptDirectory().DIRECTORY_SEPARATOR.$filename;
    }

    private function normalizeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return $type === 'bool' ? '0' : null;
        }

        if ($type === 'bool') {
            return filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0';
        }

        return (string) $value;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->is_admin, 403);
    }
}
