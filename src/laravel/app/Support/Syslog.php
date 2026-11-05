<?php

namespace App\Support;

use App\Models\Device;
use App\Models\SecurityInventory;
use App\Models\SecurityRule;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syslog from other servers and devices (RFC 3164 and 5424, UDP and TCP on port 5514, published as 514). rsyslog in the
 * container receives it and hands every message as a line of JSON to `php artisan mdm:syslog-ingest`, which uses this class:
 * the sender is the device whose IP address it is (an interface of an agent, the address of a ping device), the
 * messages are records of the log source "syslog" and go through the same parsers and rules as the logs of the agents.
 * Messages from an address that belongs to no device (or to more than one) are dropped.
 */
class Syslog
{
    /** How long the addresses of the devices are remembered by the long running ingest command. */
    private const MAP_SECONDS = 120;

    private const MAX_MESSAGE = 2000;

    /** @var array{at: int, map: array<string, int>}|null */
    private static ?array $cache = null;

    /** Same address, one spelling: no zone ("fe80::1%eth0"), IPv4-mapped IPv6 as IPv4, packed and unpacked. */
    public static function normalize(string $address): ?string
    {
        $address = explode('%', trim($address))[0];
        $packed = filter_var($address, FILTER_VALIDATE_IP) ? @inet_pton($address) : false;
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        return inet_ntop($packed) ?: null;
    }

    /** An address that says nothing about the device: loopback, unspecified, link-local. */
    private static function ignored(string $address): bool
    {
        return $address === '::1' || $address === '::' || str_starts_with($address, '127.') || $address === '0.0.0.0'
            || str_starts_with($address, '169.254.') || str_starts_with(strtolower($address), 'fe80:');
    }

    /**
     * The device of every address that belongs to exactly one: the addresses of the interfaces an agent reports, the
     * address of a ping device, and the public address a device reaches the server from (when it is the only one).
     *
     * @return array<string, int> address => device id
     */
    public static function addresses(): array
    {
        $owners = [];
        $public = [];
        foreach (Device::query()->get() as $device) {
            $own = [];
            foreach ($device->networks as $network) {
                foreach ((array) ($network['IPAddresses'] ?? []) as $address) {
                    $own[] = is_string($address) ? $address : '';
                }
            }
            foreach ($own as $address) {
                $address = self::normalize($address);
                if ($address !== null && ! self::ignored($address)) {
                    $owners[$address][$device->id] = true;
                }
            }
            $address = is_string($device->public_ip) ? self::normalize($device->public_ip) : null;
            if ($address !== null && ! self::ignored($address)) {
                $public[$address][$device->id] = true;
            }
        }
        // The public address only where no interface has it (behind NAT it is the address of many devices).
        foreach ($public as $address => $ids) {
            $owners[$address] ??= $ids;
        }
        $map = [];
        foreach ($owners as $address => $ids) {
            if (count($ids) === 1) {
                $map[$address] = (int) array_key_first($ids);
            }
        }

        return $map;
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    public static function deviceIdFor(string $address): ?int
    {
        $address = self::normalize($address);
        if ($address === null) {
            return null;
        }
        if (self::$cache === null || self::$cache['at'] < time() - self::MAP_SECONDS) {
            self::$cache = ['at' => time(), 'map' => self::addresses()];
        }

        return self::$cache['map'][$address] ?? null;
    }

    /**
     * The record of one message as rsyslog hands it over ({ip, time, host, facility, severity, app, pid, msg}),
     * null when it is not one.
     *
     * @return array<string, string>|null
     */
    public static function record(mixed $message): ?array
    {
        if (! is_array($message) || ! is_string($message['msg'] ?? null)) {
            return null;
        }
        $text = fn (string $key, int $limit = 200) => mb_substr(trim((string) ($message[$key] ?? '')), 0, $limit);
        $nil = fn (string $value) => $value === '-' ? '' : $value;

        return [
            'Time' => $text('time', 40),
            'Host' => $text('host'),
            'Facility' => $text('facility', 20),
            'Severity' => $text('severity', 20),
            'App' => $nil($text('app', 100)),
            'Pid' => $nil($text('pid', 20)),
            'Message' => mb_substr(trim($message['msg']), 0, self::MAX_MESSAGE),
        ];
    }

    /**
     * Parses the messages of the devices they come from and stores the events.
     *
     * @param  array<int, array>  $messages  decoded lines of rsyslog
     * @return array{events: int, devices: int, unknown: array<string, int>} unknown: messages per address of no device
     */
    public static function ingest(array $messages): array
    {
        $byDevice = [];
        $unknown = [];
        foreach ($messages as $message) {
            $record = self::record($message);
            $address = is_array($message) && is_string($message['ip'] ?? null) ? $message['ip'] : '';
            if ($record === null) {
                continue;
            }
            $id = self::deviceIdFor($address);
            if ($id === null) {
                $unknown[$address] = ($unknown[$address] ?? 0) + 1;

                continue;
            }
            $byDevice[$id][] = $record;
        }
        // The icon of the device: it sends syslog (one UPDATE a minute at most, updated_at stays).
        if ($byDevice !== []) {
            Device::query()->whereIn('id', array_keys($byDevice))
                ->where(fn ($query) => $query->whereNull('syslog_at')->orWhere('syslog_at', '<', now()->subMinute()))
                ->toBase()->update(['syslog_at' => now()]);
        }
        $result = ['events' => 0, 'devices' => 0, 'unknown' => $unknown];
        if ($byDevice === [] || ! SecurityScanner::logsAllowed()) {
            return $result;
        }
        SecurityRule::syncBuiltIn();
        $parsers = SecurityRule::query()->enabled()->parsers()->where('source', 'syslog')->get()->pluck('definition');
        if ($parsers->isEmpty()) {
            return $result;
        }
        foreach ($byDevice as $id => $records) {
            $device = Device::find($id);
            if ($device === null) {
                continue;
            }
            try {
                $events = SecurityInventory::sanitizeEvents(SecurityParsers::run([['source' => 'syslog', 'records' => $records]], $parsers));
                if ($events !== []) {
                    SecurityScanner::ingest($device, [], $events);
                    $result['events'] += count($events);
                    $result['devices']++;
                }
            } catch (Throwable $e) {
                Log::warning("Syslog of device $id could not be stored: ".$e->getMessage());
            }
        }

        return $result;
    }
}
