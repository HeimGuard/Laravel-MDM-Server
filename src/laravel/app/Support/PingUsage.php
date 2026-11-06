<?php

namespace App\Support;

use App\Models\Device;
use App\Models\ValueDefinition;
use App\Models\ValueSample;
use Illuminate\Support\Carbon;

/**
 * The pings of a ping-only device by an agent of its network, kept as measured values: ping.up (1 answered, 0 not) of
 * every ping and ping.rtt (the response time in ms) of the answered ones.
 */
class PingUsage
{
    /** The keys of the values of a ping (the relation Device::pingValues reads them). */
    public const KEYS = ['ping.up', 'ping.rtt', 'ping.relay_id'];

    /** The values of one ping: whether it was answered, the response time of an answered one, the agent that pinged. */
    public static function values(bool $up, ?float $rtt, ?int $relayId = null): array
    {
        $values = [['key' => 'ping.up', 'value' => $up ? 1 : 0]];
        if ($up && $rtt !== null) {
            $values[] = ['key' => 'ping.rtt', 'value' => $rtt, 'unit' => 'ms'];
        }
        if ($relayId !== null) {
            $values[] = ['key' => 'ping.relay_id', 'value' => $relayId];
        }

        return $values;
    }

    public static function record(int $deviceId, bool $up, ?float $rtt, ?\DateTimeInterface $at = null, ?int $relayId = null): void
    {
        ValueRecorder::record((new Device)->getMorphClass(), $deviceId, self::values($up, $rtt, $relayId), $at);
    }

    /**
     * The response time of the last ping says nothing about another address: it is forgotten (the history stays, and the agent
     * that pinged last stays as it did before the settings were measured values).
     */
    public static function forgetCurrent(int $deviceId): void
    {
        ValueDefinition::query()->where('valuable_type', (new Device)->getMorphClass())->where('valuable_id', $deviceId)->where('key', 'ping.rtt')->where('name', '')
            ->update(['last_value' => null, 'last_at' => null]);
    }

    private static function definition(int $deviceId, string $key): ?ValueDefinition
    {
        return ValueDefinition::query()->where('valuable_type', (new Device)->getMorphClass())->where('valuable_id', $deviceId)->where('key', $key)->where('name', '')->first();
    }

    /**
     * The pings since a time, oldest first, as the rows the views read (up, rtt, created_at): the response time of an answered
     * one is the ping.rtt sample of the same moment.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function checks(int $deviceId, \DateTimeInterface $since, ?int $limit = null)
    {
        $definition = self::definition($deviceId, 'ping.up');
        if ($definition === null) {
            return collect();
        }
        $query = ValueSample::query()->where('definition_id', $definition->id)->where('recorded_at', '>=', $since)->orderByDesc('recorded_at');
        if ($limit !== null) {
            $query->limit($limit);
        }

        $checks = $query->toBase()->get(['value', 'recorded_at'])->reverse()->values();
        $rtt = self::definition($deviceId, 'ping.rtt');
        $response = $rtt === null || $checks->isEmpty() ? collect() : ValueSample::query()->where('definition_id', $rtt->id)->where('recorded_at', '>=', $checks->first()->recorded_at)
            ->toBase()->pluck('value', 'recorded_at');

        return $checks->map(fn ($row) => (object) [
            'up' => (float) $row->value >= 0.5,
            'rtt' => isset($response[$row->recorded_at]) ? (float) $response[$row->recorded_at] : null,
            'created_at' => Carbon::parse($row->recorded_at, config('app.timezone')),
        ]);
    }

    /** Samples of ping.rtt (rtt: float, at: Carbon) since a time, oldest first. */
    public static function responses(int $deviceId, \DateTimeInterface $since)
    {
        $definition = self::definition($deviceId, 'ping.rtt');
        if ($definition === null) {
            return collect();
        }

        return ValueSample::query()->where('definition_id', $definition->id)->where('recorded_at', '>=', $since)->orderBy('recorded_at')->toBase()->get(['value', 'recorded_at'])
            ->map(fn ($row) => (object) ['rtt' => (float) $row->value, 'at' => Carbon::parse($row->recorded_at, config('app.timezone'))]);
    }

    /** Share of answered pings since the time, in percent with two decimals; null without pings. */
    public static function uptime(int $deviceId, \DateTimeInterface $since): ?float
    {
        $definition = self::definition($deviceId, 'ping.up');
        if ($definition === null) {
            return null;
        }
        $row = ValueSample::query()->where('definition_id', $definition->id)->where('recorded_at', '>=', $since)->toBase()->selectRaw('count(*) as total, sum(value) as answered')->first();

        return (int) $row->total === 0 ? null : round((float) $row->answered / (int) $row->total * 100, 2);
    }

    /** The average response time of the answered pings since the time; null without any. */
    public static function average(int $deviceId, \DateTimeInterface $since): ?float
    {
        $definition = self::definition($deviceId, 'ping.rtt');
        $average = $definition === null ? null : ValueSample::query()->where('definition_id', $definition->id)->where('recorded_at', '>=', $since)->toBase()->avg('value');

        return $average === null ? null : round((float) $average, 1);
    }
}
