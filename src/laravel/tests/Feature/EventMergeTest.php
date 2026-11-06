<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SecurityEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Two sources that report the same thing confirm each other: nothing is dropped, nothing is counted twice. */
class EventMergeTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'srv');
        $device->name = 'srv';
        $device->save();

        return $device;
    }

    private function event(string $source, int $count, string $last, ?string $first = null, string $user = 'root', string $origin = '203.0.113.9', string $type = 'failed_logon'): array
    {
        return ['Type' => $type, 'Count' => $count, 'User' => $user, 'Source' => $origin, 'Message' => 'Failed SSH sign-in', 'Last' => $last, 'First' => $first ?? $last, 'SourceType' => $source, 'Parser' => 'p.'.$source];
    }

    public function test_the_summary_of_the_agent_confirms_the_messages_of_syslog_that_came_before_it(): void
    {
        $device = $this->device();
        // Syslog: one message at a time, as they come.
        SecurityEvent::record($device, [$this->event('syslog', 1, '2026-11-06 09:04:12')]);
        SecurityEvent::record($device, [$this->event('syslog', 1, '2026-11-06 09:20:40')]);
        SecurityEvent::record($device, [$this->event('syslog', 1, '2026-11-06 09:41:03')]);
        // The agent: all three of the hour in one event.
        $counted = SecurityEvent::record($device, [$this->event('linux.auth', 3, '2026-11-06 09:41:03', '2026-11-06 09:04:12')]);

        $events = SecurityEvent::query()->orderBy('id')->get();
        // Nothing is dropped: four events.
        $this->assertCount(4, $events);
        $agent = $events->last();
        // The one with the larger count counts, the messages point to it.
        $this->assertNull($agent->duplicate_of);
        $this->assertSame([$agent->id, $agent->id, $agent->id], $events->take(3)->map->duplicate_of->all());
        $this->assertSame([true, true, true, true], $events->map->verified->all());
        $this->assertEqualsCanonicalizing(['syslog', 'linux.auth'], $agent->sources);
        // The agent's event is what the rules look at (it is new and counts).
        $this->assertCount(1, $counted);
        $this->assertSame(3, $counted[0]['Count']);
    }

    public function test_a_message_of_syslog_that_comes_after_the_summary_of_the_agent_is_a_duplicate(): void
    {
        $device = $this->device();
        SecurityEvent::record($device, [$this->event('linux.auth', 12, '2026-11-06 09:59:00', '2026-11-06 09:00:10')]);

        $counted = SecurityEvent::record($device, [$this->event('syslog', 1, '2026-11-06 09:30:00')]);

        $syslog = SecurityEvent::query()->where('source_type', 'syslog')->sole();
        $agent = SecurityEvent::query()->where('source_type', 'linux.auth')->sole();
        $this->assertSame($agent->id, $syslog->duplicate_of);
        $this->assertTrue($syslog->verified && $agent->verified);
        // It is stored (the page lists it), but it is not counted again.
        $this->assertSame([], $counted);
    }

    public function test_what_is_not_the_same_thing_is_not_merged(): void
    {
        $device = $this->device();
        SecurityEvent::record($device, [$this->event('linux.auth', 5, '2026-11-06 09:30:00', '2026-11-06 09:00:00')]);

        // Another user, another address, another type, a time far from it, and a second batch of the same source.
        SecurityEvent::record($device, [
            $this->event('syslog', 1, '2026-11-06 09:10:00', user: 'alice'),
            $this->event('syslog', 1, '2026-11-06 09:11:00', origin: '198.51.100.7'),
            $this->event('syslog', 1, '2026-11-06 09:12:00', type: 'logon'),
            $this->event('syslog', 1, '2026-11-06 11:00:00'),
        ]);
        SecurityEvent::record($device, [$this->event('linux.auth', 2, '2026-11-06 09:35:00', '2026-11-06 09:31:00')]);

        $this->assertSame(6, SecurityEvent::query()->count());
        $this->assertSame([], SecurityEvent::query()->get()->filter(fn ($event) => $event->verified || $event->duplicate_of !== null)->all());
    }

    public function test_the_minute_of_tolerance(): void
    {
        $device = $this->device();
        SecurityEvent::record($device, [$this->event('linux.auth', 1, '2026-11-06 09:30:00')]);

        SecurityEvent::record($device, [$this->event('syslog', 1, '2026-11-06 09:30:45')]);
        SecurityEvent::record($device, [$this->event('syslog', 1, '2026-11-06 09:35:00')]);

        // 45 s apart is the same one, 5 minutes is not.
        $this->assertSame([true, true, false], SecurityEvent::query()->orderBy('id')->get()->map->verified->all());
    }

    public function test_the_rules_see_only_what_counts(): void
    {
        $device = $this->device();
        SecurityEvent::record($device, [$this->event('syslog', 4, '2026-11-06 09:30:00', '2026-11-06 09:25:00')]);
        SecurityEvent::record($device, [$this->event('linux.auth', 5, '2026-11-06 09:31:00', '2026-11-06 09:24:30')]);
        Carbon::setTestNow('2026-11-06 10:00:00');

        $counting = SecurityEvent::query()->forDevice($device->id)->whereNull('data->duplicate_of')->where('occurred_at', '>=', now()->subDay())->get();

        // Both are stored, but the larger one counts: 5, not 4 + 5.
        $this->assertSame(2, SecurityEvent::query()->count());
        $this->assertSame([5], $counting->pluck('count')->all());
        Carbon::setTestNow();
    }
}
