<?php

namespace App\Models;

use App\Support\AgentScript;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;


class Device extends Model
{
    use HasFactory;

    public const COMMANDS = DeviceCommand::COMMANDS;

    /** Agents from this version report the progress and the result of their commands. */
    public const TRACKING_VERSION = '1.8.0';

    /** Agents from this version update PowerShell 7 from its GitHub release (installUpdate "pwsh"). */
    public const PWSH_UPDATE_VERSION = '1.8.2';

    /** Agents from this version collect and report everything on request (sync). */
    public const SYNC_VERSION = '1.8.2';

    /** Agents from this version report prefix lengths and send Wake-on-LAN magic packets. */
    public const WAKE_VERSION = '1.9.0';

    /** Agents from this version ping the ping-only devices of their network. */
    public const PING_VERSION = '1.10.0';

    /** Agents that ping a ping-only device on demand (Sync of the device). */
    public const PING_NOW_VERSION = '1.12.0';

    /** Agents from this version report their neighbours (ARP table) and scan networks (network_discovery). */
    public const NETWORK_DISCOVERY_VERSION = '1.16.0';

    /** Agents from this version collect the security inventory (App\Support\SecurityScanner). */
    public const SECURITY_VERSION = '1.17.0';

    /** Agents from this version scan the open ports of an address in their network (port_scan). */
    public const PORT_SCAN_VERSION = '1.18.0';

    /** Agents from this version report and install pip --user and pipx package updates. */
    public const PYTHON_UPDATE_VERSION = '1.19.0';

    /** At most this many ping-only devices per agent. */
    public const MAX_PING_TARGETS = 32;

    /** Interfaces that can wake a machine (a magic packet goes to the network card). */
    public const WAKE_INTERFACE_TYPES = ['lan', 'wifi'];

    /** Interfaces a relay may share a network through (not tunnels, containers or virtual switches). */
    public const RELAY_INTERFACE_TYPES = ['lan', 'wifi', 'bridge'];

    public const TYPE_ICONS = [
        'server' => 'fas fa-server',
        'laptop' => 'fas fa-laptop',
        'desktop' => 'fas fa-desktop',
        'ping' => 'fas fa-network-wired',
    ];

    /** Hypervisor and container names as reported by the agent (systemd-detect-virt naming). */
    public const VIRTUALIZATION_NAMES = [
        'kvm' => 'KVM', 'qemu' => 'QEMU', 'vmware' => 'VMware', 'microsoft' => 'Hyper-V', 'oracle' => 'VirtualBox',
        'xen' => 'Xen', 'parallels' => 'Parallels', 'bhyve' => 'bhyve', 'amazon' => 'Amazon EC2', 'google' => 'Google Cloud',
        'zvm' => 'z/VM', 'powervm' => 'PowerVM', 'acrn' => 'ACRN', 'apple' => 'Apple', 'sre' => 'SRE',
        'docker' => 'Docker', 'podman' => 'Podman', 'lxc' => 'LXC', 'lxc-libvirt' => 'LXC', 'openvz' => 'OpenVZ',
        'systemd-nspawn' => 'systemd-nspawn', 'wsl' => 'WSL', 'rkt' => 'rkt', 'proot' => 'proot', 'pouch' => 'Pouch',
    ];

    /** Seconds without a report after which the REST API connection is shown as inactive (reports come every 5 min). */
    public const REPORT_TIMEOUT = 660;

    /** Seconds without a heartbeat after which the device is considered offline. */
    public const HEARTBEAT_TIMEOUT = 90;

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_ws_at' => 'datetime',
        'last_http_at' => 'datetime',
        'live_state_at' => 'datetime',
        'public_key' => 'array',
        'key_registered_at' => 'datetime',
        'tags' => 'array',
        'agent_errors' => 'array',
    ];

    public const MAX_TAGS = 20;

    public const MAX_TAG_LENGTH = 32;

    /** The only command agents that do not sign (before 1.7.0) get: updating to a signing agent. */
    public const LEGACY_COMMANDS = ['updateAgent'];

    /** Live state values the agent may report (anything else is dropped). */
    private const SERVICE_STATES = ['running', 'stopped', 'failed'];

    private const CONTAINER_STATES = ['created', 'running', 'unhealthy', 'paused', 'restarting', 'removing', 'exited', 'dead'];

    /**
     * @param  'ws'|'http'  $channel
     */
    public static function recordHeartbeat(int $id, mixed $metrics = null, string $channel = 'ws', mixed $state = null, bool $announce = true): void
    {
        $values = [
            'last_seen_at' => now(),
            $channel === 'ws' ? 'last_ws_at' : 'last_http_at' => now(),
        ];
        if ($state = self::sanitizeLiveState($state)) {
            $values['live_state'] = json_encode($state);
            $values['live_state_at'] = now();
        }

        // One UPDATE (atomic, no read-modify-write) through the query builder, so the heartbeat
        // does not touch updated_at (time of the last report) or the report data.
        $updated = static::query()->whereKey($id)->toBase()->update($values);

        if ($updated && $metrics = DeviceMetric::sanitize($metrics)) {
            DeviceMetric::query()->create($metrics + ['device_id' => $id]);
        }
        // Inside Reverb the listener announces it itself (see LiveUpdates::fromReverb).
        if ($updated && $announce) {
            \App\Support\LiveUpdates::device($id, 'heartbeat');
        }
    }

    /**
     * A device goes offline by not sending anything: announces the devices whose last heartbeat
     * just passed the timeout (run every minute), so the open pages show it.
     */
    public static function announceNewlyOffline(): int
    {
        $ids = static::query()->whereBetween('last_seen_at', [now()->subSeconds(self::HEARTBEAT_TIMEOUT + 60), now()->subSeconds(self::HEARTBEAT_TIMEOUT)])->pluck('id');
        foreach ($ids as $id) {
            \App\Support\LiveUpdates::device($id, 'offline');
        }

        return $ids->count();
    }

    /**
     * Keeps only known fields and values: {restart_required: bool, services: {name: state},
     * containers: {name: state}}. Returns null when nothing usable is left.
     */
    public static function sanitizeLiveState(mixed $state): ?array
    {
        if (! is_array($state)) {
            return null;
        }

        $clean = [];
        if (array_key_exists('restart_required', $state)) {
            $clean['restart_required'] = filter_var($state['restart_required'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($state['power']) && is_array($state['power'])) {
            $battery = $state['power']['battery'] ?? null;
            $clean['power'] = [
                'battery' => is_numeric($battery) ? max(0, min(100, (int) $battery)) : null,
                'plugged' => filter_var($state['power']['plugged'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }
        foreach (['services' => self::SERVICE_STATES, 'containers' => self::CONTAINER_STATES] as $key => $allowed) {
            if (! isset($state[$key]) || ! is_array($state[$key])) {
                continue;
            }
            $clean[$key] = [];
            foreach (array_slice($state[$key], 0, 2000, true) as $name => $value) {
                if (is_string($name) && $name !== '' && strlen($name) <= 256 && in_array($value, $allowed, true)) {
                    $clean[$key][$name] = $value;
                }
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Tags from a list or a comma-separated text: trimmed, letters, digits, spaces and ._- only,
     * each once (ignoring case), at most MAX_TAGS.
     *
     * @return array<int, string>
     */
    public static function normalizeTags(array|string|null $tags): array
    {
        $tags = is_array($tags) ? $tags : explode(',', (string) $tags);
        $clean = [];
        foreach ($tags as $tag) {
            $tag = preg_replace('/\s+/u', ' ', trim((string) $tag));
            if ($tag === '' || mb_strlen($tag) > self::MAX_TAG_LENGTH || ! preg_match('/^[\pL\pN _.\-]+$/u', $tag)) {
                continue;
            }
            $clean[mb_strtolower($tag)] ??= $tag;
        }

        return array_slice(array_values($clean), 0, self::MAX_TAGS);
    }

    /** @return array<int, string> */
    public function getTagListAttribute(): array
    {
        return self::normalizeTags($this->tags ?? []);
    }

    public function hasTag(string $tag): bool
    {
        return in_array(mb_strtolower($tag), array_map('mb_strtolower', $this->tagList), true);
    }

    /**
     * Every tag in use, sorted (for filters and the tag pickers).
     *
     * @return array<int, string>
     */
    public static function allTags(bool $agentsOnly = false): array
    {
        $tags = self::normalizeTags(static::query()->whereNotNull('tags')->when($agentsOnly, fn ($query) => $query->where('kind', 'agent'))->pluck('tags')->flatten()->all());
        natcasesort($tags);

        return array_values($tags);
    }

    /**
     * A target of scripts and alerts: every device ("all"), the devices with any of the tags or
     * the listed devices. ['all' => bool, 'tags' => [..], 'devices' => [ids]].
     */
    public static function normalizeTarget(mixed $target): array
    {
        $target = is_array($target) ? $target : [];

        return [
            'all' => filter_var($target['all'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'tags' => self::normalizeTags($target['tags'] ?? []),
            'devices' => array_values(array_unique(array_filter(array_map('intval', (array) ($target['devices'] ?? []))))),
        ];
    }

    public function matchesTarget(array $target): bool
    {
        $target = self::normalizeTarget($target);

        return $target['all']
            || in_array($this->id, $target['devices'], true)
            || collect($target['tags'])->contains(fn (string $tag) => $this->hasTag($tag));
    }

    /** @return Collection<int, Device> */
    public static function targeted(array $target): Collection
    {
        return static::query()->orderBy('id')->get()->filter->matchesTarget($target)->values();
    }

    /** "All devices", "Tags: servers, family · 2 devices" */
    public static function describeTarget(array $target): string
    {
        $target = self::normalizeTarget($target);
        if ($target['all']) {
            return __('All devices');
        }
        $parts = [];
        if ($target['tags'] !== []) {
            $parts[] = __('Tags: :tags', ['tags' => implode(', ', $target['tags'])]);
        }
        if ($target['devices'] !== []) {
            $names = static::query()->whereIn('id', $target['devices'])->get()->map->displayName->all();
            $parts[] = implode(', ', $names);
        }

        return $parts === [] ? __('No devices') : implode(' · ', $parts);
    }

    /** The live state when it is newer than the last report, otherwise null (the report wins). */
    public function getLiveStateAttribute(): ?array
    {
        $value = $this->attributes['live_state'] ?? null;
        if ($value === null || $this->live_state_at === null || ($this->updated_at !== null && $this->live_state_at->lt($this->updated_at))) {
            return null;
        }

        return json_decode($value, true) ?: null;
    }

    /**
     * Hands the queued commands to the agent: each one only once, also when two requests take
     * them at the same time. Agents that do not sign (before 1.7.0) only get the agent update,
     * agents before 1.8.0 no single updates (they would install all of them).
     *
     * @return Collection<int, DeviceCommand>
     */
    public static function takeQueuedCommands(int $id): Collection
    {
        $device = static::query()->find($id);
        if ($device === null) {
            return collect();
        }
        DeviceCommand::expireStale($id);

        $taken = collect();
        foreach ($device->commands()->where('status', 'queued')->orderBy('id')->get() as $command) {
            $refused = match (true) {
                ! $device->signsRequests && ! in_array($command->command, self::LEGACY_COMMANDS, true) => __('The agent does not sign its communication'),
                $command->params && ! $device->commandTracking => __('The agent is too old for this command'),
                default => null,
            };
            // Tracked commands wait for the agent's result, the others are done with the delivery.
            $status = $refused ? 'failed' : ($device->commandTracking && ! in_array($command->command, DeviceCommand::UNTRACKED, true) ? 'sent' : 'delivered');
            $values = ['status' => $status, 'sent_at' => now(), 'updated_at' => now()];
            if ($status !== 'sent') {
                $values += ['finished_at' => now(), 'message' => $refused];
            }
            // Only the request that changes it from queued hands it over.
            if (DeviceCommand::query()->whereKey($command->id)->where('status', 'queued')->toBase()->update($values) === 1 && ! $refused) {
                $taken->push($command->fill($values));
            }
        }
        if ($taken->isNotEmpty()) {
            // A wake taken by the relay shows on the device it wakes too.
            DeviceCommand::announce($id, ...$taken->pluck('target')->all());
        }

        return $taken;
    }

    /** Names of the commands handed to the agent now (used by tests and older callers). */
    public static function takeCommands(int $id): array
    {
        return self::takeQueuedCommands($id)->pluck('command')->all();
    }

    /**
     * The commands in an agent response: "commands" (names, agents before 1.8.0 run these; commands
     * with parameters are left out) and "tasks" with the id each result is reported with.
     */
    public static function commandResponse(int $id): array
    {
        $taken = self::takeQueuedCommands($id);

        return [
            'commands' => $taken->filter(fn (DeviceCommand $command) => ! $command->params)->pluck('command')->values()->all(),
            'tasks' => $taken->map->toTask()->values()->all(),
        ];
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    /** Names of the commands waiting for the agent. */
    public function getQueuedCommandsAttribute(): array
    {
        return $this->commands()->where('status', 'queued')->orderBy('id')->pluck('command')->all();
    }

    /** Commands not finished yet, oldest first. */
    public function activeCommands(): Collection
    {
        DeviceCommand::expireStale($this->id);

        return $this->commands()->active()->orderBy('id')->get();
    }

    /** The active command for the command name (and target), if any. */
    public static function findActive(Collection $commands, string $command, ?array $params = null): ?DeviceCommand
    {
        $target = $params ? DeviceCommand::targetOf($command, $params) : null;

        return $commands->first(fn (DeviceCommand $active) => $active->command === $command && ($target === null || $active->target === $target));
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(DeviceMetric::class);
    }

    /**
     * Queues a command for the agent and pushes it over the WebSocket; returns false when it is not
     * accepted (unknown, the device is offline, or the same command is already on its way).
     */
    public function queueCommand(string $command, array $params = [], ?User $user = null): bool
    {
        return $this->issueCommand($command, $params, $user) !== null;
    }

    /**
     * Like queueCommand, returns the new command. A command that is already active (queued, taken
     * or running) is not queued again: clicking twice, two users or a bulk action on many devices
     * never runs it twice.
     */
    public function issueCommand(string $command, array $params = [], ?User $user = null): ?DeviceCommand
    {
        if ($this->commandRefusal($command, $params) !== null) {
            return null;
        }
        $params = DeviceCommand::sanitizeParams($command, $params);
        $target = DeviceCommand::targetOf($command, $params);
        DeviceCommand::expireStale($this->id);

        // The device row is locked, so two requests cannot both find no active command.
        $issued = DB::transaction(function () use ($command, $params, $target, $user) {
            static::query()->whereKey($this->id)->lockForUpdate()->value('id');
            $active = $this->commands()->active()->get();
            if ($this->conflictsWith($active, $command, $target)) {
                return null;
            }

            return $this->commands()->create([
                'command' => $command,
                'status' => 'queued',
                'params' => $params ?: null,
                'target' => $target,
                'issued_by' => $user?->id ?? (auth()->user() instanceof User ? auth()->user()->id : null),
            ]);
        });
        if ($issued === null) {
            return null;
        }

        // Instant delivery over WebSocket; the command stays queued for the HTTP report as a fallback.
        rescue(fn () => \App\Events\DeviceCommandIssued::dispatch($this, $command));
        DeviceCommand::announce($this->id, $target);

        return $issued;
    }

    /** Why the command cannot be sent to this device, or null when it can (duplicates aside). */
    public function commandRefusal(string $command, array $params = []): ?string
    {
        return match (true) {
            ! in_array($command, self::COMMANDS, true) => __('Unknown command'),
            $this->offline => __('The device is offline'),
            ! $this->signsRequests && ! in_array($command, self::LEGACY_COMMANDS, true) => __('The agent does not sign its communication'),
            DeviceCommand::sanitizeParams($command, $params) === null => __('Invalid parameters'),
            $command === 'installUpdate' && ! $this->commandTracking => __('The agent is too old for this command'),
            $command === 'wake' && version_compare((string) $this->agent_version, self::WAKE_VERSION, '<') => __('The agent is too old for this command'),
            $command === 'pingNow' && version_compare((string) $this->agent_version, self::PING_NOW_VERSION, '<') => __('Needs agent :version or newer', ['version' => self::PING_NOW_VERSION]),
            $command === 'scanNetwork' && version_compare((string) $this->agent_version, self::NETWORK_DISCOVERY_VERSION, '<') => __('Needs agent :version or newer', ['version' => self::NETWORK_DISCOVERY_VERSION]),
            // As of its last report: a change in config.json shows with the next one.
            $command === 'scanNetwork' && $this->networkDiscovery !== 'scan' => __('network_discovery is ":level" in its config.json (as of its last report :time), scans need "scan"', [
                'level' => $this->networkDiscovery ?? 'neighbours', 'time' => $this->updated_at?->diffForHumans() ?? '-',
            ]),
            $command === 'scanNetwork' && ! NetworkNeighbour::enabled() => __('Network discovery is turned off in the portal'),
            $command === 'scanPorts' && version_compare((string) $this->agent_version, self::PORT_SCAN_VERSION, '<') => __('Needs agent :version or newer', ['version' => self::PORT_SCAN_VERSION]),
            // As of its last report: a change in config.json shows with the next one.
            $command === 'scanPorts' && $this->portScan !== 'on' => __('port_scan is ":level" in its config.json (as of its last report :time), port scans need "on"', [
                'level' => $this->portScan ?? 'off', 'time' => $this->updated_at?->diffForHumans() ?? '-',
            ]),
            $command === 'scanPorts' && ! PortScanResult::enabled() => __('Port scanning is turned off in the portal'),
            $command === 'sync' && version_compare((string) $this->agent_version, self::SYNC_VERSION, '<') => __('Needs agent :version or newer', ['version' => self::SYNC_VERSION]),
            $command === 'installUpdate' && ($params['kind'] ?? null) === 'pwsh' && version_compare((string) $this->agent_version, self::PWSH_UPDATE_VERSION, '<') => __('The agent is too old for this command'),
            $command === 'installUpdate' && in_array($params['kind'] ?? null, ['pip', 'pipx'], true) && version_compare((string) $this->agent_version, self::PYTHON_UPDATE_VERSION, '<') => __('Needs agent :version or newer', ['version' => self::PYTHON_UPDATE_VERSION]),
            default => null,
        };
    }

    /** Whether an active command makes the new one a duplicate. */
    private function conflictsWith(Collection $active, string $command, ?string $target): bool
    {
        foreach ($active as $other) {
            if ($other->command === $command && $other->target === $target) {
                return true;
            }
            // All updates are being installed already, including this one.
            if ($command === 'installUpdate' && $other->command === 'doUpdates') {
                return true;
            }
            foreach (DeviceCommand::EXCLUSIVE as $group) {
                if (in_array($command, $group, true) && in_array($other->command, $group, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Agents 1.8.0+ report progress and results; older ones only take their commands. */
    public function getCommandTrackingAttribute(): bool
    {
        return $this->agent_version !== null && version_compare($this->agent_version, self::TRACKING_VERSION, '>=');
    }

    /** server, laptop or desktop, as detected by the agent. */
    public function getTypeAttribute(): string
    {
        if ($this->isPingOnly) {
            return 'ping';
        }
        $type = $this->data->machine->Type ?? null;

        return array_key_exists($type, self::TYPE_ICONS) ? $type : 'desktop';
    }

    /** windows or linux; older agents do not report it, guess from the OS name. */
    public function getPlatformAttribute(): string
    {
        $platform = $this->data->machine->Platform ?? null;
        if (in_array($platform, ['windows', 'linux'], true)) {
            return $platform;
        }

        return preg_match('/linux|ubuntu|debian/i', (string) $this->os) ? 'linux' : 'windows';
    }

    public function getTypeIconAttribute(): string
    {
        return self::TYPE_ICONS[$this->type];
    }

    /** Version reported by the agent; null for agents older than version reporting. */
    public function getAgentVersionAttribute(): ?string
    {
        return $this->data->machine->AgentVersion ?? null;
    }

    public function getAgentOutdatedAttribute(): bool
    {
        $latest = AgentScript::version();

        return $latest !== null && ($this->agent_version === null || version_compare($this->agent_version, $latest, '<'));
    }

    /** Old agents cannot update themselves, they have to be reinstalled. */
    public function getAgentUpdatableAttribute(): bool
    {
        return $this->agent_version !== null;
    }

    /** Whether the agent allows remediation scripts (config.json on the device, agents 1.7.0+). */
    public function getScriptsEnabledAttribute(): bool
    {
        return ($this->data->machine->ScriptsEnabled ?? false) === true;
    }

    /**
     * What the agent may do to find devices in its networks (config.json on the device, agents
     * 1.16.0+): off, neighbours (reports its ARP table) or scan (also scans on request); null for
     * older agents.
     */
    public function getNetworkDiscoveryAttribute(): ?string
    {
        $level = $this->isPingOnly ? null : ($this->data->machine->NetworkDiscovery ?? null);

        return in_array($level, NetworkNeighbour::LEVELS, true) ? $level : null;
    }

    /**
     * Whether the agent sends the records of its security logs (security_logs in its config.json,
     * agents 1.17.0+); false for older agents.
     */
    public function getSecurityLogsAttribute(): bool
    {
        return ($this->data->machine->Features->security_logs ?? null) === 'on';
    }

    /**
     * Whether the agent scans the open ports of an address in its network on request (port_scan in
     * its config.json, agents 1.18.0+): off (default) or on; null for older agents and ping-only devices.
     */
    public function getPortScanAttribute(): ?string
    {
        $level = $this->isPingOnly ? null : ($this->data->machine->PortScan ?? null);

        return in_array($level, ['off', 'on'], true) ? $level : null;
    }

    /**
     * What the agent is set to do, for the Agent tab (read only: the settings are changed on the
     * device). Each: key, label, description, setting (the config.json key, or null), on, detail
     * (what the state means, or why it is off) and needs (the agent version that adds it, when
     * this agent is older).
     *
     * @return list<array{key: string, label: string, description: string, setting: ?string, on: bool, detail: ?string, needs: ?string}>
     */
    public function getFeaturesAttribute(): array
    {
        $version = $this->agent_version ?? '0';
        $has = fn (string $needed) => version_compare($version, $needed, '>=');
        $feature = fn (string $key, string $label, string $description, ?string $setting, bool $on, ?string $detail = null, ?string $needs = null) => [
            'key' => $key, 'label' => $label, 'description' => $description, 'setting' => $setting, 'on' => $on && $needs === null, 'detail' => $needs !== null ? __('needs agent :version', ['version' => $needs]) : $detail, 'needs' => $needs,
        ];
        $discovery = $this->networkDiscovery;
        $disk = $this->diskHealth;
        $targets = $this->isPingOnly || ! $has(self::PING_VERSION) ? 0 : count(self::pingTargetsFor($this));

        return [
            $feature('scripts', __('Remediation scripts'), __('Runs the scripts of the Scripts page as root.'), 'scripts_enabled', $this->scriptsEnabled),
            $feature('network_discovery', __('Network discovery'), __('Reports devices it sees in its networks and scans them on request.'), 'network_discovery', $discovery !== null && $discovery !== 'off',
                $discovery === 'scan' ? __('ARP table and scans') : ($discovery === 'neighbours' ? __('ARP table') : null), $has(self::NETWORK_DISCOVERY_VERSION) ? null : self::NETWORK_DISCOVERY_VERSION),
            $feature('port_scan', __('Port scanning'), __('Scans the open ports of an address in its network on request and reports what answers.'), 'port_scan', $this->portScan === 'on',
                $this->portScan === 'on' ? __('on request') : ($has(self::PORT_SCAN_VERSION) ? __('off') : null), $has(self::PORT_SCAN_VERSION) ? null : self::PORT_SCAN_VERSION),
            $feature('security_inventory', __('Security inventory'), __('Software, programs, open ports and settings for the security checks, every hour.'), null, $this->securityInventory()->exists(),
                ($collected = $this->securityInventory()->value('collected_at')) ? __('last :time', ['time' => Carbon::parse($collected)->diffForHumans()]) : null, $has(self::SECURITY_VERSION) ? null : self::SECURITY_VERSION),
            $feature('security_logs', __('Security logs'), __('Sends sign-in and system log records, so the sign-in checks (password guessing, new administrators) work.'), 'security_logs', $this->securityLogs, null, $has(self::SECURITY_VERSION) ? null : self::SECURITY_VERSION),
            $feature('disk_health', __('Disk health'), __('S.M.A.R.T. values of the disks, every hour.'), null, $disk !== null && $this->virtualization === null,
                match (true) {
                    $this->virtualization !== null => __('not on virtual machines'),
                    $disk === null && $this->platform === 'linux' => __('needs smartmontools'),
                    default => null,
                }),
            $feature('wake', __('Wake-on-LAN relay'), __('Wakes other devices of its network (lan, wifi).'), null, $has(self::WAKE_VERSION), null, $has(self::WAKE_VERSION) ? null : self::WAKE_VERSION),
            $feature('ping', __('Pings ping-only devices'), __('Checks devices without the agent in its network every 30 s.'), null, $has(self::PING_VERSION),
                $has(self::PING_VERSION) ? trans_choice(':count device assigned|:count devices assigned', $targets) : null, $has(self::PING_VERSION) ? null : self::PING_VERSION),
        ];
    }

    /**
     * The networks the agent can scan (Scan in its menu): the IPv4 subnets of its connected wired,
     * Wi-Fi and bridge interfaces, /22 and smaller.
     *
     * @return list<string>
     */
    public function getScannableNetworksAttribute(): array
    {
        if ($this->isPingOnly) {
            return [];
        }
        $networks = [];
        foreach ($this->networks as $interface) {
            if (! $interface['Connected'] || ! in_array($interface['Type'], self::RELAY_INTERFACE_TYPES, true)) {
                continue;
            }
            foreach ($interface['Addresses'] as $address) {
                $cidr = \App\Support\NetworkMap::cidr($address['Address'], $address['PrefixLength']);
                if ($cidr !== null && $address['PrefixLength'] >= DeviceCommand::MIN_SCAN_PREFIX && $address['PrefixLength'] <= 30) {
                    $networks[] = $cidr;
                }
            }
        }

        return array_values(array_unique($networks));
    }

    /**
     * The MAC a neighbour currently has at an address (the most recently seen), with its vendor, so
     * a ping-only device added or edited by its IP can adopt the MAC that network discovery found
     * there. Null when no agent sees that address. @return array{mac: string, vendor: ?string}|null
     */
    public static function discoveredMacFor(?string $address): ?array
    {
        if (! $address || ! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $neighbour = NetworkNeighbour::query()->where('ip', $address)
            ->where('last_seen_at', '>=', now()->subHours(NetworkNeighbour::SHOWN_HOURS))
            ->orderByDesc('last_seen_at')->first();

        return $neighbour === null ? null : ['mac' => $neighbour->mac, 'vendor' => \App\Support\MacVendor::lookup($neighbour->mac)];
    }

    /** The IPv4 address whose ports are scanned: a ping-only device's address, or its first LAN IPv4. */
    public function portScanAddress(): ?string
    {
        if ($this->isPingOnly) {
            return $this->ping_address ?: null;
        }
        foreach ($this->networks as $interface) {
            if (! $interface['Connected'] || ! in_array($interface['Type'], self::RELAY_INTERFACE_TYPES, true)) {
                continue;
            }
            foreach ($interface['Addresses'] as $address) {
                if (filter_var($address['Address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $address['Address'];
                }
            }
        }

        return null;
    }

    /**
     * An online agent that can scan this host's ports on request. Null when nothing can scan it
     * (the menu hides the action then).
     */
    public function portScanner(): ?Device
    {
        $address = $this->portScanAddress();

        return $address === null ? null : self::portScannerFor($address, $this->id);
    }

    /**
     * Any online agent that can scan the ports of an address: an agent (not ping-only) whose
     * network contains it, with port_scan on and the portal switch enabled, and not the host
     * itself (an agent cannot scan its own address). Null when nothing can scan it. $agents lets a
     * caller that checks many addresses (the Networks page) pass the candidates once.
     */
    public static function portScannerFor(string $address, ?int $excludeId = null, ?Collection $agents = null): ?Device
    {
        if (! PortScanResult::enabled() || ! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $agents ??= static::all()->reject(fn (Device $device) => $device->isPingOnly);
        foreach ($agents as $agent) {
            if ($agent->id !== $excludeId
                && $agent->commandRefusal('scanPorts', ['ip' => $address]) === null
                && collect($agent->scannableNetworks)->contains(fn ($cidr) => \App\Support\NetworkMap::contains($cidr, $address))) {
                return $agent;
            }
        }

        return null;
    }

    public function scriptRuns(): HasMany
    {
        return $this->hasMany(ScriptRun::class);
    }

    public function securityInventory(): HasOne
    {
        return $this->hasOne(SecurityInventory::class);
    }

    public function securityFindings(): HasMany
    {
        return $this->hasMany(SecurityFinding::class);
    }

    /** Agents 1.7.0+ register a key and sign every request; older ones only get the agent update. */
    public function getSignsRequestsAttribute(): bool
    {
        return $this->public_key !== null;
    }

    public function getConnectedViaWebsocketAttribute(): bool
    {
        return $this->last_ws_at !== null && $this->last_ws_at->diffInSeconds() <= self::HEARTBEAT_TIMEOUT;
    }

    public function getConnectedViaApiAttribute(): bool
    {
        $last = $this->last_http_at ?? $this->updated_at;

        return $last !== null && $last->diffInSeconds() <= self::REPORT_TIMEOUT;
    }

    public function getDataAttribute($value)
    {
        return (json_decode($value) ?? []);
    }

    public function getDrivesAttribute($value)
    {
        if ([] === $this->data) {
            return [];
        }

        $drives = self::listOf(json_decode(json_encode($this->data->machine ?? []), true)['Drives'] ?? []);
        foreach ( $drives  as $key => $drive) {
            $drive = (array)$drive;
            if (($drive['Size'] ?? 0) <= 0) {
                continue;
            }

            $usedSpace = (int) $drive['Size'] - (int) ($drive['SizeRemaining'] ?? 0);
            $drives[$key]['PercentUsed'] = round($usedSpace / ((int) $drive['Size'] / 100));
        }

        return $drives;
    }

    public function setDrivesAttribute($value)
    {
        $this->attributes['drives'] = json_encode($value);
    }

    public function getDisplayNameAttribute()
    {
        $name = $this->friendly_name;
        if (empty($name)) {
            $name = $this->name;
        }

        // Enrolled devices have no name until their first report.
        return $name ?: __('Device #:id', ['id' => $this->id]);
    }

    public function getNiceUptimeAttribute()
    {
        if (isset($this->data->machine->uptime)) {
            // The two largest units ("1 day 16 hours", "5 minutes 3 seconds"), the full one is the title.
            return CarbonInterval::seconds($this->data->machine->uptime)->cascade()->forHumans(['parts' => 2]);
        }
        return false;
    }

    public function getOfflineAttribute()
    {
        if ($this->isPingOnly) {
            return $this->last_seen_at === null || $this->last_seen_at->diffInSeconds() > self::HEARTBEAT_TIMEOUT;
        }
        if ($this->last_seen_at !== null) {
            return $this->last_seen_at->diffInSeconds() > self::HEARTBEAT_TIMEOUT;
        }

        // Agents without heartbeat support only send the periodic report.
        return $this->updated_at->diffInSeconds() > 900;
    }

    public function getRestartPendingAttribute()
    {
        if (isset($this->liveState['restart_required'])) {
            return $this->liveState['restart_required'];
        }

        if (null !== ($this->data->machine->RestartRequired ?? null)) {
            if (filter_var($this->data->machine->RestartRequired, FILTER_VALIDATE_BOOLEAN) === true) {
                return true;
            }
        }

        return false;
    }

    public function getLastLogonUserAttribute()
    {
        if (isset($this->data->machine->last_logon_user)) {
            return $this->data->machine->last_logon_user;
        }
        return false;
    }

    public function getAppsPackagesUpdatesAttribute()
    {
        if (isset($this->data->packages_updates)) {
            return (array) self::stdToArray($this->data->packages_updates);
        }
        return [];
    }

    /**
     * Virtual machine or container the device runs in, null on physical hardware (or agents
     * before 1.5.0): ['type' => 'vm'|'container', 'name' => 'kvm', 'label' => 'KVM'].
     */
    public function getVirtualizationAttribute(): ?array
    {
        $virtualization = $this->data->machine->Virtualization ?? null;
        $type = $virtualization->Type ?? null;
        $name = (string) ($virtualization->Name ?? '');
        if (! in_array($type, ['vm', 'container'], true)) {
            return null;
        }

        return ['type' => $type, 'name' => $name, 'label' => self::VIRTUALIZATION_NAMES[$name] ?? ($name !== '' ? $name : __('unknown'))];
    }

    /** Disk health is not shown for virtual machines and containers (virtual disks have no S.M.A.R.T.). */
    public function getShowDiskHealthAttribute(): bool
    {
        return $this->diskHealth !== null && $this->virtualization === null;
    }

    /** Battery level in percent, null without a battery (live state first, then the report). */
    public function getBatteryLevelAttribute(): ?int
    {
        $battery = $this->liveState['power']['battery'] ?? ($this->data->machine->Battery ?? null);

        return is_numeric($battery) ? (int) $battery : null;
    }

    /** Whether the device runs on mains power (charging or full); null when unknown (older agents). */
    public function getPluggedInAttribute(): ?bool
    {
        if (isset($this->liveState['power'])) {
            return $this->liveState['power']['plugged'];
        }
        $plugged = $this->data->machine->PluggedIn ?? null;

        return $plugged === null ? null : filter_var($plugged, FILTER_VALIDATE_BOOLEAN);
    }

    /** Outdated PowerShell Gallery modules per edition (Windows PowerShell, PowerShell 7). */
    public function getModuleUpdatesAttribute(): array
    {
        $modules = [];
        foreach (self::listOf(json_decode(json_encode($this->data->module_updates ?? []), true)) as $row) {
            // Agents up to 1.7.2 on Windows PowerShell 5.1 sent all modules of an edition as one row
            // with lists (Name: [..], Version: [..], ...): split it into one row per module.
            $count = is_array($row['Name'] ?? null) ? count($row['Name']) : 1;
            for ($i = 0; $i < $count; $i++) {
                $module = [];
                foreach (['Name', 'Version', 'Available', 'Edition', 'User', 'Error'] as $field) {
                    $value = $row[$field] ?? null;
                    if (is_array($value)) {
                        // User and Error skip null values in such a list, they only line up when complete.
                        $value = array_is_list($value) && count($value) === $count ? ($value[$i] ?? null) : null;
                    }
                    $module[$field] = is_scalar($value) ? (string) $value : null;
                }
                if (filled($module['Name'])) {
                    $modules[] = $module;
                }
            }
        }

        return $modules;
    }

    /**
     * OS updates, installable ones first. Status (Linux agents 1.6+): installable, phased (apt
     * defers it, rolled out gradually) or held (apt will not install it now); missing means installable.
     * restart: installed on Windows, the restart finishes it.
     */
    public function getUpdatesAttribute()
    {
        if (! isset($this->data->os_updates)) {
            return [];
        }

        $order = ['installable' => 0, 'restart' => 1, 'phased' => 2, 'held' => 3];
        // Windows lists an installed update until the restart finishes it (RebootRequired).
        $restartPending = $this->platform === 'windows' && $this->restartPending;
        $updates = array_map(function ($update) use ($restartPending) {
            $update = (array) $update;
            $update['Status'] = match (true) {
                in_array($update['Status'] ?? null, ['phased', 'held'], true) => $update['Status'],
                $restartPending && filter_var($update['RebootRequired'] ?? false, FILTER_VALIDATE_BOOLEAN) => 'restart',
                default => 'installable',
            };

            return $update;
        }, (array) self::stdToArray($this->data->os_updates));
        usort($updates, fn ($a, $b) => $order[$a['Status']] <=> $order[$b['Status']]);

        return $updates;
    }

    /** OS updates Install updates can install now (without phased and held back ones). */
    public function getInstallableUpdatesAttribute(): array
    {
        return array_values(array_filter($this->updates, fn ($update) => $update['Status'] === 'installable'));
    }

    /**
     * The installUpdate parameters for one row of the update lists ('os', 'app' or 'module'), or
     * null when that update cannot be installed on its own (older agents, phased or held back
     * packages, modules in a Windows user profile).
     */
    public function updateTarget(string $section, array $row): ?array
    {
        if (! $this->commandTracking) {
            return null;
        }

        $params = match ($section) {
            'os' => $this->platform === 'linux'
                ? (($row['Status'] ?? 'installable') === 'installable' ? ['kind' => 'apt', 'id' => strtok((string) ($row['Title'] ?? ''), ' ') ?: '', 'title' => $row['Title'] ?? null] : null)
                : (! empty($row['Id']) ? ['kind' => 'windows', 'id' => (string) $row['Id'], 'title' => $row['Title'] ?? null] : null),
            'app' => match (true) {
                str_starts_with((string) ($row['Source'] ?? ''), 'flatpak') => ['kind' => 'flatpak', 'id' => (string) ($row['Id'] ?? ''), 'user' => preg_match('/^flatpak \((.+)\)$/', $row['Source'], $m) ? $m[1] : null],
                ($row['Source'] ?? null) === 'snap' => ['kind' => 'snap', 'id' => (string) ($row['Id'] ?? '')],
                // Installed from the GitHub release, the agent installs the newer one from there.
                ($row['Source'] ?? null) === 'github.com/PowerShell' => version_compare((string) $this->agent_version, self::PWSH_UPDATE_VERSION, '<') ? null : ['kind' => 'pwsh', 'id' => (string) ($row['Avaliable'] ?? ''), 'title' => 'PowerShell '.($row['Avaliable'] ?? '')],
                // Python: a pipx app or a user's pip --user package ("pipx"/"pip", with " (user)").
                str_starts_with((string) ($row['Source'] ?? ''), 'pipx') => version_compare((string) $this->agent_version, self::PYTHON_UPDATE_VERSION, '<') ? null : ['kind' => 'pipx', 'id' => (string) ($row['Id'] ?? ''), 'user' => preg_match('/\((.+)\)$/', (string) $row['Source'], $m) ? $m[1] : null],
                str_starts_with((string) ($row['Source'] ?? ''), 'pip') => version_compare((string) $this->agent_version, self::PYTHON_UPDATE_VERSION, '<') ? null : ['kind' => 'pip', 'id' => (string) ($row['Id'] ?? ''), 'user' => preg_match('/\((.+)\)$/', (string) $row['Source'], $m) ? $m[1] : null],
                // Scope user: installed only for the logged-on user (agents 1.14.0+ update it in their session).
                $this->platform === 'windows' => ['kind' => 'winget', 'id' => (string) ($row['Id'] ?? ''), 'source' => in_array($row['Source'] ?? null, ['winget', 'msstore'], true) ? $row['Source'] : null, 'scope' => ($row['Scope'] ?? null) === 'user' ? 'user' : null],
                default => null,
            },
            'module' => $this->platform === 'windows' && ! empty($row['User'])
                ? null
                : ['kind' => 'module', 'id' => (string) ($row['Name'] ?? ''), 'edition' => $row['Edition'] ?? null, 'version' => $row['Available'] ?? null, 'user' => $row['User'] ?: null],
            default => null,
        };
        if ($params === null) {
            return null;
        }
        $params = array_filter($params, fn ($value) => $value !== null);
        $params['title'] ??= $params['id'];

        return DeviceCommand::sanitizeParams('installUpdate', $params);
    }

    /** Interface types with their icon and label (the agent reports Type from 1.8.0 on). */
    public const NETWORK_TYPES = [
        'lan' => ['icon' => 'fas fa-ethernet', 'label' => 'LAN'],
        'wifi' => ['icon' => 'fas fa-wifi', 'label' => 'Wi-Fi'],
        'vpn' => ['icon' => 'fas fa-shield-alt', 'label' => 'VPN'],
        'docker' => ['icon' => 'fab fa-docker', 'label' => 'Docker'],
        'bridge' => ['icon' => 'fas fa-project-diagram', 'label' => 'Bridge'],
        'virtual' => ['icon' => 'fas fa-clone', 'label' => 'Virtual'],
        'cellular' => ['icon' => 'fas fa-signal', 'label' => 'Mobile'],
        'bluetooth' => ['icon' => 'fab fa-bluetooth-b', 'label' => 'Bluetooth'],
    ];

    /** Whether the address is reachable from the internet (not private, loopback, link-local …). */
    public static function isPublicIp(?string $address): bool
    {
        $address = $address === null ? '' : explode('%', $address)[0];

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // The shared address space of carrier-grade NAT (100.64.0.0/10) is not reachable either.
        $ipv4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($address) : false;

        return $ipv4 === false || ($ipv4 & 0xFFC00000) !== (ip2long('100.64.0.0') & 0xFFC00000);
    }

    /**
     * The public addresses of the device: the one it reaches the server from (behind NAT the
     * address of its router; none when the server is in the same network) first, then the public
     * ones of its connected interfaces (IPv6 mostly).
     *
     * @return list<string>
     */
    public function getPublicAddressesAttribute(): array
    {
        if ($this->isPingOnly) {
            return [];
        }
        $interfaces = collect($this->networks)->where('Connected', true)->pluck('IPAddresses')->flatten()
            ->map(fn ($address) => explode('%', (string) $address)[0]);

        return collect([$this->public_ip])->merge($interfaces)
            ->filter(fn ($address) => self::isPublicIp($address))->unique()->values()->all();
    }

    /**
     * Whether the device reaches the server over a VPN of its own: from a private address that is
     * none of its other interfaces' while a VPN is connected (the server in the home network, the
     * internet not through the tunnel). Its networks are not the server's then.
     */
    public function getReachesOverVpnAttribute(): bool
    {
        if ($this->isPingOnly || $this->public_ip === null || self::isPublicIp($this->public_ip)) {
            return false;
        }
        $connected = collect($this->networks)->where('Connected', true);

        return $connected->where('Type', 'vpn')->isNotEmpty()
            && ! $connected->where('Type', '!=', 'vpn')->pluck('IPAddresses')->flatten()->map(fn ($ip) => explode('%', (string) $ip)[0])->contains($this->public_ip);
    }

    /**
     * Network interfaces, connected first: Name, Description, Type (see NETWORK_TYPES, guessed
     * from the name for older agents), Connected, Status, Mac, IPAddresses, Addresses, Gateway, GatewayMac, PublicAddress.
     */
    public function getNetworksAttribute(): array
    {
        // A ping-only device has the one interface it was added with.
        if ($this->isPingOnly) {
            return [[
                'Name' => 'ping', 'Description' => '', 'Type' => 'lan', 'Connected' => ! $this->offline, 'Status' => '',
                'Mac' => $this->ping_mac, 'IPAddresses' => array_filter([$this->ping_address]),
                'Addresses' => $this->ping_address ? [['Address' => $this->ping_address, 'PrefixLength' => (int) ($this->ping_prefix ?? 24)]] : [],
            ]];
        }
        $networks = [];
        foreach (self::listOf(json_decode(json_encode($this->data->machine->Networks ?? []), true)) as $network) {
            $name = (string) ($network['Name'] ?? '');
            $description = (string) ($network['InterfaceDescription'] ?? '');
            $status = (string) ($network['Status'] ?? '');
            $addresses = array_values(array_filter((array) ($network['IPAddresses'] ?? []), 'is_string'));
            $type = $network['Type'] ?? null;
            // Agents before 1.12.1 on Windows took "Realtek" for LTE (a mobile network).
            if ($type === 'cellular' && ! preg_match('/^(wwan|ww)|mobile broadband|cellular|\blte\b/i', $name.' '.$description)) {
                $type = null;
            }
            $networks[] = [
                'Name' => $name,
                'Description' => $description,
                'Type' => isset(self::NETWORK_TYPES[$type]) ? $type : self::guessNetworkType($name, $description),
                'Connected' => isset($network['Connected']) ? (bool) $network['Connected'] : in_array(strtolower($status), ['up', 'connected'], true),
                'Status' => $status,
                'Mac' => $network['Mac'] ?? null,
                'IPAddresses' => $addresses,
                // Agents 1.9.0+ send the prefix length of each address (Wake-on-LAN relays).
                'Addresses' => array_values(array_filter(array_map(
                    fn ($address) => is_array($address) && is_string($address['Address'] ?? null) && is_numeric($address['PrefixLength'] ?? null)
                        ? ['Address' => $address['Address'], 'PrefixLength' => (int) $address['PrefixLength']] : null,
                    self::listOf($network['Addresses'] ?? []),
                ))),
                // Agents 1.15.0+: the default gateway of the interface and its MAC (network map).
                'Gateway' => filter_var($network['Gateway'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: null,
                'GatewayMac' => is_string($network['GatewayMac'] ?? null) && preg_match(DeviceCommand::MAC_PATTERN, $network['GatewayMac'])
                    ? strtoupper(str_replace('-', ':', $network['GatewayMac'])) : null,
                // Agents 1.21.0+: the public address the internet sees from this interface (each
                // can have its own: a second card, a mobile network, a VPN to the server only).
                'PublicAddress' => is_string($network['PublicAddress'] ?? null) && self::isPublicIp($network['PublicAddress']) ? $network['PublicAddress'] : null,
            ];
        }
        usort($networks, fn ($a, $b) => [! $a['Connected'], strtolower($a['Name'])] <=> [! $b['Connected'], strtolower($b['Name'])]);

        return $networks;
    }

    /**
     * The IPv4 networks of the device's interfaces of these types: [network => [prefix, broadcast]],
     * e.g. ['192.168.1.0/24' => ['broadcast' => '192.168.1.255']]. Loopback and link-local are left out.
     *
     * @param  array<int, string>  $types
     * @return array<string, array{broadcast: string}>
     */
    public function ipv4Networks(array $types, bool $connectedOnly = false): array
    {
        $networks = [];
        foreach ($this->networks as $network) {
            if ($connectedOnly && ! $network['Connected']) {
                continue;
            }
            if (! in_array($network['Type'], $types, true)) {
                continue;
            }
            foreach ($network['Addresses'] as $address) {
                $ip = ip2long($address['Address']);
                $prefix = $address['PrefixLength'];
                if ($ip === false || $prefix < 8 || $prefix > 30 || str_starts_with($address['Address'], '127.') || str_starts_with($address['Address'], '169.254.')) {
                    continue;
                }
                $mask = (-1 << (32 - $prefix)) & 0xFFFFFFFF;
                $base = $ip & $mask;
                $networks[long2ip($base).'/'.$prefix] = ['broadcast' => long2ip($base | (~$mask & 0xFFFFFFFF))];
            }
        }

        return $networks;
    }

    /**
     * The IPv4 networks of the cards a magic packet can wake (network => [broadcast]): the address
     * set by hand (Wake-on-LAN settings) or the ones the agent reports.
     */
    public function wakeNetworks(): array
    {
        if (! $this->isPingOnly && $this->wake_address && ($ip = ip2long($this->wake_address)) !== false) {
            $prefix = (int) ($this->wake_prefix ?? 24);
            $mask = (-1 << (32 - $prefix)) & 0xFFFFFFFF;

            return [long2ip($ip & $mask).'/'.$prefix => ['broadcast' => long2ip(($ip & $mask) | (~$mask & 0xFFFFFFFF))]];
        }

        return $this->ipv4Networks(self::WAKE_INTERFACE_TYPES);
    }

    /**
     * Checks the Wake-on-LAN settings of a device with the agent; empty fields mean "as the agent
     * reports". Null when they are not valid.
     */
    public static function sanitizeWakeSettings(?string $mac, ?string $address, int|string|null $prefix): ?array
    {
        $mac = $mac === null || trim($mac) === '' ? null : strtoupper(str_replace('-', ':', trim($mac)));
        $address = $address === null || trim($address) === '' ? null : trim($address);
        $prefix = $address === null ? null : ($prefix === null || $prefix === '' ? 24 : (int) $prefix);
        if (($mac !== null && (! preg_match(DeviceCommand::MAC_PATTERN, $mac) || $mac === '00:00:00:00:00:00'))
            || ($address !== null && (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $prefix < 8 || $prefix > 30))) {
            return null;
        }

        return ['wake_mac' => $mac, 'wake_address' => $address, 'wake_prefix' => $prefix];
    }

    /**
     * MAC addresses a magic packet can wake: wired interfaces first, then Wi-Fi.
     *
     * @return array<int, string>
     */
    public function getWakeMacsAttribute(): array
    {
        // Set by hand (Wake-on-LAN settings): only that card.
        if (! $this->isPingOnly && $this->wake_mac) {
            return [$this->wake_mac];
        }
        $macs = [];
        foreach (['lan', 'wifi'] as $type) {
            foreach ($this->networks as $network) {
                $mac = strtoupper(str_replace('-', ':', (string) $network['Mac']));
                if ($network['Type'] === $type && preg_match(DeviceCommand::MAC_PATTERN, $mac) && $mac !== '00:00:00:00:00:00' && ! in_array($mac, $macs, true)) {
                    $macs[] = $mac;
                }
            }
        }

        return array_slice($macs, 0, 8);
    }

    /**
     * An online agent in the same network that can send the magic packet: it shares an IPv4
     * network with a wake interface of this device and (when both are known) the public address.
     * Returns [relay, broadcasts] or null.
     *
     * @return array{0: Device, 1: array<int, string>}|null
     */
    public function wakeRelay(): ?array
    {
        if ($this->wakeMacs === []) {
            return null;
        }
        $best = $this->bestRelay(self::WAKE_INTERFACE_TYPES, fn (Device $relay) => $relay->commandRefusal('wake', ['macs' => ['00:00:00:00:00:01'], 'broadcasts' => ['255.255.255.255'], 'device' => $this->id]) === null, true, $this->wakeNetworks());
        if ($best === null) {
            return null;
        }
        $broadcasts = array_values(array_unique(array_merge(array_column($best[1], 'broadcast'), ['255.255.255.255'])));

        return [$best[0], array_slice($broadcasts, 0, 4)];
    }

    /**
     * The best online, signing agent sharing an IPv4 network with this device's interfaces of the
     * types (and, when both are known, the public address): [relay, shared networks] or null.
     *
     * Devices on a battery move between networks: their last report may show a network they have
     * left, and the same private address can be another machine elsewhere. They are only taken
     * when $allowMobile, nothing stationary qualifies and they reported the same public address as
     * this device. Every relay needs a recent report, so its networks are current.
     */
    private function bestRelay(array $types, callable $qualifies, bool $allowMobile = false, ?array $networks = null): ?array
    {
        $best = $this->relayCandidates($types, $qualifies, $allowMobile, $networks)[0] ?? null;

        return $best === null ? null : [$best[0], $best[1]];
    }

    /**
     * The agents that can act for this device in its network ([relay, shared networks, score]),
     * best first: online, signing, reachable over the API, behind the same public address when
     * both are known; a mobile one only with $allowMobile and the same public address.
     *
     * @param  Collection<int, Device>|null  $agents  the agents to choose from (loaded once by callers that ask for many devices)
     */
    private function relayCandidates(array $types, callable $qualifies, bool $allowMobile = false, ?array $networks = null, ?Collection $agents = null): array
    {
        $networks ??= $this->ipv4Networks($types);
        if ($networks === []) {
            return [];
        }

        $candidates = [];
        foreach ($agents ?? static::query()->where('kind', 'agent')->orderBy('id')->get() as $relay) {
            if ($relay->id === $this->id || $relay->offline || ! $relay->signsRequests || ! $relay->connectedViaApi || ! $qualifies($relay)) {
                continue;
            }
            if ($relay->isMobile && (! $allowMobile || $this->public_ip === null || $relay->public_ip !== $this->public_ip)) {
                continue;
            }
            // Only addresses of the same family tell: the same network can reach the server over
            // IPv6 from one device and over IPv4 from another.
            if ($this->behindOtherAddress($relay)) {
                continue;
            }
            $shared = array_intersect_key($networks, $relay->ipv4Networks(self::RELAY_INTERFACE_TYPES, true));
            if ($shared === []) {
                continue;
            }
            // A relay behind the same public address is the safer match, then wired ones.
            $score = ($relay->isMobile ? 0 : 4)
                + ($this->public_ip !== null && $relay->public_ip === $this->public_ip ? 2 : 0)
                + ($relay->ipv4Networks(['lan'], true) !== [] ? 1 : 0);
            $candidates[] = [$relay, $shared, $score];
        }
        usort($candidates, fn ($a, $b) => [$b[2], $a[0]->id] <=> [$a[2], $b[0]->id]);

        return $candidates;
    }

    /**
     * Both reach the server from different public addresses (another site). An address that is not
     * public (an agent in the server's own network reaches it over a private one) tells nothing,
     * nor does one of another family (IPv6 from one device, IPv4 from another).
     */
    private function behindOtherAddress(Device $relay): bool
    {
        return self::isPublicIp($this->public_ip) && self::isPublicIp($relay->public_ip) && $this->public_ip !== $relay->public_ip
            && str_contains($this->public_ip, ':') === str_contains($relay->public_ip, ':');
    }

    /** Runs on a battery (laptops, tablets): it may be in another network than last time. */
    public function getIsMobileAttribute(): bool
    {
        return ! $this->isPingOnly && ($this->type === 'laptop' || $this->batteryLevel !== null);
    }

    public function getIsPingOnlyAttribute(): bool
    {
        return ($this->attributes['kind'] ?? 'agent') === 'ping';
    }

    /** The agent that pings this ping-only device now (online, 1.10.0+, in its network). */
    public function pingRelay(): ?Device
    {
        if (! $this->isPingOnly) {
            return null;
        }

        $relayId = self::pingAssignments()[$this->id] ?? null;

        return $relayId === null ? null : static::query()->find($relayId);
    }

    /**
     * Which agent pings which ping-only device (device id => agent id), spread over all agents
     * that can ping it: each device goes to the one of its candidates (online, 1.10.0+, in its
     * network, not on a battery) with the fewest devices so far, staying with its current agent
     * on a tie so the work does not move around; at most MAX_PING_TARGETS per agent.
     *
     * @return array<int, int>
     */
    public static function pingAssignments(): array
    {
        $agents = static::query()->where('kind', 'agent')->orderBy('id')->get()
            ->filter(fn (Device $agent) => version_compare((string) $agent->agent_version, self::PING_VERSION, '>='))->values();
        if ($agents->isEmpty()) {
            return [];
        }
        $load = [];
        $assignments = [];
        foreach (static::query()->where('kind', 'ping')->whereNotNull('ping_address')->orderBy('id')->get() as $device) {
            $best = null;
            foreach ($device->relayCandidates(['lan'], fn () => true, false, null, $agents) as [$relay]) {
                $count = $load[$relay->id] ?? 0;
                if ($count >= self::MAX_PING_TARGETS) {
                    continue;
                }
                $rank = [$count, $relay->id === $device->ping_relay_id ? 0 : 1];
                if ($best === null || $rank < $best[1]) {
                    $best = [$relay->id, $rank];
                }
            }
            if ($best !== null) {
                $assignments[$device->id] = $best[0];
                $load[$best[0]] = ($load[$best[0]] ?? 0) + 1;
            }
        }

        return $assignments;
    }

    /**
     * What the agent pings: the ping-only devices it is the relay of, [{id, address}].
     *
     * @return array<int, array{id: int, address: string}>
     */
    public static function pingTargetsFor(Device $relay): array
    {
        if ($relay->isPingOnly || version_compare((string) $relay->agent_version, self::PING_VERSION, '<')) {
            return [];
        }

        $mine = array_keys(array_filter(self::pingAssignments(), fn ($relayId) => $relayId === $relay->id));

        return static::query()->whereKey($mine)->orderBy('id')->get()
            ->take(self::MAX_PING_TARGETS)
            ->map(fn (Device $device) => ['id' => $device->id, 'address' => $device->ping_address])
            ->values()->all();
    }

    /**
     * Results of the agent's pings ([{id, up, rtt}]): only for the ping-only devices it may ping
     * (it shares their network). An answer is the device's heartbeat. Returns how many were taken.
     */
    public static function recordPings(Device $relay, mixed $results): int
    {
        if (! is_array($results)) {
            return 0;
        }
        $allowed = collect(self::pingTargetsFor($relay))->pluck('id')->all();
        $taken = 0;
        foreach (array_slice($results, 0, self::MAX_PING_TARGETS) as $result) {
            $id = is_array($result) && is_int($result['id'] ?? null) ? $result['id'] : null;
            if ($id === null || ! in_array($id, $allowed, true)) {
                continue;
            }
            $up = filter_var($result['up'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $rtt = is_numeric($result['rtt'] ?? null) ? max(0, min(60000, round((float) $result['rtt'], 1))) : null;
            $values = ['ping_relay_id' => $relay->id, 'ping_rtt' => $up ? $rtt : null];
            if ($up) {
                $values['last_seen_at'] = now();
                DeviceCommand::completeWakes($id);
            }
            static::query()->whereKey($id)->toBase()->update($values);
            PingResult::query()->create(['device_id' => $id, 'up' => $up, 'rtt' => $up ? $rtt : null, 'relay_id' => $relay->id]);
            \App\Support\LiveUpdates::device($id, 'ping');
            $taken++;
        }

        return $taken;
    }

    /** Ping results one backfill request may carry. */
    public const PING_BACKFILL_MAX = 2000;

    /**
     * Pings a relay made while it could not reach the server (agents 1.11.0+), each with the time
     * it was made: [{id, up, rtt, at}]. Only for its current targets, from the last 30 days; a
     * result next to one already stored for that device (a batch sent again) is skipped. They fill
     * the history only, the current state comes from the live pings.
     */
    public static function backfillPings(Device $relay, mixed $results): int
    {
        if (! is_array($results)) {
            return 0;
        }
        $allowed = collect(self::pingTargetsFor($relay))->pluck('id')->all();
        $oldest = now()->subDays(PingResult::RETENTION_DAYS)->getTimestamp();
        $newest = now()->addMinute()->getTimestamp();
        $taken = 0;
        $touched = [];
        foreach (array_slice($results, 0, self::PING_BACKFILL_MAX) as $result) {
            $id = is_array($result) && is_int($result['id'] ?? null) ? $result['id'] : null;
            $at = is_array($result) && is_numeric($result['at'] ?? null) ? (int) $result['at'] : null;
            if ($id === null || $at === null || ! in_array($id, $allowed, true) || $at < $oldest || $at > $newest) {
                continue;
            }
            $near = PingResult::query()->where('device_id', $id)
                ->whereBetween('created_at', [Carbon::createFromTimestamp($at - 10, config('app.timezone')), Carbon::createFromTimestamp($at + 10, config('app.timezone'))])->exists();
            if ($near) {
                continue;
            }
            $up = filter_var($result['up'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $rtt = is_numeric($result['rtt'] ?? null) ? max(0, min(60000, round((float) $result['rtt'], 1))) : null;
            PingResult::query()->insert(['device_id' => $id, 'up' => $up, 'rtt' => $up ? $rtt : null, 'relay_id' => $relay->id, 'created_at' => Carbon::createFromTimestamp($at, config('app.timezone'))]);
            $touched[$id] = true;
            $taken++;
        }
        foreach (array_keys($touched) as $id) {
            \App\Support\LiveUpdates::device($id, 'ping');
        }

        return $taken;
    }

    /** Distinct agent errors kept per device, and for how long. */
    public const MAX_AGENT_ERRORS = 20;

    public const AGENT_ERRORS_DAYS = 7;

    /**
     * Errors from the agent's log ([{message, count, first, last}], unix times): merged with the
     * ones it sent before by message, the newest MAX_AGENT_ERRORS of the last days. Returns how
     * many were taken.
     */
    public function recordAgentErrors(mixed $errors): int
    {
        if (! is_array($errors)) {
            return 0;
        }
        $oldest = now()->subDays(self::AGENT_ERRORS_DAYS)->getTimestamp();
        $newest = now()->addMinutes(5)->getTimestamp();
        $kept = collect($this->agent_errors ?? [])->keyBy('message');
        $taken = 0;
        foreach (array_slice($errors, 0, 50) as $error) {
            $message = is_array($error) && is_string($error['message'] ?? null) ? trim(mb_strcut($error['message'], 0, 500)) : '';
            $last = is_array($error) && is_numeric($error['last'] ?? null) ? (int) $error['last'] : null;
            if ($message === '' || $last === null || $last < $oldest || $last > $newest) {
                continue;
            }
            $first = is_numeric($error['first'] ?? null) ? min((int) $error['first'], $last) : $last;
            $count = is_numeric($error['count'] ?? null) ? max(1, min(100000, (int) $error['count'])) : 1;
            $before = $kept->get($message);
            $kept->put($message, [
                'message' => $message,
                'count' => ($before['count'] ?? 0) + $count,
                'first' => min($before['first'] ?? $first, $first),
                'last' => max($before['last'] ?? $last, $last),
            ]);
            $taken++;
        }
        $this->agent_errors = $kept->filter(fn ($error) => $error['last'] >= $oldest)
            ->sortByDesc('last')->take(self::MAX_AGENT_ERRORS)->values()->all() ?: null;
        static::query()->whereKey($this->id)->toBase()->update(['agent_errors' => $this->agent_errors === null ? null : json_encode($this->agent_errors)]);

        return $taken;
    }

    /** The agent's errors of the last days, newest first. */
    public function getRecentAgentErrorsAttribute(): array
    {
        $oldest = now()->subDays(self::AGENT_ERRORS_DAYS)->getTimestamp();

        return array_values(array_filter($this->agent_errors ?? [], fn ($error) => ($error['last'] ?? 0) >= $oldest));
    }

    /** Forgets the agent's errors (the alert's Clear). */
    public function clearAgentErrors(): void
    {
        $this->agent_errors = null;
        static::query()->whereKey($this->id)->toBase()->update(['agent_errors' => null]);
    }

    /** Checks and cleans the settings of a ping-only device; null when they are not valid. */
    public static function sanitizePingSettings(string $address, int|string|null $prefix, ?string $mac): ?array
    {
        $address = trim($address);
        $prefix = $prefix === null || $prefix === '' ? 24 : (int) $prefix;
        $mac = $mac === null || trim($mac) === '' ? null : strtoupper(str_replace('-', ':', trim($mac)));
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $prefix < 8 || $prefix > 30 || ($mac !== null && ! preg_match(DeviceCommand::MAC_PATTERN, $mac))) {
            return null;
        }

        return ['ping_address' => $address, 'ping_prefix' => $prefix, 'ping_mac' => $mac];
    }

    /** Why the device cannot be woken now, or null when a relay can do it. */
    public function wakeRefusal(): ?string
    {
        return match (true) {
            ! $this->offline => __('The device is online'),
            $this->wakeMacs === [] => $this->isPingOnly ? __('Add its MAC address to wake it') : __('No wired or Wi-Fi network card is known'),
            $this->wakeNetworks() === [] => __('Its network is not known yet (agent 1.9.0+ reports it, or set it in the Wake-on-LAN settings)'),
            $this->wakeRelay() === null => $this->wakeRelayRefusal(),
            default => null,
        };
    }

    /**
     * "No online agent …" with what keeps each agent that has an interface in the network from
     * relaying (offline, too old, no signing, no recent report, another public address, on a battery).
     */
    private function wakeRelayRefusal(): string
    {
        $message = __('No online agent 1.9.0+ in the same network');
        $networks = $this->wakeNetworks();
        $reasons = [];
        foreach (static::query()->where('kind', 'agent')->orderBy('id')->get() as $relay) {
            if ($relay->id === $this->id || array_intersect_key($networks, $relay->ipv4Networks(self::RELAY_INTERFACE_TYPES, true)) === []) {
                continue;
            }
            $reason = match (true) {
                $relay->offline => __('offline'),
                ! $relay->signsRequests => __('does not sign its communication'),
                ! $relay->connectedViaApi => __('no report over the API in the last :seconds s', ['seconds' => self::REPORT_TIMEOUT]),
                ($wake = $relay->commandRefusal('wake', ['macs' => ['00:00:00:00:00:01'], 'broadcasts' => ['255.255.255.255'], 'device' => $this->id])) !== null => $wake,
                $relay->isMobile && ($this->public_ip === null || $relay->public_ip !== $this->public_ip) => __('on a battery, not behind the public address of this device'),
                $this->behindOtherAddress($relay) => __('behind another public address (:relay, this device :device)', ['relay' => $relay->public_ip, 'device' => $this->public_ip]),
                default => null,
            };
            if ($reason !== null) {
                $reasons[] = $relay->displayName.': '.$reason;
            }
        }

        return $reasons === [] ? $message : $message.' ('.implode('; ', $reasons).')';
    }

    /** Asks a relay in the same network to send the magic packet; the command is the relay's. */
    public function wake(?User $user = null): ?DeviceCommand
    {
        if (! $this->offline || ($relay = $this->wakeRelay()) === null) {
            return null;
        }
        [$device, $broadcasts] = $relay;

        return $device->issueCommand('wake', [
            'macs' => $this->wakeMacs,
            'broadcasts' => $broadcasts,
            'device' => $this->id,
            'title' => $this->displayName,
        ], $user);
    }

    /** Why a ping-only device cannot be pinged now (Sync), or null when its agent can do it. */
    public function pingNowRefusal(): ?string
    {
        if (! $this->isPingOnly) {
            return __('Unknown command');
        }
        $relay = $this->pingRelay();
        if ($relay === null) {
            return __('No agents available to send ping');
        }
        $refusal = $relay->commandRefusal('pingNow', ['device' => $this->id]);

        return $refusal === null ? null : $relay->displayName.': '.$refusal;
    }

    /** Sync of a ping-only device: the agent that pings it pings it now; the command is the agent's. */
    public function pingNow(?User $user = null): ?DeviceCommand
    {
        if ($this->pingNowRefusal() !== null) {
            return null;
        }

        return $this->pingRelay()->issueCommand('pingNow', ['device' => $this->id, 'title' => $this->displayName], $user);
    }

    /** The latest Sync (ping now) of this ping-only device in the last 10 minutes. */
    public function recentPingNow(): ?DeviceCommand
    {
        return DeviceCommand::query()->with('device')->where('command', 'pingNow')->where('target', 'ping:'.$this->id)
            ->where('created_at', '>=', now()->subMinutes(10))->latest('id')->first();
    }

    /**
     * The latest wake of this device of the last 10 minutes, or one still on its way (sent through
     * another agent; a wake runs until the device reports, up to its timeout after it was taken).
     */
    public function recentWake(): ?DeviceCommand
    {
        return DeviceCommand::query()->with('device')->where('command', 'wake')->where('target', 'device:'.$this->id)
            ->where(fn ($query) => $query->where('created_at', '>=', now()->subMinutes(10))->orWhereIn('status', DeviceCommand::ACTIVE))
            ->latest('id')->first();
    }

    /** Interface types of the hardware; the others (Docker, VPN, bridges, virtual) are virtual. */
    public const PHYSICAL_NETWORK_TYPES = ['lan', 'wifi', 'cellular', 'bluetooth'];

    /**
     * The interfaces as the Networks tab lists them: the ones with a public address, then the
     * physical connected ones, virtual connected, physical disconnected, virtual disconnected; in
     * the reported order within each.
     */
    public static function sortNetworks(array $networks): array
    {
        $rank = fn (array $network) => match (true) {
            collect($network['IPAddresses'] ?? [])->contains(fn ($address) => self::isPublicIp($address)) => 0,
            default => 1 + ($network['Connected'] ? 0 : 2) + (in_array($network['Type'] ?? null, self::PHYSICAL_NETWORK_TYPES, true) ? 0 : 1),
        };
        $ranked = array_map(fn ($network, $index) => [$rank($network), $index, $network], $networks, array_keys($networks));
        usort($ranked, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($ranked, 2);
    }

    /** The interface type from its name and description (agents before 1.8.0 do not report it). */
    public static function guessNetworkType(string $name, string $description = ''): string
    {
        $text = $name.' '.$description;

        return match (true) {
            (bool) preg_match('/^wl|wi-?fi|wireless|wlan|802\.11/i', $text) => 'wifi',
            (bool) preg_match('/^(docker|br-)/i', $name) => 'docker',
            (bool) preg_match('/^(tun|tap|wg|tailscale|zt|ppp|vpn|ipsec|nordlynx)|vpn|wireguard|tap-|openvpn|tailscale|zerotier|fortinet|anyconnect|globalprotect|wan miniport/i', $text) => 'vpn',
            (bool) preg_match('/bluetooth/i', $text) => 'bluetooth',
            // \blte\b: "Realtek" contains "lte".
            (bool) preg_match('/^(wwan|ww)|mobile broadband|cellular|\blte\b/i', $text) => 'cellular',
            (bool) preg_match('/^(virbr|vnet|lxc|lxd|incus|cni|flannel|cali|podman)|hyper-v|vethernet|virtualbox|vmware/i', $text) => 'virtual',
            (bool) preg_match('/^(br|bond)/i', $name) => 'bridge',
            default => 'lan',
        };
    }

    /** Running services and the ones that failed or should run but do not, failed first. */
    public function getServicesAttribute(): array
    {
        $services = self::withLiveStates(
            self::listOf(json_decode(json_encode($this->data->services ?? []), true)),
            $this->liveState['services'] ?? null,
        );
        $order = ['failed' => 0, 'stopped' => 1, 'running' => 2];
        usort($services, fn ($a, $b) => [$order[$a['State'] ?? ''] ?? 1, strtolower($a['Name'] ?? '')] <=> [$order[$b['State'] ?? ''] ?? 1, strtolower($b['Name'] ?? '')]);

        return $services;
    }

    /** Null when Docker is not installed on the device. */
    public function getDockerAttribute(): ?array
    {
        if (! isset($this->data->docker)) {
            return null;
        }

        $docker = json_decode(json_encode($this->data->docker), true);
        $live = $this->liveState['containers'] ?? null;

        // Agents before 1.5.0 report Docker as soon as the CLI exists, even without an engine: an
        // error with no containers means Docker is not installed there. Newer agents only report
        // a reachable engine, so their errors are shown.
        if ($live === null && ! empty($docker['error']) && empty($docker['containers'])
            && version_compare($this->agentVersion ?? '0', '1.5.0', '<')) {
            return null;
        }

        $containers = array_map(function (array $container) {
            // "unhealthy" is a running container whose health check fails.
            if (($container['State'] ?? null) === 'unhealthy') {
                $container['State'] = 'running';
                $container['Health'] = 'unhealthy';
            }

            return $container;
        }, self::withLiveStates(array_map(function (array $container) {
            // Same naming as the live state, so an unchanged container keeps its status text.
            if (($container['State'] ?? null) === 'running' && str_contains($container['Status'] ?? '', '(unhealthy)')) {
                $container['State'] = 'unhealthy';
            }

            return $container;
        }, self::listOf($docker['containers'] ?? [])), $live));

        return [
            // A newer live state means the agent reads the containers again.
            'error' => $live !== null ? null : ($docker['error'] ?? null),
            'containers' => $containers,
        ];
    }

    /**
     * Applies the live states to the items of the last report. The live state lists every item
     * (running and failed / stopped ones): items it no longer has are gone, new ones are added
     * with their name only. A changed item loses its report-time status text.
     */
    private static function withLiveStates(array $items, ?array $states): array
    {
        if ($states === null) {
            return $items;
        }

        $byName = [];
        foreach ($items as $item) {
            $byName[$item['Name'] ?? ''] = $item;
        }

        $merged = [];
        foreach ($states as $name => $state) {
            $item = $byName[$name] ?? ['Name' => $name];
            if (($item['State'] ?? null) !== $state) {
                $item['State'] = $state;
                unset($item['Status'], $item['Health']);
            }
            $merged[] = $item;
        }

        return $merged;
    }

    /** Null until the agent reported disk health (agents before 1.2.0). */
    public function getDiskHealthAttribute(): ?array
    {
        if (! isset($this->data->disk_health)) {
            return null;
        }

        $health = json_decode(json_encode($this->data->disk_health), true);

        return [
            'error' => $health['error'] ?? null,
            'disks' => self::listOf($health['disks'] ?? []),
        ];
    }

    /** Any disk reporting a failed or warning SMART status. */
    public function getDiskHealthProblemAttribute(): bool
    {
        foreach ($this->diskHealth['disks'] ?? [] as $disk) {
            if (in_array($disk['Health'] ?? null, ['failed', 'warning'], true)) {
                return true;
            }
        }

        return false;
    }

    /** A single item serialized by PowerShell as an object instead of a list. */
    private static function listOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_is_list($value) ? array_values(array_filter($value, 'is_array')) : [$value];
    }

    private static function  stdToArray($stdObject){
        if (is_object($stdObject)){
            return [json_decode(json_encode($stdObject), true)];
        }
        return json_decode(json_encode($stdObject), true);
    }
}
