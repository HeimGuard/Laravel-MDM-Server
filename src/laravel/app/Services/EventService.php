<?php

namespace App\Services;

use App\Models\Device;
use App\Models\SecurityEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Events two sources report as the same thing (the auth log the agent reads and the same messages forwarded as syslog):
 * nothing is dropped, they confirm each other. The events are the same when the owner, the type, the user and the address
 * it came from are the same, the sources differ and the time of the one (from its first to its last occurrence, a minute
 * more each way) overlaps the time of the other. The one with the larger count is the one that counts, the others point to
 * it (data.duplicate_of), and all of them say which sources reported it (data.sources) and that it is verified.
 * Two events of one source are never merged: they are different occurrences.
 */
class EventService
{
    /** Seconds the time of two reports may be apart (the clocks and the batches of the sources differ). */
    public const TOLERANCE = 60;

    /**
     * Compares new events with the ones already stored (of other sources) and marks what is the same thing.
     *
     * @param  Collection<int, SecurityEvent>  $events  the events that were just stored
     * @return array<int, int> the ids of the new events that are another report of something already counted
     */
    public static function corroborate(Device $device, Collection $events): array
    {
        $duplicates = [];
        foreach ($events as $event) {
            if ($event->source_type === '') {
                continue;
            }
            $overlapping = self::overlapping($device, $event);
            if ($overlapping->isEmpty()) {
                continue;
            }
            $sources = $overlapping->pluck('source_type')->push($event->source_type)->unique()->values()->all();
            // The larger one counts; on a tie the new one (it is the report that came with the whole picture).
            $primary = $event->count >= $overlapping->sum('count') ? $event : $overlapping->sortByDesc('count')->first();
            foreach ($overlapping->push($event) as $member) {
                $data = $member->data ?? [];
                $data['sources'] = $sources;
                $data['verified'] = true;
                if ($member->id === $primary->id) {
                    unset($data['duplicate_of']);
                } else {
                    $data['duplicate_of'] = $primary->id;
                }
                $member->data = $data;
                $member->save();
                // What pointed to a member that is not the primary any more points to the primary.
                if ($member->id !== $primary->id) {
                    self::repoint($device, $member->id, $primary->id);
                }
            }
            if ($event->id !== $primary->id) {
                $duplicates[] = $event->id;
            }
        }

        return $duplicates;
    }

    /** The stored events of other sources that are the same thing as this one. @return Collection<int, SecurityEvent> */
    private static function overlapping(Device $device, SecurityEvent $event): Collection
    {
        $first = $event->first_at->copy()->subSeconds(self::TOLERANCE);
        $last = $event->occurred_at->copy()->addSeconds(self::TOLERANCE);

        return SecurityEvent::query()->forDevice($device->id)
            ->where('type', $event->type)->where('source_type', '!=', $event->source_type)->where('source_type', '!=', '')
            ->where('id', '!=', $event->id)->where('occurred_at', '>=', $first)
            ->get()
            ->filter(fn (SecurityEvent $other) => $other->user === $event->user && $other->source === $event->source && $other->first_at->lte($last))
            ->values();
    }

    private static function repoint(Device $device, int $from, int $to): void
    {
        SecurityEvent::query()->forDevice($device->id)->where('data->duplicate_of', $from)->get()->each(function (SecurityEvent $other) use ($to) {
            $data = $other->data;
            $data['duplicate_of'] = $to;
            $other->data = $data;
            $other->save();
        });
    }
}
