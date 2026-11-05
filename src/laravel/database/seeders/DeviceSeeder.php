<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\PortScanResult;
use App\Support\AgentScript;
use Illuminate\Database\Seeder;

/**
 * Demo devices for development: agents in one LAN (192.168.1.0/24) and ping-only hosts they ping,
 * with a day of ping results and a port scan. The online ones stay online (last seen a year ahead).
 * Run again: the devices of the seeder are replaced.
 */
class DeviceSeeder extends Seeder
{
    private const NETWORK = '192.168.1';

    public function run(): void
    {
        Device::query()->where('token', 'like', 'seed-%')->get()->each->delete();

        $version = AgentScript::version() ?? '1.30.1';
        $nas = $this->agent('nas', 'linux', 'Ubuntu 24.04.3 LTS', 'Intel(R) Celeron(R) N5105 @ 2.00GHz', 4, 10, $version, uptime: 1_987_200, drives: [
            ['DriveLetter' => '/', 'DriveType' => 3, 'FriendlyName' => 'root', 'Size' => 240_000_000_000, 'SizeRemaining' => 151_000_000_000],
            ['DriveLetter' => '/srv/data', 'DriveType' => 3, 'FriendlyName' => 'data', 'Size' => 8_000_000_000_000, 'SizeRemaining' => 2_300_000_000_000],
        ], extra: ['PortScan' => 'on', 'Type' => 'server']);
        $this->agent('asus-pc-2', 'windows', 'Microsoft Windows 11 Enterprise (10.0.26300)', '11th Gen Intel(R) Core(TM) i7-11700K @ 3.60GHz', 16, 20, $version, uptime: 93_600, user: 'ASUS-PC-2\\jonatan', extra: ['Type' => 'desktop'], drives: [
            ['DriveLetter' => 'C', 'DriveType' => 3, 'FriendlyName' => 'System', 'Size' => 1_000_000_000_000, 'SizeRemaining' => 412_000_000_000],
        ]);
        $this->agent('laptop-anna', 'windows', 'Microsoft Windows 11 Pro (10.0.26100)', 'AMD Ryzen 7 7840U w/ Radeon 780M Graphics', 16, 31, $version, online: false, extra: ['Battery' => 64, 'Type' => 'laptop'], drives: [
            ['DriveLetter' => 'C', 'DriveType' => 3, 'FriendlyName' => 'Windows', 'Size' => 512_000_000_000, 'SizeRemaining' => 98_000_000_000],
        ]);

        $nanokvm = $this->ping('NanoKVM', 203, '48:DA:35:6F:D1:8A');
        $this->ping('Tiskárna', 50, '00:1B:A9:3C:44:10');
        $this->ping('Access point', 2, '1C:0B:8B:BA:57:F6');
        $this->ping('Kamera dvůr', 120, null, online: false);

        PortScanResult::query()->updateOrCreate(['site' => self::NETWORK.'.1', 'ip' => $nanokvm->ping_address], [
            'network' => self::NETWORK.'.0/24',
            'scanned_by' => $nas->id,
            'ports' => [
                ['port' => 80, 'service' => 'http', 'banner' => 'HTTP 307 Temporary Redirect'],
                ['port' => 443, 'service' => 'https', 'banner' => 'TLS: localhost, NanoKVM'],
            ],
            'findings' => ['Port 80: no Content-Security-Policy or X-Frame-Options header, the service runs over HTTP without TLS.'],
            'scanned_at' => now()->subWeeks(3),
        ]);
    }

    private function agent(string $name, string $platform, string $os, string $cpu, int $cores, int $host, string $version, int $uptime = 3600, ?string $user = null, array $drives = [], bool $online = true, array $extra = []): Device
    {
        $ip = self::NETWORK.'.'.$host;
        $mac = sprintf('02-00-00-00-00-%02X', $host);
        $device = new Device;
        $device->forceFill(['token' => 'seed-'.$name, 'name' => $name, 'os' => $os]);
        $device->data = json_encode(['machine' => array_filter([
            'Hostname' => $name,
            'AgentVersion' => $version,
            'Platform' => $platform,
            'Processor' => $cpu,
            'Cores' => $cores,
            'uptime' => $uptime,
            'last_logon_user' => $user,
            'ScriptsEnabled' => true,
            'Drives' => $drives,
            'Networks' => [[
                'Name' => $platform === 'windows' ? 'Ethernet' : 'eth0', 'Description' => '', 'Type' => 'lan', 'Connected' => true, 'Status' => 'Up',
                'Mac' => $mac, 'IPAddresses' => [$ip], 'Addresses' => [['Address' => $ip, 'PrefixLength' => 24]],
                'Gateway' => self::NETWORK.'.1', 'GatewayMac' => '50-C7-BF-AA-BB-CC',
            ]],
        ] + $extra, fn ($value) => $value !== null)]);
        $device->save();

        // Online "for a year": without a connected agent a device is offline after HEARTBEAT_TIMEOUT.
        // A signing key makes it a relay that pings the ping-only hosts and can scan ports.
        $key = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]));
        $device->forceFill([
            'public_key' => ['n' => base64_encode(ltrim($key['rsa']['n'], "\0")), 'e' => base64_encode(ltrim($key['rsa']['e'], "\0"))],
            'key_registered_at' => now(),
            'last_seen_at' => $online ? now()->addYear() : now()->subDays(2),
            'last_http_at' => $online ? now()->addYear() : now()->subDays(2),
        ])->saveQuietly();

        return $device->fresh();
    }

    private function ping(string $name, int $host, ?string $mac, bool $online = true): Device
    {
        $device = new Device;
        $device->forceFill([
            'kind' => 'ping', 'name' => $name, 'os' => '', 'token' => 'seed-ping-'.$host,
            'ping_address' => self::NETWORK.'.'.$host, 'ping_prefix' => 24, 'ping_mac' => $mac,
            'ping_rtt' => $online ? round(mt_rand(5, 40) / 10, 1) : null,
        ]);
        $device->save();

        // A day of checks every 5 minutes, with a short outage two hours ago.
        $moments = [];
        for ($minutes = 24 * 60; $minutes >= 0; $minutes -= 5) {
            $up = $online ? ! ($minutes >= 120 && $minutes < 135) : $minutes > 6 * 60;
            $moments[] = [now()->subMinutes($minutes), \App\Support\PingUsage::values($up, $up ? round(mt_rand(8, 60) / 10, 1) : null)];
        }
        \App\Support\ValueRecorder::recordMany($device->getMorphClass(), $device->id, $moments);

        $device->forceFill(['last_seen_at' => $online ? now()->addYear() : now()->subHours(6)])->saveQuietly();

        return $device->fresh();
    }
}
