<?php

namespace App\Support;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\ScriptRun;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What needs attention on a device, most severe first, each with the action that fixes it where
 * there is one (restart, update the agent, install the updates, retry a failed command).
 *
 * An alert: key, severity (danger, warning, info, secondary), icon, title, message, action
 * (command, params, label, icon, confirm) or null, active (the command of the action on its way)
 * and tab (the device tab with the details).
 */
class SmartAlerts
{
    public const SEVERITIES = ['danger' => 0, 'warning' => 1, 'info' => 2, 'secondary' => 3];

    /** Drives this full (percent) raise a warning, from DISK_DANGER on an error. */
    public const DISK_WARNING = 90;

    public const DISK_DANGER = 95;

    /** Failed commands are shown this long (seconds), unless the command ran again since. */
    public const FAILED_COMMAND_WINDOW = 86400;

    /**
     * @param  Collection<int, DeviceCommand>|null  $commands  the device's commands of the last day (loaded when null)
     */
    public static function for(Device $device, ?Collection $commands = null): array
    {
        // Ping-only devices have no report: only a failed wake.
        if (empty($device->data) && ! $device->isPingOnly) {
            return [];
        }
        $commands ??= self::recentCommands($device);
        $active = $commands->filter->active;
        $alerts = [];

        // The last wake of this device (sent by another agent) failed and it is still offline.
        $wake = $device->offline ? self::lastWake($device) : null;
        if ($wake && in_array($wake->status, ['failed', 'expired'], true)) {
            $alerts[] = [
                'key' => 'wake',
                'severity' => 'danger',
                'icon' => 'fas fa-sun',
                'title' => __('Wake failed'),
                'message' => ($wake->displayMessage ?: $wake->statusLabel).' · '.__('through :relay', ['relay' => $wake->device?->displayName ?? '?']).' · '.$wake->updated_at->diffForHumans(),
                'action' => ['command' => 'wake', 'label' => __('Try again'), 'icon' => 'fas fa-redo'],
            ];
        }

        // A ping-only device with no MAC, but network discovery sees one at its address: offer to
        // adopt it, so the device is known (not shown as unknown) and follows its MAC over DHCP.
        if ($device->isPingOnly && empty($device->ping_mac) && ($mac = Device::discoveredMacFor($device->ping_address))) {
            $alerts[] = [
                'key' => 'mac',
                'severity' => 'info',
                'icon' => 'fas fa-ethernet',
                'title' => __('A MAC address was found for this device'),
                'message' => __('Network discovery sees :mac at :ip.', ['mac' => $mac['mac'].($mac['vendor'] ? ' ('.$mac['vendor'].')' : ''), 'ip' => $device->ping_address])
                    .' '.__('Add it so the device is known and follows its address when it changes.'),
                'action' => ['command' => 'adoptMac', 'label' => __('Add the MAC address'), 'icon' => 'fas fa-ethernet'],
            ];
        }

        // Its MAC is at another address now: the portal asks before it pings that one instead.
        if ($moved = $device->movedAddress()) {
            $alerts[] = [
                'key' => 'address',
                'severity' => 'warning',
                'icon' => 'fas fa-random',
                'title' => __('The device has another address'),
                'message' => __('Network discovery sees :mac at :to (:time), the portal pings :from.', [
                    'mac' => $device->ping_mac, 'to' => $moved['ip'], 'from' => $device->ping_address, 'time' => $moved['seen']->diffForHumans(),
                ]).' '.__('Reserve the address in the router (static DHCP lease) so it does not change again.'),
                'action' => ['command' => 'moveAddress', 'params' => ['ip' => $moved['ip']], 'label' => __('Use :ip', ['ip' => $moved['ip']]), 'icon' => 'fas fa-check',
                    'confirm' => __('Ping :device at :ip from now on?', ['device' => $device->displayName, 'ip' => $moved['ip']])],
                // A dismissal is for this address: another one asks again.
                'revision' => $moved['ip'],
            ];
        }

        if (empty($device->data)) {
            return self::finish($device, $alerts, $active);
        }

        if ($device->offline) {
            $alerts[] = [
                'key' => 'offline',
                'severity' => 'secondary',
                'icon' => 'fas fa-plug',
                'title' => __('Device is offline'),
                'message' => __('Last seen :time', ['time' => ($device->last_seen_at ?? $device->updated_at)?->diffForHumans()]),
            ];
        }

        if ($device->restartPending) {
            $alerts[] = [
                'key' => 'restart',
                'severity' => 'warning',
                'icon' => 'fas fa-redo',
                'title' => __('Restart required'),
                'message' => __('Installed updates take effect after a restart.'),
                'action' => ['command' => 'restart', 'label' => __('Restart'), 'icon' => 'fas fa-redo', 'confirm' => __('Restart :device now? Unsaved work of its users is lost.', ['device' => $device->displayName])],
            ];
        }

        if ($device->agentOutdated) {
            $canUpdate = $device->agentUpdatable && self::remoteUpdateAvailable();
            $alerts[] = [
                'key' => 'agent',
                'severity' => $device->signsRequests ? 'warning' : 'danger',
                'icon' => 'fas fa-robot',
                'title' => __('A newer agent is available'),
                'message' => __(':current → :latest', ['current' => $device->agentVersion ?? __('unknown'), 'latest' => AgentScript::version()]).' · '.match (true) {
                    ! $device->agentUpdatable => __('This agent cannot update itself, reinstall it with the command from Add device.'),
                    ! self::remoteUpdateAvailable() => __('Remote update needs the portal on HTTPS.'),
                    ! $device->signsRequests => __('The agent does not sign its communication, only the agent update can be sent to it.'),
                    default => __('The agent updates itself and restarts, the device keeps running.'),
                },
                'action' => $canUpdate ? ['command' => 'updateAgent', 'label' => __('Update agent'), 'icon' => 'fas fa-download'] : null,
                'copy' => $device->agentUpdatable ? InstallCommands::update($device) : null,
                // A dismissal is for these versions: a newer release (or another version on the device) shows it again.
                'revision' => ($device->agentVersion ?? '').'→'.AgentScript::version(),
            ];
        }

        $os = count($device->installableUpdates);
        $apps = count($device->apps_packages_updates);
        $modules = count($device->moduleUpdates);
        if ($os + $apps + $modules > 0) {
            $alerts[] = [
                'key' => 'updates',
                'severity' => $os > 0 ? 'warning' : 'info',
                'icon' => 'fas fa-sync',
                'title' => trans_choice(':count update available|:count updates available', $os + $apps + $modules),
                'message' => collect([
                    $os ? __(':count operating system', ['count' => $os]) : null,
                    $apps ? __(':count applications', ['count' => $apps]) : null,
                    $modules ? __(':count PowerShell modules', ['count' => $modules]) : null,
                ])->filter()->implode(', '),
                'action' => ['command' => 'doUpdates', 'label' => __('Install all'), 'icon' => 'fas fa-download', 'confirm' => __('Install all updates on :device?', ['device' => $device->displayName])],
                'tab' => 'updates',
            ];
        }

        $full = collect($device->drives)->filter(fn ($drive) => ($drive['PercentUsed'] ?? 0) >= self::DISK_WARNING);
        if ($full->isNotEmpty()) {
            $alerts[] = [
                'key' => 'disk_space',
                'severity' => $full->max('PercentUsed') >= self::DISK_DANGER ? 'danger' : 'warning',
                'icon' => 'fas fa-hdd',
                'title' => __('Low disk space'),
                'message' => $full->map(fn ($drive) => __(':drive :percent % full, :free free', ['drive' => $drive['DriveLetter'] ?? $drive['FriendlyName'] ?? '?', 'percent' => $drive['PercentUsed'], 'free' => Bytes::format($drive['SizeRemaining'] ?? 0)]))->implode(', '),
                'tab' => 'drives',
            ];
        }

        if ($device->showDiskHealth && $device->diskHealthProblem) {
            $alerts[] = [
                'key' => 'disk_health',
                'severity' => 'danger',
                'icon' => 'fas fa-heartbeat',
                'title' => __('A disk reports a problem'),
                'message' => collect($device->diskHealth['disks'])->filter(fn ($disk) => in_array($disk['Health'] ?? null, ['failed', 'warning'], true))->map(fn ($disk) => $disk['Model'] ?? $disk['Device'] ?? '?')->implode(', '),
                'tab' => 'drives',
            ];
        }

        $failedServices = collect($device->services)->where('State', 'failed');
        if ($failedServices->isNotEmpty()) {
            $alerts[] = [
                'key' => 'services',
                'severity' => 'warning',
                'icon' => 'fas fa-cogs',
                'title' => trans_choice(':count service failed|:count services failed', $failedServices->count()),
                'message' => $failedServices->pluck('Name')->take(5)->implode(', ').($failedServices->count() > 5 ? ' …' : ''),
                'tab' => 'services',
            ];
        }

        // Open findings of the security scanner nobody acknowledged (from medium severity).
        $findings = $device->securityFindings()->active()->atLeast('medium')->bySeverity()->get();
        if ($findings->isNotEmpty()) {
            $severe = $findings->whereIn('severity', ['critical', 'high'])->isNotEmpty();
            $alerts[] = [
                'key' => 'security',
                'severity' => $severe ? 'danger' : 'warning',
                'icon' => 'fas fa-shield-alt',
                'title' => trans_choice(':count security finding|:count security findings', $findings->count()),
                'message' => $findings->take(3)->pluck('message')->implode(' · ').($findings->count() > 3 ? ' …' : ''),
                'tab' => 'security',
            ];
        }

        // Errors from the agent's log (agents 1.13.2+), newest first.
        $errors = $device->recentAgentErrors;
        if ($errors !== []) {
            $describe = fn ($error) => $error['message'].' · '.trans_choice(':count time|:count times', $error['count']).', '.\Illuminate\Support\Carbon::createFromTimestamp($error['last'], config('app.timezone'))->diffForHumans();
            $alerts[] = [
                'key' => 'agent_errors',
                'severity' => 'warning',
                'icon' => 'fas fa-bug',
                'title' => trans_choice('The agent reported :count error|The agent reported :count errors', count($errors)),
                'message' => $describe($errors[0]),
                'details' => count($errors) > 1 ? array_map($describe, array_slice($errors, 1)) : [],
                'action' => ['command' => 'clearAgentErrors', 'label' => __('Clear'), 'icon' => 'fas fa-check'],
            ];
        }

        $failedScripts = $device->scriptRuns()->with('script')
            ->whereIn('id', ScriptRun::query()->selectRaw('max(id)')->where('device_id', $device->id)->groupBy('script_id'))
            ->whereIn('status', ['failed', 'error', 'rejected'])->get();
        if ($failedScripts->isNotEmpty()) {
            $alerts[] = [
                'key' => 'scripts',
                'severity' => 'danger',
                'icon' => 'fas fa-scroll',
                'title' => trans_choice(':count remediation failed|:count remediations failed', $failedScripts->count()),
                'message' => $failedScripts->map(fn ($run) => $run->script?->name)->filter()->implode(', '),
                'tab' => 'scripts',
            ];
        }

        // Scripts with manual remediation whose latest run here found something to remediate.
        $toRemediate = $device->scriptRuns()->with('script')
            ->whereIn('id', ScriptRun::query()->selectRaw('max(id)')->where('device_id', $device->id)->groupBy('script_id'))
            ->where('status', 'noncompliant')->get()
            ->filter(fn ($run) => $run->script?->remediation !== null);
        foreach ($toRemediate as $run) {
            $alerts[] = [
                'key' => 'remediate:'.$run->script_id,
                'severity' => 'warning',
                'icon' => 'fas fa-magic',
                'title' => __(':script needs remediation', ['script' => $run->script->name]),
                'message' => __('Detected :time, the remediation runs when you start it.', ['time' => ($run->finished_at ?? $run->updated_at)->diffForHumans()]),
                'action' => ['command' => 'remediate', 'params' => ['script' => $run->script_id], 'label' => __('Remediate'), 'icon' => 'fas fa-magic',
                    'confirm' => __('Run the remediation of :script on :device now?', ['script' => $run->script->name, 'device' => $device->displayName])],
                'tab' => 'scripts',
            ];
        }

        // The last run of each command (and target) that failed, unless it ran again since.
        foreach ($commands->groupBy(fn ($command) => $command->command.'|'.$command->target) as $runs) {
            $last = $runs->sortByDesc('id')->first();
            // A wake is about the device it wakes: its alert is there.
            if (! in_array($last->status, ['failed', 'expired'], true) || in_array($last->command, DeviceCommand::UNTRACKED, true) || $last->command === 'wake') {
                continue;
            }
            $failure = ($last->message ?: $last->statusLabel).' · '.$last->updated_at->diffForHumans();
            // An alert with the same action (e.g. the agent is still outdated) tells it there.
            foreach ($alerts as &$alert) {
                if (($alert['action']['command'] ?? null) === $last->command && ($alert['action']['params'] ?? []) == ($last->params ?? [])) {
                    $alert['failure'] = $failure;

                    continue 2;
                }
            }
            unset($alert);
            $alerts[] = [
                'key' => 'failed:'.$last->id,
                'severity' => 'danger',
                'icon' => $last->icon,
                'title' => __(':command failed', ['command' => $last->label]),
                'message' => ($last->message ?: $last->statusLabel).' · '.$last->updated_at->diffForHumans(),
                'action' => ['command' => $last->command, 'params' => $last->params ?? [], 'label' => __('Try again'), 'icon' => 'fas fa-redo'],
                'tab' => 'history',
            ];
        }

        return self::finish($device, $alerts, $active);
    }

    /** The defaults, the commands on their way and the refusals; without the dismissed ones, sorted. */
    private static function finish(Device $device, array $alerts, Collection $active): array
    {
        foreach ($alerts as &$alert) {
            $alert += ['message' => null, 'details' => [], 'action' => null, 'tab' => null, 'copy' => null, 'failure' => null, 'refusal' => null];
            $alert['active'] = null;
            if (in_array($alert['action']['command'] ?? null, ['clearAgentErrors', 'adoptMac', 'moveAddress'], true)) {
                // Not a command for the device: done on the server right away.
                $alert['action'] += ['params' => [], 'confirm' => null];
            } elseif (($alert['action']['command'] ?? null) === 'remediate') {
                // A full run of the script (remediation scripts are for system admins).
                $alert['action'] += ['params' => [], 'confirm' => null];
                $script = Script::find($alert['action']['params']['script'] ?? null);
                $alert['refusal'] = match (true) {
                    $script === null => __('Nothing to do anymore.'),
                    ! \Illuminate\Support\Facades\Gate::allows('is-system-admin') => __('Only system admins run scripts'),
                    default => $script->unavailableReason($device),
                };
            } elseif (($alert['action']['command'] ?? null) === 'wake') {
                // Sent by another agent; the alert is only there while its last wake failed (nothing
                // on its way), so only the refusal of the wake itself.
                $alert['action'] += ['params' => [], 'confirm' => null];
                $alert['refusal'] = $device->wakeRefusal();
            } elseif ($alert['action']) {
                $alert['action'] += ['params' => [], 'confirm' => null];
                // The command on its way (also a doUpdates for a single update).
                $alert['active'] = Device::findActive($active, $alert['action']['command'], $alert['action']['params'] ?: null)
                    ?? ($alert['action']['command'] === 'installUpdate' ? Device::findActive($active, 'doUpdates') : null);
                $alert['refusal'] = $device->commandRefusal($alert['action']['command'], $alert['action']['params']);
            }
        }
        unset($alert);

        $alerts = self::withoutDismissed($device, $alerts);

        // Offline first: it is why no action can run now. Then the most severe.
        usort($alerts, fn ($a, $b) => [$a['key'] !== 'offline', self::SEVERITIES[$a['severity']]] <=> [$b['key'] !== 'offline', self::SEVERITIES[$b['severity']]]);

        return $alerts;
    }

    /** The latest wake of the device of the last day (a command of the agent that sent it). */
    private static function lastWake(Device $device): ?DeviceCommand
    {
        return DeviceCommand::query()->with('device')->where('command', 'wake')->where('target', 'device:'.$device->id)
            ->where('updated_at', '>=', now()->subSeconds(self::FAILED_COMMAND_WINDOW))->latest('id')->first();
    }

    /**
     * What the alert says: a dismissal holds until this changes (e.g. "5 updates" → "6 updates").
     * Alerts with the same title for something new (a newer agent release) set a revision.
     */
    public static function signature(array $alert): string
    {
        return hash('sha256', $alert['key'].'|'.$alert['title'].(isset($alert['revision']) ? '|'.$alert['revision'] : ''));
    }

    /**
     * Drops the dismissed alerts. Dismissals of alerts that are gone or say something else now
     * are removed, so the alert shows again the next time it comes up.
     */
    private static function withoutDismissed(Device $device, array $alerts): array
    {
        $dismissed = DB::table('device_alert_dismissals')->where('device_id', $device->id)->pluck('signature', 'key');
        if ($dismissed->isEmpty()) {
            return $alerts;
        }

        $current = collect($alerts)->mapWithKeys(fn ($alert) => [$alert['key'] => self::signature($alert)]);
        $stale = $dismissed->filter(fn ($signature, $key) => ($current[$key] ?? null) !== $signature)->keys();
        if ($stale->isNotEmpty()) {
            DB::table('device_alert_dismissals')->where('device_id', $device->id)->whereIn('key', $stale)->delete();
        }

        return array_values(array_filter($alerts, fn ($alert) => ($dismissed[$alert['key']] ?? null) !== $current[$alert['key']]));
    }

    /** Hides the device's alert until it changes or goes away and comes back. */
    public static function dismiss(Device $device, string $key, ?User $user = null): bool
    {
        $alert = collect(self::for($device))->firstWhere('key', $key);
        if ($alert === null) {
            return false;
        }
        DB::table('device_alert_dismissals')->updateOrInsert(
            ['device_id' => $device->id, 'key' => $key],
            ['signature' => self::signature($alert), 'dismissed_by' => $user?->id, 'created_at' => now(), 'updated_at' => now()],
        );

        return true;
    }

    /** The device's commands of the last day (active ones are never older). */
    public static function recentCommands(Device $device): Collection
    {
        DeviceCommand::expireStale($device->id);

        return $device->commands()->where('updated_at', '>=', now()->subSeconds(self::FAILED_COMMAND_WINDOW))->orderBy('id')->get();
    }

    /**
     * Runs the action of the device's alert. The alert is looked up again, so only an action the
     * device needs right now runs; a command already on its way is not sent twice. Returns the
     * reason when it did not run, null when the command was queued.
     */
    public static function run(Device $device, string $key, ?User $user = null): ?string
    {
        $alert = collect(self::for($device))->firstWhere('key', $key);
        if ($alert === null || $alert['action'] === null) {
            return __('Nothing to do anymore.');
        }
        if ($alert['active']) {
            return __('Already on its way.');
        }
        if ($alert['refusal']) {
            return $alert['refusal'];
        }
        if ($alert['action']['command'] === 'remediate') {
            $script = Script::find($alert['action']['params']['script'] ?? null);
            if ($script === null || ! $user?->can('is-system-admin')) {
                return __('Only system admins run scripts');
            }

            return $script->runOn([$device->id], $user, false, true)->isNotEmpty() ? null : __('Already on its way.');
        }
        if ($alert['action']['command'] === 'clearAgentErrors') {
            $device->clearAgentErrors();
            \App\Support\LiveUpdates::device($device->id, 'errors');

            return null;
        }
        if ($alert['action']['command'] === 'adoptMac') {
            $found = Device::discoveredMacFor($device->ping_address);
            if ($found === null) {
                return __('Nothing to do anymore.');
            }
            $device->forceFill(['ping_mac' => $found['mac']])->save();
            \App\Support\LiveUpdates::device($device->id, 'settings');

            return null;
        }
        if ($alert['action']['command'] === 'moveAddress') {
            $device->forceFill(['ping_address' => $alert['action']['params']['ip']])->save();
            \App\Support\LiveUpdates::device($device->id, 'ping');

            return null;
        }
        if ($alert['action']['command'] === 'wake') {
            return $device->wake($user) ? null : __('Already on its way.');
        }

        return $device->issueCommand($alert['action']['command'], $alert['action']['params'], $user) ? null : __('Already on its way.');
    }

    /** Agents refuse remote updates over plain HTTP (except localhost). */
    public static function remoteUpdateAvailable(): bool
    {
        return str_starts_with(url('/'), 'https://') || in_array(parse_url(url('/'), PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]'], true);
    }
}
