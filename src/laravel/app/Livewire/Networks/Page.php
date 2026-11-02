<?php

namespace App\Livewire\Networks;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\NetworkNeighbour;
use App\Models\PortScanResult;
use App\Support\NetworkMap;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The networks of the fleet as a map (top down: internet, public addresses, gateways, networks,
 * devices) and a list, with the devices the agents see that the portal does not know (add as
 * ping-only, ignore) and scans of a network. Kept current over Reverb: a change of any device
 * redraws the map.
 */
class Page extends Component
{
    /** What the page shows: the map (filling the window) or the list of the networks. */
    #[Url(except: 'map')]
    public string $view = 'map';

    public function updatedView(): void
    {
        $this->view = $this->view === 'list' ? 'list' : 'map';
    }
    /** Live update (resources/js/live.js): devices changed; the map gets the new data. */
    #[On('devices-changed')]
    public function devicesChanged(): void
    {
        $this->dispatch('network-map-updated', map: NetworkMap::build());
    }

    /** Asks an agent in the network that allows scans to scan it (its result comes with its next report). */
    public function scan(string $network): void
    {
        $found = collect(NetworkMap::build()['networks'])->firstWhere('id', $network);
        $scan = $found['scan'] ?? null;
        $agent = $scan && $scan['agent'] ? Device::find($scan['agent']) : null;
        if ($agent === null) {
            $this->addError('scan.'.($found['anchor'] ?? ''), $scan['refusal'] ?? __('The network is not known.'));

            return;
        }
        if ($agent->issueCommand('scanNetwork', ['cidr' => $found['cidr']], auth()->user()) === null) {
            $this->addError('scan.'.$found['anchor'], $agent->commandRefusal('scanNetwork', ['cidr' => $found['cidr']]) ?? __('Already on its way.'));
        }
    }

    /**
     * Scans the open ports of an unknown device: any online agent whose network contains it (with
     * port_scan on) connects to the ports and reports what each service returns on its own.
     */
    public function scanPorts(int $id, bool $all = false): void
    {
        $neighbour = NetworkNeighbour::query()->whereNull('ignored_at')->findOrFail($id);
        $agent = Device::portScannerFor($neighbour->ip);
        if ($agent === null) {
            $this->addError('portscan.'.$id, __('No agent reported this device.'));

            return;
        }
        // all: every port (1-65535); otherwise the agent's default common ports.
        $params = ['ip' => $neighbour->ip] + ($all ? ['all' => true] : []);
        if ($agent->issueCommand('scanPorts', $params, auth()->user()) === null) {
            $this->addError('portscan.'.$id, $agent->commandRefusal('scanPorts', $params) ?? __('Already on its way.'));
        }
    }

    /** Port scanning of the whole portal: off, no agent scans ports whatever its config.json allows (system admins). */
    public function togglePortScan(): void
    {
        Gate::authorize('is-system-admin');
        PortScanResult::setEnabled(! PortScanResult::enabled());
    }

    /** An unknown device as a ping-only device: pinged by an agent of its network, its MAC followed. */
    public function add(int $id)
    {
        $neighbour = NetworkNeighbour::query()->whereNull('ignored_at')->findOrFail($id);
        $device = new Device();
        $device->forceFill([
            'kind' => 'ping',
            'name' => $neighbour->hostname ? Str::before($neighbour->hostname, '.') : $neighbour->ip,
            'os' => '',
            'token' => hash('sha256', Str::random(60)),
            'ping_address' => $neighbour->ip,
            'ping_prefix' => (int) explode('/', $neighbour->network)[1],
            'ping_mac' => $neighbour->mac,
        ]);
        $device->save();
        \App\Support\Audit::device($device, 'create', __('Added from the network map: :ip', ['ip' => $neighbour->ip]), ['ip' => $neighbour->ip, 'mac' => $neighbour->mac]);

        return $this->redirect(route('devices', ['selectedDeviceId' => $device->id]));
    }

    /** Not shown as unknown anymore (and no alerts about it). */
    public function ignore(int $id): void
    {
        NetworkNeighbour::query()->whereKey($id)->update(['ignored_at' => now()]);
        $this->devicesChanged();
    }

    public function restore(int $id): void
    {
        NetworkNeighbour::query()->whereKey($id)->update(['ignored_at' => null]);
        $this->devicesChanged();
    }

    /** Network discovery of the whole portal: off, the agents' neighbours are not taken (system admins). */
    public function toggleDiscovery(): void
    {
        Gate::authorize('is-system-admin');
        NetworkNeighbour::setEnabled(! NetworkNeighbour::enabled());
    }

    public function render()
    {
        $portScanEnabled = PortScanResult::enabled();
        $unknown = NetworkNeighbour::unknown()->keyBy('id');
        // Per unknown device: its latest port scan, an active one, and whether a scan can be sent.
        $results = PortScanResult::query()
            ->whereIn('site', $unknown->pluck('site')->unique()->all())
            ->whereIn('ip', $unknown->pluck('ip')->unique()->all())
            ->get()->keyBy(fn (PortScanResult $row) => $row->site.'|'.$row->ip);
        $active = $unknown->isEmpty() ? collect() : DeviceCommand::query()->where('command', 'scanPorts')->active()
            ->whereIn('target', $unknown->map(fn (NetworkNeighbour $n) => 'ports:'.$n->ip)->unique()->values()->all())
            ->get()->keyBy('target');
        // The scan can go to any online agent whose network contains the address, not only the one
        // that last reported it; the candidate agents are gathered once for all unknown devices.
        $agents = Device::all()->reject(fn (Device $device) => $device->isPingOnly);
        $portScans = $unknown->map(function (NetworkNeighbour $n) use ($results, $active, $portScanEnabled, $agents) {
            $scanner = Device::portScannerFor($n->ip, null, $agents);

            return [
                'result' => $results[$n->site.'|'.$n->ip] ?? null,
                'command' => $active['ports:'.$n->ip] ?? null,
                'agent' => $scanner !== null,
                'refusal' => ! $portScanEnabled ? __('Port scanning is turned off in the portal')
                    : __('No online agent in its network can scan it.'),
            ];
        });

        return view('livewire.networks.page', [
            'map' => NetworkMap::build(),
            'discovery' => NetworkNeighbour::enabled(),
            'portScanEnabled' => $portScanEnabled,
            'portScans' => $portScans,
            'ignored' => NetworkNeighbour::query()->whereNotNull('ignored_at')->latest('last_seen_at')->limit(50)->get(),
        ])->title(__('Networks'));
    }
}
