<?php

namespace App\Support;

use App\Models\Device;
use App\Models\ValueDefinition;
use App\Models\ValueSample;
use Illuminate\Support\Carbon;

/**
 * CPU and memory of a device as the agent reports them with every heartbeat, kept as measured values (cpu, memory.used,
 * memory.total of App\Models\ValueDefinition): taking what the agent sends, the history it sends after an outage, and
 * what the pages and the alerts read.
 */
class DeviceUsage
{
    public const KEYS = ['cpu' => '%', 'memory.used' => 'bytes', 'memory.total' => 'bytes'];

    /** Samples one backfill request may carry (agents send at most this many at once). */
    public const BACKFILL_MAX = 600;

    /** Samples this far apart (seconds) are one: a batch sent again is skipped. */
    private const NEAR = 10;

    /** Days the history is kept (the setting of the portal, see ValueRetention). */
    public static function retentionDays(): int
    {
        return ValueRetention::days();
    }

    /** Validates metrics sent by the agent, returns null when they are missing or invalid. */
    public static function sanitize(mixed $data): ?array
    {
        if (! is_array($data) || ! isset($data['cpu'], $data['memory_used'], $data['memory_total'])) {
            return null;
        }
        if (! is_numeric($data['cpu']) || ! is_numeric($data['memory_used']) || ! is_numeric($data['memory_total']) || $data['memory_total'] <= 0) {
            return null;
        }

        return [
            'cpu' => max(0, min(100, round((float) $data['cpu'], 2))),
            'memory_total' => (int) $data['memory_total'],
            'memory_used' => max(0, min((int) $data['memory_total'], (int) $data['memory_used'])),
        ];
    }

    /** The values of sanitized metrics, as ValueRecorder takes them. */
    public static function values(array $metrics): array
    {
        return [
            ['key' => 'cpu', 'value' => $metrics['cpu'], 'unit' => '%'],
            ['key' => 'memory.used', 'value' => $metrics['memory_used'], 'unit' => 'bytes'],
            ['key' => 'memory.total', 'value' => $metrics['memory_total'], 'unit' => 'bytes'],
        ];
    }

    /** The metrics of a heartbeat. */
    public static function record(int $deviceId, array $metrics): void
    {
        ValueRecorder::record((new Device)->getMorphClass(), $deviceId, self::values($metrics));
    }

    /** The definitions of the device's values by key (those that exist). @return array<string, ValueDefinition> */
    public static function definitions(int $deviceId, ?array $keys = null): array
    {
        $keys ??= array_keys(self::KEYS);
        return ValueDefinition::query()->where('valuable_type', (new Device)->getMorphClass())->where('valuable_id', $deviceId)
            ->where('name', '')->whereIn('key', $keys)->get()->keyBy('key')->all();
    }

    /**
     * The newest metrics of the device (cpu, memory_used, memory_total, memory_percent, created_at), null without any.
     */
    public static function latest(int $deviceId): ?object
    {
        $definitions = self::definitions($deviceId);
        $at = $definitions['cpu']->last_at ?? null;
        if ($at === null || ! isset($definitions['memory.used'], $definitions['memory.total'])) {
            return null;
        }
        $total = (int) $definitions['memory.total']->last_value;
        $used = (int) $definitions['memory.used']->last_value;

        return (object) [
            'cpu' => (float) $definitions['cpu']->last_value,
            'memory_used' => $used,
            'memory_total' => $total,
            'memory_percent' => $total > 0 ? round($used / $total * 100, 1) : 0,
            'created_at' => $at,
        ];
    }

    /**
     * What the device measured since a time: samples (heartbeats), the average cpu, memory used and memory total.
     *
     * @return array{count: int, cpu: float, memory_used: float, memory_total: float}
     */
    public static function window(int $deviceId, \DateTimeInterface $since): array
    {
        $definitions = self::definitions($deviceId);
        $average = [];
        $count = 0;
        foreach (array_keys(self::KEYS) as $key) {
            $row = isset($definitions[$key])
                ? ValueSample::query()->where('definition_id', $definitions[$key]->id)->where('recorded_at', '>=', $since)->toBase()->selectRaw('count(*) as samples, avg(value) as average')->first()
                : null;
            $average[$key] = (float) ($row->average ?? 0);
            $count = $key === 'cpu' ? (int) ($row->samples ?? 0) : $count;
        }

        return ['count' => $count, 'cpu' => $average['cpu'], 'memory_used' => $average['memory.used'], 'memory_total' => $average['memory.total']];
    }

    /**
     * Samples an agent collected while it could not reach the server (agents 1.11.0+), with the time each was taken:
     * [{at: unix time, cpu, memory_used, memory_total}]. Only from the last retention days and not from the future; a
     * sample next to one already stored (a batch sent again) is skipped. Returns how many were stored.
     */
    public static function backfill(int $deviceId, mixed $samples): int
    {
        if (! is_array($samples)) {
            return 0;
        }
        $oldest = now()->subDays(self::retentionDays())->getTimestamp();
        $newest = now()->addMinute()->getTimestamp();
        $accepted = [];
        foreach (array_slice($samples, 0, self::BACKFILL_MAX) as $sample) {
            $at = is_array($sample) && is_numeric($sample['at'] ?? null) ? (int) $sample['at'] : null;
            $metrics = self::sanitize($sample);
            if ($at === null || $metrics === null || $at < $oldest || $at > $newest) {
                continue;
            }
            $accepted[$at] = $metrics;
        }
        if ($accepted === []) {
            return 0;
        }
        ksort($accepted);
        $stored = self::storedAt($deviceId, 'cpu', array_key_first($accepted) - self::NEAR, array_key_last($accepted) + self::NEAR);
        $moments = [];
        foreach ($accepted as $at => $metrics) {
            if (! self::near($stored, $at)) {
                $moments[] = [Carbon::createFromTimestamp($at, config('app.timezone')), self::values($metrics)];
                $stored[] = $at;
            }
        }

        return intdiv(ValueRecorder::recordMany((new Device)->getMorphClass(), $deviceId, $moments), count(self::KEYS));
    }

    /** The times (unix) of the samples of a value in a range. @return array<int, int> */
    public static function storedAt(int $deviceId, string $key, int $from, int $to, string $name = ''): array
    {
        $definition = ValueDefinition::query()->where('valuable_type', (new Device)->getMorphClass())->where('valuable_id', $deviceId)->where('key', $key)->where('name', $name)->first();
        if ($definition === null) {
            return [];
        }

        return ValueSample::query()->where('definition_id', $definition->id)
            ->whereBetween('recorded_at', [Carbon::createFromTimestamp($from, config('app.timezone')), Carbon::createFromTimestamp($to, config('app.timezone'))])
            ->toBase()->pluck('recorded_at')->map(fn ($time) => Carbon::parse($time, config('app.timezone'))->getTimestamp())->all();
    }

    /** Whether one of the times (unix) is within NEAR seconds of this one. */
    public static function near(array $times, int $at): bool
    {
        foreach ($times as $time) {
            if (abs($time - $at) <= self::NEAR) {
                return true;
            }
        }

        return false;
    }
}
