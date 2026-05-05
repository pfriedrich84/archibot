<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('admin/Settings', [
            'settings' => [
                'paperless_url' => AppSetting::getValue('paperless.url', ''),
                'audit_retention_days' => (int) AppSetting::getValue('audit.retention_days', '7'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'paperless_url' => ['required', 'url:http,https', 'max:2048'],
            'audit_retention_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $changes = [];
        $settingMap = [
            'paperless_url' => 'paperless.url',
            'audit_retention_days' => 'audit.retention_days',
        ];

        foreach ($settingMap as $inputKey => $settingKey) {
            $oldValue = AppSetting::getValue($settingKey);
            $newValue = (string) Arr::get($validated, $inputKey);

            if ($oldValue !== $newValue) {
                $changes[$settingKey] = ['old' => $oldValue, 'new' => $newValue];
                AppSetting::put($settingKey, $newValue);
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

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->is_admin, 403);
    }
}
