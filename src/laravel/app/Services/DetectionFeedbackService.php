<?php

namespace App\Services;

use App\Models\Device;
use App\Models\SecurityFinding;
use App\Models\SecurityInventory;
use App\Models\SecurityRule;
use App\Support\AgentScript;
use App\Support\SecurityCves;

/**
 * What the detection did on this installation, as one JSON document without names, addresses and users, for somebody
 * (a person or an AI) who improves the rules and the engine: which rules fire where, which ones people acknowledge,
 * which findings do not fit the device they are on (a rule of Arm on an x86 machine), and a few examples.
 *
 * It decides nothing: it measures. The kind of the problem (rule, engine, agent, data) is for the reader to judge from
 * the numbers and the examples, the fields are chosen so that it can (`contexts`, `acknowledged_share`, `mismatches`).
 * Devices are numbered per export (`d1`, `d2` ...), the number cannot be mapped back to a device.
 */
class DetectionFeedbackService
{
    public const FORMAT = 1;

    /** Examples of findings kept per rule. */
    public const SAMPLES = 5;

    /** A finding message is cut to this many characters. */
    private const MESSAGE = 300;

    /** Architecture words a finding may name; the first is the normal form (SecurityCves::normalArch). */
    private const ARCHES = ['amd64' => ['amd64', 'x86_64', 'x64'], 'arm64' => ['arm64', 'aarch64'], 'i386' => ['i386', 'i686']];

    /** @return array<string, mixed> */
    public static function build(bool $anonymize = true): array
    {
        $devices = self::devices();
        $names = $anonymize ? self::names($devices) : [];
        $rules = SecurityRule::query()->where('kind', 'detection')->get()->keyBy('id');
        $perRule = [];
        $mismatches = [];

        SecurityFinding::query()->with('device')->orderBy('id')->chunkById(500, function ($findings) use (&$perRule, &$mismatches, $devices, $names, $rules, $anonymize) {
            foreach ($findings as $finding) {
                $rule = $rules[$finding->security_rule_id] ?? null;
                if ($rule === null) {
                    continue;
                }
                $context = $devices[$finding->device_id] ?? null;
                $row = &$perRule[$rule->id];
                $row ??= ['open' => 0, 'acknowledged' => 0, 'resolved' => 0, 'stale' => 0, 'devices' => [], 'contexts' => [], 'samples' => []];
                $state = $finding->resolved_at !== null ? 'resolved' : ($finding->acknowledged_at !== null ? 'acknowledged' : ($finding->stale_at !== null ? 'stale' : 'open'));
                $row[$state]++;
                if ($finding->device_id !== null) {
                    $row['devices'][$finding->device_id] = true;
                }
                $label = $context ? self::label($context) : 'unknown';
                $row['contexts'][$label][$state === 'acknowledged' ? 'acknowledged' : 'other'] = ($row['contexts'][$label][$state === 'acknowledged' ? 'acknowledged' : 'other'] ?? 0) + 1;
                $message = self::clean((string) $finding->message, $finding->device, $anonymize);
                if (count($row['samples']) < self::SAMPLES) {
                    $row['samples'][] = ['device' => $names[$finding->device_id] ?? null, 'context' => $label, 'state' => $state, 'message' => $message];
                }
                if ($context && ($other = self::foreignArch($message, $context['arch'])) !== null && count($mismatches) < 200) {
                    $mismatches[] = ['rule' => $rule->key, 'device' => $names[$finding->device_id] ?? null, 'device_arch' => $context['arch'], 'message_arch' => $other, 'message' => $message];
                }
                unset($row);
            }
        });

        $out = [];
        foreach ($rules as $id => $rule) {
            $row = $perRule[$id] ?? null;
            $total = $row ? $row['open'] + $row['acknowledged'] + $row['resolved'] + $row['stale'] : 0;
            $out[] = [
                'key' => $rule->key, 'name' => $rule->name, 'origin' => $rule->origin, 'feed_version' => $rule->feed_version, 'enabled' => $rule->enabled,
                'severity' => $rule->severity, 'source' => $rule->source, 'platform' => $rule->platform,
                'findings' => $row ? ['open' => $row['open'], 'acknowledged' => $row['acknowledged'], 'resolved' => $row['resolved'], 'stale' => $row['stale']] : ['open' => 0, 'acknowledged' => 0, 'resolved' => 0, 'stale' => 0],
                'devices' => $row ? count($row['devices']) : 0,
                'acknowledged_share' => $total > 0 ? round(($row['acknowledged'] ?? 0) / $total, 3) : null,
                'contexts' => $row['contexts'] ?? [],
                'samples' => $row['samples'] ?? [],
            ];
        }
        usort($out, fn ($a, $b) => ($b['findings']['open'] + $b['findings']['acknowledged']) <=> ($a['findings']['open'] + $a['findings']['acknowledged']));

        return [
            'format' => self::FORMAT,
            'generated_at' => now()->toIso8601String(),
            'anonymized' => $anonymize,
            'agent_version' => AgentScript::version(),
            'feed_version' => SecurityRule::query()->where('origin', SecurityRule::FEED)->value('feed_version'),
            'devices' => ['total' => count($devices), 'contexts' => collect($devices)->countBy(fn ($d) => self::label($d))->sortDesc()->all()],
            'rules' => $out,
            'mismatches' => $mismatches,
            'not_collected' => ['unparsed_log_records' => 'raw logs are not stored, so the records no parser matched are not counted yet'],
        ];
    }

    /**
     * The context of every device: platform, distribution, architecture. A device with no inventory yet has none of them.
     *
     * @return array<int, array{platform: ?string, os: ?string, arch: ?string}>
     */
    private static function devices(): array
    {
        $devices = [];
        Device::query()->orderBy('id')->chunkById(200, function ($chunk) use (&$devices) {
            foreach ($chunk as $device) {
                $os = SecurityInventory::query()->where('device_id', $device->id)->first()?->items('os')[0] ?? [];
                $arch = ($os['Arch'] ?? '') !== '' ? SecurityCves::normalArch($os['Arch']) : null;
                $devices[$device->id] = ['platform' => $device->platform, 'os' => $os['Id'] ?? null, 'arch' => $arch, 'name' => $device->displayName];
            }
        });

        return $devices;
    }

    /** d1, d2 ... in an order that does not follow the ids. */
    private static function names(array $devices): array
    {
        $ids = array_keys($devices);
        usort($ids, fn ($a, $b) => strcmp(md5($a.config('app.key')), md5($b.config('app.key'))));

        return array_combine($ids, array_map(fn ($i) => 'd'.($i + 1), array_keys($ids))) ?: [];
    }

    private static function label(array $context): string
    {
        return implode('/', array_map(fn ($v) => $v ?: '?', [$context['platform'], $context['os'], $context['arch']]));
    }

    /** The architecture a message names when it is not the device's own, else null. */
    public static function foreignArch(string $message, ?string $own): ?string
    {
        if ($own === null) {
            return null;
        }
        foreach (self::ARCHES as $normal => $words) {
            if ($normal !== $own && preg_match('/(?<![a-z0-9])('.implode('|', $words).')(?![a-z0-9])/i', $message)) {
                return $normal;
            }
        }

        return null;
    }

    /** The message without what identifies the device or a person: its name, addresses, MACs, e-mails, home directories. */
    private static function clean(string $message, ?Device $device, bool $anonymize): string
    {
        $message = mb_substr($message, 0, self::MESSAGE);
        if (! $anonymize) {
            return $message;
        }
        $name = $device?->displayName;
        if ($name !== null && mb_strlen($name) > 2) {
            $message = str_ireplace($name, 'DEVICE', $message);
        }
        $patterns = [
            '/\b[\w.+-]+@[\w-]+(\.[\w-]+)+\b/' => 'EMAIL',
            '/\b(?:[0-9a-f]{2}[:-]){5}[0-9a-f]{2}\b/i' => 'MAC',
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => 'IP',
            '/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{1,4}\b/i' => 'IP',
            '#(?<=/home/)[^/\s]+#' => 'USER',
            '#(?<=\\\\Users\\\\)[^\\\\\s]+#i' => 'USER',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $message) ?? $message;
    }
}
