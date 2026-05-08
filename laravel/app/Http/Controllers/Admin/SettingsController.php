<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Services\Settings\SettingsCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(Request $request, SettingsCatalog $catalog): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('admin/Settings', [
            'groups' => $catalog->groupedForDisplay(),
            'prompts' => $this->promptPayloads(),
        ]);
    }

    public function update(Request $request, SettingsCatalog $catalog): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $definitions = $catalog->definitions();
        $rules = [];

        foreach ($definitions as $key => $definition) {
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

        foreach ($definitions as $key => $definition) {
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
