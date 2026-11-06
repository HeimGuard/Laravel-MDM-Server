<?php

namespace Tests\Feature;

use App\Livewire\SecurityScan\Page;
use App\Models\Device;
use App\Models\SecurityEvent;
use App\Models\SecurityInventory;
use App\Models\User;
use App\Support\SecurityParsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** What happened (the events table): of any model, what it came from, and in data what not every event has. */
class EventTest extends TestCase
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

    private function parser(string $key, string $source): array
    {
        return [
            'key' => $key, 'name' => $key, 'source' => $source,
            'pattern' => '^Failed password for (?<user>\S+) from (?<ip>\S+) port',
            'event' => ['type' => 'failed_logon', 'user' => '{user}', 'source' => '{ip}', 'message' => 'Failed sign-in of {user} from {ip}'],
        ];
    }

    private function logs(string $source): array
    {
        $record = $source === 'syslog' ? ['App' => 'sshd'] : ['Identifier' => 'sshd'];

        return [['source' => $source, 'records' => [$record + ['Time' => '2026-11-06T09:04:12Z', 'Message' => 'Failed password for root from 203.0.113.9 port 51234 ssh2']]]];
    }

    public function test_an_event_knows_where_it_came_from_and_what_made_it(): void
    {
        $device = $this->device();
        $events = SecurityInventory::sanitizeEvents(SecurityParsers::run($this->logs('syslog'), [$this->parser('org.syslog-sshd-failed', 'syslog')]));

        SecurityEvent::record($device, $events);

        $event = SecurityEvent::query()->with('eventable')->sole();
        $this->assertSame('syslog', $event->source_type);
        // What not every event has is in data, the pages and the rules read it as they always did.
        $this->assertSame(['user' => 'root', 'source_origin' => '203.0.113.9', 'parser' => 'org.syslog-sshd-failed'], $event->data);
        $this->assertSame(['root', '203.0.113.9', '203.0.113.9', 'org.syslog-sshd-failed'], [$event->user, $event->source, $event->source_origin, $event->parser]);
        $this->assertSame([$device->id, $device->id], [$event->device_id, $event->device->id]);
        $this->assertSame($device->getMorphClass(), $event->eventable_type);
    }

    public function test_the_same_thing_from_two_sources_is_two_events_that_say_where_they_came_from(): void
    {
        $device = $this->device();
        $parsers = [$this->parser('org.linux-sshd-failed', 'linux.auth'), $this->parser('org.syslog-sshd-failed', 'syslog')];

        $events = SecurityInventory::sanitizeEvents(SecurityParsers::run([...$this->logs('linux.auth'), ...$this->logs('syslog')], $parsers));
        SecurityEvent::record($device, $events);

        // Nothing is dropped (and not summed into one either): each source has its event.
        $this->assertSame(['linux.auth', 'syslog'], SecurityEvent::query()->orderBy('source_type')->pluck('source_type')->all());
    }

    public function test_an_event_can_be_made_with_the_names_the_code_knows(): void
    {
        $device = $this->device();

        $event = SecurityEvent::query()->create(['device_id' => $device->id, 'type' => 'logon', 'user' => 'bob', 'source' => '198.51.100.1', 'count' => 2, 'occurred_at' => now()]);

        $event = $event->fresh();
        $this->assertSame([$device->id, 'bob', '198.51.100.1', 2], [$event->device_id, $event->user, $event->source, $event->count]);
        $this->assertSame(1, SecurityEvent::query()->forDevice($device->id)->count());
        $this->assertSame(0, SecurityEvent::query()->forDevice($device->id + 1)->count());
        // No user, no address: nothing in data.
        $plain = SecurityEvent::query()->create(['device_id' => $device->id, 'type' => 'log_cleared', 'occurred_at' => now()])->fresh();
        $this->assertNull($plain->data);
        $this->assertSame([null, null], [$plain->user, $plain->source]);
    }

    public function test_the_events_page_searches_the_user_and_the_address(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->device();
        SecurityEvent::query()->create(['device_id' => $device->id, 'type' => 'failed_logon', 'user' => 'Alice', 'source' => '192.0.2.10', 'message' => 'one', 'occurred_at' => now()]);
        SecurityEvent::query()->create(['device_id' => $device->id, 'type' => 'failed_logon', 'user' => 'bob', 'source' => '198.51.100.7', 'message' => 'two', 'occurred_at' => now()]);
        $messages = fn (string $search) => Livewire::test(Page::class)->set('tab', 'events')->set('eventDays', 0)->set('eventSearch', $search)->viewData('events')->pluck('message')->sort()->values()->all();

        $this->assertSame(['one'], $messages('alice'));
        $this->assertSame(['two'], $messages('198.51.100'));
        $this->assertSame(['one', 'two'], $messages(''));
    }
}
