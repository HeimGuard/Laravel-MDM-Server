<?php

namespace App\Services;

use App\Models\Device;
use App\Models\InventoryDefinition;
use App\Models\InventorySample;
use Carbon\CarbonInterface;

/**
 * The state of the things of a kind (the containers of a device) hour by hour from the history of their inventory (the samples are
 * the changes of state), for a bar of beats: up (it ran the whole hour), down (it did not run for a moment of the hour; with the
 * time and the state of that moment) or none (nothing is known of it in that hour: it did not exist yet, or was gone).
 */
class InventoryTimelineService
{
    /** States of a container that count as running (a failing health check is a running container). */
    private const UP = ['running', 'unhealthy'];

    /**
     * @return array<string, array<int, array{from: CarbonInterface, to: CarbonInterface, state: string, down: array<int, array{from: CarbonInterface, to: CarbonInterface, state: string}>}>> name => the beats, oldest first
     */
    public static function beats(Device $device, string $kind, int $hours = 12, ?CarbonInterface $now = null): array
    {
        $now = ($now ?? now())->copy();
        $end = $now->copy()->startOfHour()->addHour();
        $start = $end->copy()->subHours($hours);

        $definitions = InventoryDefinition::query()->where('inventorable_type', $device->getMorphClass())->where('inventorable_id', $device->id)->where('key', $kind)->get(['id', 'name']);
        if ($definitions->isEmpty()) {
            return [];
        }
        $ids = $definitions->pluck('id')->all();
        // The state when the window began (the last sample before it) and the changes in it.
        $before = InventorySample::query()->whereIn('id', InventorySample::query()->selectRaw('max(id)')->whereIn('definition_id', $ids)->where('recorded_at', '<', $start)->groupBy('definition_id'))
            ->get(['definition_id', 'data'])->keyBy('definition_id');
        $within = InventorySample::query()->whereIn('definition_id', $ids)->where('recorded_at', '>=', $start)->where('recorded_at', '<=', $now)
            ->orderBy('recorded_at')->orderBy('id')->get(['definition_id', 'data', 'recorded_at'])->groupBy('definition_id');

        $timelines = [];
        foreach ($definitions as $definition) {
            $points = [[$start, $before->has($definition->id) ? self::state($before[$definition->id]->data) : 'unknown']];
            foreach ($within->get($definition->id, []) as $sample) {
                $points[] = [$sample->recorded_at, self::state($sample->data)];
            }
            $timelines[$definition->name] = self::beatsOf($points, $start, $now, $hours);
        }
        ksort($timelines);

        return $timelines;
    }

    private static function state(?array $data): string
    {
        return $data === null ? 'gone' : (string) ($data['State'] ?? 'unknown');
    }

    /**
     * @param  array<int, array{0: CarbonInterface, 1: string}>  $points  [time, state] from the start of the window, the state holds until the next one
     */
    private static function beatsOf(array $points, CarbonInterface $start, CarbonInterface $now, int $hours): array
    {
        $segments = [];
        foreach ($points as $i => [$from, $state]) {
            $to = $points[$i + 1][0] ?? $now;
            if ($to->gt($from)) {
                $segments[] = [$from, $to, $state];
            }
        }
        $beats = [];
        for ($h = 0; $h < $hours; $h++) {
            $from = $start->copy()->addHours($h);
            $to = $from->copy()->addHour();
            $until = $to->gt($now) ? $now : $to;
            $up = false;
            $down = [];
            foreach ($segments as [$segmentFrom, $segmentTo, $state]) {
                $overlapFrom = $segmentFrom->gt($from) ? $segmentFrom : $from;
                $overlapTo = $segmentTo->lt($until) ? $segmentTo : $until;
                if (! $overlapTo->gt($overlapFrom)) {
                    continue;
                }
                if (in_array($state, self::UP, true)) {
                    $up = true;
                } elseif (! in_array($state, ['gone', 'unknown'], true)) {
                    $down[] = ['from' => $overlapFrom->copy(), 'to' => $overlapTo->copy(), 'state' => $state];
                }
            }
            $beats[] = ['from' => $from, 'to' => $to, 'state' => $down !== [] ? 'down' : ($up ? 'up' : 'none'), 'down' => $down];
        }

        return $beats;
    }
}
