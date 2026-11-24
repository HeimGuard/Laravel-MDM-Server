<?php

namespace App\Livewire;

use App\Models\Device;
use App\Services\SwitchService;
use App\Support\Audit;
use App\Support\LiveUpdates;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/**
 * SNMP of a ping-only device (from the device menu, next to the Ping settings): only the community (version 1 or 2c); the agent of its network
 * finds out the rest (the version, what the device is, its ports, the MAC table: SwitchService). Empty turns SNMP off.
 */
#[AllowInModal]
class SnmpSettings extends Component
{
    public int $deviceId;

    public string $community = '';

    public function mount(int $deviceId): void
    {
        $device = Device::query()->where('kind', 'ping')->findOrFail($deviceId);
        $this->deviceId = $device->id;
        // The community is shown (it is read only, every signed-in user is a trusted operator).
        $this->community = (string) $device->switch_type === SwitchService::SNMP ? (string) SwitchService::password($device) : '';
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $device = Device::query()->where('kind', 'ping')->findOrFail($this->deviceId);
        $community = trim($this->community);
        if ($community !== '' && (mb_strlen($community) > 100 || preg_match('/[\x00-\x1f]/', $community))) {
            $this->addError('community', __('Enter the SNMP community.'));

            return;
        }
        $snmp = (string) $device->switch_type === SwitchService::SNMP;
        // Empty: off (only what SNMP set; the web login of a TP-Link switch stays); the same community is not stored again.
        if ($community === '' ? $snmp : (! $snmp || SwitchService::password($device) !== $community)) {
            SwitchService::setCommunity($device, $community === '' ? null : $community);
            Audit::device($device, 'settings', __('SNMP settings changed'));
            LiveUpdates::device($device->id, 'settings');
        }
        $this->dispatch('ping-settings-saved');
        $this->dispatch('closeModal');
    }

    public function render()
    {
        $device = Device::query()->where('kind', 'ping')->findOrFail($this->deviceId);

        return view('livewire.snmp-settings', [
            'info' => (string) $device->switch_type === SwitchService::SNMP ? SwitchService::info($device) : null,
            'replaces' => (string) $device->switch_type === SwitchService::TPLINK,
        ]);
    }
}
