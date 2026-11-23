<?php

namespace App\Models;

use App\Support\NetworkMap;
use App\Support\SecurityScanner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SteelAnts\LaravelBoilerplate\Models\Setting;
use SteelAnts\LaravelBoilerplate\Types\SettingDataType;

/**
 * What an agent found on one address in its network when asked to scan its ports (agents 1.18.0+):
 * the open TCP ports with a passive identification of the service (a banner, the HTTP Server header
 * and title, the TLS certificate subject) and passive observations about them (no payloads are
 * sent, only what the service returns on its own is read). One row per address and network segment
 * (NetworkMap's site), the latest scan replaces the previous one. Everything the scanned host
 * returns is untrusted: it is stored as plain text and shown escaped.
 */
class PortScanResult extends Model
{
    use MassPrunable;

    /** Days a result is kept (a scan is a point-in-time snapshot). */
    public const RETENTION_DAYS = 30;

    /** The portal setting that turns port scanning off for all agents (Networks page, system admins). */
    public const SETTING = 'mdm.port_scan';

    /** At most this many ports in one result (a custom list is capped the same way by the command). */
    public const MAX_PORTS = 1024;

    /** At most this many passive observations per result. */
    public const MAX_FINDINGS = 50;

    /** The common TCP ports scanned when the command carries no port list. */
    public const DEFAULT_PORTS = [
        21, 22, 23, 25, 53, 80, 110, 111, 135, 139, 143, 161, 389, 443, 445, 465, 587, 631, 636,
        993, 995, 1433, 1521, 1723, 1883, 2049, 2375, 2376, 3000, 3306, 3389, 5000, 5060, 5432,
        5601, 5900, 5985, 5986, 6379, 7070, 8000, 8008, 8080, 8086, 8096, 8123, 8443, 8883, 9000,
        9090, 9100, 9200, 11211, 27017,
    ];

    protected $fillable = ['site', 'network', 'ip', 'scanned_by', 'ports', 'findings', 'scanned_at'];

    protected $casts = [
        'ports' => 'array',
        'findings' => 'array',
        'scanned_at' => 'datetime',
    ];

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'scanned_by');
    }

    public function prunable(): Builder
    {
        return static::query()->where('scanned_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /** Whether the portal takes port scans from the agents (on unless a system admin turned it off). */
    public static function enabled(): bool
    {
        return (string) Setting::query()->whereNull('settable_id')->where('index', self::SETTING)->value('value') !== '0';
    }

    public static function setEnabled(bool $enabled): void
    {
        Setting::query()->whereNull('settable_id')->updateOrCreate(['index' => self::SETTING], ['value' => $enabled ? '1' : '0', 'type' => SettingDataType::BOOL]);
    }

    /**
     * The result an agent reports for a scan ({ip, ports: [{port, service?, banner?}], findings?: []}):
     * only an address in one of the agent's own networks, when the device allows port scans and the
     * portal does too. Everything the host returned is kept as plain text (control characters
     * stripped, lengths capped). Returns whether it was taken.
     */
    public static function record(Device $device, mixed $payload): bool
    {
        if (! is_array($payload) || $device->isPingOnly || $device->portScan !== 'on' || ! self::enabled()) {
            return false;
        }
        $ip = $payload['ip'] ?? null;
        if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $networks = NetworkNeighbour::networksOf($device);
        $network = collect(array_keys($networks))->first(fn ($cidr) => NetworkMap::contains($cidr, $ip) && NetworkMap::isHost($cidr, $ip));
        if ($network === null) {
            return false;
        }

        $result = static::query()->updateOrCreate(['site' => $networks[$network], 'ip' => $ip], [
            'network' => $network,
            'scanned_by' => $device->id,
            'ports' => self::cleanPorts($payload['ports'] ?? null),
            'findings' => self::cleanFindings($payload['findings'] ?? null),
            'scanned_at' => now(),
        ]);
        // Turn the open ports into security findings (and alerts) via the detection rules.
        rescue(fn () => SecurityScanner::scanPorts($result));

        return true;
    }

    /**
     * Open ports, sorted, deduplicated, capped; bad entries dropped. Each keeps the structured facts
     * the detection rules match on (service, the passive HTTP/TLS flags) plus the display banner.
     */
    private static function cleanPorts(mixed $ports): array
    {
        $clean = [];
        foreach (is_array($ports) ? $ports : [] as $entry) {
            $port = is_array($entry) ? ($entry['port'] ?? null) : null;
            if (! is_int($port) && ! (is_string($port) && ctype_digit($port))) {
                continue;
            }
            $port = (int) $port;
            if ($port < 1 || $port > 65535 || isset($clean[$port])) {
                continue;
            }
            $clean[$port] = [
                'port' => $port,
                'service' => self::text($entry['service'] ?? null, 40),
                'banner' => self::text($entry['banner'] ?? null, 500),
                'server' => self::text($entry['server'] ?? null, 200),
                'title' => self::text($entry['title'] ?? null, 200),
                'tls' => self::bool($entry['tls'] ?? null),
                'http' => self::bool($entry['http'] ?? null),
                // Header flags default to present (true) so an old agent or a non-web port is not a finding.
                'hsts' => self::bool($entry['hsts'] ?? true),
                'csp' => self::bool($entry['csp'] ?? true),
                'xframe' => self::bool($entry['xframe'] ?? true),
                'certSelfSigned' => self::bool($entry['certSelfSigned'] ?? null),
                'certExpired' => self::bool($entry['certExpired'] ?? null),
                'tlsFailed' => self::bool($entry['tlsFailed'] ?? null),
            ];
            if (count($clean) >= self::MAX_PORTS) {
                break;
            }
        }
        ksort($clean);

        return array_values($clean);
    }

    /**
     * One item per open port, shaped to the `portscan` detection-rule source fields
     * (App\Support\SecurityRules::SOURCES). The booleans are what the rules match on.
     *
     * @return list<array<string, mixed>>
     */
    public function toSecurityItems(): array
    {
        return array_map(fn (array $p) => [
            'Ip' => $this->ip,
            'Network' => $this->network,
            'Port' => (int) ($p['port'] ?? 0),
            'Service' => (string) ($p['service'] ?? ''),
            'Banner' => (string) ($p['banner'] ?? ''),
            'Server' => (string) ($p['server'] ?? ''),
            'Title' => (string) ($p['title'] ?? ''),
            'Tls' => (bool) ($p['tls'] ?? false),
            'Http' => (bool) ($p['http'] ?? false),
            'Hsts' => (bool) ($p['hsts'] ?? true),
            'Csp' => (bool) ($p['csp'] ?? true),
            'XFrameOptions' => (bool) ($p['xframe'] ?? true),
            'CertSelfSigned' => (bool) ($p['certSelfSigned'] ?? false),
            'CertExpired' => (bool) ($p['certExpired'] ?? false),
            'TlsFailed' => (bool) ($p['tlsFailed'] ?? false),
        ], $this->ports ?? []);
    }

    private static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** Passive observations as a list of short plain-text strings, capped. */
    private static function cleanFindings(mixed $findings): ?array
    {
        $clean = [];
        foreach (is_array($findings) ? $findings : [] as $finding) {
            $text = self::text($finding, 200);
            if ($text !== null) {
                $clean[] = $text;
            }
            if (count($clean) >= self::MAX_FINDINGS) {
                break;
            }
        }

        return $clean === [] ? null : array_values(array_unique($clean));
    }

    /** A value from the scanned host as safe plain text: control characters removed, length capped. */
    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        // Strip C0/C1 control characters (ANSI escapes, NUL) so a hostile banner stays plain text.
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
