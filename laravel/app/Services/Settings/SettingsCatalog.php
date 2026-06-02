<?php

namespace App\Services\Settings;

use App\Models\AppSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SettingsCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        /** @var array<string, array<string, mixed>> $definitions */
        $definitions = config('archibot_settings.definitions', []);

        return $definitions;
    }

    /**
     * @return array<int, array{name: string, settings: array<int, array<string, mixed>>}>
     */
    public function groupedForDisplay(): array
    {
        return collect($this->definitions())
            ->reject(fn (array $definition): bool => (bool) ($definition['hidden'] ?? false))
            ->map(function (array $definition, string $key): array {
                $sensitive = (bool) ($definition['sensitive'] ?? false);
                $stored = AppSetting::query()->where('key', $key)->first();

                return [
                    'key' => $key,
                    'input_name' => Str::slug(str_replace('.', '_', $key), '_'),
                    'group' => $definition['group'],
                    'group_slug' => Str::slug($definition['group']),
                    'label' => $definition['label'],
                    'type' => $definition['type'] ?? 'text',
                    'options' => $definition['options'] ?? [],
                    'required' => (bool) ($definition['required'] ?? false),
                    'sensitive' => $sensitive,
                    'has_value' => $stored !== null && $stored->value !== null && $stored->value !== '',
                    'value' => $sensitive ? '' : AppSetting::getValue($key, (string) ($definition['default'] ?? '')),
                    'help' => $definition['help'] ?? null,
                    'min' => $definition['min'] ?? null,
                    'max' => $definition['max'] ?? null,
                    'step' => $definition['step'] ?? null,
                    'entity' => $definition['entity'] ?? null,
                ];
            })
            ->groupBy('group')
            ->map(fn (Collection $settings, string $group): array => [
                'name' => $group,
                'slug' => Str::slug($group),
                'settings' => $settings->values()->all(),
            ])
            ->values()
            ->all();
    }

    public function inputNameForKey(string $key): string
    {
        return Str::slug(str_replace('.', '_', $key), '_');
    }
}
