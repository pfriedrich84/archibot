<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['key', 'value', 'encrypted'])]
class AppSetting extends Model
{
    public static function put(string $key, ?string $value, bool $encrypted = false): self
    {
        return self::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $encrypted && $value !== null ? Crypt::encryptString($value) : $value,
                'encrypted' => $encrypted,
            ],
        );
    }

    public static function getValue(string $key, ?string $default = null): ?string
    {
        $setting = self::query()->where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        if ($setting->encrypted && $setting->value !== null) {
            return Crypt::decryptString($setting->value);
        }

        return $setting->value;
    }

    public static function delete(string $key): void
    {
        self::query()->where('key', $key)->delete();
    }
}
