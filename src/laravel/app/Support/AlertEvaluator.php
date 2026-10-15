<?php

namespace App\Support;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\ScriptRun;
use App\Models\SecurityFinding;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks the alert rules every minute. A rule that starts to hold on a device opens an alert and
 * notifies its user; when it stops holding, the alert is resolved and the user is told so. Nothing
 * is sent again while an alert stays open. Alerts and notifications are always in English, like the
 * logs, also when a web request of a user with another language causes them.
 */
class AlertEvaluator
{
    /** Sizes are 1024 based, as shown in the portal. */
    private const GB = 1073741824;

    /** @var Collection<int, Device> loaded once per run */
    private Collection $devices;

    /** @return array{triggered: int, resolved: int} */
    public static function run(): array
    {
        return Locales::inEnglish(fn () => (new self)->evaluate());
    }

    public function evaluate(): array
    {
        $this->devices = Device::query()->orderBy('id')->get();
        $counts = ['triggered' => 0, 'resolved' => 0];

        foreach (AlertRule::query()->with('user')->get() as $rule) {
            if (AlertRule::isEvent($rule->type)) {
                // Sent when it happens (addressChanged), not by a run.
                if ($rule->type === 'address_changed') {
                    continue;
                }
                try {
                    $counts['triggered'] += match ($rule->type) {
                        'unknown_device' => $this->announceUnknownDevices($rule),
                        'exposure' => $this->announceExposures($rule),
                        default => $this->announceNewDevices($rule),
                    };
                } catch (Throwable $e) {
                    Log::warning("Alert rule {$rule->id} failed: {$e->getMessage()}");
                }

                continue;
            }
            try {
                $open = $rule->events()->whereNull('resolved_at')->get()->keyBy('device_id');
                $targeted = $rule->enabled ? $this->devices->filter->matchesTarget($rule->target ?? []) : collect();

                // Devices no longer in the target (or a disabled rule): closed without a message.
                foreach ($open as $deviceId => $event) {
                    if (! $targeted->contains('id', $deviceId)) {
                        $event->update(['resolved_at' => now()]);
                    }
                }

                foreach ($targeted as $device) {
                    $result = $this->check($rule, $device);
                    if ($result === null) {
                        continue;
                    }
                    $event = $open->get($device->id);
                    if ($result['active'] && $event === null) {
                        $this->trigger($rule, $device, $result);
                        $counts['triggered']++;
                    } elseif (! $result['active'] && $event !== null) {
                        $this->resolve($rule, $device, $event);
                        $counts['resolved']++;
                    }
                }
            } catch (Throwable $e) {
                Log::warning("Alert rule {$rule->id} failed: {$e->getMessage()}");
            }
        }

        return $counts;
    }

    /**
     * Whether the rule holds on the device: ['active' => bool, 'value' => ?float, 'message' => string],
     * or null when it cannot be told now (no data yet, the device is offline for a metric).
     */
    public function check(AlertRule $rule, Device $device): ?array
    {
        if (empty($device->data) && $rule->type !== 'status') {
            return null;
        }

        return match ($rule->type) {
            'status' => $this->checkStatus($rule, $device),
            'cpu', 'memory' => $this->checkMetric($rule, $device),
            'disk' => $this->checkDisk($rule, $device),
            'disk_health' => $device->virtualization !== null || $device->diskHealth === null ? null : [
                'active' => $device->diskHealthProblem,
                'value' => null,
                'message' => __('A disk of :device reports a S.M.A.R.T. problem.', ['device' => $device->displayName]),
            ],
            'services' => $this->checkServices($device),
            'scripts' => $this->checkScripts($device),
            'security' => $this->checkSecurity($device),
            default => null,
        };
    }

    private function checkStatus(AlertRule $rule, Device $device): array
    {
        $lastSeen = $device->last_seen_at ?? $device->updated_at;
        $minutes = max(1, (int) $rule->minutes);
        $down = $device->offline && $lastSeen !== null && $lastSeen->lt(now()->subMinutes($minutes));

        return [
            'active' => $down,
            'value' => null,
            'message' => __(':device is offline, last seen :time.', ['device' => $device->displayName, 'time' => $lastSeen?->diffForHumans()]),
        ];
    }

    private function checkMetric(AlertRule $rule, Device $device): ?array
    {
        if ($device->offline) {
            return null;
        }
        $minutes = max(1, (int) $rule->minutes);
        $column = match (true) {
            $rule->type === 'cpu' => 'cpu',
            $rule->inGb => DB::raw('memory_total - memory_used'),
            default => DB::raw('100.0 * memory_used / memory_total'),
        };
        $samples = $device->metrics()->where('created_at', '>=', now()->subMinutes($minutes));
        // Heartbeats come every 30 s: at least half of the window must be covered.
        if ((clone $samples)->count() < max(1, $minutes)) {
            return null;
        }
        if ($rule->inGb) {
            $free = round((float) (clone $samples)->avg($column) / self::GB, 1);

            return [
                'active' => $free < $rule->limit_gb,
                'value' => $free,
                'message' => __('Free memory of :device averaged :value GB in the last :minutes min (limit :limit GB).', [
                    'device' => $device->displayName, 'value' => AlertRule::formatGb($free), 'minutes' => $minutes, 'limit' => AlertRule::formatGb($rule->limit_gb),
                ]),
            ];
        }
        $average = round((float) (clone $samples)->avg($column), 1);
        $label = $rule->type === 'cpu' ? __('CPU') : __('Memory');

        return [
            'active' => $average > $rule->threshold,
            'value' => $average,
            'message' => __(':label usage of :device averaged :value % in the last :minutes min (threshold :threshold %).', [
                'label' => $label, 'device' => $device->displayName, 'value' => $average, 'minutes' => $minutes, 'threshold' => $rule->threshold,
            ]),
        ];
    }

    private function checkDisk(AlertRule $rule, Device $device): ?array
    {
        $drives = collect($device->drives)->filter(fn ($drive) => isset($drive['PercentUsed']));
        if ($drives->isEmpty()) {
            return null;
        }
        $name = fn ($drive) => trim(($drive['FriendlyName'] ?? '').' ('.($drive['DriveLetter'] ?? '?').')');
        if ($rule->inGb) {
            $free = fn ($drive) => round((float) ($drive['SizeRemaining'] ?? 0) / self::GB, 1);
            $low = $drives->filter(fn ($drive) => $free($drive) < $rule->limit_gb);

            return [
                'active' => $low->isNotEmpty(),
                'value' => $drives->map($free)->min(),
                'message' => __('Drives of :device with less than :limit GB free: :drives.', [
                    'device' => $device->displayName,
                    'limit' => AlertRule::formatGb($rule->limit_gb),
                    'drives' => $low->map(fn ($drive) => $name($drive).' '.AlertRule::formatGb($free($drive)).' GB')->implode(', '),
                ]),
            ];
        }
        $full = $drives->filter(fn ($drive) => $drive['PercentUsed'] > $rule->threshold);
        $top = $drives->sortByDesc('PercentUsed')->first();

        return [
            'active' => $full->isNotEmpty(),
            'value' => (float) $top['PercentUsed'],
            'message' => __('Drives of :device above :threshold %: :drives.', [
                'device' => $device->displayName,
                'threshold' => $rule->threshold,
                'drives' => $full->map(fn ($drive) => $name($drive).' '.$drive['PercentUsed'].' %')->implode(', '),
            ]),
        ];
    }

    private function checkServices(Device $device): array
    {
        $failed = collect($device->services)->where('State', 'failed')->pluck('Name');
        $containers = collect($device->docker['containers'] ?? [])
            ->filter(fn ($container) => ($container['Health'] ?? null) === 'unhealthy' || in_array($container['State'] ?? null, ['dead', 'restarting'], true))
            ->pluck('Name');
        $names = $failed->merge($containers)->filter()->values();

        return [
            'active' => $names->isNotEmpty(),
            'value' => (float) $names->count(),
            'message' => __('Problems on :device: :names.', ['device' => $device->displayName, 'names' => $names->implode(', ')]),
        ];
    }

    private function checkScripts(Device $device): array
    {
        $failed = ScriptRun::query()->with('script')->where('device_id', $device->id)
            ->whereIn('id', ScriptRun::query()->selectRaw('max(id)')->where('device_id', $device->id)->groupBy('script_id'))
            ->whereIn('status', ['failed', 'error', 'rejected'])->get();

        return [
            'active' => $failed->isNotEmpty(),
            'value' => (float) $failed->count(),
            'message' => __('Remediations failed on :device: :scripts.', ['device' => $device->displayName, 'scripts' => $failed->map(fn ($run) => $run->script?->name)->filter()->implode(', ')]),
        ];
    }

    private function checkSecurity(Device $device): array
    {
        $findings = SecurityFinding::query()->with('rule')->active()->atLeast('high')
            ->where('device_id', $device->id)->bySeverity()->get();

        return [
            'active' => $findings->isNotEmpty(),
            'value' => (float) $findings->count(),
            'message' => __('Security findings on :device: :findings.', [
                'device' => $device->displayName,
                'findings' => $findings->take(5)->map(fn ($finding) => $finding->message)->implode('; ').($findings->count() > 5 ? ' …' : ''),
            ]),
        ];
    }

    /** Minutes an enrolled agent has to send its first report (its name and system) before the message goes anyway. */
    private const NEW_DEVICE_GRACE_MINUTES = 10;

    /**
     * New device: each device added since the rule exists (within the last day) is announced once,
     * as soon as its name is known (the agent's first report, a ping-only device right away). The
     * event is recorded resolved: nothing stays open.
     */
    private function announceNewDevices(AlertRule $rule): int
    {
        if (! $rule->enabled) {
            return 0;
        }
        $since = $rule->created_at->max(now()->subDay());
        $known = $rule->events()->where('triggered_at', '>=', $since->copy()->subMinute())->pluck('device_id')->all();
        $sent = 0;
        foreach ($this->devices as $device) {
            if ($device->created_at === null || $device->created_at->lt($since) || in_array($device->id, $known, true)) {
                continue;
            }
            $named = $device->isPingOnly || ! empty($device->data) || $device->created_at->lt(now()->subMinutes(self::NEW_DEVICE_GRACE_MINUTES));
            if (! $named) {
                continue;
            }
            $what = $device->isPingOnly
                ? __('ping-only, :address', ['address' => $device->ping_address])
                : collect([$device->os ?: null, $device->agentVersion ? __('agent :version', ['version' => $device->agentVersion]) : null, $device->public_ip])->filter()->implode(', ');
            $message = $device->isPingOnly
                ? __(':device was added (:what).', ['device' => $device->displayName, 'what' => $what])
                : __(':device was enrolled (:what).', ['device' => $device->displayName, 'what' => $what ?: __('no report yet')]);
            $rule->events()->create([
                'device_id' => $device->id,
                'message' => mb_strimwidth($message, 0, 1000),
                'value' => null,
                'triggered_at' => now(),
                'resolved_at' => now(),
            ]);
            LiveUpdates::device($device->id, 'alert');
            Notifier::notify($rule->user, "🆕 {$device->displayName}: ".__('New device'), $message."\n".url('/devices?selectedDeviceId='.$device->id), $rule->channels);
            $sent++;
        }

        return $sent;
    }

    /**
     * Unknown devices that appeared in a network since the rule exists (at most a day back), once
     * each: the event is of the agent that saw it, its value the neighbour.
     */
    private function announceUnknownDevices(AlertRule $rule): int
    {
        if (! $rule->enabled) {
            return 0;
        }
        $since = $rule->created_at->max(now()->subDay());
        $known = $rule->events()->where('triggered_at', '>=', $since->copy()->subMinute())->pluck('value')->map(fn ($value) => (int) $value)->all();
        $sent = 0;
        foreach (\App\Models\NetworkNeighbour::unknown($this->devices) as $neighbour) {
            $agent = $this->devices->firstWhere('id', $neighbour->seen_by);
            if ($agent === null || $neighbour->first_seen_at->lt($since) || in_array($neighbour->id, $known, true)) {
                continue;
            }
            $message = __(':device appeared in :network (:details), seen by :agent.', [
                'device' => $neighbour->displayName,
                'network' => $neighbour->network,
                'details' => collect([$neighbour->hostname ? $neighbour->ip : null, $neighbour->mac, \App\Support\MacVendor::lookup($neighbour->mac)])->filter()->implode(', '),
                'agent' => $agent->displayName,
            ]);
            $rule->events()->create([
                'device_id' => $agent->id,
                'message' => mb_strimwidth($message, 0, 1000),
                'value' => $neighbour->id,
                'triggered_at' => now(),
                'resolved_at' => now(),
            ]);
            Notifier::notify($rule->user, "❔ {$neighbour->displayName}: ".__('Unknown device'), $message."\n".NetworkMap::cardUrl('net:'.$neighbour->site.'|'.$neighbour->network), $rule->channels);
            $sent++;
        }

        return $sent;
    }

    /**
     * Exposed or weak services a port scan found on hosts that are not managed devices (device-less
     * findings of the `portscan` rules), of medium severity or worse, announced once each: the event
     * keeps the finding id as its value and is recorded against the agent that ran the scan (as with
     * unknown devices), while its message and link point to the scanned host and its network.
     */
    private function announceExposures(AlertRule $rule): int
    {
        if (! $rule->enabled) {
            return 0;
        }
        $since = $rule->created_at->max(now()->subDay());
        $known = $rule->events()->where('triggered_at', '>=', $since->copy()->subMinute())->pluck('value')->map(fn ($value) => (int) $value)->all();
        $findings = SecurityFinding::query()->with('rule')->active()->atLeast('medium')
            ->whereNull('device_id')
            ->whereHas('rule', fn ($query) => $query->where('source', 'portscan'))
            ->where('first_seen_at', '>=', $since)
            ->when($known !== [], fn ($query) => $query->whereNotIn('id', $known))
            ->bySeverity()->get();
        $sent = 0;
        foreach ($findings as $finding) {
            $scanner = \App\Models\PortScanResult::query()->where('site', $finding->site)->where('ip', $finding->target_ip)->value('scanned_by');
            $agent = $scanner === null ? null : $this->devices->firstWhere('id', $scanner);
            if ($agent === null) {
                // The scan it came from is gone (pruned): it will resolve on the next scan, skip it.
                continue;
            }
            $host = collect([$finding->target_ip, $finding->target_mac, \App\Support\MacVendor::lookup($finding->target_mac)])->filter()->implode(', ');
            $message = __(':finding on :host in :network, seen by :agent.', [
                'finding' => $finding->message,
                'host' => $host ?: ($finding->target_ip ?? '?'),
                'network' => $finding->network ?? '?',
                'agent' => $agent->displayName,
            ]);
            $rule->events()->create([
                'device_id' => $agent->id,
                'message' => mb_strimwidth($message, 0, 1000),
                'value' => $finding->id,
                'triggered_at' => now(),
                'resolved_at' => now(),
            ]);
            $link = $finding->targetUrl;
            Notifier::notify($rule->user, "🚪 {$finding->target_ip}: ".__('Exposed service'), $message.($link ? "\n".$link : ''), $rule->channels);
            $sent++;
        }

        return $sent;
    }

    /**
     * A ping-only device with a MAC address moved to another IP address (the agents saw the MAC
     * there, NetworkNeighbour::followMacs): its address is followed, the alert says to pin it.
     */
    public static function addressChanged(Device $device, string $from, string $to): void
    {
        Locales::inEnglish(fn () => self::announceAddressChange($device, $from, $to));
    }

    private static function announceAddressChange(Device $device, string $from, string $to): void
    {
        $message = __(':device (:mac) is at :to now, the portal pings :from: its address is dynamic. Confirm the new address on the device (smart alert), and reserve it for the MAC address in the router (static DHCP lease) or set it on the device.', [
            'device' => $device->displayName, 'mac' => $device->ping_mac, 'from' => $from, 'to' => $to,
        ]);
        foreach (AlertRule::query()->with('user')->where('type', 'address_changed')->where('enabled', true)->get() as $rule) {
            try {
                $rule->events()->create([
                    'device_id' => $device->id,
                    'message' => mb_strimwidth($message, 0, 1000),
                    'value' => null,
                    'triggered_at' => now(),
                    'resolved_at' => now(),
                ]);
                LiveUpdates::device($device->id, 'alert');
                Notifier::notify($rule->user, "🔀 {$device->displayName}: ".__('Address changed'), $message."\n".url('/devices?selectedDeviceId='.$device->id), $rule->channels);
            } catch (Throwable $e) {
                Log::warning("Alert rule {$rule->id} failed: {$e->getMessage()}");
            }
        }
    }

    private function trigger(AlertRule $rule, Device $device, array $result): void
    {
        LiveUpdates::device($device->id, 'alert');
        $rule->events()->create([
            'device_id' => $device->id,
            'message' => mb_strimwidth($result['message'], 0, 1000),
            'value' => $result['value'],
            'triggered_at' => now(),
        ]);

        $title = $rule->type === 'status'
            ? "🔴 {$device->displayName} ".__('is down')
            : "🔴 {$device->displayName}: {$rule->label}";
        Notifier::notify($rule->user, $title, $result['message']."\n".url('/devices?selectedDeviceId='.$device->id), $rule->channels);
    }

    private function resolve(AlertRule $rule, Device $device, AlertEvent $event): void
    {
        $event->update(['resolved_at' => now()]);
        LiveUpdates::device($device->id, 'alert');

        $title = $rule->type === 'status'
            ? "✅ {$device->displayName} ".__('is up')
            : "✅ {$device->displayName}: {$rule->label} ".__('resolved');
        $duration = $event->triggered_at->diffForHumans(now(), ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]);
        Notifier::notify($rule->user, $title, __('Resolved after :duration.', ['duration' => $duration])."\n".url('/devices?selectedDeviceId='.$device->id), $rule->channels);
    }
}
