<?php

use App\Jobs\RefreshDockerIcons;
use App\Jobs\SyncSecurityFeed;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\NetworkNeighbour;
use App\Models\Script;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\DockerIconService;
use App\Support\AlertEvaluator;
use App\Support\SecurityFeed;
use App\Support\SecurityScanner;
use App\Support\Signing;
use App\Support\SqliteImport;
use App\Support\ValueRetention;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

Schedule::command('model:prune', ['--model' => [DeviceCommand::class, NetworkNeighbour::class, SecurityEvent::class]])->daily();

// The history of measured values (CPU, memory, ping, ...) is kept as long as the setting history_days says (System > Settings).
Schedule::call(fn () => ValueRetention::prune())->daily()->name('mdm:prune-values');

// Commands the device never finished (it went offline, the agent was stopped) are given up.
Schedule::call(fn () => DeviceCommand::expireStale())->everyFiveMinutes()->name('mdm:expire-commands');

// Remediation scripts with a schedule (cron expression) run on their target devices.
Schedule::call(fn () => Script::runScheduled())->everyMinute()->name('mdm:scheduled-scripts')->withoutOverlapping();

// Devices that just went offline show up on the open pages (live updates, no polling).
Schedule::call(fn () => Device::announceNewlyOffline())->everyMinute()->name('mdm:announce-offline');

// Alert rules (App\Models\AlertRule): opens and resolves alerts, notifies their users.
Schedule::call(fn () => AlertEvaluator::run())->everyMinute()->name('mdm:alerts')->withoutOverlapping();

// The rules of the security rules feed (MDM_SECURITY_FEED_URL), once a day when one is set.
Schedule::job(new SyncSecurityFeed)->dailyAt('03:17')->name('mdm:security-feed')->when(fn () => SecurityFeed::enabled());

// Takes the rules of the feed now, as the Security page does. The files are fetched and applied by jobs of the queue (a worker has to run);
// without --force nothing happens when the version did not change, --queue leaves even the check to the queue.
Artisan::command('mdm:security-feed {--force : Take the rules even when the version did not change} {--queue : Run the check in the queue instead of here}', function () {
    if (! SecurityFeed::enabled()) {
        $this->warn('The security rules feed is not set (MDM_SECURITY_FEED_URL).');

        return 1;
    }
    if ($this->option('queue')) {
        SyncSecurityFeed::dispatch((bool) $this->option('force'));
        $this->info('Queued.');

        return 0;
    }
    $state = SecurityFeed::sync((bool) $this->option('force'));
    $this->info('Feed '.(($state['ok'] ?? false) ? 'ok' : 'not applied yet').(isset($state['version']) ? ', version '.$state['version'] : '').'.');
    if (! empty($state['error'])) {
        $this->warn($state['error']);

        return 1;
    }

    return 0;
})->purpose('Take the rules of the security rules feed now');

// Findings: acknowledged ones expire (when set), the ones of a rule that is gone are resolved, silent devices' ones are stale.
Schedule::call(fn () => SecurityScanner::sweep())->dailyAt('03:47')->name('mdm:security-sweep');

// The icons of the containers (dashboard-icons, selfh.st/icons): the index and the icons of the images the devices run, into the cache.
Schedule::job(new RefreshDockerIcons)->weeklyOn(1, '03:27')->name('mdm:docker-icons');

// Reads the icon databases again and stores the icons of the containers, also the new ones. --force downloads the stored ones again, --queue leaves it to the queue.
Artisan::command('mdm:docker-icons {--force : Download the stored icons again} {--queue : Run in the queue instead of here}', function () {
    if ($this->option('queue')) {
        RefreshDockerIcons::dispatch((bool) $this->option('force'));
        $this->info('Queued.');

        return 0;
    }
    $result = DockerIconService::refresh((bool) $this->option('force'));
    $this->info("Icons of the containers: {$result['icons']}, downloaded: {$result['downloaded']}.");
    foreach ($result['failed'] as $failure) {
        $this->warn($failure);
    }

    return $result['failed'] === [] ? 0 : 1;
})->purpose('Refresh the icons of the containers (index of the icon databases and the icons in use)');

// Creates the server signing key when it does not exist yet and shows its fingerprint.
Artisan::command('mdm:signing-key', function () {
    $this->info('Signing key: '.Signing::keyPath());
    $this->info('Fingerprint: '.Signing::fingerprint());
})->purpose('Create the server signing key (if needed) and show its fingerprint');

// Prints the SQLite file that has to be copied into the PostgreSQL database (the entrypoint does it with pgloader),
// exit code 1 and no output when there is nothing to copy.
Artisan::command('mdm:sqlite-import-source', function () {
    $path = SqliteImport::pending();
    if ($path === null) {
        return 1;
    }
    $this->line($path);

    return 0;
})->purpose('Show the SQLite database to import into PostgreSQL, if any');

// Creates a user or sets a new password, e.g. docker exec -it mdm php artisan mdm:user admin@example.com
Artisan::command('mdm:user {email} {--name= : Name of a new user} {--password= : Password (asked when left out)}', function (string $email) {
    $validator = Validator::make(['email' => $email], ['email' => 'required|email|max:255']);
    if ($validator->fails()) {
        $this->error($validator->errors()->first('email'));

        return 1;
    }

    $user = User::query()->where('email', $email)->first();
    $password = $this->option('password') ?? $this->secret('Password');
    if (strlen((string) $password) < 8) {
        $this->error('The password must be at least 8 characters.');

        return 1;
    }

    if ($user === null) {
        $user = User::create([
            'name' => $this->option('name') ?? Str::before($email, '@'),
            'email' => $email,
            'password' => $password,
        ]);
        $this->info("Created user {$user->email} (ID {$user->id}).");
    } else {
        $user->update(['password' => $password]);
        $this->info("Password of {$user->email} (ID {$user->id}) changed.");
    }

    if (! $user->is_system_admin) {
        $this->warn("Not a system admin: add {$user->id} to APP_SYSTEM_ADMINS to allow the system pages and scripts.");
    }

    return 0;
})->purpose('Create a user or set a new password');
