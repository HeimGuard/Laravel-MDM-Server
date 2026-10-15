<?php

namespace App\Models;

use App\Support\AlertEvaluator;
use App\Support\LiveUpdates;
use App\Support\NetworkMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use SteelAnts\LaravelBoilerplate\Models\Setting;
use SteelAnts\LaravelBoilerplate\Types\SettingDataType;

/**
 * A device an agent sees in its network (its ARP table, a scan): one per MAC address and network
 * (site: the network's gateway MAC as "gw:…", without one the public address of the agent,
 * NetworkMap::segmentOf), with the IP address it had last. Known ones (a device of the portal has the MAC)
 * are left out where they are shown; unknown ones are on the Networks page, can be ignored or
 * added as a ping-only device. Ping-only devices with a MAC follow it to a new address (DHCP).
 */
class NetworkNeighbour extends Model
{
    use MassPrunable;

    /** Levels an agent's config.json allows (network_discovery): nothing, its ARP table, also scans. */
    public const LEVELS = ['off', 'neighbours', 'scan'];

    /** At most this many neighbours are taken from one report. */
    public const MAX_PER_REPORT = 512;

    /** Minutes since it was seen last for a neighbour to be shown as present. */
    public const FRESH_MINUTES = 15;

    /** Hours a neighbour not seen anymore is still shown (faint). */
    public const SHOWN_HOURS = 24;

    /** Days after which a neighbour not seen anymore is forgotten (ignored ones much later). */
    public const RETENTION_DAYS = 30;

    public const IGNORED_RETENTION_DAYS = 180;

    /** The portal setting that turns discovery off for all agents (Networks page, system admins). */
    public const SETTING = 'mdm.network_discovery';

    protected $fillable = ['site', 'network', 'mac', 'ip', 'hostname', 'seen_by', 'first_seen_at', 'last_seen_at', 'ignored_at'];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'ignored_at' => 'datetime',
    ];

    public function seenBy(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'seen_by');
    }

    public function prunable(): Builder
    {
        return static::query()->where(fn ($query) => $query
            ->where(fn ($query) => $query->whereNull('ignored_at')->where('last_seen_at', '<', now()->subDays(self::RETENTION_DAYS)))
            ->orWhere('last_seen_at', '<', now()->subDays(self::IGNORED_RETENTION_DAYS)));
    }

    /** Whether the portal takes what the agents see (on unless a system admin turned it off). */
    public static function enabled(): bool
    {
        return (string) Setting::query()->whereNull('settable_id')->where('index', self::SETTING)->value('value') !== '0';
    }

    public static function setEnabled(bool $enabled): void
    {
        Setting::query()->whereNull('settable_id')->updateOrCreate(['index' => self::SETTING], ['value' => $enabled ? '1' : '0', 'type' => SettingDataType::BOOL]);
    }

    /** "AA:BB:CC:DD:EE:FF" for a unicast MAC address in any notation; null for others (broadcast, multicast). */
    public static function normalizeMac(mixed $mac): ?string
    {
        if (! is_string($mac) || ! preg_match(DeviceCommand::MAC_PATTERN, $mac)) {
            return null;
        }
        $mac = strtoupper(str_replace('-', ':', $mac));
        // The lowest bit of the first byte marks a group (multicast, broadcast) address.
        if ($mac === '00:00:00:00:00:00' || (hexdec(substr($mac, 0, 2)) & 1) === 1) {
            return null;
        }

        return $mac;
    }

    /** A locally administered MAC address: phones and laptops make up one per Wi-Fi network (private address). */
    public function getRandomMacAttribute(): bool
    {
        return (hexdec(substr($this->mac, 0, 2)) & 2) === 2;
    }

    public function getFreshAttribute(): bool
    {
        return $this->last_seen_at->gte(now()->subMinutes(self::FRESH_MINUTES));
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->hostname ?: $this->ip;
    }

    /**
     * The neighbours of an agent's report ([{Ip, Mac, Hostname?}], agents 1.16.0+): only addresses
     * in a network of one of its interfaces, under the public address it reports from. Ping-only
     * devices with one of these MACs and an address in the same network move to its new address.
     * Returns how many were taken.
     */
    public static function record(Device $device, mixed $neighbours): int
    {
        if (! is_array($neighbours) || $device->isPingOnly || $device->networkDiscovery === null || $device->networkDiscovery === 'off' || ! self::enabled()) {
            return 0;
        }
        $serverSite = NetworkMap::serverAddresses()[0] ?? null;
        // cidr => the key of its network (its gateway's MAC, NetworkMap::segmentOf).
        $networks = [];
        $own = [];
        foreach ($device->networks as $interface) {
            if (in_array($interface['Type'], [...NetworkMap::SKIPPED_TYPES, 'vpn'], true) || ! $interface['Connected']) {
                continue;
            }
            foreach ($interface['Addresses'] as $address) {
                $own[] = $address['Address'];
                if ($cidr = NetworkMap::cidr($address['Address'], $address['PrefixLength'])) {
                    $networks[$cidr] ??= NetworkMap::segmentOf($interface, $device, $serverSite);
                }
            }
        }
        if ($networks === []) {
            return 0;
        }

        $candidates = [];
        foreach (array_slice(array_values($neighbours), 0, self::MAX_PER_REPORT) as $neighbour) {
            $ip = is_array($neighbour) ? ($neighbour['Ip'] ?? null) : null;
            $mac = self::normalizeMac(is_array($neighbour) ? ($neighbour['Mac'] ?? null) : null);
            if ($mac === null || ! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || in_array($ip, $own, true)) {
                continue;
            }
            $network = collect(array_keys($networks))->first(fn ($cidr) => NetworkMap::contains($cidr, $ip));
            if ($network === null || ! NetworkMap::isHost($network, $ip)) {
                continue;
            }
            $hostname = is_string($neighbour['Hostname'] ?? null) ? trim(preg_replace('/[^\w.\-]/u', '', $neighbour['Hostname'])) : '';
            // Agents 1.21.0+: whether the entry was confirmed lately (fresh) or is an old one the
            // table still keeps (stale: the device may have another address by now).
            $state = in_array($neighbour['State'] ?? null, ['fresh', 'stale'], true) ? $neighbour['State'] : null;
            $candidates[$mac][] = ['ip' => $ip, 'network' => $network, 'site' => $networks[$network], 'state' => $state,
                'hostname' => $hostname !== '' && $hostname !== $ip ? mb_substr($hostname, 0, 255) : null];
        }
        if ($candidates === []) {
            return 0;
        }

        $existing = static::query()->whereIn('site', array_unique(array_merge(...array_map(fn ($entries) => array_column($entries, 'site'), array_values($candidates)))))
            ->whereIn('mac', array_keys($candidates))->get()
            ->keyBy(fn (self $row) => $row->site.'|'.$row->mac);
        // One MAC at several addresses (the old one still in the table after a change): the fresh
        // ones count; it stays where it was while that is one of them, and only one address that
        // is surely its own now moves a ping-only device (followMacs).
        $seen = [];
        foreach ($candidates as $mac => $entries) {
            $fresh = array_values(array_filter($entries, fn ($entry) => $entry['state'] === 'fresh'));
            $pool = $fresh !== [] ? $fresh : $entries;
            $ips = array_values(array_unique(array_column($pool, 'ip')));
            $kept = collect($pool)->first(fn ($entry) => ($existing[$entry['site'].'|'.$mac] ?? null)?->ip === $entry['ip']);
            // Only old entries of another address: it is still where it was seen last.
            $row = $existing[$pool[0]['site'].'|'.$mac] ?? null;
            if ($kept === null && $fresh === [] && $row !== null && collect($entries)->contains(fn ($entry) => $entry['state'] === 'stale')) {
                $kept = ['ip' => $row->ip, 'network' => $row->network, 'site' => $row->site, 'hostname' => null];
            }
            $seen[$mac] = ($kept ?? $pool[0]) + [
                'move' => count($ips) === 1 && ($fresh !== [] || collect($entries)->every(fn ($entry) => $entry['state'] === null)),
            ];
        }
        foreach ($seen as $mac => $values) {
            $row = $existing[$values['site'].'|'.$mac] ?? new static(['site' => $values['site'], 'mac' => $mac, 'first_seen_at' => now()]);
            $row->fill([
                'ip' => $values['ip'],
                'network' => $values['network'],
                // A name from a scan stays until another one is found.
                'hostname' => $values['hostname'] ?? $row->hostname,
                'seen_by' => $device->id,
                'last_seen_at' => now(),
            ])->save();
        }
        self::followMacs($seen);

        return count($seen);
    }

    /**
     * The agent's own IPv4 networks (its connected, non-VPN interfaces) as cidr => the network's
     * segment (its gateway's MAC, NetworkMap::segmentOf). The same keying used for neighbours and
     * port scan results, so an address can be matched to the segment it was seen in.
     *
     * @return array<string, string>
     */
    public static function networksOf(Device $device): array
    {
        if ($device->isPingOnly) {
            return [];
        }
        $serverSite = NetworkMap::serverAddresses()[0] ?? null;
        $networks = [];
        foreach ($device->networks as $interface) {
            if (in_array($interface['Type'], [...NetworkMap::SKIPPED_TYPES, 'vpn'], true) || ! $interface['Connected']) {
                continue;
            }
            foreach ($interface['Addresses'] as $address) {
                if ($cidr = NetworkMap::cidr($address['Address'], $address['PrefixLength'])) {
                    $networks[$cidr] ??= NetworkMap::segmentOf($interface, $device, $serverSite);
                }
            }
        }

        return $networks;
    }

    /**
     * Ping-only devices whose MAC is now at another address of the same network: the portal does
     * not move them, the device gets a smart alert to confirm it (SmartAlerts, movedAddress) and
     * the address_changed rules are told once per new address. Only when the report has the MAC at
     * that one address, confirmed lately (agents 1.21.0+; a stale ARP entry of an old address
     * would send it back and forth).
     */
    private static function followMacs(array $seen): void
    {
        $devices = Device::query()->where('kind', 'ping')->whereIn('ping_mac', array_keys($seen))->get();
        foreach ($devices as $device) {
            $now = $seen[$device->ping_mac];
            if (! $now['move'] || $device->ping_address === $now['ip'] || ! $device->ping_address || ! NetworkMap::contains($now['network'], $device->ping_address)) {
                continue;
            }
            if (Cache::add('address-moved:'.$device->id.':'.$now['ip'], true, now()->addDay())) {
                AlertEvaluator::addressChanged($device, $device->ping_address, $now['ip']);
                LiveUpdates::device($device->id, 'alert');
            }
        }
    }

    /** MAC addresses the portal knows: of its devices (interfaces, ping-only and Wake-on-LAN settings) and their gateways. */
    public static function knownMacs(?Collection $devices = null): array
    {
        $macs = [];
        foreach ($devices ?? Device::all() as $device) {
            // The gateways of its networks too: they are drawn as gateways.
            $interfaces = $device->isPingOnly ? [] : $device->networks;
            foreach ([$device->ping_mac, $device->wake_mac, ...array_column($interfaces, 'Mac'), ...array_column($interfaces, 'GatewayMac')] as $mac) {
                if ($mac = self::normalizeMac($mac)) {
                    $macs[$mac] = true;
                }
            }
        }

        return $macs;
    }

    /** Unknown neighbours (not ignored, no device of the portal has the MAC) seen in the last day. */
    public static function unknown(?Collection $devices = null): Collection
    {
        $known = self::knownMacs($devices);

        return static::query()->whereNull('ignored_at')->where('last_seen_at', '>=', now()->subHours(self::SHOWN_HOURS))
            ->orderBy('ip')->get()->reject(fn (self $neighbour) => isset($known[$neighbour->mac]))
            ->sortBy(fn (self $neighbour) => ip2long($neighbour->ip))->values();
    }
}
