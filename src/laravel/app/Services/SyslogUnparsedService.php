<?php

namespace App\Services;

use App\Models\Device;
use App\Models\SecurityEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The syslog messages no parser reads: they are not stored, they are kept in the cache for a day (the same message of the same program of
 * the same host is one entry with a count), so it is visible what arrives and a parser can be written for it. They are shown in the same
 * table as the events (as events of the type "syslog" that are not in the database); an entry that has not been seen for a day is gone.
 */
class SyslogUnparsedService
{
    /** How long a message is kept after it was seen last. */
    public const TTL_HOURS = 24;

    /** At most this many different messages per device (the oldest go first). */
    private const MAX_GROUPS = 500;

    /** The devices that have messages: device id => the last write. */
    private const INDEX = 'syslog.unparsed.devices';

    private static function key(int $deviceId): string
    {
        return 'syslog.unparsed.'.$deviceId;
    }

    private static function hash(?string $app, ?string $host, string $message): string
    {
        return substr(sha1(mb_strtolower((string) $app)."\0".$host."\0".$message), 0, 16);
    }

    /**
     * What is kept of a device, the old ones left out.
     *
     * @return array<string, array{app: ?string, host: ?string, message: string, count: int, last: int}>
     */
    private static function entries(int $deviceId): array
    {
        $oldest = now()->subHours(self::TTL_HOURS)->getTimestamp();

        return array_filter((array) Cache::get(self::key($deviceId), []), fn (array $entry) => $entry['last'] >= $oldest);
    }

    /**
     * Keeps the messages of a batch ([{User: the program, Source: the host, Message, Count, Last}], see Syslog::unparsed).
     *
     * @param  array<int, array>  $groups
     */
    public static function record(int $deviceId, array $groups): void
    {
        $now = now()->getTimestamp();
        $entries = self::entries($deviceId);
        foreach ($groups as $group) {
            $id = self::hash($group['User'] ?? null, $group['Source'] ?? null, (string) $group['Message']);
            // When it arrived (not the time in the message: a device with a wrong clock or time zone would send messages that are gone at once).
            $seen = $now;
            $entry = $entries[$id] ?? ['app' => $group['User'] ?? null, 'host' => $group['Source'] ?? null, 'message' => (string) $group['Message'], 'count' => 0, 'last' => $seen];
            $entry['count'] += (int) ($group['Count'] ?? 1);
            $entry['last'] = max($entry['last'], $seen);
            $entries[$id] = $entry;
        }
        uasort($entries, fn (array $a, array $b) => $b['last'] <=> $a['last']);
        Cache::put(self::key($deviceId), array_slice($entries, 0, self::MAX_GROUPS, true), now()->addHours(self::TTL_HOURS));
        $index = array_filter((array) Cache::get(self::INDEX, []), fn (int $at) => $at >= now()->subHours(self::TTL_HOURS)->getTimestamp());
        $index[$deviceId] = $now;
        Cache::put(self::INDEX, $index, now()->addHours(self::TTL_HOURS));
    }

    /**
     * The messages as events of the type "syslog" that are not in the database (newest first), for the tables of the events.
     * cache_key says which one it is (device id and hash).
     *
     * @return Collection<int, SecurityEvent>
     */
    public static function events(?int $deviceId = null, int $days = 0, string $search = ''): Collection
    {
        $ids = $deviceId !== null ? [$deviceId] : array_map('intval', array_keys((array) Cache::get(self::INDEX, [])));
        $devices = Device::query()->whereKey($ids)->get()->keyBy('id');
        $since = $days > 0 ? now()->subDays($days)->getTimestamp() : 0;
        $search = mb_strtolower(trim($search));
        $events = collect();
        foreach ($ids as $id) {
            $device = $devices->get($id);
            if ($device === null) {
                continue;
            }
            foreach (self::entries($id) as $hash => $entry) {
                if ($entry['last'] < $since || ($search !== '' && ! str_contains(mb_strtolower($entry['app'].' '.$entry['host'].' '.$entry['message']), $search))) {
                    continue;
                }
                $events->push(self::event($device, (string) $hash, $entry));
            }
        }

        return $events->sortByDesc(fn (SecurityEvent $event) => $event->occurred_at->getTimestamp())->values();
    }

    /** One message by its cache_key (device id, colon, hash), null when it is gone. */
    public static function find(string $key): ?SecurityEvent
    {
        [$deviceId, $hash] = array_pad(explode(':', $key, 2), 2, '');
        $device = ctype_digit($deviceId) ? Device::find((int) $deviceId) : null;
        $entry = $device === null ? null : (self::entries($device->id)[$hash] ?? null);

        return $entry === null ? null : self::event($device, $hash, $entry);
    }

    private static function event(Device $device, string $hash, array $entry): SecurityEvent
    {
        $event = new SecurityEvent;
        $event->forceFill([
            'eventable_type' => $device->getMorphClass(), 'eventable_id' => $device->id, 'type' => 'syslog', 'source_type' => 'syslog',
            'count' => $entry['count'], 'message' => $entry['message'], 'occurred_at' => Carbon::createFromTimestamp($entry['last']),
        ]);
        $event->user = $entry['app'];
        $event->source = $entry['host'];
        $event->setAttribute('cache_key', $device->id.':'.$hash);
        $event->setRelation('eventable', $device);

        return $event;
    }
}
