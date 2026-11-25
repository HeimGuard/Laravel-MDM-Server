<?php

namespace App\Livewire\Networks;

use App\Models\Device;
use App\Services\SwitchService;
use App\Support\Audit;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/** The devices plugged into a port of a switch (from the card of the switch on the network map): the ones of its network without a port. */
#[AllowInModal]
class SwitchPort extends Component
{
    public int $deviceId;

    public int $port;

    /** @var array<int, int|string> the ids of the devices on the port */
    public array $selected = [];

    /** @var array<int|string, int|string|null> the port of each device the cable goes into (optional): device id => port */
    public array $remote = [];

    public function mount(int $deviceId, int $port): void
    {
        $switch = $this->switch($deviceId);
        $this->deviceId = $switch->id;
        $this->port = max(1, min(64, $port));
        $on = array_filter(SwitchService::candidates($switch, $this->port), fn (array $candidate) => $candidate['selected']);
        $this->selected = array_column($on, 'id');
        $this->remote = array_column($on, 'remote', 'id');
    }

    private function switch(int $id): Device
    {
        $switch = Device::query()->where('kind', 'ping')->findOrFail($id);
        abort_unless(SwitchService::enabled($switch), 404);

        return $switch;
    }

    /** A port of a device is clicked as the one its cable goes into: a second click takes it back (it is optional). */
    public function pickRemote(int $deviceId, int $port): void
    {
        $this->remote[$deviceId] = (int) ($this->remote[$deviceId] ?? 0) === $port ? null : $port;
    }

    public function save(): void
    {
        $switch = $this->switch($this->deviceId);
        $changed = SwitchService::assign($switch, $this->port, $this->selected, $this->remote);
        if ($changed > 0) {
            Audit::device($switch, 'settings', __('Devices on a port of the switch changed'), ['port' => $this->port, 'devices' => array_map('intval', $this->selected), 'remote_ports' => array_filter(array_map('intval', array_intersect_key($this->remote, array_flip($this->selected))))]);
        }
        // The map redraws from the new data.
        $this->dispatch('devices-changed');
        $this->dispatch('closeModal');
    }

    public function render()
    {
        $switch = $this->switch($this->deviceId);

        return view('livewire.networks.switch-port', [
            'switch' => $switch,
            'candidates' => SwitchService::candidates($switch, $this->port),
        ]);
    }
}
