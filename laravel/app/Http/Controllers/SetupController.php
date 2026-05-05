<?php

namespace App\Http\Controllers;

use App\Models\SetupState;
use App\Services\Setup\CompleteSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class SetupController extends Controller
{
    public function show(): Response
    {
        $state = SetupState::current();
        abort_if($state->is_complete, 404);

        return Inertia::render('Setup/Index', [
            'requiresResetToken' => $state->requiresResetToken(),
        ]);
    }

    public function store(Request $request, CompleteSetup $completeSetup): RedirectResponse
    {
        $state = SetupState::current();
        abort_if($state->is_complete, 404);

        $validated = $request->validate([
            'paperless_url' => ['required', 'url'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'setup_token' => [$state->requiresResetToken() ? 'required' : 'nullable', 'string'],
        ]);

        if ($state->requiresResetToken() && ! Hash::check($validated['setup_token'], $state->reset_token_hash)) {
            throw ValidationException::withMessages([
                'setup_token' => 'The setup token is invalid or expired.',
            ]);
        }

        try {
            $completeSetup->handle([
                'paperless_url' => $validated['paperless_url'],
                'username' => $validated['username'],
                'password' => $validated['password'],
            ], $request);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'paperless_url' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('dashboard');
    }
}
