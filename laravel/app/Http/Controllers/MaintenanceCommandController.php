<?php

namespace App\Http\Controllers;

use App\Services\Pipeline\MaintenanceCommandDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MaintenanceCommandController extends Controller
{
    public function poll(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        app(MaintenanceCommandDispatcher::class)->queuePollReconciliation($request, $request->integer('limit'));

        return back()->with('status', 'Polling reconciliation queued.');
    }

    public function reindex(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        app(MaintenanceCommandDispatcher::class)->queueReindex($request, $request->integer('limit'));

        return back()->with('status', 'Reindex queued.');
    }
}
