<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use SteelAnts\LaravelAuth\Traits\Authentication;

class AuthController extends Controller
{
    use Authentication {
        login as protected showLogin;
    }

    protected string $redirectTo = 'devices';

    // Before the first user exists there is nobody to log in: create the account first.
    public function login(Request $request)
    {
        if (! User::query()->exists()) {
            return redirect()->route('setup');
        }

        return $this->showLogin($request);
    }

    // Self-registration is disabled, users are created by a system admin.
    public function register()
    {
        abort(404);
    }

    public function registerPost()
    {
        abort(404);
    }
}
