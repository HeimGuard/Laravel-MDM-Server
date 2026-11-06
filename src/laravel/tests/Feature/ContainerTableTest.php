<?php

namespace Tests\Feature;

use App\Livewire\DeviceDetail;
use App\Models\Device;
use App\Models\User;
use App\Services\DockerPortsService;
use App\Services\InventoryTimelineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The table of the containers: the ports published to the outside, short, and the bar of the last 12 hours. */
class ContainerTableTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function device(array $containers = []): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'srv');
        $device->name = 'srv';
        $device->data = json_encode(['machine' => ['Hostname' => 'srv', 'Platform' => 'linux', 'AgentVersion' => '1.31.0', 'Drives' => []], 'docker' => ['containers' => $containers]]);
        $device->save();

        return $device->fresh();
    }

    /** @return array<string, array{0: string, 1: array<int, string>}> */
    public static function ports(): array
    {
        // What Docker says on real hosts (both spellings of IPv6).
        return [
            'one port, ipv4 and ipv6' => ['0.0.0.0:7788->8000/tcp, [::]:7788->8000/tcp', ['7788']],
            'only inside' => ['80/tcp', []],
            'two only inside' => ['22/tcp, 3000/tcp', []],
            'inside tcp and udp' => ['80/tcp, 9032/udp', []],
            'nothing' => ['', []],
            'tcp and udp of one port, and another' => ['0.0.0.0:514->5514/tcp, 0.0.0.0:514->5514/udp, [::]:514->5514/tcp, [::]:514->5514/udp, 0.0.0.0:3838->8000/tcp, [::]:3838->8000/tcp', ['514', '514/udp', '3838']],
            'a range and an udp' => ['0.0.0.0:80-81->80-81/tcp, [::]:80-81->80-81/tcp, 0.0.0.0:443->443/tcp, [::]:443->443/tcp, 0.0.0.0:8081->8081/udp, [::]:8081->8081/udp', ['80-81', '443', '8081/udp']],
            'published among inside ones' => ['0.0.0.0:8080->8080/tcp, [::]:8080->8080/tcp, 6789/tcp, 8843/tcp, 0.0.0.0:3478->3478/udp, [::]:3478->3478/udp, 8880/tcp, 0.0.0.0:8443->8443/tcp, [::]:8443->8443/tcp, 10001/udp', ['8080', '3478/udp', '8443']],
            'the other ipv6 spelling' => ['0.0.0.0:3030->3000/tcp, :::3030->3000/tcp', ['3030']],
            'only on the host itself' => ['127.0.0.1:5432->5432/tcp', ['127.0.0.1:5432']],
        ];
    }

    /** @dataProvider ports */
    #[\PHPUnit\Framework\Attributes\DataProvider('ports')]
    public function test_only_the_published_ports_by_the_host_port(string $docker, array $expected): void
    {
        $this->assertSame($expected, DockerPortsService::published($docker));
    }

    public function test_the_bar_shows_when_a_container_did_not_run(): void
    {
        Carbon::setTestNow('2026-11-06 12:30:00');
        $device = $this->device();
        $data = fn (string $state) => ['Image' => 'nginx:1.27', 'State' => $state];
        $device->recordInventory('container', 'web', $data('running'), null, Carbon::parse('2026-11-06 02:16:40'));
        $device->recordInventory('container', 'web', $data('exited'), null, Carbon::parse('2026-11-06 09:14:09'));
        $device->recordInventory('container', 'web', $data('running'), null, Carbon::parse('2026-11-06 09:16:40'));

        $beats = InventoryTimelineService::beats($device, 'container', 12)['web'];

        // 01:00 to 13:00, the last one is the hour that is running.
        $this->assertCount(12, $beats);
        $this->assertSame('01:00', $beats[0]['from']->format('H:i'));
        $this->assertSame('13:00', $beats[11]['to']->format('H:i'));
        $states = array_column($beats, 'state');
        // 01:00 nothing was known yet, 02:00 it started, 09:00 it was down for a moment, the rest ran.
        $this->assertSame(['none', 'up', 'up', 'up', 'up', 'up', 'up', 'up', 'down', 'up', 'up', 'up'], $states);
        $this->assertSame(['09:14', '09:16', 'exited'], [$beats[8]['down'][0]['from']->format('H:i'), $beats[8]['down'][0]['to']->format('H:i'), $beats[8]['down'][0]['state']]);
    }

    public function test_a_container_that_was_down_for_all_of_it_and_one_that_is_gone(): void
    {
        Carbon::setTestNow('2026-11-06 12:30:00');
        $device = $this->device();
        $device->recordInventory('container', 'stopped', ['State' => 'exited'], null, Carbon::parse('2026-11-05 10:00:00'));
        $device->recordInventory('container', 'removed', ['State' => 'running'], null, Carbon::parse('2026-11-05 10:00:00'));
        $device->recordInventory('container', 'removed', null, null, Carbon::parse('2026-11-06 08:30:00'));

        $beats = InventoryTimelineService::beats($device, 'container', 12);

        $this->assertSame(array_fill(0, 12, 'down'), array_column($beats['stopped'], 'state'));
        // Up until 08:30 (the hour of 08:00 half of it), then it is gone: not down, no information.
        $this->assertSame(['up', 'up', 'up', 'up', 'up', 'up', 'up', 'up', 'none', 'none', 'none', 'none'], array_column($beats['removed'], 'state'));
        // A container with a failing health check is running.
        $device->recordInventory('container', 'unwell', ['State' => 'unhealthy'], null, Carbon::parse('2026-11-05 10:00:00'));
        $this->assertSame(array_fill(0, 12, 'up'), array_column(InventoryTimelineService::beats($device, 'container', 12)['unwell'], 'state'));
        $this->assertSame([], InventoryTimelineService::beats($device, 'service', 12));
    }

    public function test_the_table_shows_the_published_ports_and_the_bar(): void
    {
        $this->actingAs(User::factory()->create());
        Carbon::setTestNow('2026-11-06 12:30:00');
        $web = fn (string $state) => ['Name' => 'web', 'Image' => 'nginx:1.27', 'State' => $state, 'Status' => 'Up 3 weeks', 'Ports' => '0.0.0.0:7788->8000/tcp, [::]:7788->8000/tcp, 6379/tcp'];
        $device = $this->device([$web('running'), ['Name' => 'db', 'Image' => 'mysql', 'State' => 'running', 'Status' => 'Up 10 days', 'Ports' => '3306/tcp']]);
        $device->recordInventory('container', 'web', $web('exited'), null, Carbon::parse('2026-11-06 09:14:00'));
        $device->recordInventory('container', 'web', $web('running'), null, Carbon::parse('2026-11-06 09:16:00'));

        $html = Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])->html();

        // The host port only; what is inside the container (6379, 3306) and the long form are not there.
        $this->assertStringContainsString('>7788<', $html);
        $this->assertStringNotContainsString('0.0.0.0:7788', $html);
        $this->assertStringNotContainsString('6379/tcp', $html);
        $this->assertStringNotContainsString('3306/tcp', $html);
        // The bar of the web container: 12 beats, one of them down with the time of the gap.
        $this->assertSame(12, preg_match_all('/class="ping-beat[ "]/', substr($html, strpos($html, 'ping-beats ping-beats-sm'), 4000)));
        $this->assertStringContainsString('is-down', $html);
        $this->assertStringContainsString('09:14–09:16', $html);
        $this->assertStringContainsString('Last 12 hours', $html);
    }
}
