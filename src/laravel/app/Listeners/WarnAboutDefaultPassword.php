<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Hash;

/**
 * Installations before the setup page got a default account from a migration
 * (the-email@example.com / the-password-of-choice). Its password is public: ask to change it.
 */
class WarnAboutDefaultPassword
{
    public function handle(Login $event): void
    {
        $password = $event->user->getAuthPassword();

        if (! is_string($password) || ! Hash::check('the-password-of-choice', $password)) {
            return;
        }

        session()->flash('warning', __('You are using the default password. Change it in your profile now.'));
    }
}
