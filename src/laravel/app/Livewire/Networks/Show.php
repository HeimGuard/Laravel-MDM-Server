<?php

namespace App\Livewire\Networks;

use App\Models\NetworkNeighbour;
use App\Models\PortScanResult;
use App\Support\NetworkMap;

/**
 * One network of the map (/networks/{anchor}, NetworkMap::cardUrl): its devices with their addresses
 * and MAC addresses, and the unknown devices its agents see. The actions are those of the networks page.
 */
class Show extends Page
{
    /** NetworkMap::anchor() of the network. */
    public string $network;

    public function mount(string $network): void
    {
        $this->network = $network;
        abort_if($this->found() === null, 404);
    }

    /** Not shown as unknown anymore: every unknown device of the network (Mark as known…). */
    public function ignoreAll(): void
    {
        $ids = collect($this->found()['unknown'] ?? [])->pluck('id')->all();
        NetworkNeighbour::query()->whereKey($ids)->update(['ignored_at' => now()]);
        $this->devicesChanged();
    }

    private function found(): ?array
    {
        return collect(NetworkMap::build()['networks'])->firstWhere('anchor', $this->network);
    }

    public function render()
    {
        $network = $this->found();

        return view('livewire.networks.show', [
            'net' => $network,
            'portScanEnabled' => PortScanResult::enabled(),
            'portScans' => $this->portScans(),
        ])->title($network['cidr'] ?? __('Networks'));
    }
}
