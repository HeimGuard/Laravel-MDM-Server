<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * First start: creates the first user (the system admin) while there is no user yet. Afterwards
 * the page is gone and users are created by a system admin.
 */
class SetupController extends Controller
{
    public function index(): View
    {
        abort_if(User::query()->exists(), 404);

        return view('setup.index', ['locales' => Locales::available()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(User::query()->exists(), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'locale' => ['nullable', Rule::in(array_keys(Locales::available()))],
        ]);
        // The language the setup page is shown in when none is sent.
        $locale = $validated['locale'] ?? App::getLocale();
        unset($validated['locale']);

        // Two requests at the same time must not both create a first user.
        $user = Cache::lock('mdm-setup', 10)->block(5, function () use ($validated, $locale) {
            if (User::query()->exists()) {
                return null;
            }

            $user = User::create($validated);
            // The language of the system admin and the default of everyone without one of their own.
            Locales::setSystem($locale);
            Locales::setForUser($user, $locale);

            return $user;
        });
        abort_if($user === null, 404);
        App::setLocale($locale);

        Auth::login($user);
        $request->session()->regenerate();

        $redirect = redirect()->route('devices');

        // Only when APP_SYSTEM_ADMINS lists other IDs (or the database skipped ID 1).
        if (! $user->is_system_admin) {
            $redirect->with('warning', __('Your account is not a system admin yet. Add its ID :id to APP_SYSTEM_ADMINS.', ['id' => $user->id]));
        }

        return $redirect;
    }
}
