<?php

namespace App\Livewire;

use App\Models\Device;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/**
 * Wake-on-LAN of a device with the agent (from the device menu): the MAC address and the IPv4
 * address set by hand, for a card the agent does not report well (docking station, a card that is
 * not the one waking). Empty fields use what the agent reports.
 */
#[AllowInModal]
class WakeSettings extends Component
{
    public int $deviceId;

    public string $mac = '';

    public string $address = '';

    public $prefix = 24;

    public function mount(int $deviceId): void
    {
        $device = Device::query()->where('kind', 'agent')->findOrFail($deviceId);
        $this->deviceId = $device->id;
        $this->mac = (string) $device->wake_mac;
        $this->address = (string) $device->wake_address;
        $this->prefix = $device->wake_prefix ?? 24;
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $device = Device::query()->where('kind', 'agent')->findOrFail($this->deviceId);
        $settings = Device::sanitizeWakeSettings($this->mac, $this->address, $this->prefix);
        if ($settings === null) {
            $this->addError('mac', __('Enter a valid MAC address and an IPv4 address with a prefix of 8–30, or leave them empty.'));

            return;
        }
        $device->forceFill($settings);
        $changes = $device->getDirty();
        $device->save();
        if ($changes !== []) {
            \App\Support\Audit::device($device, 'settings', __('Wake-on-LAN settings changed'), ['changes' => $changes]);
        }
        \App\Support\LiveUpdates::device($device->id, 'settings');

        $this->dispatch('closeModal');
    }

    public function render()
    {
        $device = Device::query()->findOrFail($this->deviceId);
        // What is used without the settings: the agent's cards.
        $reported = clone $device;
        $reported->wake_mac = $reported->wake_address = $reported->wake_prefix = null;

        return view('livewire.wake-settings', [
            'reportedMacs' => $reported->wakeMacs,
            'reportedNetworks' => array_keys($reported->wakeNetworks()),
        ]);
    }
}
