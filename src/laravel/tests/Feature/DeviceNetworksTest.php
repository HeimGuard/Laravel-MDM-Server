<?php

namespace Tests\Feature;

use App\Livewire\DeviceDetail;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceNetworksTest extends TestCase
{
    use RefreshDatabase;

    public function test_interfaces_have_a_type_and_a_connection_state(): void
    {
        $this->actingAs(User::factory()->create());
        $device = new Device;
        $device->token = hash('sha256', 'secret-token');
        $device->data = json_encode(['machine' => ['Drives' => [], 'Networks' => [
            // Agents 1.8.0+: type and connection reported.
            ['Name' => 'enp3s0', 'Status' => 'DOWN', 'Connected' => false, 'Type' => 'lan', 'Mac' => 'aa:bb:cc:dd:ee:ff', 'IPAddresses' => []],
            ['Name' => 'wg0', 'Status' => 'UNKNOWN', 'Connected' => true, 'Type' => 'vpn', 'IPAddresses' => ['10.8.0.2']],
            // Older agents: guessed from the name / description, a single address as a string.
            ['Name' => 'wlp0s20f3', 'Status' => 'Up', 'IPAddresses' => ['192.168.77.123', 'fe80::ae77:8fa7:ee61:4bfd']],
            ['Name' => 'Ethernet 2', 'InterfaceDescription' => 'TAP-Windows Adapter V9', 'Status' => 'Up', 'IPAddresses' => '10.9.0.5'],
            ['Name' => 'docker0', 'Status' => 'Up', 'IPAddresses' => ['172.17.0.1']],
        ]]]);
        $device->save();

        $networks = collect($device->networks)->keyBy('Name');
        $this->assertSame(['wifi', true], [$networks['wlp0s20f3']['Type'], $networks['wlp0s20f3']['Connected']]);
        $this->assertSame(['vpn', true, ['10.9.0.5']], [$networks['Ethernet 2']['Type'], $networks['Ethernet 2']['Connected'], $networks['Ethernet 2']['IPAddresses']]);
        $this->assertSame('docker', $networks['docker0']['Type']);
        $this->assertSame(['lan', false], [$networks['enp3s0']['Type'], $networks['enp3s0']['Connected']]);
        // Connected ones first.
        $this->assertSame('enp3s0', collect($device->networks)->last()['Name']);

        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id, 'tab' => 'networks'])
            ->assertSee('Disconnected')
            ->assertSee('Connected')
            ->assertSeeHtml('fa-wifi')
            ->assertSeeHtml('fab fa-docker')
            ->assertSeeHtml('fa-shield-alt')
            ->assertSee('aa:bb:cc:dd:ee:ff');
    }
}
