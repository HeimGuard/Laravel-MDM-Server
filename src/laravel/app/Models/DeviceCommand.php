<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A command for one device and its state. queued: waits for the agent, sent: the agent took it,
 * running: the agent reports it is working on it (optionally with a progress in percent), then
 * succeeded / failed. Agents before 1.8.0 do not report back: their commands end as delivered.
 */
class DeviceCommand extends Model
{
    use MassPrunable;

    /** Finished commands are kept this many days (the device history). */
    public const KEEP_DAYS = 90;

    public const COMMANDS = ['turnOff', 'restart', 'doUpdates', 'installUpdate', 'updateAgent', 'runScripts', 'sync', 'wake', 'pingNow', 'scanNetwork', 'scanPorts'];

    public const STATUSES = ['queued', 'sent', 'running', 'succeeded', 'failed', 'delivered', 'expired', 'cancelled'];

    public const ACTIVE = ['queued', 'sent', 'running'];

    /** What the agent may report. */
    public const RESULTS = ['running', 'succeeded', 'failed'];

    /** Only triggers, the agent does not report them (runScripts: the runs have their own results). */
    public const UNTRACKED = ['runScripts'];

    /** Commands that cannot wait for each other: a restart and a shutdown at the same time. */
    public const EXCLUSIVE = [['turnOff', 'restart']];

    /** Seconds a command not taken by the agent waits (the device is online when it is queued). */
    public const QUEUE_TTL = 3600;

    /** Seconds without news from the agent after which an active command is given up. */
    public const TIMEOUTS = [
        'turnOff' => 600,
        'restart' => 1800,
        'doUpdates' => 14400,
        'installUpdate' => 7200,
        'updateAgent' => 1800,
        'runScripts' => 600,
        'sync' => 1800,
        // Agents 1.13.2+ keep a wake running until the woken device reports (completeWakes):
        // booting and starting the agent takes a while.
        'wake' => 600,
        'pingNow' => 120,
        'scanNetwork' => 300,
        // A full 1-65535 sweep takes a while; progress keeps it from timing out early.
        'scanPorts' => 1200,
    ];

    /** The largest network an agent scans (a /22: 1022 addresses). */
    public const MIN_SCAN_PREFIX = 22;

    /** At most this many ports in a scanPorts command (a custom list, the default list aside). */
    public const MAX_SCAN_PORTS = 1024;

    /** A MAC address as the agents report it (Windows AA-BB-..., Linux aa:bb:...). */
    public const MAC_PATTERN = '/^[0-9A-Fa-f]{2}([:-][0-9A-Fa-f]{2}){5}$/';

    /** Kinds of single updates the agent can install (installUpdate), with the pattern of their id. */
    public const UPDATE_KINDS = [
        'windows' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        'apt' => '/^[a-z0-9][a-z0-9+.\-]*(:[a-z0-9]+)?$/',
        'winget' => '/^[A-Za-z0-9][A-Za-z0-9._+\-]*$/',
        'flatpak' => '/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)+$/',
        'snap' => '/^[a-z0-9][a-z0-9\-]*$/',
        'module' => '/^[A-Za-z0-9][A-Za-z0-9._\-]*$/',
        // A PowerShell 7 release from GitHub (installations no package manager knows about).
        'pwsh' => '/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,4}$/',
        // Google Chrome of all users (Windows) from Google's enterprise MSI, by the version it is updated to.
        'chrome' => '/^[0-9]{1,4}(\.[0-9]{1,5}){3}$/',
        // A Python package (pip --user) or a pipx app, by its name.
        'pip' => '/^[A-Za-z0-9][A-Za-z0-9._\-]*$/',
        'pipx' => '/^[A-Za-z0-9][A-Za-z0-9._\-]*$/',
    ];

    public const MODULE_EDITIONS = ['Windows PowerShell', 'PowerShell 7'];

    protected $fillable = ['device_id', 'command', 'params', 'target', 'status', 'progress', 'message', 'current', 'done', 'issued_by'];

    protected $casts = [
        'params' => 'array',
        'progress' => 'integer',
        'done' => 'array',
        'sent_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function prunable(): Builder
    {
        return static::query()->whereNotIn('status', self::ACTIVE)->where('updated_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    /**
     * Checks the parameters of installUpdate and returns them cleaned up (only the known keys), or
     * null when they are not valid. Other commands take no parameters.
     */
    public static function sanitizeParams(string $command, array $params): ?array
    {
        if ($command === 'wake') {
            return self::sanitizeWakeParams($params);
        }
        if ($command === 'pingNow') {
            // A ping-only device the agent pings: it pings the address it was given for it.
            $device = $params['device'] ?? null;
            if (! is_int($device) || $device < 1) {
                return null;
            }

            return ['device' => $device] + (isset($params['title']) && is_string($params['title']) ? ['title' => mb_substr($params['title'], 0, 200)] : []);
        }
        if ($command === 'scanNetwork') {
            // A network of the agent's own interfaces (it checks that too), not larger than a /22.
            $cidr = $params['cidr'] ?? null;
            if (! is_string($cidr) || ! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $cidr, $matches)
                || (int) $matches[2] < self::MIN_SCAN_PREFIX || (int) $matches[2] > 30 || \App\Support\NetworkMap::cidr($matches[1], (int) $matches[2]) !== $cidr) {
                return null;
            }

            return ['cidr' => $cidr];
        }
        if ($command === 'scanPorts') {
            // One host address (the agent checks it is in its own networks); an optional list of
            // ports, otherwise the agent scans its default list of common ports.
            $ip = $params['ip'] ?? null;
            if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return null;
            }
            $clean = ['ip' => $ip];
            // Scan every port (1-65535); the agent expands it, so no list is sent.
            if (! empty($params['all'])) {
                return $clean + ['all' => true];
            }
            if (array_key_exists('ports', $params) && $params['ports'] !== null) {
                if (! is_array($params['ports'])) {
                    return null;
                }
                $ports = array_values(array_unique(array_filter(array_map(
                    fn ($port) => is_int($port) || (is_string($port) && ctype_digit($port)) ? (int) $port : null,
                    $params['ports'],
                ), fn ($port) => $port !== null && $port >= 1 && $port <= 65535)));
                if ($ports === [] || count($ports) > self::MAX_SCAN_PORTS) {
                    return null;
                }
                sort($ports);
                $clean['ports'] = $ports;
            }

            return $clean;
        }
        if ($command !== 'installUpdate') {
            return $params === [] ? [] : null;
        }

        $kind = $params['kind'] ?? null;
        $id = $params['id'] ?? null;
        if (! is_string($kind) || ! isset(self::UPDATE_KINDS[$kind]) || ! is_string($id) || strlen($id) > 200 || ! preg_match(self::UPDATE_KINDS[$kind], $id)) {
            return null;
        }

        $clean = ['kind' => $kind, 'id' => $id];
        $user = $params['user'] ?? null;
        if ($user !== null) {
            if (! in_array($kind, ['flatpak', 'module', 'pip', 'pipx'], true) || ! is_string($user) || ! preg_match('/^[a-z_][a-z0-9_.\-]{0,31}$/', $user)) {
                return null;
            }
            $clean['user'] = $user;
        }
        if ($kind === 'module') {
            $edition = $params['edition'] ?? null;
            $version = $params['version'] ?? null;
            if (! in_array($edition, self::MODULE_EDITIONS, true) || ! is_string($version) || ! preg_match('/^[0-9][0-9A-Za-z.\-]{0,49}$/', $version)) {
                return null;
            }
            $clean['edition'] = $edition;
            $clean['version'] = $version;
        }
        // winget: the source of the package (without it winget also asks the Microsoft Store).
        if ($kind === 'winget' && in_array($params['source'] ?? null, ['winget', 'msstore'], true)) {
            $clean['source'] = $params['source'];
        }
        if ($kind === 'winget' && ($params['scope'] ?? null) === 'user') {
            $clean['scope'] = 'user';
        }
        // Only shown in the portal, never used by the agent.
        if (isset($params['title']) && is_string($params['title'])) {
            $clean['title'] = mb_substr($params['title'], 0, 200);
        }

        return $clean;
    }

    /**
     * wake (sent to a relay agent in the network of the sleeping device): the MAC addresses to wake,
     * the broadcast addresses to send the magic packet to, the device it wakes and its name.
     */
    private static function sanitizeWakeParams(array $params): ?array
    {
        $macs = array_values(array_unique(array_map(
            fn ($mac) => strtoupper(str_replace('-', ':', (string) $mac)),
            array_filter((array) ($params['macs'] ?? []), fn ($mac) => is_string($mac) && preg_match(self::MAC_PATTERN, $mac)),
        )));
        $broadcasts = array_values(array_unique(array_filter((array) ($params['broadcasts'] ?? []), fn ($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))));
        $device = $params['device'] ?? null;
        if ($macs === [] || count($macs) > 8 || $broadcasts === [] || count($broadcasts) > 4 || ! is_int($device) || $device < 1) {
            return null;
        }
        $clean = ['macs' => $macs, 'broadcasts' => $broadcasts, 'device' => $device];
        if (isset($params['title']) && is_string($params['title'])) {
            $clean['title'] = mb_substr($params['title'], 0, 200);
        }

        return $clean;
    }

    /** What the command works on, the same for duplicates: "winget:Git.Git", "module:PowerShell 7:Az:". */
    public static function targetOf(string $command, array $params): ?string
    {
        if ($command === 'wake') {
            return 'device:'.$params['device'];
        }
        if ($command === 'pingNow') {
            return 'ping:'.$params['device'];
        }
        if ($command === 'scanNetwork') {
            return 'scan:'.$params['cidr'];
        }
        if ($command === 'scanPorts') {
            return 'ports:'.$params['ip'];
        }
        if ($command !== 'installUpdate') {
            return null;
        }

        return implode(':', [$params['kind'], $params['edition'] ?? '', $params['id'], $params['user'] ?? '']);
    }

    /**
     * Active commands without news for longer than their timeout are given up. The open pages of
     * their devices (and of the device a wake was for) are told.
     */
    public static function expireStale(?int $deviceId = null): int
    {
        $expired = 0;
        foreach (self::TIMEOUTS as $command => $timeout) {
            $stale = self::query()->where('command', $command)
                ->when($deviceId, fn ($query) => $query->where('device_id', $deviceId))
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query->where('status', 'queued')->where('updated_at', '<', now()->subSeconds(self::QUEUE_TTL)))
                    ->orWhere(fn ($query) => $query->whereIn('status', ['sent', 'running'])->where('updated_at', '<', now()->subSeconds($timeout))))
                ->get(['id', 'device_id', 'command', 'status', 'target', 'params']);
            foreach ($stale as $row) {
                // A magic packet that went out: the device did not come online.
                $message = $row->command === 'wake' && $row->status === 'running'
                    ? __('Magic packet sent, but :device did not come online within :minutes minutes', ['device' => $row->params['title'] ?? '?', 'minutes' => intdiv($timeout, 60)])
                    : __('No result from the device');
                $done = self::query()->whereKey($row->id)->whereIn('status', self::ACTIVE)->toBase()->update([
                    'status' => 'expired',
                    'message' => $message,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($done === 1) {
                    $expired++;
                    self::announce($row->device_id, $row->target);
                }
            }
        }

        return $expired;
    }

    /** Tells the open pages of the device and of the devices the wakes or pings are for (once each). */
    public static function announce(int $deviceId, ?string ...$targets): void
    {
        $ids = [$deviceId];
        foreach ($targets as $target) {
            if ($target !== null && preg_match('/^(device|ping):(\d+)$/', $target, $matches)) {
                $ids[] = (int) $matches[2];
            }
        }
        foreach (array_unique($ids) as $id) {
            \App\Support\LiveUpdates::device($id, 'command');
        }
    }

    /** A device that was being woken reports: its wakes are done. Returns how many. */
    public static function completeWakes(int $deviceId): int
    {
        $done = 0;
        foreach (self::query()->where('command', 'wake')->where('target', 'device:'.$deviceId)->active()->get() as $wake) {
            $updated = self::query()->whereKey($wake->id)->whereIn('status', self::ACTIVE)->toBase()->update([
                'status' => 'succeeded',
                'progress' => 100,
                'message' => __('Online after :time', ['time' => $wake->created_at->diffForHumans(now(), ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2])]),
                'started_at' => $wake->started_at ?? now(),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            if ($updated === 1) {
                $done++;
                self::announce($wake->device_id, $wake->target);
            }
        }

        return $done;
    }

    public function getActiveAttribute(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function getLabelAttribute(): string
    {
        return match ($this->command) {
            'turnOff' => __('Turn off'),
            'restart' => __('Restart'),
            'doUpdates' => __('Install updates'),
            'installUpdate' => __('Update :name', ['name' => $this->params['title'] ?? $this->params['id'] ?? '?']),
            'updateAgent' => __('Update agent'),
            'runScripts' => __('Run scripts'),
            'sync' => __('Sync'),
            'wake' => __('Wake :name', ['name' => $this->params['title'] ?? '?']),
            'pingNow' => __('Ping :name', ['name' => $this->params['title'] ?? '?']),
            'scanNetwork' => __('Scan :network', ['network' => $this->params['cidr'] ?? '?']),
            'scanPorts' => ! empty($this->params['all'])
                ? __('Scan all ports of :ip', ['ip' => $this->params['ip'] ?? '?'])
                : __('Scan ports of :ip', ['ip' => $this->params['ip'] ?? '?']),
            default => $this->command,
        };
    }

    public function getIconAttribute(): string
    {
        return match ($this->command) {
            'turnOff' => 'fas fa-power-off',
            'restart' => 'fas fa-redo',
            'doUpdates', 'installUpdate' => 'fas fa-sync',
            'updateAgent' => 'fas fa-robot',
            'runScripts' => 'fas fa-scroll',
            'sync' => 'fas fa-cloud-download-alt',
            'wake' => 'fas fa-sun',
            'pingNow' => 'fas fa-network-wired',
            'scanNetwork' => 'fas fa-search-location',
            'scanPorts' => 'fas fa-plug',
            default => 'fas fa-terminal',
        };
    }

    /** Human readable state: "Waiting for the device", "Running · 40 %" ... */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'queued' => __('Waiting for the device'),
            'sent' => __('Taken by the device'),
            'running' => __('Running'),
            'succeeded' => __('Done'),
            'failed' => __('Failed'),
            'delivered' => __('Sent, no result'),
            'expired' => __('Timed out'),
            'cancelled' => __('Cancelled'),
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'queued', 'sent', 'running' => 'info',
            'succeeded' => 'success',
            'delivered' => 'secondary',
            'failed', 'expired' => 'danger',
            default => 'secondary',
        };
    }

    /** What the status badge means, for its tooltip. */
    public function getStatusHintAttribute(): ?string
    {
        return match ($this->status) {
            'delivered' => __('Taken by an agent older than 1.8.0, it does not report the result.'),
            'expired' => __('The device did not report a result in time.'),
            default => null,
        };
    }

    /**
     * The agent's message when it adds something to the status: not the plain "Done" of a
     * finished update ("Done, restart required" becomes "Restart required").
     */
    /** The message with the meaning of winget exit codes (App\Support\WingetCodes). */
    public function getDisplayMessageAttribute(): ?string
    {
        return \App\Support\WingetCodes::explain($this->message);
    }

    public function getResultNoteAttribute(): ?string
    {
        $message = trim((string) $this->displayMessage);
        if ($this->status === 'succeeded' && preg_match('/^Done\b[,.\s]*/i', $message)) {
            $message = ucfirst(trim(preg_replace('/^Done\b[,.\s]*/i', '', $message)));
        }

        return $message === '' || strcasecmp($message, $this->statusLabel) === 0 ? null : $message;
    }

    /** How long the device worked on it ("5 min"), null when unknown. */
    public function getDurationAttribute(): ?string
    {
        if (! $this->finished_at || ! $this->started_at) {
            return null;
        }
        $seconds = max(0, $this->finished_at->getTimestamp() - $this->started_at->getTimestamp());

        return $seconds < 60 ? __(':count s', ['count' => $seconds]) : \Carbon\CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }

    /** What the agent gets: the id it reports back with, the command and its parameters. */
    public function toTask(): array
    {
        $params = $this->params ?? [];
        unset($params['title']);

        return ['id' => $this->id, 'command' => $this->command, 'params' => (object) $params];
    }
}
