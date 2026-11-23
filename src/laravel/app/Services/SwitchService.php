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
 * The ports of a switch without SNMP (TP-Link Easy Smart, the way check_tplink reads them): the server decides which pages
 * of its web interface are requested and reads the answers; the agent of its network only makes the requests (the ingress
 * proxy of Device::pingTargetsFor, agents 1.37.0+) and never interprets them. The password is stored encrypted (APP_KEY),
 * and goes to the agent encrypted with the public key of that agent, so only it can read it.
 *
 * The ports are measured values of the ping-only device: switch.port.link (code of LINKS, name = the port), switch.port.bad
 * (bad packets, TX + RX), switch.port.tx / switch.port.rx (good packets, at most every 10 minutes), switch.ports.up.
 */
class SwitchService
{
    /** Agents from this version make the requests of a probe. */
    public const VERSION = '1.37.0';

    /** The kinds of switch the portal reads (the value of the setting switch.type). */
    public const TYPES = ['tplink' => 'TP-Link Easy Smart'];

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
        return $device->isPingOnly && isset(self::TYPES[(string) $device->switch_type]) && (string) $device->switch_user !== '' && (string) $device->switch_password !== '';
    }

    public static function storePassword(string $password): string
    {
        return Crypt::encryptString($password);
    }

    private static function password(Device $device): ?string
    {
        try {
            return Crypt::decryptString((string) $device->switch_password);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The requests an agent makes to the switch, or null when it cannot (an older agent, no key, nothing set): the login only
     * when the last answer had no ports or after LOGIN_SECONDS, otherwise just the page.
     *
     * @return array{port: int, steps: array<int, array<string, string>>}|null
     */
    public static function probe(Device $device, Device $relay): ?array
    {
        if (! self::enabled($device) || ! $relay->public_key || version_compare((string) $relay->agent_version, self::VERSION, '<')) {
            return null;
        }
        $steps = [['method' => 'GET', 'path' => self::PAGE]];
        if (! Cache::has(self::loginKey($device))) {
            $pem = Signing::publicKeyPem((array) $relay->public_key);
            $password = self::password($device);
            $secret = null;
            if ($pem !== null && $password !== null && openssl_public_encrypt(json_encode(['username' => (string) $device->switch_user, 'password' => $password]), $encrypted, $pem, OPENSSL_PKCS1_OAEP_PADDING)) {
                $secret = base64_encode($encrypted);
            }
            if ($secret === null) {
                return null;
            }
            array_unshift($steps, ['method' => 'POST', 'path' => '/logon.cgi', 'form' => ['logon' => 'Login'], 'secret' => $secret]);
            $steps[] = ['method' => 'GET', 'path' => self::PAGE];
        }

        return ['port' => 80, 'steps' => $steps];
    }

    private static function loginKey(Device $device): string
    {
        return 'switch-login.'.$device->id;
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
            $ports = $device !== null && is_string($probe['body'] ?? null) ? self::parse($probe['body']) : null;
            if ($ports === null) {
                // The next request logs in again.
                Cache::forget(self::loginKey($device ?? new Device(['id' => $id])));

                continue;
            }
            Cache::put(self::loginKey($device), true, self::LOGIN_SECONDS);
            self::store($device, $ports);
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
            $device->forceFill(['switch_id' => $wanted ? $switch->id : null, 'switch_port' => $wanted ? $port : null])->save();
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
            ->each(fn (Device $device) => $device->forceFill(['switch_id' => null, 'switch_port' => null])->save());
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
            $on = $plugged->where('switch_port', $port['port'])->map(fn (Device $other) => $other->displayName)->values()->all();
            $link = __(self::LINKS[$port['link']] ?? 'Down');
            $ports[] = $port + [
                'devices' => $on,
                'heading' => __(':switch: port :port', ['switch' => $device->displayName, 'port' => $port['port']]),
                'title' => implode(' · ', array_filter([
                    __('Port :port', ['port' => $port['port']]), $link,
                    $port['bad'] > 0 ? trans_choice(':count bad packet|:count bad packets', $port['bad']) : null,
                    $on === [] ? null : implode(', ', $on),
                ])),
            ];
        }

        return ['id' => $device->id, 'ports' => $ports, 'empty' => __('Ports not read yet')];
    }
}
