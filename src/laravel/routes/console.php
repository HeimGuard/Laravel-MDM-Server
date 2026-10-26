<?php

use App\Jobs\SyncSecurityFeed;
use App\Models\DeviceCommand;
use App\Models\DeviceMetric;
use App\Support\SecurityFeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [DeviceMetric::class, DeviceCommand::class, App\Models\PingResult::class, App\Models\NetworkNeighbour::class, App\Models\SecurityEvent::class]])->daily();

// Commands the device never finished (it went offline, the agent was stopped) are given up.
Schedule::call(fn () => DeviceCommand::expireStale())->everyFiveMinutes()->name('mdm:expire-commands');

// Remediation scripts with a schedule (cron expression) run on their target devices.
Schedule::call(fn () => App\Models\Script::runScheduled())->everyMinute()->name('mdm:scheduled-scripts')->withoutOverlapping();

// Devices that just went offline show up on the open pages (live updates, no polling).
Schedule::call(fn () => App\Models\Device::announceNewlyOffline())->everyMinute()->name('mdm:announce-offline');

// Alert rules (App\Models\AlertRule): opens and resolves alerts, notifies their users.
Schedule::call(fn () => App\Support\AlertEvaluator::run())->everyMinute()->name('mdm:alerts')->withoutOverlapping();

// The rules of the security rules feed (MDM_SECURITY_FEED_URL), once a day when one is set.
Schedule::job(new SyncSecurityFeed)->dailyAt('03:17')->name('mdm:security-feed')->when(fn () => SecurityFeed::enabled());

// Findings: acknowledged ones expire (when set), the ones of a rule that is gone are resolved, silent devices' ones are stale.
Schedule::call(fn () => App\Support\SecurityScanner::sweep())->dailyAt('03:47')->name('mdm:security-sweep');

// Creates the server signing key when it does not exist yet and shows its fingerprint.
Artisan::command('mdm:signing-key', function () {
    $this->info('Signing key: '.App\Support\Signing::keyPath());
    $this->info('Fingerprint: '.App\Support\Signing::fingerprint());
})->purpose('Create the server signing key (if needed) and show its fingerprint');

// Creates a user or sets a new password, e.g. docker exec -it mdm php artisan mdm:user admin@example.com
Artisan::command('mdm:user {email} {--name= : Name of a new user} {--password= : Password (asked when left out)}', function (string $email) {
    $validator = Illuminate\Support\Facades\Validator::make(['email' => $email], ['email' => 'required|email|max:255']);
    if ($validator->fails()) {
        $this->error($validator->errors()->first('email'));

        return 1;
    }

    $user = App\Models\User::query()->where('email', $email)->first();
    $password = $this->option('password') ?? $this->secret('Password');
    if (strlen((string) $password) < 8) {
        $this->error('The password must be at least 8 characters.');

        return 1;
    }

    if ($user === null) {
        $user = App\Models\User::create([
            'name' => $this->option('name') ?? Illuminate\Support\Str::before($email, '@'),
            'email' => $email,
            'password' => $password,
        ]);
        $this->info("Created user {$user->email} (ID {$user->id}).");
    } else {
        $user->update(['password' => $password]);
        $this->info("Password of {$user->email} (ID {$user->id}) changed.");
    }

    if (!$user->is_system_admin) {
        $this->warn("Not a system admin: add {$user->id} to APP_SYSTEM_ADMINS to allow the system pages and scripts.");
    }

    return 0;
})->purpose('Create a user or set a new password');
