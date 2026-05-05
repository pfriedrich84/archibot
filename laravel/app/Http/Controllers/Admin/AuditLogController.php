<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->is_admin, 403);

        $logs = AuditLog::query()
            ->with('actor:id,name,email,paperless_username')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'metadata' => $log->metadata ?? [],
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => $log->actor->name,
                    'email' => $log->actor->email,
                    'paperless_username' => $log->actor->paperless_username,
                ] : null,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toISOString(),
            ]);

        return Inertia::render('admin/AuditLogs', [
            'logs' => $logs,
        ]);
    }
}
