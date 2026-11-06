<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Support\PingUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The last ping of a ping-only device (response time, the agent that pinged) is read from its measured values. */
class PingValuesTest extends TestCase
{
    use RefreshDatabase;

    private function ping(): Device
    {
        $device = new Device;
        $device->forceFill(['kind' => 'ping', 'name' => 'printer', 'os' => '', 'token' => hash('sha256', 'p'), 'ping_address' => '192.168.1.50', 'ping_prefix' => 24])->save();

        return $device;
    }

    public function test_the_last_ping_is_the_current_state(): void
    {
        $printer = $this->ping();
        $this->assertSame([null, null], [$printer->fresh()->ping_rtt, $printer->fresh()->ping_relay_id]);

        PingUsage::record($printer->id, true, 2.4, now()->subMinute(), 7);
        $this->assertSame([2.4, 7], [Device::find($printer->id)->ping_rtt, Device::find($printer->id)->ping_relay_id]);

        // Not answered: no response time, but the agent that pinged is known.
        PingUsage::record($printer->id, false, null, null, 8);
        $this->assertSame([null, 8], [Device::find($printer->id)->ping_rtt, Device::find($printer->id)->ping_relay_id]);

        // Answered again, without a response time: not the old one.
        PingUsage::record($printer->id, true, null, now()->addSecond(), 8);
        $this->assertNull(Device::find($printer->id)->ping_rtt);
    }

    public function test_the_history_keeps_every_ping_and_the_agent_that_made_it(): void
    {
        $printer = $this->ping();

        PingUsage::record($printer->id, true, 3.0, now()->subMinutes(2), 7);
        PingUsage::record($printer->id, false, null, now()->subMinute(), 8);

        $this->assertSame([1.0, 0.0], $printer->valueHistory('ping.up')->pluck('value')->all());
        $this->assertSame([3.0], $printer->valueHistory('ping.rtt')->pluck('value')->all());
        $this->assertSame([7.0, 8.0], $printer->valueHistory('ping.relay_id')->pluck('value')->all());
    }

    public function test_a_changed_address_forgets_the_state_but_not_the_history(): void
    {
        $printer = $this->ping();
        PingUsage::record($printer->id, true, 2.4, null, 7);

        PingUsage::forgetCurrent($printer->id);

        $fresh = Device::find($printer->id);
        $this->assertSame([null, null], [$fresh->ping_rtt, $fresh->ping_relay_id]);
        $this->assertSame(1, $printer->valueHistory('ping.up')->count());
    }

    public function test_a_backfill_does_not_change_the_current_state(): void
    {
        $printer = $this->ping();
        PingUsage::record($printer->id, true, 2.4, null, 7);

        // A ping from an hour ago (a backlog the agent sent later).
        \App\Support\ValueRecorder::recordMany((new Device)->getMorphClass(), $printer->id, [[now()->subHour(), PingUsage::values(false, null, 9)]]);

        $fresh = Device::find($printer->id);
        $this->assertSame([2.4, 7], [$fresh->ping_rtt, $fresh->ping_relay_id]);
        $this->assertSame(2, $printer->valueHistory('ping.up')->count());
    }
}
