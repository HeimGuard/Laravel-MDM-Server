<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Remembers when the user logged in last. Written past the model, so neither updated_at
 * nor the audit log change on every login.
 */
class RecordLastLogin
{
    public function handle(Login $event): void
    {
        if (!$event->user instanceof User) {
            return;
        }

        User::query()->whereKey($event->user->getKey())->toBase()->update(['last_login_at' => now()]);
    }
}
