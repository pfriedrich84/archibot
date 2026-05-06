<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Services\Settings\SettingsCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
