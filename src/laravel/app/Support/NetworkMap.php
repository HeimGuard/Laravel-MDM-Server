<?php

namespace App\Support;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\NetworkNeighbour;
use App\Models\NetworkSite;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The map of the networks (the Networks page), built from what the agents report: top down the
 * internet, the public addresses (sites), their gateways, the networks (IPv4 subnets; VPNs span
 * sites) and the devices, each linked to every network it has an interface in. This server is
 * placed where its name resolves to.
 *
 * Nodes: id, kind (internet, site, gateway, network, device, server), label, sub, icon, tone,
 * state (up, down, offline, warning), url, badges. Edges: from, to, type (wan, uplink, lan, wifi,
 * cellular, vpn, tunnel), up, label.
 */
class NetworkMap
{
    /** Interfaces that stay inside the machine (containers, VMs) or are no network. */
    public const SKIPPED_TYPES = ['docker', 'virtual', 'bluetooth'];

    /** Seconds the addresses of this server's name are kept. */
    private const RESOLVE_TTL = 3600;

    private array $nodes = [];

    private array $edges = [];

    /** network key => [cidr, site, vpn, types, members [device id => interface], gateways, reach [device id => address it reports from]] */
    private array $networks = [];

    /** Keys of networks merged into another one (mergeWithoutGatewayMac) => that one's key. */
    private array $aliases = [];

    /** @param  Collection<int, Device>|null  $devices */
    public static function build(?Collection $devices = null): array
    {
        return (new self)->make($devices ?? Device::query()->orderBy('id')->get());
    }

    /**
     * The public IPv4 addresses of this server: MDM_PUBLIC_ADDRESS, else what its name (APP_URL,
     * else the remembered address) resolves to, else the address it reaches the internet from.
     */
    public static function serverAddresses(): array
    {
        $named = self::namedAddresses();

        return $named !== [] ? $named : self::egressAddresses();
    }

    /** MDM_PUBLIC_ADDRESS, or the public addresses the portal's name resolves to. */
    private static function namedAddresses(): array
    {
        // Set by hand (MDM_PUBLIC_ADDRESS): when the name resolves to something else (split DNS, a proxy).
        $set = (string) config('mdm.public_address');
        if ($set !== '' && Device::isPublicIp($set)) {
            return [$set];
        }
        $url = PortalUrl::configured() ? (string) config('app.url') : PortalUrl::remembered();
        $host = $url ? (string) parse_url($url, PHP_URL_HOST) : '';
        if ($host === '') {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return Device::isPublicIp($host) ? [$host] : [];
        }

        return Cache::remember('network-map:resolve:'.$host, self::RESOLVE_TTL, function () use ($host) {
            try {
                $addresses = @gethostbynamel($host) ?: [];
            } catch (Throwable) {
                $addresses = [];
            }

            return array_values(array_filter($addresses, fn ($address) => Device::isPublicIp($address)));
        });
    }

    /**
     * The address this server reaches the internet from (the public address of its network),
     * asked of mdm.public_address_url (ifconfig.me) once an hour; none for a server without
     * internet access, nothing is asked with MDM_DETECT_PUBLIC_ADDRESS=false. A failed try is
     * not repeated for ten minutes (the page does not wait for it every time).
     */
    public static function egressAddresses(): array
    {
        $url = (string) config('mdm.public_address_url');
        if (! config('mdm.detect_public_address') || $url === '') {
            return [];
        }
        $key = 'network-map:egress:'.md5($url);
        $known = Cache::get($key);
        if (is_array($known)) {
            return $known;
        }
        $addresses = [];
        try {
            // IPv4 only (the networks of the map are).
            $body = trim(Http::timeout(3)->connectTimeout(2)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->get($url)->body());
            if (filter_var($body, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && Device::isPublicIp($body)) {
                $addresses = [$body];
            }
        } catch (Throwable) {
        }
        Cache::put($key, $addresses, $addresses === [] ? 600 : self::RESOLVE_TTL);

        return $addresses;
    }

    /**
     * The key of the L2 network of an interface: its gateway's MAC ("gw:50:C7:BF:AA:BB:CC"), the
     * same for all its devices; without one (no default route, the router not in the ARP table)
     * the address the device reaches the server from (siteOf).
     */
    public static function segmentOf(array $interface, Device $device, ?string $serverSite): string
    {
        $mac = NetworkNeighbour::normalizeMac($interface['GatewayMac'] ?? null);

        return $mac !== null ? 'gw:'.$mac : self::siteOf($device, $serverSite);
    }

    /**
     * A network keyed without a gateway MAC (a device whose ARP table lacks the router) joins the
     * one network with the same subnet and gateway MAC its devices fit: they reach the server
     * from the same public addresses, or all from inside (over private addresses).
     */
    private function mergeWithoutGatewayMac(?string $serverSite): void
    {
        $publics = fn (array $network) => collect($network['reach'])->filter(fn ($ip) => Device::isPublicIp($ip))->unique()->values()->all();
        $inside = fn (array $network) => collect($network['reach'])->contains(fn ($ip) => $ip !== null && ! Device::isPublicIp($ip));
        // The public addresses of a network: the ones its devices reach the server from, and the
        // server's own when they reach it from inside (they are in its network: its address is theirs).
        $known = fn (array $network) => array_values(array_unique([...$publics($network), ...($inside($network) && $serverSite !== null ? [$serverSite] : [])]));
        foreach (array_keys($this->networks) as $key) {
            $network = $this->networks[$key];
            if ($network['vpn'] || str_starts_with($key, 'net:gw:')) {
                continue;
            }
            $mine = $publics($network);
            $targets = collect($this->networks)->filter(fn ($other, $otherKey) => str_starts_with($otherKey, 'net:gw:') && $other['cidr'] === $network['cidr']
                && ($mine === [] ? $inside($other) || $publics($other) === [] : array_diff($mine, $known($other)) === []))->keys();
            if ($targets->count() !== 1) {
                continue;
            }
            $target = $targets->first();
            $this->networks[$target]['types'] += $network['types'];
            $this->networks[$target]['members'] += $network['members'];
            $this->networks[$target]['reach'] += $network['reach'];
            $this->networks[$target]['egress'] += $network['egress'];
            $this->networks[$target]['gateways'] = [...$this->networks[$target]['gateways'], ...$network['gateways']];
            $this->aliases[$key] = $target;
            unset($this->networks[$key]);
        }
    }

    /** The site of an agent: the public address it reports from; this server's when it reports over a private one. */
    public static function siteOf(Device $device, ?string $serverSite): string
    {
        return match (true) {
            Device::isPublicIp($device->public_ip) => $device->public_ip,
            $device->public_ip !== null && $serverSite !== null && ! $device->reachesOverVpn => $serverSite,
            default => '?',
        };
    }

    private function make(Collection $devices): array
    {
        $serverIps = self::serverAddresses();
        $serverSite = $serverIps[0] ?? null;
        $agents = $devices->reject->isPingOnly;
        $pings = $devices->filter->isPingOnly;

        $this->node('internet', 'internet', __('Internet'), null, 'fas fa-globe', 'secondary');

        // Networks: an L2 network is the same for all its devices, whatever address each of them
        // reaches the server from (over the internet, from inside the server's LAN, through a
        // proxy): it is keyed by the MAC of its gateway, without one by that address (segmentOf).
        foreach ($agents as $device) {
            // Over its own VPN the address it reaches the server from tells nothing of its networks.
            $reach = $device->reachesOverVpn ? null : $device->public_ip;
            foreach ($device->networks as $interface) {
                if (in_array($interface['Type'], self::SKIPPED_TYPES, true)) {
                    continue;
                }
                foreach ($interface['Addresses'] as $address) {
                    // VPN clients often have a /32 (WireGuard, Tailscale): grouped in their /24.
                    $prefix = $interface['Type'] === 'vpn' && $address['PrefixLength'] >= 31 ? 24 : $address['PrefixLength'];
                    $cidr = self::cidr($address['Address'], $prefix);
                    if ($cidr === null) {
                        continue;
                    }
                    $vpn = $interface['Type'] === 'vpn';
                    $key = 'net:'.($vpn ? 'vpn' : self::segmentOf($interface, $device, $serverSite)).'|'.$cidr;
                    $this->networks[$key] ??= ['cidr' => $cidr, 'site' => null, 'vpn' => $vpn, 'types' => [], 'members' => [], 'gateways' => [], 'reach' => [], 'egress' => []];
                    $this->networks[$key]['types'][$interface['Type']] = true;
                    $this->networks[$key]['members'][$device->id] ??= [
                        'type' => $interface['Type'], 'connected' => $interface['Connected'], 'name' => $interface['Name'], 'address' => $address['Address'],
                    ];
                    if (! $vpn) {
                        // The address the device reaches the server from (its site), and the one the
                        // internet sees from this interface (agents 1.21.0+).
                        $this->networks[$key]['reach'][$device->id] = $reach;
                        if ($interface['Connected'] && $interface['PublicAddress'] !== null) {
                            $this->networks[$key]['egress'][$device->id] = $interface['PublicAddress'];
                        }
                    }
                    if ($interface['Gateway'] ?? null) {
                        $this->networks[$key]['gateways'][] = ['ip' => $interface['Gateway'], 'mac' => $interface['GatewayMac'] ?? null];
                    }
                }
            }
        }
        $this->mergeWithoutGatewayMac($serverSite);

        // Sites: the public address of each network, the most common one its devices see the
        // internet from (agents 1.21.0+), else reach the server from. The network the agents reach the server from over private addresses is
        // the server's: its public address is MDM_PUBLIC_ADDRESS, else what a device of it reaches
        // the server through (the router's address), else the server's name resolved.
        $mostCommon = fn (array $addresses) => collect($addresses)->filter(fn ($ip) => Device::isPublicIp($ip))->countBy()->sortDesc()->keys()->first();
        $publicOf = fn (array $network) => $mostCommon($network['egress']) ?? $mostCommon($network['reach']);
        $insideCount = fn (array $network) => collect($network['reach'])->filter(fn ($ip) => $ip !== null && ! Device::isPublicIp($ip))->count();
        $inside = collect($this->networks)->reject(fn ($network) => $network['vpn'])->filter(fn ($network) => $insideCount($network) > 0)
            ->sortByDesc(fn ($network) => $insideCount($network))->keys()->first();
        if ($inside !== null) {
            $set = (string) config('mdm.public_address');
            $serverSite = Device::isPublicIp($set) ? $set : ($publicOf($this->networks[$inside]) ?? $serverSite ?? 'local');
        }
        foreach ($this->networks as $key => $network) {
            if (! $network['vpn']) {
                $this->networks[$key]['site'] = $key === $inside ? $serverSite
                    : ($publicOf($network) ?? ($insideCount($network) > 0 ? ($serverSite ?? 'local') : '?'));
            }
        }

        // The site of an agent: of its network (a connected one first), without one where it reports from.
        $siteOf = [];
        foreach ($agents as $device) {
            $mine = collect($this->networks)->filter(fn ($network) => ! $network['vpn'] && isset($network['members'][$device->id]))
                ->sortByDesc(fn ($network) => $network['members'][$device->id]['connected']);
            $siteOf[$device->id] = $mine->first()['site'] ?? self::siteOf($device, $serverSite);
        }

        // Ping-only devices: in the network of their address their agent is in, else one at its site.
        foreach ($pings as $device) {
            if (! $device->ping_address) {
                continue;
            }
            $relaySite = $device->ping_relay_id ? ($siteOf[$device->ping_relay_id] ?? null) : null;
            $key = collect($this->networks)->filter(fn ($network) => ! $network['vpn'] && ($relaySite === null || $network['site'] === $relaySite) && self::contains($network['cidr'], $device->ping_address))
                ->sortByDesc(fn ($network) => isset($network['members'][$device->ping_relay_id]))->keys()->first();
            if ($key === null) {
                $cidr = self::cidr($device->ping_address, (int) ($device->ping_prefix ?? 24));
                $site = $relaySite ?? '?';
                $key = 'net:'.$site.'|'.$cidr;
                $this->networks[$key] ??= ['cidr' => $cidr, 'site' => $site, 'vpn' => false, 'types' => ['lan' => true], 'members' => [], 'gateways' => [], 'reach' => [], 'egress' => []];
            }
            $this->networks[$key]['members'][$device->id] = ['type' => 'lan', 'connected' => true, 'name' => 'ping', 'address' => $device->ping_address];
            $siteOf[$device->id] = $this->networks[$key]['site'];
        }

        // Sites, with this server's even without devices.
        $sites = collect($siteOf)->filter()->unique()->values()->all();
        if ($serverSite !== null && ! in_array($serverSite, $sites, true)) {
            $sites[] = $serverSite;
        }
        foreach ($sites as $site) {
            $count = collect($siteOf)->filter(fn ($s) => $s === $site)->count();
            $this->node('site:'.$site, 'site', match ($site) { '?' => __('Unknown address'), 'local' => __('Public address unknown'), default => $site },
                $site === 'local' ? __('this server · set MDM_PUBLIC_ADDRESS') : ($site === $serverSite ? __('public · this server') : __('public')),
                $site === $serverSite ? 'fas fa-home' : 'fas fa-building', $site === '?' ? 'secondary' : 'success',
                badges: [trans_choice(':count device|:count devices', $count)], extra: ['group' => $site]);
            $this->edge('internet', 'site:'.$site, 'wan', true);
        }
        // The public addresses devices were behind before, nobody there now: faint, with when.
        foreach (NetworkSite::query()->whereNotIn('address', $sites)->orderBy('address')->get() as $known) {
            $this->node('site:'.$known->address, 'site', $known->address, __('public · last seen :time', ['time' => $known->last_seen_at->diffForHumans()]),
                'fas fa-building', 'secondary', state: 'down', badges: [trans_choice(':count device|:count devices', 0)], extra: ['group' => $known->address]);
            $this->edge('internet', 'site:'.$known->address, 'wan', false);
        }

        $byId = $devices->keyBy('id');
        $relays = $pings->whereNotNull('ping_relay_id')->countBy('ping_relay_id');

        // Networks and their gateways (one node per gateway MAC, or IP: a router of several networks).
        foreach ($this->networks as $key => $network) {
            $members = collect($network['members']);
            $online = $members->filter(fn ($member, $id) => $member['connected'] && ! $byId[$id]->offline)->count();
            $types = array_keys($network['types']);
            $kind = match (true) {
                in_array('vpn', $types, true) => 'vpn',
                $types === ['wifi'] => 'wifi',
                in_array('wifi', $types, true) && in_array('lan', $types, true) => 'mixed',
                $types === ['cellular'] => 'cellular',
                default => 'lan',
            };
            $label = ['vpn' => __('VPN'), 'wifi' => __('Wi-Fi'), 'mixed' => __('LAN + Wi-Fi'), 'cellular' => __('Mobile'), 'lan' => __('LAN')][$kind];
            $this->node($key, 'network', $network['cidr'],
                $label.' · '.trans_choice(':count device|:count devices', $members->count()),
                ['vpn' => 'fas fa-shield-alt', 'wifi' => 'fas fa-wifi', 'cellular' => 'fas fa-signal'][$kind] ?? 'fas fa-ethernet',
                ['vpn' => 'info', 'wifi' => 'purple'][$kind] ?? 'primary',
                state: $online > 0 ? 'up' : 'down',
                extra: ['network' => $kind, 'online' => $online, 'devices' => $members->count(), 'group' => $network['site']]);
            $this->networks[$key]['kind'] = $kind;
            $this->networks[$key]['online'] = $online;

            if ($network['site'] === null) {
                // A VPN runs over the internet, between the sites.
                $this->edge('internet', $key, 'tunnel', $online > 0);

                continue;
            }
            $gateway = collect($network['gateways'])->groupBy(fn ($gateway) => $gateway['ip'])->sortByDesc(fn ($group) => $group->count())->keys()->first();
            if ($gateway === null) {
                $this->edge('site:'.$network['site'], $key, 'uplink', $online > 0);

                continue;
            }
            $mac = collect($network['gateways'])->where('ip', $gateway)->pluck('mac')->filter()->first();
            $id = 'gw:'.$network['site'].'|'.($mac ?? $gateway);
            $this->networks[$key]['gateway'] = $gateway;
            // A device of the portal with that address is the gateway (a router added as ping-only).
            $known = $byId->first(fn (Device $device) => isset($network['members'][$device->id]) && $network['members'][$device->id]['address'] === $gateway);
            if (isset($this->nodes[$id])) {
                $this->nodes[$id]['sub'] = $this->nodes[$id]['sub'].' · '.$gateway;
            } else {
                $this->node($id, 'gateway', $known?->displayName ?? __('Gateway'), $gateway, 'fas fa-route', 'success',
                    url: $known ? route('devices', ['selectedDeviceId' => $known->id]) : null, badges: [__('gateway')],
                    extra: ['title' => $mac ? __('MAC :mac', ['mac' => $mac]) : null, 'group' => $network['site']]);
                $this->edge('site:'.$network['site'], $id, 'uplink', true);
            }
            $this->edge($id, $key, 'uplink', $online > 0);
        }

        // Devices, linked to each of their networks.
        foreach ($devices as $device) {
            $memberOf = collect($this->networks)->filter(fn ($network) => isset($network['members'][$device->id]));
            if ($memberOf->isEmpty() && ! isset($siteOf[$device->id])) {
                continue;
            }
            $addresses = $memberOf->map(fn ($network) => $network['members'][$device->id]['address'])->unique()->take(3)->implode(' · ');
            // Isolated: no other agent in any of its own networks (VPNs aside): nothing next to it
            // can wake it, nor ping the devices next to it.
            $neighbours = $memberOf->filter(fn ($network) => $network['site'] !== null)
                ->flatMap(fn ($network) => array_keys($network['members']))
                ->filter(fn ($id) => $id !== $device->id && ! $byId[$id]->isPingOnly)->unique();
            $isolated = ! $device->isPingOnly && $neighbours->isEmpty();
            $badges = array_values(array_filter([
                ($relays[$device->id] ?? 0) > 0 ? trans_choice('pings :count|pings :count', $relays[$device->id]) : null,
                $isolated ? __('isolated') : null,
            ]));
            $state = match (true) {
                $device->isPingOnly && $device->offline => 'warning',
                $device->offline => 'offline',
                default => 'up',
            };
            $this->node('device:'.$device->id, 'device', $device->displayName,
                $addresses ?: ($device->offline ? __('offline') : null), $device->typeIcon,
                $device->isPingOnly ? 'success' : 'primary', state: $state,
                url: route('devices', ['selectedDeviceId' => $device->id]), badges: $badges,
                extra: ['isolated' => $isolated, 'ping' => $device->isPingOnly,
                    // Laid out with the site of its own network (not of a VPN).
                    'group' => $memberOf->pluck('site')->filter()->first() ?? ($siteOf[$device->id] ?? null)]);
            foreach ($memberOf as $key => $network) {
                $member = $network['members'][$device->id];
                $type = match ($member['type']) {
                    'wifi' => 'wifi', 'vpn' => 'vpn', 'cellular' => 'cellular', default => 'lan',
                };
                $this->edge($key, 'device:'.$device->id, $type, $member['connected'] && ! $device->offline, $member['address']);
            }
            if ($memberOf->isEmpty()) {
                $this->edge('site:'.$siteOf[$device->id], 'device:'.$device->id, 'uplink', ! $device->offline);
            }
        }

        // Devices the agents see in their networks that the portal does not know (agents 1.16.0+),
        // not the gateway nor an address of a device of the network (ping-only without a MAC).
        foreach (NetworkNeighbour::unknown($devices) as $neighbour) {
            $key = 'net:'.$neighbour->site.'|'.$neighbour->network;
            $key = $this->aliases[$key] ?? $key;
            $network = $this->networks[$key] ?? null;
            if ($network === null || ($network['gateway'] ?? null) === $neighbour->ip
                || in_array($neighbour->mac, array_map(fn ($gateway) => strtoupper(str_replace('-', ':', (string) $gateway['mac'])), $network['gateways']), true)
                || collect($network['members'])->contains(fn ($member) => $member['address'] === $neighbour->ip)) {
                continue;
            }
            $this->networks[$key]['unknown'][] = $neighbour;
        }
        // All unknown devices of a network are one card (the list below has them).
        foreach ($this->networks as $key => $network) {
            $unknown = $network['unknown'] ?? [];
            if ($unknown === []) {
                continue;
            }
            $new = collect($unknown)->filter(fn (NetworkNeighbour $neighbour) => $neighbour->first_seen_at->gte(now()->subDay()))->count();
            $this->node('unknown:'.$key, 'unknown', trans_choice(':count unknown device|:count unknown devices', count($unknown), ['count' => count($unknown)]),
                __('see the network below'),
                'fas fa-question', 'secondary', url: self::cardUrl($key), badges: $new > 0 ? [__(':count new', ['count' => $new])] : [],
                extra: ['group' => $network['site'], 'more' => true]);
            $this->nodes[$key]['sub'] .= ' · '.__(':count unknown', ['count' => count($unknown)]);
            $this->edge($key, 'unknown:'.$key, 'unknown', collect($unknown)->contains(fn (NetworkNeighbour $neighbour) => $neighbour->fresh));
        }

        // This server: in the network the agents reach it from over private addresses, else at its site.
        if ($serverSite !== null) {
            $local = $agents->filter(fn (Device $device) => $device->public_ip !== null && ! Device::isPublicIp($device->public_ip) && ! $device->reachesOverVpn)->pluck('public_ip')->all();
            $network = collect($this->networks)->filter(fn ($network) => $network['site'] === $serverSite)
                ->sortByDesc(fn ($network) => collect($local)->filter(fn ($ip) => self::contains($network['cidr'], $ip))->count())
                ->filter(fn ($network) => collect($local)->contains(fn ($ip) => self::contains($network['cidr'], $ip)))->keys()->first();
            $host = (string) parse_url(url('/'), PHP_URL_HOST);
            $this->node('server', 'server', 'Laravel-MDM', in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) ? $serverSite : $host, 'fas fa-shield-virus', 'success', badges: [__('this server')], extra: ['group' => $serverSite]);
            $this->edge($network ?? 'site:'.$serverSite, 'server', $network ? 'lan' : 'uplink', true);
        }

        return [
            'nodes' => array_values($this->nodes),
            'edges' => array_values($this->edges),
            'networks' => $this->summary($byId),
        ];
    }

    /** The networks for the list under the diagram: busiest first, VPNs last. */
    private function summary(Collection $byId): array
    {
        return collect($this->networks)->map(fn ($network, $key) => [
            'id' => $key,
            'cidr' => $network['cidr'],
            'site' => $network['site'] === 'local' ? null : $network['site'],
            'kind' => $network['kind'],
            'gateway' => $network['gateway'] ?? null,
            'online' => $network['online'],
            'devices' => collect($network['members'])->keys()->map(fn ($id) => ['id' => $id, 'name' => $byId[$id]->displayName, 'online' => ! $byId[$id]->offline, 'ping' => $byId[$id]->isPingOnly])->values()->all(),
            'relays' => collect($network['members'])->keys()->filter(fn ($id) => ! $byId[$id]->isPingOnly && ! $byId[$id]->offline && $byId[$id]->signsRequests)->map(fn ($id) => $byId[$id]->displayName)->values()->all(),
            'anchor' => self::anchor($key),
            'unknown' => collect($network['unknown'] ?? [])->map(fn (NetworkNeighbour $neighbour) => [
                'id' => $neighbour->id, 'ip' => $neighbour->ip, 'mac' => $neighbour->mac, 'hostname' => $neighbour->hostname,
                'fresh' => $neighbour->fresh, 'random' => $neighbour->randomMac, 'title' => self::neighbourTitle($neighbour),
            ])->all(),
            'scan' => $network['site'] === null ? null : self::scanner($network, $byId),
        ])->sortBy(fn ($network) => [$network['kind'] === 'vpn' ? 1 : 0, -count($network['devices'])])->values()->all();
    }

    /** The network's card in the list view of the page (where its unknown devices are listed). */
    public static function cardUrl(string $key): string
    {
        return route('networks', ['view' => 'list']).'#'.self::anchor($key);
    }

    /** The id of the network's card on the page (the links of its unknown devices go there). */
    public static function anchor(string $key): string
    {
        return 'network-'.substr(md5($key), 0, 10);
    }

    private static function neighbourTitle(NetworkNeighbour $neighbour): string
    {
        return collect([
            __('MAC :mac', ['mac' => $neighbour->mac]).($neighbour->randomMac ? ' ('.__('private, made up by the device').')' : ''),
            __('first seen :time', ['time' => $neighbour->first_seen_at->diffForHumans()]),
            __('last seen :time', ['time' => $neighbour->last_seen_at->diffForHumans()]),
        ])->implode(' · ');
    }

    /**
     * Who can scan the network: [device id, name] of the first online agent in it that allows scans
     * (network_discovery "scan" in its config.json), or the reason no one can, and the scan of the
     * last 10 minutes (or one still running).
     *
     * @return array{agent: ?int, name: ?string, refusal: ?string, command: ?array}
     */
    private static function scanner(array $network, Collection $byId): array
    {
        $agents = collect($network['members'])->filter(fn ($member, $id) => ! $byId[$id]->isPingOnly && $member['connected'] && $member['type'] !== 'vpn')->keys();
        $refusal = (int) explode('/', $network['cidr'])[1] < DeviceCommand::MIN_SCAN_PREFIX
            ? __('Too large to scan (at most /:prefix)', ['prefix' => DeviceCommand::MIN_SCAN_PREFIX])
            : null;
        // Off for the whole portal: that is the reason, whatever the agents allow.
        $refusal ??= NetworkNeighbour::enabled() ? null : __('Network discovery is turned off in the portal');
        $agent = null;
        $quiet = false;
        if ($refusal === null) {
            // Why each agent of the network cannot scan (too old, not allowed, offline): the first
            // that can scans, otherwise their reasons are shown.
            // Short, the agents with the same reason together: "FURV4_1, IPAD-ANNA: network_discovery "neighbours"".
            $reasons = [];
            foreach ($agents as $id) {
                $device = $byId[$id];
                $reason = $device->commandRefusal('scanNetwork', ['cidr' => $network['cidr']]);
                if ($reason === null) {
                    $agent = $device;
                    break;
                }
                $short = match (true) {
                    $device->offline => __('offline'),
                    version_compare((string) $device->agent_version, Device::NETWORK_DISCOVERY_VERSION, '<') => __('needs agent :version', ['version' => Device::NETWORK_DISCOVERY_VERSION]),
                    $device->networkDiscovery !== 'scan' => __('network_discovery ":level"', ['level' => $device->networkDiscovery ?? 'neighbours']),
                    default => $reason,
                };
                $reasons[$short][] = $device->displayName;
            }
            // Only offline agents: the card already says nothing here is online.
            $quiet = $agent === null && array_keys($reasons) === [__('offline')];
            if ($agent === null) {
                $refusal = $reasons === [] ? __('No agent in this network')
                    : collect($reasons)->map(fn ($names, $reason) => implode(', ', array_slice($names, 0, 4)).(count($names) > 4 ? ' …' : '').': '.$reason)->implode(' · ');
            }
        }
        $command = DeviceCommand::query()->where('command', 'scanNetwork')->where('target', 'scan:'.$network['cidr'])->whereIn('device_id', $agents->all())
            ->where(fn ($query) => $query->where('created_at', '>=', now()->subMinutes(10))->orWhereIn('status', DeviceCommand::ACTIVE))
            ->latest('id')->first();

        return ['agent' => $agent?->id, 'name' => $agent?->displayName, 'refusal' => $refusal, 'quiet' => $quiet ?? false, 'command' => $command ? [
            'id' => $command->id, 'status' => $command->status, 'progress' => $command->progress, 'message' => $command->message,
            'active' => in_array($command->status, DeviceCommand::ACTIVE, true), 'by' => $byId[$command->device_id]->displayName ?? null,
        ] : null];
    }

    private function node(string $id, string $kind, string $label, ?string $sub, string $icon, string $tone, string $state = 'up', ?string $url = null, array $badges = [], array $extra = []): void
    {
        $this->nodes[$id] = compact('id', 'kind', 'label', 'sub', 'icon', 'tone', 'state', 'url', 'badges') + $extra;
    }

    private function edge(string $from, string $to, string $type, bool $up, ?string $label = null): void
    {
        $this->edges[$from.'>'.$to] = ['id' => $from.'>'.$to, 'from' => $from, 'to' => $to, 'type' => $type, 'up' => $up, 'label' => $label];
    }

    /** "192.168.1.0/24" for 192.168.1.42/24; null for loopback, link-local and odd prefixes. */
    public static function cidr(string $address, int $prefix): ?string
    {
        $ip = ip2long($address);
        if ($ip === false || $prefix < 8 || $prefix > 32 || str_starts_with($address, '127.') || str_starts_with($address, '169.254.')) {
            return null;
        }
        $mask = $prefix === 32 ? 0xFFFFFFFF : ((-1 << (32 - $prefix)) & 0xFFFFFFFF);

        return long2ip($ip & $mask).'/'.$prefix;
    }

    /** An address of a host in the network: not its network address, broadcast, loopback or link-local. */
    public static function isHost(string $cidr, string $address): bool
    {
        [$network, $prefix] = explode('/', $cidr);
        $ip = ip2long($address);
        $broadcast = ip2long($network) | ((1 << (32 - (int) $prefix)) - 1);

        return self::contains($cidr, $address) && self::cidr($address, 32) !== null && ((int) $prefix >= 31 || ($ip !== ip2long($network) && $ip !== $broadcast));
    }

    public static function contains(string $cidr, string $address): bool
    {
        [$network, $prefix] = explode('/', $cidr);
        $ip = ip2long($address);
        $mask = (int) $prefix === 32 ? 0xFFFFFFFF : ((-1 << (32 - (int) $prefix)) & 0xFFFFFFFF);

        return $ip !== false && ($ip & $mask) === (ip2long($network) & $mask);
    }
}
