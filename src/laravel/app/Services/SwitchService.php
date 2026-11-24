<?php

namespace App\Services;

use App\Models\Device;
use App\Models\ValueDefinition;
use App\Support\LiveUpdates;
use App\Support\NetworkMap;
use App\Support\Signing;
use App\Support\ValueRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The ports of a switch: the server decides what is requested and reads the answers; the agent of its network only makes
 * the requests (the ingress proxy of Device::pingTargetsFor, agents 1.37.0+) and never interprets them. Two kinds: a switch
 * without SNMP (TP-Link Easy Smart, the pages of its web interface, the way check_tplink reads them) and any device with
 * SNMP v1 or v2c (agents 1.39.0+): the user gives only the community, the rest is found out from the answers (the version,
 * what the device is, the interface table, the MAC table), all of it in standard MIBs. The password (the community of SNMP)
 * is stored encrypted (APP_KEY), and goes to the agent encrypted with the public key of that agent, so only it can read it.
 *
 * The ports are measured values of the ping-only device: switch.port.link (code of LINKS, name = the port), switch.port.bad
 * (bad packets, TX + RX), switch.port.tx / switch.port.rx (good packets, at most every 10 minutes), switch.ports.up.
 */
class SwitchService
{
    /** Agents from this version make the requests of a probe. */
    public const VERSION = '1.37.0';

    /** Agents from this version read a device over SNMP (v1 and v2c, strings in the answer). */
    public const SNMP_VERSION = '1.39.0';

    /** The kind of switch set by the community alone (the value of the setting switch.type). */
    public const SNMP = 'snmp';

    /** The kinds of switch the portal reads from a web page, chosen by hand (the value of the setting switch.type). */
    public const TYPES = ['tplink' => 'TP-Link Easy Smart'];

    /** The SNMP versions the agent tries: the code it reports is the one of the protocol (0 = v1, 1 = v2c). */
    public const SNMP_VERSIONS = [0 => '1', 1 => '2c'];

    /**
     * The columns the agent walks and returns as lines `oid value` (a string as `oid x:hex`); the server reads them (parseSnmp,
     * snmpMacs, system). All of them are standard: SNMPv2-MIB (RFC 3418) the system, IF-MIB (RFC 2863) the interfaces,
     * BRIDGE-MIB (RFC 4188) and Q-BRIDGE-MIB (RFC 4363) the MAC table, so they are the same on every make.
     */
    public const SNMP_COLUMNS = [
        'sys_descr' => '1.3.6.1.2.1.1.1', 'sys_name' => '1.3.6.1.2.1.1.5',
        'type' => '1.3.6.1.2.1.2.2.1.3', 'status' => '1.3.6.1.2.1.2.2.1.8', 'speed' => '1.3.6.1.2.1.2.2.1.5', 'fast' => '1.3.6.1.2.1.31.1.1.1.15',
        'rx' => '1.3.6.1.2.1.2.2.1.11', 'rx_bad' => '1.3.6.1.2.1.2.2.1.14', 'tx' => '1.3.6.1.2.1.2.2.1.17', 'tx_bad' => '1.3.6.1.2.1.2.2.1.20',
        // The table of MAC addresses (the last ones: a long table does not push the interfaces out): BRIDGE-MIB (index = the MAC), Q-BRIDGE-MIB
        // (index = VLAN and the MAC), and the bridge port => ifIndex of BRIDGE-MIB. Not every switch has them.
        'bridge_port' => '1.3.6.1.2.1.17.1.4.1.2', 'fdb' => '1.3.6.1.2.1.17.4.3.1.2', 'fdb_vlan' => '1.3.6.1.2.1.17.7.1.2.2.1.2',
    ];

    /** The columns of the interface table (one row per ifIndex). */
    private const SNMP_INTERFACE_COLUMNS = ['type' => 1, 'status' => 1, 'speed' => 1, 'fast' => 1, 'rx' => 1, 'rx_bad' => 1, 'tx' => 1, 'tx_bad' => 1];

    /** A port that has more MAC addresses behind it than this is a trunk to another switch: the devices are not paired with it. */
    private const MAX_MACS_ON_PORT = 16;

    /** link_status of the page of TP-Link: the code is stored, the name is shown. */
    public const LINKS = [0 => 'Down', 1 => 'Auto', 2 => '10M half', 3 => '10M full', 4 => '100M half', 5 => '100M full', 6 => '1000M full'];

    /** The most that is stored from a page, and how often the counters of good packets are (they change all the time). */
    private const MAX_PORTS = 64;

    private const COUNTER_SECONDS = 600;

    /** How long the switch stays logged in (it trusts the address of the agent after a login). */
    private const LOGIN_SECONDS = 600;

    private const PAGE = '/PortStatisticsRpm.htm';

    public static function enabled(Device $device): bool
    {
        $type = (string) $device->switch_type;

        // SNMP has a community (the password) and no user.
        return $device->isPingOnly && ($type === self::SNMP || (isset(self::TYPES[$type]) && (string) $device->switch_user !== '')) && (string) $device->switch_password !== '';
    }

    public static function storePassword(string $password): string
    {
        return Crypt::encryptString($password);
    }

    /** The password of the switch, or the community of SNMP (null when it cannot be read). */
    public static function password(Device $device): ?string
    {
        try {
            return Crypt::decryptString((string) $device->switch_password);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The requests an agent makes to the switch, or null when it cannot (an older agent, no key, nothing set). TP-Link: the login
     * only when the last answer had no ports or after LOGIN_SECONDS, otherwise just the page. SNMP: the columns to walk and the
     * community (sealed for that agent), every time (UDP has no login).
     *
     * @return array{port: int, steps?: array<int, array<string, string>>, snmp?: array{secret: string, columns: array<int, string>, version?: int}}|null
     */
    public static function probe(Device $device, Device $relay): ?array
    {
        $snmp = (string) $device->switch_type === self::SNMP;
        if (! self::enabled($device) || ! $relay->public_key || version_compare((string) $relay->agent_version, $snmp ? self::SNMP_VERSION : self::VERSION, '<')) {
            return null;
        }
        if ($snmp) {
            $secret = self::seal($relay, ['community' => self::password($device)]);

            // The version that answered last goes first (the agent tries the other one when it does not answer).
            $version = Cache::get(self::versionKey($device));

            return $secret === null ? null : ['port' => 161, 'snmp' => ['secret' => $secret, 'columns' => array_values(self::SNMP_COLUMNS)] + ($version === null ? [] : ['version' => $version])];
        }
        $steps = [['method' => 'GET', 'path' => self::PAGE]];
        if (! Cache::has(self::loginKey($device))) {
            $secret = self::seal($relay, ['username' => (string) $device->switch_user, 'password' => self::password($device)]);
            if ($secret === null) {
                return null;
            }
            array_unshift($steps, ['method' => 'POST', 'path' => '/logon.cgi', 'form' => ['logon' => 'Login'], 'secret' => $secret]);
            $steps[] = ['method' => 'GET', 'path' => self::PAGE];
        }

        return ['port' => 80, 'steps' => $steps];
    }

    /** Fields (strings) encrypted with the public key of the agent (RSA-OAEP), null when it cannot be done. */
    private static function seal(Device $relay, array $fields): ?string
    {
        $pem = Signing::publicKeyPem((array) $relay->public_key);
        if ($pem === null || in_array(null, $fields, true) || ! openssl_public_encrypt(json_encode($fields), $encrypted, $pem, OPENSSL_PKCS1_OAEP_PADDING)) {
            return null;
        }

        return base64_encode($encrypted);
    }

    private static function versionKey(Device $device): string
    {
        return 'snmp-version.'.$device->id;
    }

    private static function loginKey(Device $device): string
    {
        return 'switch-login.'.$device->id;
    }

    /**
     * What the walk found out about a device: the version that answered (remembered, the agent tries it first) and what the device says
     * about itself (the inventory `snmp` / `system`: name, description, version, only the changes).
     */
    private static function discovered(Device $device, string $body, mixed $version): void
    {
        $version = is_int($version) && isset(self::SNMP_VERSIONS[$version]) ? $version : null;
        if ($version !== null) {
            Cache::put(self::versionKey($device), $version, 86400);
        }
        $info = self::system($body);
        $version ??= Cache::get(self::versionKey($device));
        if ($version === null && $info['name'] === null && $info['descr'] === null) {
            return;
        }
        $device->recordInventory('snmp', 'system', $info + ['version' => $version === null ? null : self::SNMP_VERSIONS[$version]]);
        LiveUpdates::device($device->id, 'switch');
    }

    /** What the device said about itself, null before the first answer: name, descr, version. */
    public static function info(Device $device): ?array
    {
        return $device->latestInventory('snmp', 'system');
    }

    /**
     * The ports in the answer of the page of TP-Link, null when it is not one (the login page, another model).
     *
     * @return array<int, array{port: int, enabled: bool, link: int, tx: int, tx_bad: int, rx: int, rx_bad: int}>|null
     */
    public static function parse(string $body): ?array
    {
        if (! preg_match('/max_port_num\s*=\s*(\d+)/', $body, $count) || ! preg_match('/link_status:\[(.*?)\],/s', $body, $link) || ! preg_match('/pkts:\[(.*?)\]/s', $body, $pkts)) {
            return null;
        }
        $ports = (int) $count[1];
        $states = preg_match('/state:\[(.*?)\],/s', $body, $state) ? array_map('trim', explode(',', $state[1])) : [];
        $links = array_map('trim', explode(',', $link[1]));
        $packets = array_map('trim', explode(',', $pkts[1]));
        if ($ports < 1 || $ports > self::MAX_PORTS || count($links) < $ports || count($packets) < $ports * 4) {
            return null;
        }
        $rows = [];
        for ($i = 0; $i < $ports; $i++) {
            $rows[] = [
                'port' => $i + 1,
                'enabled' => ($states[$i] ?? '1') === '1',
                'link' => max(0, min(6, (int) $links[$i])),
                'tx' => max(0, (int) $packets[$i * 4]),
                'tx_bad' => max(0, (int) $packets[$i * 4 + 1]),
                'rx' => max(0, (int) $packets[$i * 4 + 2]),
                'rx_bad' => max(0, (int) $packets[$i * 4 + 3]),
            ];
        }

        return $rows;
    }

    /**
     * The ports in the answer of an SNMP walk (lines `oid value` of the columns of SNMP_COLUMNS): the Ethernet interfaces
     * (ifType 6), the port is the ifIndex. Down unless ifOperStatus is up; the link code is the speed (ifHighSpeed, else ifSpeed):
     * 1000 Mb/s and more 1000M full, 100 Mb/s 100M full, less 10M full (the duplex is not read, full is assumed). Null when there
     * are no Ethernet interfaces in it.
     *
     * @return array<int, array{port: int, enabled: bool, link: int, tx: int, tx_bad: int, rx: int, rx_bad: int}>|null
     */
    public static function parseSnmp(string $body): ?array
    {
        $interfaces = [];
        foreach (self::snmpLines($body) as [$column, $index, $value]) {
            if (isset(self::SNMP_INTERFACE_COLUMNS[$column]) && is_int($value) && ctype_digit($index) && (int) $index > 0) {
                $interfaces[(int) $index][$column] = $value;
            }
        }
        ksort($interfaces);
        $rows = [];
        foreach ($interfaces as $index => $values) {
            if (($values['type'] ?? null) !== 6 || count($rows) >= self::MAX_PORTS) {
                continue;
            }
            $mbit = $values['fast'] ?? intdiv($values['speed'] ?? 0, 1000000);
            $rows[] = [
                'port' => $index,
                'enabled' => true,
                'link' => ($values['status'] ?? 2) !== 1 ? 0 : ($mbit >= 1000 ? 6 : ($mbit >= 100 ? 5 : 3)),
                'tx' => $values['tx'] ?? 0,
                'tx_bad' => $values['tx_bad'] ?? 0,
                'rx' => $values['rx'] ?? 0,
                'rx_bad' => $values['rx_bad'] ?? 0,
            ];
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * The lines `oid value` of an SNMP walk as [column name, the rest of the OID (the index), value]: a number is an int, a string
     * (`x:hex` in the answer) its bytes.
     *
     * @return \Generator<int, array{0: string, 1: string, 2: int|string}>
     */
    private static function snmpLines(string $body): \Generator
    {
        $columns = array_flip(self::SNMP_COLUMNS);
        foreach (preg_split('/\R/', $body) as $line) {
            if (! preg_match('/^(1(?:\.\d+)+) (\d+|x:(?:[0-9a-f]{2})*)$/', trim($line), $match)) {
                continue;
            }
            foreach ($columns as $oid => $name) {
                if (str_starts_with($match[1], $oid.'.')) {
                    yield [$name, substr($match[1], strlen($oid) + 1), str_starts_with($match[2], 'x:') ? (string) hex2bin(substr($match[2], 2)) : (int) $match[2]];

                    break;
                }
            }
        }
    }

    /**
     * What the device says about itself (the system group): its name and description, null for what it did not say. Control
     * characters are removed, the text is cut.
     *
     * @return array{name: ?string, descr: ?string}
     */
    public static function system(string $body): array
    {
        $info = ['name' => null, 'descr' => null];
        foreach (self::snmpLines($body) as [$column, , $value]) {
            if (is_string($value) && in_array($column, ['sys_name', 'sys_descr'], true)) {
                $text = trim((string) preg_replace('/[\x00-\x20\x7f]+/', ' ', mb_convert_encoding($value, 'UTF-8', 'UTF-8')));
                $info[$column === 'sys_name' ? 'name' : 'descr'] ??= $text === '' ? null : mb_substr($text, 0, 255);
            }
        }

        return $info;
    }

    /**
     * The MAC addresses a switch has learned on each port of an SNMP walk, by the number of the port (ifIndex): the table of BRIDGE-MIB
     * and of Q-BRIDGE-MIB, the bridge port turned into the ifIndex. Only the ports in $ports; the MAC addresses that are no unicast address
     * are left out.
     *
     * @param  array<int, array{port: int}>  $ports
     * @return array<int, array<int, string>>
     */
    public static function snmpMacs(string $body, array $ports): array
    {
        $bridge = [];
        $learned = [];
        foreach (self::snmpLines($body) as [$column, $index, $value]) {
            if ($column === 'bridge_port' && is_int($value) && ctype_digit($index)) {
                $bridge[(int) $index] = $value;
            } elseif ($column === 'fdb' || $column === 'fdb_vlan') {
                $arcs = array_map('intval', explode('.', $index));
                $arcs = array_slice($arcs, -6);
                if (is_int($value) && count($arcs) === 6 && max($arcs) <= 255 && $value > 0) {
                    $learned[] = [implode(':', array_map(fn (int $arc) => sprintf('%02X', $arc), $arcs)), $value];
                }
            }
        }
        $known = array_flip(array_column($ports, 'port'));
        $byPort = [];
        foreach ($learned as [$mac, $bridgePort]) {
            $port = $bridge[$bridgePort] ?? $bridgePort;
            $mac = \App\Models\NetworkNeighbour::normalizeMac($mac);
            if ($mac !== null && isset($known[$port])) {
                $byPort[$port][$mac] = $mac;
            }
        }

        return array_map('array_values', $byPort);
    }

    /**
     * What the agent got from the probes ([{id, status, body, error}]): only for the devices it was asked about. Returns how
     * many ended in ports.
     */
    public static function record(Device $relay, mixed $probes): int
    {
        if (! is_array($probes)) {
            return 0;
        }
        $targets = collect(Device::pingTargetsFor($relay))->filter(fn (array $target) => isset($target['probe']))->pluck('id')->all();
        $taken = 0;
        foreach (array_slice($probes, 0, 4) as $probe) {
            $id = is_array($probe) && is_int($probe['id'] ?? null) ? $probe['id'] : null;
            if ($id === null || ! in_array($id, $targets, true)) {
                continue;
            }
            $device = Device::query()->where('kind', 'ping')->find($id);
            $body = $device !== null && is_string($probe['body'] ?? null) ? $probe['body'] : null;
            $snmp = $device !== null && (string) $device->switch_type === self::SNMP;
            $ports = $body === null ? null : ($snmp ? self::parseSnmp($body) : self::parse($body));
            if ($snmp && $body !== null) {
                self::discovered($device, $body, $probe['version'] ?? null);
            }
            if ($ports === null) {
                // The next request logs in again.
                Cache::forget(self::loginKey($device ?? new Device(['id' => $id])));

                continue;
            }
            if (! $snmp) {
                Cache::put(self::loginKey($device), true, self::LOGIN_SECONDS);
            }
            self::store($device, $ports);
            if ($snmp) {
                self::pair($device, self::snmpMacs($body, $ports));
            }
            $taken++;
        }

        return $taken;
    }

    /** @param  array<int, array>  $ports */
    private static function store(Device $device, array $ports): void
    {
        $counters = ValueDefinition::query()->where('valuable_type', $device->getMorphClass())->where('valuable_id', $device->id)->where('key', 'switch.port.tx')->orderByDesc('last_at')->first();
        $withCounters = $counters?->last_at === null || $counters->last_at->lt(now()->subSeconds(self::COUNTER_SECONDS));
        $values = [['key' => 'switch.ports.up', 'value' => count(array_filter($ports, fn (array $port) => $port['link'] >= 2))]];
        foreach ($ports as $port) {
            $name = (string) $port['port'];
            $values[] = ['key' => 'switch.port.link', 'name' => $name, 'value' => $port['enabled'] ? $port['link'] : 0];
            $values[] = ['key' => 'switch.port.bad', 'name' => $name, 'value' => $port['tx_bad'] + $port['rx_bad']];
            if ($withCounters) {
                $values[] = ['key' => 'switch.port.tx', 'name' => $name, 'value' => $port['tx']];
                $values[] = ['key' => 'switch.port.rx', 'name' => $name, 'value' => $port['rx']];
            }
        }
        ValueRecorder::record($device->getMorphClass(), $device->id, $values);
        LiveUpdates::device($device->id, 'switch');
    }

    /**
     * The last ports of a switch for the pages, null when none were read.
     *
     * @return array<int, array{port: int, link: int, bad: int, tx: ?int, rx: ?int}>|null
     */
    public static function ports(Device $device): ?array
    {
        $definitions = ValueDefinition::query()->where('valuable_type', $device->getMorphClass())->where('valuable_id', $device->id)
            ->whereIn('key', ['switch.port.link', 'switch.port.bad', 'switch.port.tx', 'switch.port.rx'])->get();
        if ($definitions->isEmpty()) {
            return null;
        }
        $ports = [];
        foreach ($definitions as $definition) {
            $number = (int) $definition->name;
            $ports[$number] ??= ['port' => $number, 'link' => 0, 'bad' => 0, 'tx' => null, 'rx' => null];
            $field = ['switch.port.link' => 'link', 'switch.port.bad' => 'bad', 'switch.port.tx' => 'tx', 'switch.port.rx' => 'rx'][$definition->key];
            $ports[$number][$field] = $definition->last_value === null ? $ports[$number][$field] : (int) $definition->last_value;
        }
        ksort($ports);

        return array_values($ports);
    }

    private static function macsKey(Device|int $switch): string
    {
        return 'switch-macs.'.($switch instanceof Device ? $switch->id : $switch);
    }

    /** The MAC addresses of a device: its interfaces, the followed one of a ping-only device, the one of its Wake-on-LAN settings. */
    private static function macsOf(Device $device): array
    {
        $interfaces = $device->isPingOnly ? [] : $device->networks;
        $macs = [];
        foreach ([$device->ping_mac, $device->wake_mac, ...array_column($interfaces, 'Mac')] as $mac) {
            if ($mac = \App\Models\NetworkNeighbour::normalizeMac($mac)) {
                $macs[$mac] = $mac;
            }
        }

        return array_values($macs);
    }

    /**
     * Plugs the devices into the ports where their MAC address is learned (a switch with a MAC table, read over SNMP): the switch
     * and the port are set like by hand, marked `auto`. A device seen by several switches goes to the port with the fewest MAC addresses
     * behind it (the nearest one; a trunk to another switch has many), and none when two are equal. Not touched: a device plugged in by
     * hand and one taken off a port by hand (`off`). The tables are kept in the cache for an hour (the pairing needs the tables of all
     * switches) and the devices are looked at again when one changes, or every 10 minutes.
     *
     * @param  array<int, array<int, string>>  $byPort  the MAC addresses by port of this switch
     */
    public static function pair(Device $switch, array $byPort): int
    {
        Cache::put(self::macsKey($switch), $byPort, 3600);
        $hash = md5(json_encode($byPort));
        if (Cache::get('switch-macs-hash.'.$switch->id) === $hash) {
            return 0;
        }
        Cache::put('switch-macs-hash.'.$switch->id, $hash, 600);

        // Where each MAC address is seen: [switch id, port, how many MAC addresses are on that port].
        $seen = [];
        foreach (Device::query()->where('kind', 'ping')->whereSettingIn('switch.type', [self::SNMP])->pluck('id') as $id) {
            foreach (Cache::get(self::macsKey((int) $id), []) as $port => $macs) {
                foreach ($macs as $mac) {
                    $seen[$mac][] = ['switch' => (int) $id, 'port' => (int) $port, 'count' => count($macs)];
                }
            }
        }
        $changed = 0;
        foreach (Device::all() as $device) {
            if ($device->switch_auto === 'off' || ($device->switch_id !== null && $device->switch_auto !== 'auto')) {
                continue;
            }
            $places = collect(self::macsOf($device))->flatMap(fn (string $mac) => $seen[$mac] ?? [])
                ->reject(fn (array $place) => $place['switch'] === $device->id || $place['count'] > self::MAX_MACS_ON_PORT)
                ->sortBy('count')->values();
            $best = $places->first();
            if ($best === null || ($places->count() > 1 && $places[1]['count'] === $best['count'] && ($places[1]['switch'] !== $best['switch'] || $places[1]['port'] !== $best['port']))) {
                continue;
            }
            if ($device->switch_id === $best['switch'] && $device->switch_port === $best['port']) {
                continue;
            }
            $before = $device->switch_id;
            $device->forceFill(['switch_id' => $best['switch'], 'switch_port' => $best['port'], 'switch_auto' => 'auto'])->save();
            foreach (array_unique(array_filter([$before, $best['switch']])) as $id) {
                LiveUpdates::device($id, 'switch');
            }
            LiveUpdates::device($device->id, 'settings');
            $changed++;
        }

        return $changed;
    }

    /** Takes a device off its port by hand: one that was paired by the MAC address is not paired again (`off`). */
    public static function unplug(Device $device): void
    {
        $device->forceFill(['switch_id' => null, 'switch_port' => null, 'switch_auto' => $device->switch_auto === 'auto' ? 'off' : null])->save();
    }

    /**
     * The devices that can be plugged into a port of the switch: the ones of its network (the network map) that have no port
     * yet, and the ones already on this port.
     *
     * @return array<int, array{id: int, name: string, address: string, selected: bool}>
     */
    public static function candidates(Device $switch, int $port): array
    {
        $network = collect(NetworkMap::build()['networks'])->first(fn (array $network) => collect($network['devices'])->contains('id', $switch->id));
        if ($network === null) {
            return [];
        }
        $devices = Device::query()->whereKey(collect($network['members'])->pluck('id')->reject($switch->id)->all())->get()->keyBy('id');
        $rows = [];
        foreach ($network['members'] as $member) {
            $device = $devices->get($member['id']);
            if ($device === null) {
                continue;
            }
            $here = $device->switch_id === $switch->id && $device->switch_port === $port;
            if ($device->switch_id === null || $here) {
                $rows[] = ['id' => $device->id, 'name' => $member['name'], 'address' => $member['address'], 'selected' => $here];
            }
        }

        return $rows;
    }

    /**
     * Sets which devices are on a port: the selected ones (of the candidates) are plugged into it, the ones that were on it and are not
     * selected any more are not. Returns how many devices changed.
     *
     * @param  array<int, mixed>  $selected  device ids
     */
    public static function assign(Device $switch, int $port, array $selected): int
    {
        $selected = array_map('intval', $selected);
        $changed = 0;
        foreach (self::candidates($switch, $port) as $candidate) {
            $device = Device::find($candidate['id']);
            $wanted = in_array($candidate['id'], $selected, true);
            if ($device === null || $wanted === $candidate['selected']) {
                continue;
            }
            if ($wanted) {
                // By hand now: the MAC table does not move it any more.
                $device->forceFill(['switch_id' => $switch->id, 'switch_port' => $port, 'switch_auto' => null])->save();
            } else {
                self::unplug($device);
            }
            $changed++;
        }
        if ($changed > 0) {
            LiveUpdates::device($switch->id, 'switch');
        }

        return $changed;
    }

    /** The devices of a deleted switch are not plugged into it any more (a setting has no foreign key). */
    public static function forget(Device $switch): void
    {
        Device::query()->whereSettingIn('lan.switch_device_id', [$switch->id])->get()
            ->each(fn (Device $device) => $device->forceFill(['switch_id' => null, 'switch_port' => null, 'switch_auto' => null])->save());
        Cache::forget(self::macsKey($switch));
    }

    /**
     * What the map shows on the card of a switch: its ports with the link and the devices plugged into each, or null for a
     * device that is no switch.
     *
     * @param  \Illuminate\Support\Collection<int, Device>  $plugged  the devices plugged into this switch (switch_id)
     * @return array{id: int, ports: array<int, array<string, mixed>>, empty: string}|null
     */
    public static function mapCard(Device $device, $plugged): ?array
    {
        if (! self::enabled($device)) {
            return null;
        }
        $ports = [];
        foreach (self::ports($device) ?? [] as $port) {
            $onPort = $plugged->where('switch_port', $port['port'])->values();
            $on = $onPort->map(fn (Device $other) => $other->displayName)->all();
            $link = __(self::LINKS[$port['link']] ?? 'Down');
            $ports[] = $port + [
                'devices' => $onPort->map(fn (Device $other) => ['name' => $other->displayName, 'url' => route('devices', ['selectedDeviceId' => $other->id])])->all(),
                'link_name' => $link,
                'heading' => __(':switch: port :port', ['switch' => $device->displayName, 'port' => $port['port']]),
                'title' => implode(' · ', array_filter([
                    __('Port :port', ['port' => $port['port']]), $link,
                    $port['bad'] > 0 ? trans_choice(':count bad packet|:count bad packets', $port['bad']) : null,
                    $on === [] ? null : implode(', ', $on),
                ])),
            ];
        }

        return [
            'id' => $device->id, 'ports' => $ports, 'empty' => __('Ports not read yet'),
            // The texts of the dropdown of a port (network-map.js).
            'labels' => [
                'bad' => __('Bad packets'), 'tx' => __('Sent (TX)'), 'rx' => __('Received (RX)'),
                'devices' => __('Plugged into the port'), 'none' => __('No device on this port'), 'hint' => __('Click to choose the devices on this port'),
            ],
        ];
    }

    /** A port reading older than this does not say anything (the switch or its agent is not answering). */
    private const FRESH_SECONDS = 600;

    /**
     * The port a device is plugged into (by hand) and its link now, null when it is plugged into none, the switch is gone or not
     * answering, or the ports were not read lately.
     *
     * @return array{switch: Device, port: int, link: int, name: string}|null
     */
    public static function plugState(Device $device): ?array
    {
        if ($device->switch_id === null || $device->switch_port === null) {
            return null;
        }
        $switch = Device::query()->where('kind', 'ping')->find($device->switch_id);
        if ($switch === null || $switch->offline || ! self::enabled($switch)) {
            return null;
        }
        $link = ValueDefinition::query()->where('valuable_type', $switch->getMorphClass())->where('valuable_id', $switch->id)
            ->where('key', 'switch.port.link')->where('name', (string) $device->switch_port)->first();
        if ($link === null || $link->last_value === null || $link->last_at === null || $link->last_at->lt(now()->subSeconds(self::FRESH_SECONDS))) {
            return null;
        }

        return ['switch' => $switch, 'port' => $device->switch_port, 'link' => (int) $link->last_value, 'name' => $switch->displayName];
    }
}
