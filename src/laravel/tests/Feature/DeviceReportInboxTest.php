<?php

namespace Tests\Feature;

use App\Jobs\ProcessDeviceReport;
use App\Models\Device;
use App\Support\DeviceReportInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The report of an agent is taken into the cache and stored by a job; the answer to the agent stays the same. */
class DeviceReportInboxTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'secret-token');
        $device->name = 'old-name';
        $device->data = json_encode(['machine' => ['Hostname' => 'old-name', 'Drives' => []]]);
        $device->save();

        return $device->fresh();
    }

    private function report(string $host, array $extra = []): array
    {
        return ['machine' => ['Hostname' => $host, 'os' => 'linux', 'Drives' => [], 'Networks' => [
            ['Name' => 'eth0', 'Type' => 'lan', 'Connected' => true, 'Status' => 'Up', 'IPAddresses' => ['192.168.1.10']],
        ]]] + $extra;
    }

    public function test_the_report_is_stored_by_the_job_and_the_answer_is_the_same(): void
    {
        Event::fake([\App\Events\DevicesChanged::class]);
        $device = $this->device();

        $response = $this->withToken('secret-token')->postJson('/api/device', $this->report('new-name'));

        $response->assertOk()->assertJsonStructure(['scripts_pending', 'ping_targets', 'public_address_url']);
        $device = $device->fresh();
        $this->assertSame('new-name', $device->name);
        $this->assertSame('new-name', $device->data->machine->Hostname);
        // The portal is told once the report is stored (the pages reload).
        Event::assertDispatched(\App\Events\DevicesChanged::class, fn ($event) => $event->deviceId === $device->id && $event->what === 'report');
    }

    public function test_the_request_only_queues_the_report(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $device = $this->device();

        $this->withToken('secret-token')->postJson('/api/device', $this->report('new-name'))->assertOk();

        // The columns of the request are there, the report waits in the cache for the worker.
        $device = $device->fresh();
        $this->assertSame('new-name', $device->name);
        $this->assertSame('old-name', $device->data->machine->Hostname);
        $this->assertTrue(Cache::has(DeviceReportInbox::key($device->id)));
        Queue::assertPushed(ProcessDeviceReport::class, fn (ProcessDeviceReport $job) => $job->deviceId === $device->id);

        (new ProcessDeviceReport($device->id))->handle();
        $this->assertSame('new-name', $device->fresh()->data->machine->Hostname);
        $this->assertFalse(Cache::has(DeviceReportInbox::key($device->id)));
    }

    public function test_only_the_newest_report_is_kept(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $device = $this->device();

        $this->withToken('secret-token')->postJson('/api/device', $this->report('first'))->assertOk();
        $this->withToken('secret-token')->postJson('/api/device', $this->report('second'))->assertOk();

        (new ProcessDeviceReport($device->id))->handle();
        $this->assertSame('second', $device->fresh()->data->machine->Hostname);
        // The job of the replaced report finds nothing and does nothing.
        (new ProcessDeviceReport($device->id))->handle();
        $this->assertSame('second', $device->fresh()->data->machine->Hostname);
    }

    public function test_a_cache_that_fails_does_not_lose_the_report(): void
    {
        $device = $this->device();
        Crypt::shouldReceive('encryptString')->andThrow(new \RuntimeException('cache down'));

        DeviceReportInbox::push($device, json_encode($this->report('kept')));

        $this->assertSame('kept', $device->fresh()->data->machine->Hostname);
    }
}
