<?php

namespace App\Support;

use App\Models\Device;
use App\Models\NetworkNeighbour;
use App\Models\PortScanResult;
use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Models\SecurityInventory;
use App\Models\SecurityInventoryItem;
use App\Models\SecurityRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SteelAnts\LaravelBoilerplate\Models\Setting;
use SteelAnts\LaravelBoilerplate\Types\SettingDataType;
use Throwable;

/**
 * Runs the rules (App\Support\SecurityRules) on a device's security inventory: a finding opens
 * for each item a rule matches and is resolved when the next inventory does not have it
 * anymore. Events are matched once, when they come in.
 */
class SecurityScanner
{
    /** At most this many findings per rule and device (a rule that matches everything). */
    public const MAX_FINDINGS_PER_RULE = 50;

    /**
     * The collection policy for the agents: what to send of the logs. Nothing when the portal takes no logs.
     *
     * @return array{version: string, sources: array<string, array<int, string|int>|null>}
     */
    public static function policy(): array
    {
        SecurityRule::syncBuiltIn();
        $parsers = self::logsAllowed() ? SecurityRule::query()->enabled()->parsers()->get()->pluck('definition') : collect();

        return SecurityParsers::policy($parsers);
    }

    /** Settings (days; 0 is off): an acknowledged finding is open again after this long, a device that has not reported for this long has stale findings. */
    public const ACK_DAYS_SETTING = 'main.general.security_ack_days';

    public const STALE_DAYS_SETTING = 'main.general.security_stale_days';

    public const DEFAULT_STALE_DAYS = 14;

    /** The days of the settings of the portal (System > Settings): acknowledgements do not expire unless set. */
    public static function ackDays(): int
    {
        $value = Setting::query()->whereNull('settable_id')->where('index', self::ACK_DAYS_SETTING)->value('value');

        return max(0, (int) $value);
    }

    public static function staleDays(): int
    {
        $value = Setting::query()->whereNull('settable_id')->where('index', self::STALE_DAYS_SETTING)->value('value');

        return $value === null || $value === '' ? self::DEFAULT_STALE_DAYS : max(0, (int) $value);
    }

    /**
     * Housekeeping of the findings of the inventories (daily): acknowledged ones that are older than the expiry open again,
     * the findings of a rule that is off (or not for the platform of the device) are resolved, and the findings of a device
     * that has not reported for the stale days are marked stale (a report that finds them again makes them current).
     *
     * @return array{reopened: int, resolved: int, stale: int}
     */
    public static function sweep(): array
    {
        $result = ['reopened' => 0, 'resolved' => 0, 'stale' => 0];
        $inventory = SecurityFinding::query()->open()->whereNotNull('device_id');
        if (($days = self::ackDays()) > 0) {
            $result['reopened'] = (clone $inventory)->whereNotNull('acknowledged_at')->where('acknowledged_at', '<', now()->subDays($days))
                ->whereHas('rule', fn ($rule) => $rule->where('source', '!=', 'events'))
                ->update(['acknowledged_at' => null, 'acknowledged_by' => null]);
        }
        // What no scan will look at again: the rule is off, or is not for the platform of the device.
        (clone $inventory)->with(['rule', 'device'])->chunkById(500, function ($findings) use (&$result) {
            $result['resolved'] += self::resolve($findings->filter(fn (SecurityFinding $finding) => $finding->rule !== null && $finding->device !== null && $finding->rule->source !== 'events'
                && (! $finding->rule->enabled || ! $finding->rule->appliesTo($finding->device))));
        });
        if (($days = self::staleDays()) > 0) {
            $silent = SecurityInventory::query()->where('collected_at', '<', now()->subDays($days))->pluck('device_id');
            $result['stale'] = (clone $inventory)->whereIn('device_id', $silent)->whereNull('stale_at')->update(['stale_at' => now()]);
        }

        return $result;
    }

    /** The portal setting that turns the logs of all agents off (system admins, Security page). */
    public const LOGS_SETTING = 'mdm.security_logs';

    /** Whether the portal takes the logs the agents send (on unless a system admin turned it off). */
    public static function logsAllowed(): bool
    {
        return (string) Setting::query()->whereNull('settable_id')->where('index', self::LOGS_SETTING)->value('value') !== '0';
    }

    public static function setLogsAllowed(bool $allowed): void
    {
        Setting::query()->whereNull('settable_id')->updateOrCreate(['index' => self::LOGS_SETTING], ['value' => $allowed ? '1' : '0', 'type' => SettingDataType::BOOL]);
    }

    /**
     * The sources of a collection as an agent sends them, cleaned: each {source, state} (unchanged),
     * {source, base, state, upsert: [[key, hash, item], ...], remove: [key, ...]} (a delta) or
     * {source, full: true, state, upsert: [...]} (everything). Null when something is not right.
     *
     * @return array<int, array>|null
     */
    public static function normalizeSources(mixed $sources, ?string &$problem = null): ?array
    {
        $problem = null;
        if (! is_array($sources) || ! array_is_list($sources) || count($sources) > count(SecurityInventory::SOURCES)) {
            $problem = 'sources: not a list of at most '.count(SecurityInventory::SOURCES);

            return null;
        }
        $clean = [];
        $isKey = fn ($key) => is_string($key) && (preg_match('/^[0-9a-f]{16}$/', $key) || $key === SecurityInventory::POSTURE_KEY);
        foreach ($sources as $delta) {
            $source = is_array($delta) ? ($delta['source'] ?? null) : null;
            if (! in_array($source, SecurityInventory::SOURCES, true) || isset($clean[$source])
                || ! is_string($delta['state'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/', $delta['state'])
                || (isset($delta['base']) && (! is_string($delta['base']) || ! preg_match('/^[0-9a-f]{64}$/', $delta['base'])))) {
                $problem = (is_string($source) ? mb_substr($source, 0, 20) : 'a source').': unknown, twice, or without a valid state / base';

                return null;
            }
            $upsert = [];
            foreach ($delta['upsert'] ?? [] as $row) {
                // Windows PowerShell 5.1 writes an array that went through the pipeline as an
                // object (agents before 1.21.1): {"value": [key, hash, fields], "Count": 3}.
                if (is_array($row) && ! array_is_list($row) && is_array($row['value'] ?? null) && array_is_list($row['value'])) {
                    $row = $row['value'];
                }
                if (! is_array($row) || count($row) !== 3 || ! $isKey($row[0]) || ! is_string($row[1]) || ! preg_match('/^[0-9a-f]{16}$/', $row[1]) || ! is_array($row[2])) {
                    $problem = "{$source}: an item is not [key, hash, fields]";

                    return null;
                }
                $upsert[] = [$row[0], $row[1], SecurityInventory::sanitizeItem($source, $row[2])];
            }
            $remove = array_values(array_filter($delta['remove'] ?? [], $isKey));
            if (count($upsert) > SecurityInventory::LIMITS[$source] || count($remove) > SecurityInventory::LIMITS[$source] * 2) {
                $problem = "{$source}: ".count($upsert).' items, at most '.SecurityInventory::LIMITS[$source];

                return null;
            }
            $clean[$source] = [
                'source' => $source,
                'state' => $delta['state'],
                'base' => $delta['base'] ?? null,
                'full' => (bool) ($delta['full'] ?? false),
                'upsert' => $upsert,
                'remove' => $remove,
            ];
        }

        return array_values($clean);
    }

    /**
     * The sources whose delta does not follow what the server has stored (the agent sends them
     * whole next): checked when a collection comes in, before anything is stored.
     *
     * @param  array<int, array>  $sources  from normalizeSources
     * @return array<int, string>
     */
    public static function mismatches(Device $device, array $sources): array
    {
        $states = SecurityInventory::query()->where('device_id', $device->id)->value('states');
        $states = is_array($states) ? $states : (is_string($states) ? (json_decode($states, true) ?: []) : []);
        $wrong = [];
        foreach ($sources as $delta) {
            if (! self::follows($states[$delta['source']] ?? null, $delta)) {
                $wrong[] = $delta['source'];
            }
        }

        return $wrong;
    }

    /** Whether a delta applies to a stored state (everything does; an unchanged source needs the same state). */
    private static function follows(?string $stored, array $delta): bool
    {
        if ($delta['full']) {
            return true;
        }
        return self::unchanged($delta) ? $stored === $delta['state'] : $stored === $delta['base'];
    }

    /** A source the agent reports only by its state: nothing changed since the state the server has. */
    private static function unchanged(array $delta): bool
    {
        return ! $delta['full'] && $delta['base'] === null && $delta['upsert'] === [] && $delta['remove'] === [];
    }

    /**
     * Applies the deltas of a collection in one transaction: a source is only changed when its
     * stored state is the one the delta follows, and the result must have the state the agent
     * computed (otherwise nothing of the collection is stored).
     *
     * @param  array<int, array>  $sources  from normalizeSources
     * @return array<string, array{full: bool, base: ?string, state: string, old: array<int, array>, new: array<int, array>}>
     *                                                                                                                  what changed per source (the items before and after), for the incremental scan
     * @throws SecurityStateMismatch
     */
    public static function applyDeltas(Device $device, array $sources): array
    {
        $changes = [];
        DB::transaction(function () use ($device, $sources, &$changes) {
            $inventory = SecurityInventory::query()->where('device_id', $device->id)->lockForUpdate()->first()
                ?? new SecurityInventory(['device_id' => $device->id, 'states' => []]);
            $states = $inventory->states ?? [];

            foreach ($sources as $delta) {
                $source = $delta['source'];
                if (! self::follows($states[$source] ?? null, $delta)) {
                    throw new SecurityStateMismatch($source);
                }
                if (self::unchanged($delta)) {
                    // Unchanged: the stored state is the agent's state (checked above).
                    continue;
                }
                $items = SecurityInventoryItem::query()->where('device_id', $device->id)->where('source', $source);
                $old = [];
                if (! $delta['full']) {
                    $touched = array_values(array_unique([...$delta['remove'], ...array_column($delta['upsert'], 0)]));
                    foreach (array_chunk($touched, 500) as $keys) {
                        foreach ((clone $items)->whereIn('item_key', $keys)->pluck('data') as $json) {
                            $old[] = json_decode($json, true);
                        }
                    }
                }
                $changes[$source] = ['full' => $delta['full'], 'base' => $delta['base'], 'state' => $delta['state'], 'old' => $old, 'new' => array_column($delta['upsert'], 2)];
                if ($delta['full']) {
                    (clone $items)->delete();
                }
                foreach (array_chunk($delta['remove'], 500) as $keys) {
                    (clone $items)->whereIn('item_key', $keys)->delete();
                }
                $rows = array_map(fn ($row) => [
                    'device_id' => $device->id, 'source' => $source, 'item_key' => $row[0], 'item_hash' => $row[1], 'data' => json_encode($row[2], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ], $delta['upsert']);
                foreach (array_chunk($rows, 200) as $chunk) {
                    SecurityInventoryItem::query()->upsert($chunk, ['device_id', 'source', 'item_key'], ['item_hash', 'data']);
                }
                // What is stored must add up to the state the agent has.
                $hashes = (clone $items)->pluck('item_hash', 'item_key')->all();
                if (count($hashes) > SecurityInventory::LIMITS[$source] || SecurityInventory::stateOf($hashes) !== $delta['state']) {
                    throw new SecurityStateMismatch($source, 'does not add up to the state of the agent');
                }
                $states[$source] = $delta['state'];
            }

            $inventory->states = $states;
            $inventory->collected_at = now();
            $inventory->save();
        });

        return $changes;
    }

    /**
     * A collection (SecurityInbox: the inventory deltas, the logs already parsed into events):
     * applied, the events recorded, then scanned.
     *
     * @param  array<int, array>  $sources  from normalizeSources
     * @param  array<int, array>  $events  from SecurityInventory::sanitizeEvents
     * @return array{opened: int, resolved: int}
     * @throws SecurityStateMismatch
     */
    public static function ingest(Device $device, array $sources, array $events = []): array
    {
        $changes = self::applyDeltas($device, $sources);
        // The events stored before these: what "not seen in N days" (unseen_days) compares with.
        $before = (int) SecurityEvent::query()->max('id');
        SecurityEvent::record($device, $events);

        return self::scan($device, $events, $changes, $before);
    }

    /** A rule is checked in full again at least this often (a safety net for the incremental scan). */
    private const FULL_SCAN_AFTER = 86400;

    /**
     * The rules on the device's last inventory (and on the events that just came in). Work that
     * would find the same as last time is not done: a rule whose definition and source have not
     * changed since it ran is skipped, and when only some items changed (the deltas of a
     * collection) a rule looks at those items alone.
     *
     * @param  array<int, array>  $events
     * @param  array<string, array>|null  $changes  from applyDeltas, null: look at everything
     * @return array{opened: int, resolved: int}
     */
    public static function scan(Device $device, array $events = [], ?array $changes = null, ?int $eventsBefore = null): array
    {
        SecurityRule::syncBuiltIn();
        $inventory = SecurityInventory::query()->where('device_id', $device->id)->first();
        $counts = ['opened' => 0, 'resolved' => 0];
        if ($inventory === null) {
            return $counts;
        }

        // The device reports again: what was stale is current (or resolved below).
        SecurityFinding::query()->where('device_id', $device->id)->whereNotNull('stale_at')->update(['stale_at' => null]);
        $states = $inventory->states ?? [];
        $scanned = $inventory->scanned ?? [];
        $previous = ($scanned['full_at'] ?? 0) > time() - self::FULL_SCAN_AFTER ? ($scanned['rules'] ?? []) : [];
        $next = [];
        $open = null;
        $platform = $device->platform;
        foreach (SecurityRule::scanPlan() as $plan) {
            $active = $plan['enabled'] && ($plan['platform'] === 'any' || $plan['platform'] === $platform) && ! self::allowed($plan['rule'], $device);
            if ($plan['event']) {
                if ($active && $events !== []) {
                    $rule = $plan['rule'];
                    $open ??= self::openFindings($device);
                    self::add($counts, self::guard($rule, $device, fn () => self::scanEvents($device, $rule, $open->get($rule->id, collect()), $events, $eventsBefore)));
                }

                continue;
            }
            $mark = [$plan['token'].($active ? '' : '!'), $states[$plan['source']] ?? ''];
            $before = $previous[$plan['id']] ?? null;
            if ($before !== null && $before[0] === $mark[0] && ($before[1] === $mark[1] || ! $active)) {
                $next[$plan['id']] = $mark;

                continue;
            }
            $rule = $plan['rule'];
            $open ??= self::openFindings($device);
            $findings = $open->get($rule->id, collect());
            $result = self::guard($rule, $device, function () use ($device, $rule, $active, $findings, $inventory, $before, $mark, $changes) {
                if (! $active) {
                    // Switched off: what it found is gone with it.
                    return ['opened' => 0, 'resolved' => self::resolve($findings)];
                }
                $change = $changes[$rule->source] ?? null;
                $incremental = $before !== null && $before[0] === $mark[0] && $change !== null && ! $change['full']
                    && $change['base'] === $before[1] && $change['state'] === $mark[1]
                    && ! isset($rule->definition['threshold']) && ! isset($rule->definition['cves']) && ! isset($rule->definition['group']) && $rule->source !== 'posture';

                return $incremental
                    ? self::scanChanged($device, $rule, $findings, $change)
                    : self::scanInventory($device, $rule, $findings, $inventory->items($rule->source));
            });
            if ($result !== null) {
                $next[$rule->id] = $mark;
                self::add($counts, $result);
            }
        }

        $full = $scanned['full_at'] ?? 0;
        $fresh = ['rules' => $next, 'full_at' => $previous === [] ? time() : $full];
        if ($fresh !== $scanned) {
            $inventory->forceFill(['scanned' => $fresh])->saveQuietly();
        }
        if ($counts['opened'] + $counts['resolved'] > 0) {
            LiveUpdates::device($device->id, 'security');
        }

        return $counts;
    }

    /** Whether the device is allowed to have what the rule looks for (its role: a tag of "allow_tags", e.g. developer). */
    private static function allowed(SecurityRule $rule, Device $device): bool
    {
        foreach ($rule->definition['allow_tags'] ?? [] as $tag) {
            if ($device->hasTag((string) $tag)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, Collection<int, SecurityFinding>> the open findings of a device by rule */
    private static function openFindings(Device $device): Collection
    {
        return SecurityFinding::query()->open()->where('device_id', $device->id)->get()->groupBy('security_rule_id');
    }

    /** What a rule does, null when it fails (the others go on, it is tried again next time). */
    private static function guard(SecurityRule $rule, Device $device, callable $work): ?array
    {
        try {
            return $work();
        } catch (Throwable $e) {
            Log::warning("Security rule {$rule->key} failed on device {$device->id}: {$e->getMessage()}");

            return null;
        }
    }

    private static function add(array &$counts, ?array $result): void
    {
        $counts['opened'] += $result['opened'] ?? 0;
        $counts['resolved'] += $result['resolved'] ?? 0;
    }

    /**
     * The rules on every device with an inventory, after rules changed.
     *
     * @return array{opened: int, resolved: int}
     */
    public static function scanAll(): array
    {
        $counts = ['opened' => 0, 'resolved' => 0];
        $ids = SecurityInventory::query()->pluck('device_id');
        foreach (Device::query()->whereIn('id', $ids)->get() as $device) {
            $result = self::scan($device);
            $counts['opened'] += $result['opened'];
            $counts['resolved'] += $result['resolved'];
        }
        // Port-scan findings re-evaluate too, so a changed/added portscan rule takes effect.
        $ports = self::scanAllPorts();
        $counts['opened'] += $ports['opened'];
        $counts['resolved'] += $ports['resolved'];

        return $counts;
    }

    /** Re-runs the portscan rules over every stored port scan (after rules change). */
    public static function scanAllPorts(): array
    {
        $counts = ['opened' => 0, 'resolved' => 0];
        foreach (PortScanResult::query()->cursor() as $result) {
            $one = rescue(fn () => self::scanPorts($result), ['opened' => 0, 'resolved' => 0]);
            $counts['opened'] += $one['opened'];
            $counts['resolved'] += $one['resolved'];
        }

        return $counts;
    }

    /**
     * Runs the `portscan` detection rules over one scanned host's open ports: opens a finding per
     * matching port and resolves this host's findings whose port/condition is gone. The finding is
     * linked to a managed device when the address is one, otherwise it keeps the network target
     * (target_ip/mac) and no device — so unknown neighbours get findings too.
     */
    public static function scanPorts(PortScanResult $result): array
    {
        SecurityRule::syncBuiltIn();
        $rules = SecurityRule::query()->where('source', 'portscan')->where('enabled', true)->get();
        if ($rules->isEmpty()) {
            return ['opened' => 0, 'resolved' => 0];
        }

        $items = $result->toSecurityItems();
        $device = self::deviceForAddress($result->ip);
        $target = [
            'device_id' => $device?->id,
            'target_ip' => $result->ip,
            'target_mac' => NetworkNeighbour::query()->where('ip', $result->ip)->orderByDesc('last_seen_at')->value('mac'),
            'network' => $result->network,
            'site' => $result->site,
        ];

        $open = SecurityFinding::query()->open()->whereIn('security_rule_id', $rules->modelKeys())
            ->where('site', $result->site)->where('target_ip', $result->ip)->get()
            ->groupBy('security_rule_id');

        $opened = 0;
        $resolvedIds = [];
        DB::transaction(function () use ($rules, $items, $target, $open, &$opened, &$resolvedIds) {
            foreach ($rules as $rule) {
                $found = self::matches($rule->definition, $items);
                $ruleOpen = ($open->get($rule->id) ?? collect())->keyBy('fingerprint');
                $rows = [];
                foreach ($found as $fingerprint => $match) {
                    $finding = $ruleOpen->get($fingerprint);
                    if ($finding === null) {
                        $rows[] = $target + [
                            'security_rule_id' => $rule->id,
                            'fingerprint' => $fingerprint,
                            'severity' => $rule->severity,
                            'message' => self::message($match),
                            'details' => json_encode($match['item'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'first_seen_at' => now(),
                            'last_seen_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        continue;
                    }
                    $fresh = ['message' => self::message($match), 'details' => $match['item'], 'severity' => $rule->severity, 'device_id' => $target['device_id'], 'target_mac' => $target['target_mac']];
                    if ($finding->message !== $fresh['message'] || $finding->details !== $fresh['details'] || $finding->severity !== $fresh['severity'] || $finding->device_id !== $fresh['device_id'] || $finding->last_seen_at->lt(now()->subHour())) {
                        $finding->update($fresh + ['last_seen_at' => now()]);
                    }
                }
                foreach (array_chunk($rows, 100) as $chunk) {
                    SecurityFinding::query()->insert($chunk);
                }
                $opened += count($rows);
                foreach ($ruleOpen as $fingerprint => $finding) {
                    if (! isset($found[$fingerprint])) {
                        $resolvedIds[] = $finding->id;
                    }
                }
            }
            if ($resolvedIds !== []) {
                SecurityFinding::query()->whereKey($resolvedIds)->update(['resolved_at' => now()]);
            }
        });

        return ['opened' => $opened, 'resolved' => count($resolvedIds)];
    }

    /** The managed device at an address (a ping-only device, or an agent whose own IP it is), or null. */
    private static function deviceForAddress(string $ip): ?Device
    {
        $ping = Device::query()->where('kind', 'ping')->where('ping_address', $ip)->first();
        if ($ping !== null) {
            return $ping;
        }
        foreach (Device::all()->reject(fn (Device $device) => $device->isPingOnly) as $agent) {
            foreach ($agent->networks as $interface) {
                foreach ($interface['Addresses'] ?? [] as $address) {
                    if (($address['Address'] ?? null) === $ip) {
                        return $agent;
                    }
                }
            }
        }

        return null;
    }

    /**
     * What a rule (a definition, not saved yet) finds on a device's last inventory, for the editor.
     *
     * @return array<int, array{message: string, item: array}>|null null without an inventory
     */
    public static function preview(array $definition, Device $device): ?array
    {
        $inventory = SecurityInventory::query()->where('device_id', $device->id)->first();
        if ($inventory === null) {
            return null;
        }
        $items = $definition['source'] === 'events'
            ? SecurityEvent::query()->where('device_id', $device->id)->where('occurred_at', '>=', now()->subDay())->get()
                ->map(fn (SecurityEvent $event) => ['Type' => $event->type, 'Count' => $event->count, 'User' => $event->user, 'Source' => $event->source, 'Message' => $event->message, 'Last' => (string) $event->occurred_at])->all()
            : $inventory->items($definition['source']);

        return array_map(fn ($match) => ['message' => $match['message'], 'item' => $match['item']], self::matches($definition, $items, self::context($device)));
    }

    /** What a rule needs to know of the device besides the list it looks at: the release of its distribution. */
    private static function context(Device $device): array
    {
        $os = SecurityInventory::query()->where('device_id', $device->id)->first()?->items('os')[0] ?? null;

        return ['release' => SecurityCves::release($os)];
    }

    /**
     * The findings of a rule in a list: one per matching item, or one for the whole list with
     * "threshold", or one per group of items ("group", and the packages of one source package
     * with "cves"). Each: fingerprint, message, item (the details), and for a rule with "cves" the
     * severity of its CVEs and "legacy", the fingerprints the packages of the group had when each was a finding.
     *
     * @param  array{release?: ?string}  $context
     * @return array<string, array{fingerprint: string, message: string, item: array, severity?: string, legacy?: array<int, string>}>
     */
    public static function matches(array $definition, array $items, array $context = []): array
    {
        if (($definition['source'] ?? null) === 'posture') {
            // A failed apt-get update: the repositories that are the problem and what to do about them (AptUpdate).
            $items = array_map(fn ($item) => is_array($item) && ($item['AptUpdateOk'] ?? null) === false ? $item + AptUpdate::fields($item['AptUpdateErrors'] ?? null) : $item, $items);
        }
        $matching = SecurityRules::matching($definition, $items);
        // A rule with "cves" says what it found by itself (the "message" of the feed counts the CVEs for servers that do not judge them one by one).
        $template = isset($definition['cves']) ? '{Packages}: {CveCount} CVEs ({Status}): {Cves}' : ($definition['message'] ?? $definition['name']);
        $found = [];

        if (isset($definition['threshold'])) {
            if (count($matching) < $definition['threshold']) {
                return [];
            }
            $names = array_map(fn ($item) => self::itemName($definition['source'], $item), array_slice($matching, 0, 10));
            $summary = ['count' => count($matching), 'items' => implode(', ', array_filter($names)).(count($matching) > 10 ? ', …' : '')];
            $fingerprint = SecurityRules::fingerprint($definition, null);

            return [$fingerprint => [
                'fingerprint' => $fingerprint,
                'message' => SecurityRules::render($template, $summary + ($matching[0] ?? [])),
                'item' => $summary,
            ]];
        }

        if (isset($definition['cves']) || isset($definition['group'])) {
            return self::matchGroups($definition, $matching, $template, $context);
        }

        foreach ($matching as $item) {
            $fingerprint = SecurityRules::fingerprint($definition, $item);
            if (isset($found[$fingerprint])) {
                continue;
            }
            $found[$fingerprint] = ['fingerprint' => $fingerprint, 'message' => SecurityRules::render($template, $item), 'item' => $item];
            if (count($found) >= self::MAX_FINDINGS_PER_RULE) {
                break;
            }
        }

        return $found;
    }

    /**
     * Findings of groups of packages: the binary packages of one source package (libsoup-3.0-0 and libsoup-3.0-common
     * are libsoup3) with the CVEs that affect them (a rule with "cves": none left, no finding), or the packages of one
     * tool (nmap, nmap-common, zenmap) by the first group of the pattern of "group". The fingerprint of a group of one
     * package is the one it always had, so what was acknowledged stays acknowledged.
     *
     * @param  array<int, array>  $matching
     * @return array<string, array>
     */
    private static function matchGroups(array $definition, array $matching, string $template, array $context): array
    {
        $groups = [];
        foreach ($matching as $item) {
            $affecting = [];
            if (isset($definition['cves'])) {
                $affecting = SecurityCves::affecting($definition['cves'], $item, $context['release'] ?? null);
                if ($affecting === []) {
                    continue;
                }
            }
            $group = isset($definition['group']) ? self::groupName($definition['group'], $item) : null;
            $name = $group ?? (strtolower((string) ($item['Source'] ?? '')) === 'dpkg' && ($item['SourcePackage'] ?? '') !== '' ? $item['SourcePackage'] : ($item['Name'] ?? ''));
            $fingerprint = hash('sha256', implode("\x1f", [$definition['key'], mb_strtolower((string) $name), mb_strtolower((string) ($group !== null ? '' : ($item['Source'] ?? '')))]));
            $groups[$fingerprint]['items'][] = $item;
            $groups[$fingerprint]['legacy'][] = SecurityRules::fingerprint($definition, $item);
            $groups[$fingerprint]['group'] = $group;
            foreach ($affecting as $id => $cve) {
                $known = $groups[$fingerprint]['cves'][$id] ?? null;
                if ($known === null || array_search($cve['status'], array_keys(SecurityCves::STATUSES)) < array_search($known['status'], array_keys(SecurityCves::STATUSES))) {
                    $groups[$fingerprint]['cves'][$id] = $cve;
                }
            }
        }

        $found = [];
        foreach ($groups as $fingerprint => $group) {
            $items = $group['items'];
            $item = $items[0];
            $item['Packages'] = implode(', ', array_unique(array_map(fn ($one) => trim(($one['Name'] ?? '').' '.($one['Version'] ?? '')), $items)));
            $item['PackageNames'] = implode(', ', array_unique(array_map(fn ($one) => (string) ($one['Name'] ?? ''), $items)));
            if ($group['group'] !== null) {
                $item['Group'] = $group['group'];
            }
            $match = ['fingerprint' => $fingerprint, 'legacy' => array_values(array_diff(array_unique($group['legacy']), [$fingerprint]))];
            if (isset($group['cves'])) {
                $cves = $group['cves'];
                ksort($cves);
                $item['CveCount'] = count($cves);
                $item['Cves'] = self::fit(array_keys($cves), 600);
                $item['Status'] = SecurityCves::STATUSES[SecurityCves::status($cves)] ?? '';
                $item['Remediation'] = SecurityCves::remediation($items, $cves);
                $item['Severity'] = $match['severity'] = SecurityCves::severity($cves, $definition['severity'] ?? 'medium');
            }
            $found[$fingerprint] = $match + ['message' => SecurityRules::render($template, $item), 'item' => $item];
            if (count($found) >= self::MAX_FINDINGS_PER_RULE) {
                break;
            }
        }

        return $found;
    }

    /** The group of an item by the first group of the pattern on its field (lower case), null when it does not match. */
    private static function groupName(array $group, array $item): ?string
    {
        $regex = SecurityRules::regex($group['pattern']);
        if ($regex !== null && preg_match($regex, (string) SecurityRules::field($item, $group['field']), $m)) {
            $name = mb_strtolower($m[1] ?? $m[0]);

            // "names" puts tools of one family together (zenmap and ncat are nmap).
            return $group['names'][$name] ?? $name;
        }

        return mb_strtolower((string) ($item['Name'] ?? ''));
    }

    /** @param  array<int, string>  $ids  as many ids as fit in $limit characters, then "and n more" */
    private static function fit(array $ids, int $limit): string
    {
        $shown = [];
        $length = 0;
        foreach ($ids as $id) {
            if ($length + strlen($id) + 2 > $limit) {
                break;
            }
            $shown[] = $id;
            $length += strlen($id) + 2;
        }

        return implode(', ', $shown).(count($shown) < count($ids) ? ' and '.(count($ids) - count($shown)).' more' : '');
    }

    /** @param Collection<int, SecurityFinding> $open */
    private static function scanInventory(Device $device, SecurityRule $rule, Collection $open, array $items): array
    {
        $found = self::matches($rule->definition, $items, self::context($device));
        $byFingerprint = $open->keyBy('fingerprint');

        $opened = self::write($device, $rule, $found, $byFingerprint);

        return ['opened' => $opened, 'resolved' => self::resolve($open->reject(fn ($finding) => isset($found[$finding->fingerprint])))];
    }

    /**
     * A rule on the items that changed (not on the whole list): what the items that went away or
     * changed had found is resolved unless they still match, the new ones are matched.
     *
     * @param  Collection<int, SecurityFinding>  $open
     * @param  array{old: array<int, array>, new: array<int, array>}  $change
     */
    private static function scanChanged(Device $device, SecurityRule $rule, Collection $open, array $change): array
    {
        $definition = $rule->definition;
        $found = self::matches($definition, $change['new']);
        if ($found === [] && $open->isEmpty()) {
            return ['opened' => 0, 'resolved' => 0];
        }
        $touched = [];
        foreach ([...$change['old'], ...$change['new']] as $item) {
            $touched[SecurityRules::fingerprint($definition, $item)] = true;
        }
        $byFingerprint = $open->keyBy('fingerprint');
        $room = max(0, self::MAX_FINDINGS_PER_RULE - $byFingerprint->count());
        $opened = self::write($device, $rule, $found, $byFingerprint, $room);

        return ['opened' => $opened, 'resolved' => self::resolve($open->filter(fn ($finding) => isset($touched[$finding->fingerprint]) && ! isset($found[$finding->fingerprint])))];
    }

    /**
     * Updates the open findings that a rule found again (only when something about them changed,
     * their last_seen_at at most hourly) and opens the new ones in one insert.
     *
     * @param  array<string, array>  $found
     * @param  Collection<string, SecurityFinding>  $byFingerprint
     * @return int how many were opened
     */
    private static function write(Device $device, SecurityRule $rule, array $found, Collection $byFingerprint, ?int $room = null): int
    {
        $rows = [];
        DB::transaction(function () use ($device, $rule, $found, $byFingerprint, $room, &$rows) {
            foreach ($found as $fingerprint => $match) {
                $finding = $byFingerprint->get($fingerprint) ?? self::adopt($byFingerprint, $fingerprint, $match['legacy'] ?? []);
                $severity = $match['severity'] ?? $rule->severity;
                if ($finding === null) {
                    if ($room === null || count($rows) < $room) {
                        $rows[] = [
                            'device_id' => $device->id,
                            'security_rule_id' => $rule->id,
                            'fingerprint' => $fingerprint,
                            'severity' => $severity,
                            'message' => self::message($match),
                            'details' => json_encode($match['item'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'first_seen_at' => now(),
                            'last_seen_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    continue;
                }
                $fresh = ['message' => self::message($match), 'details' => $match['item'], 'severity' => $severity];
                // Acknowledged, and now worse (a more severe CVE, the program runs as a service): it needs attention again.
                if ($finding->acknowledged_at !== null && (SecurityRules::SEVERITIES[$severity] ?? 9) < (SecurityRules::SEVERITIES[$finding->severity] ?? 9)) {
                    $fresh += ['acknowledged_at' => null, 'acknowledged_by' => null];
                }
                if ($finding->message !== $fresh['message'] || $finding->details !== $fresh['details'] || $finding->severity !== $fresh['severity'] || $finding->last_seen_at->lt(now()->subHour())) {
                    $finding->update($fresh + ['last_seen_at' => now()]);
                }
            }
            foreach (array_chunk($rows, 100) as $chunk) {
                SecurityFinding::query()->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * A group of packages that were findings one by one (before the findings were grouped by source package) takes over
     * the one of them that was acknowledged (else the oldest), so acknowledging and the history stay; the others are
     * not in the new list of findings and are resolved with the rest of what the rule no longer finds.
     *
     * @param  Collection<string, SecurityFinding>  $byFingerprint
     * @param  array<int, string>  $legacy
     */
    private static function adopt(Collection $byFingerprint, string $fingerprint, array $legacy): ?SecurityFinding
    {
        $old = collect($legacy)->map(fn ($fp) => $byFingerprint->get($fp))->filter()
            ->sortBy([fn ($a, $b) => ($b->acknowledged_at !== null) <=> ($a->acknowledged_at !== null), fn ($a, $b) => $a->id <=> $b->id])->first();
        if ($old === null) {
            return null;
        }
        $old->update(['fingerprint' => $fingerprint]);
        $byFingerprint->forget($legacy);
        $byFingerprint->put($fingerprint, $old);

        return $old;
    }

    /**
     * Events add to an open finding of the same kind (user, source) or open a new one; nothing
     * resolves them but acknowledging.
     *
     * @param  Collection<int, SecurityFinding>  $open
     */
    private static function scanEvents(Device $device, SecurityRule $rule, Collection $open, array $events, ?int $eventsBefore = null): array
    {
        if (isset($rule->definition['unseen_days'])) {
            $events = self::unseen($rule->definition, $events, $eventsBefore);
        }
        if ($events === []) {
            return ['opened' => 0, 'resolved' => 0];
        }
        $byFingerprint = $open->whereNull('acknowledged_at')->keyBy('fingerprint');
        $opened = 0;
        foreach (self::matches($rule->definition, $events) as $fingerprint => $match) {
            $finding = $byFingerprint->get($fingerprint);
            if ($finding !== null) {
                $finding->update([
                    'message' => self::message($match),
                    'details' => $match['item'],
                    'severity' => $rule->severity,
                    'occurrences' => $finding->occurrences + 1,
                    'last_seen_at' => now(),
                ]);

                continue;
            }
            self::open($device, $rule, $match);
            $opened++;
        }

        return ['opened' => $opened, 'resolved' => 0];
    }

    /**
     * The events whose type with the same unseen_by fields (User and Source by default: who from
     * where) no device had in the unseen_days before this collection ($before: the last event
     * stored before it), e.g. a sign-in of a user from an address not seen for 10 days.
     */
    private static function unseen(array $definition, array $events, ?int $before): array
    {
        $columns = ['User' => 'user', 'Source' => 'source'];
        $fields = $definition['unseen_by'] ?? array_keys($columns);

        return array_values(array_filter($events, function (array $event) use ($definition, $fields, $columns, $before) {
            $query = SecurityEvent::query()->where('type', (string) ($event['Type'] ?? ''))
                ->where('occurred_at', '>=', now()->subDays($definition['unseen_days']))
                ->when($before !== null, fn ($query) => $query->where('id', '<=', $before));
            foreach ($fields as $field) {
                $value = $event[$field] ?? null;
                $value === null ? $query->whereNull($columns[$field]) : $query->where($columns[$field], $value);
            }

            return ! $query->exists();
        }));
    }

    private static function open(Device $device, SecurityRule $rule, array $match): SecurityFinding
    {
        return SecurityFinding::query()->create([
            'device_id' => $device->id,
            'security_rule_id' => $rule->id,
            'fingerprint' => $match['fingerprint'],
            'severity' => $rule->severity,
            'message' => self::message($match),
            'details' => $match['item'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /** @param Collection<int, SecurityFinding> $findings */
    private static function resolve(Collection $findings): int
    {
        if ($findings->isEmpty()) {
            return 0;
        }
        SecurityFinding::query()->whereKey($findings->modelKeys())->update(['resolved_at' => now()]);

        return $findings->count();
    }

    private static function message(array $match): string
    {
        return mb_strimwidth($match['message'], 0, 1000, '…');
    }

    /** How an item of a list is named in a summary ("Administrator", "AnyDesk", "tcp 0.0.0.0:23"). */
    private static function itemName(string $source, array $item): string
    {
        return match ($source) {
            'listening' => trim(($item['Protocol'] ?? '').' '.($item['Address'] ?? '').':'.($item['Port'] ?? '')),
            'events' => (string) ($item['Type'] ?? ''),
            default => (string) ($item['Name'] ?? ''),
        };
    }
}
