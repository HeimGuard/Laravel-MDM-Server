<?php

namespace App\Livewire;

use App\Models\Device;
use App\Services\SwitchService;
use App\Support\Audit;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/**
 * The ports of a ping-only device that nothing reads (a switch without SNMP, a modem, a patch panel), set by hand: how many (from the device
 * menu, next to the SNMP). They show on the network map like the read ones, without a link, and what is plugged into them is chosen by hand.
 */
#[AllowInModal]
class ManualPorts extends Component
{
    public int $deviceId;

    public string $ports = '';

    public function mount(int $deviceId): void
    {
        $device = Device::query()->where('kind', 'ping')->findOrFail($deviceId);
        $this->deviceId = $device->id;
        $this->ports = SwitchService::manualPorts($device) > 0 ? (string) SwitchService::manualPorts($device) : '';
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $device = Device::query()->where('kind', 'ping')->findOrFail($this->deviceId);
        $value = trim($this->ports);
        if ($value !== '' && (! ctype_digit($value) || (int) $value > 64)) {
            $this->addError('ports', __('Enter the number of ports, 1 to 64.'));

            return;
        }
        $count = $value === '' ? null : (int) $value;
        if ((int) $count !== SwitchService::manualPorts($device)) {
            SwitchService::setPorts($device, $count);
            Audit::device($device, 'settings', __('Ports of the device changed'), ['ports' => $count]);
        }
        // The map redraws from the new data.
        $this->dispatch('devices-changed');
        $this->dispatch('ping-settings-saved');
        $this->dispatch('closeModal');
    }

    public function render()
    {
        $device = Device::query()->where('kind', 'ping')->findOrFail($this->deviceId);

        return view('livewire.manual-ports', ['reads' => SwitchService::reads($device)]);
    }
}
