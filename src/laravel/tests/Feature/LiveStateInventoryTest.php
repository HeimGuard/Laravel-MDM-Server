<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\InventorySample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The states of services and containers the agent sends with its heartbeat are inventory: a change is a sample, so there is a history. */
class LiveStateInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function device(array $report): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'srv');
        $device->name = 'srv';
        $device->data = json_encode(['machine' => ['Hostname' => 'srv', 'Platform' => 'linux', 'AgentVersion' => '1.31.0', 'Drives' => []]] + $report);
        $device->save();

        return $device->fresh();
    }

    private function heartbeat(Device $device, array $state): void
    {
        Device::recordHeartbeat($device->id, null, 'http', $state);
    }

    public function test_a_change_of_state_is_a_sample_and_the_page_shows_it(): void
    {
        $device = $this->device(['services' => [
            ['Name' => 'nginx', 'DisplayName' => 'Web server', 'State' => 'running', 'Status' => 'Up 3 days'],
            ['Name' => 'cron', 'State' => 'running'],
        ]]);
        $this->assertSame(2, InventorySample::query()->count());

        $this->heartbeat($device, ['services' => ['nginx' => 'stopped', 'cron' => 'running']]);

        $services = collect($device->fresh()->services)->keyBy('Name');
        // The state of the heartbeat, without the status text of the report, the rest of the row stays.
        $this->assertSame(['stopped', 'Web server', false], [$services['nginx']['State'], $services['nginx']['DisplayName'], isset($services['nginx']['Status'])]);
        $this->assertSame('running', $services['cron']['State']);
        // One sample: nginx; cron did not change.
        $this->assertSame(3, InventorySample::query()->count());
        $this->assertSame(['running', 'stopped'], $device->inventoryHistory('service', 'nginx')->get()->map(fn ($sample) => $sample->data['State'])->all());

        // The same state again is nothing new.
        $this->heartbeat($device, ['services' => ['nginx' => 'stopped', 'cron' => 'running']]);
        $this->assertSame(3, InventorySample::query()->count());
    }

    public function test_a_thing_the_state_does_not_have_is_gone_and_a_new_one_has_its_name_only(): void
    {
        $device = $this->device(['services' => [['Name' => 'nginx', 'State' => 'running'], ['Name' => 'cron', 'State' => 'running']]]);

        $this->heartbeat($device, ['services' => ['nginx' => 'running', 'redis' => 'failed']]);

        $services = collect($device->fresh()->services)->keyBy('Name');
        $this->assertSame(['nginx', 'redis'], $services->keys()->sort()->values()->all());
        $this->assertSame(['Name' => 'redis', 'State' => 'failed'], $services['redis']);
        // Gone is a change too: cron has a sample with no data.
        $this->assertNull($device->latestInventory('service', 'cron'));
        $this->assertSame([['Name' => 'cron', 'State' => 'running'], null], $device->inventoryHistory('service', 'cron')->get()->map(fn ($sample) => $sample->data)->all());
    }

    public function test_the_error_of_docker_is_cleared_by_the_states_of_the_containers(): void
    {
        $device = $this->device(['docker' => ['error' => 'permission denied', 'containers' => []]]);
        $this->assertSame('permission denied', $device->docker['error']);

        $this->heartbeat($device, ['containers' => ['web' => 'running']]);

        $docker = $device->fresh()->docker;
        $this->assertNull($docker['error']);
        $this->assertSame([['Name' => 'web', 'State' => 'running']], $docker['containers']);
    }

    public function test_an_unhealthy_container_is_running_with_a_failing_health_check(): void
    {
        $device = $this->device(['docker' => ['containers' => [
            ['Name' => 'api', 'Image' => 'api:1', 'State' => 'running', 'Status' => 'Up 2 hours (unhealthy)'],
            ['Name' => 'web', 'Image' => 'nginx', 'State' => 'running', 'Status' => 'Up 2 hours'],
        ]]]);
        $containers = fn () => collect($device->fresh()->docker['containers'])->keyBy('Name');
        $this->assertSame(['running', 'unhealthy'], [$containers()['api']['State'], $containers()['api']['Health']]);

        // The same as the report says: nothing changes.
        $this->heartbeat($device, ['containers' => ['api' => 'unhealthy', 'web' => 'running']]);
        $this->assertSame([1, 1], [$device->inventoryHistory('container', 'api')->count(), $device->inventoryHistory('container', 'web')->count()]);

        // The health check passes again: running, without the failing status.
        $this->heartbeat($device, ['containers' => ['api' => 'running', 'web' => 'running']]);
        $this->assertSame(['running', null], [$containers()['api']['State'], $containers()['api']['Health'] ?? null]);
        // The health check fails: unhealthy.
        $this->heartbeat($device, ['containers' => ['api' => 'unhealthy', 'web' => 'running']]);
        $this->assertSame(['running', 'unhealthy'], [$containers()['api']['State'], $containers()['api']['Health']]);
    }

    public function test_the_order_of_a_list_stays_when_a_state_changes(): void
    {
        $device = $this->device(['docker' => ['containers' => [['Name' => 'c', 'State' => 'running'], ['Name' => 'a', 'State' => 'running'], ['Name' => 'b', 'State' => 'running']]]]);

        $this->heartbeat($device, ['containers' => ['c' => 'exited', 'a' => 'running', 'b' => 'running', 'z' => 'running']]);

        // Where they were in the report; the new one at the end.
        $this->assertSame(['c', 'a', 'b', 'z'], array_column($device->fresh()->docker['containers'], 'Name'));
    }

    public function test_the_live_state_keeps_what_is_not_inventory(): void
    {
        $device = $this->device([]);

        $this->heartbeat($device, ['restart_required' => true, 'services' => ['nginx' => 'running']]);

        $stored = json_decode($device->fresh()->getRawOriginal('live_state'), true);
        $this->assertSame(['restart_required' => true], $stored);
        $this->assertTrue($device->fresh()->restartPending);
    }
}
