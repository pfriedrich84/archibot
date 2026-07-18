<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

final class OperatorPrincipal
{
    public const LOCAL_OPERATOR = 'local_operator';

    public const SYSTEM_SCHEDULER = 'system_scheduler';

    public static function markLocalOperator(Request $request): Request
    {
        $request->attributes->set('archibot_principal', self::LOCAL_OPERATOR);

        return $request;
    }

    public static function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    public static function userId(Request $request): ?int
    {
        return self::user($request)?->id;
    }

    public static function name(Request $request): string
    {
        return (string) ($request->attributes->get('archibot_principal')
            ?? (self::user($request) ? 'authenticated_user' : 'unknown'));
    }

    /** @return array{actor_principal: string, actor_user_id: int|null} */
    public static function metadata(Request $request): array
    {
        return [
            'actor_principal' => self::name($request),
            'actor_user_id' => self::userId($request),
        ];
    }
}
