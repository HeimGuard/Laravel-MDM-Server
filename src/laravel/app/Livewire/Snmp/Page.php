<?php

namespace App\Livewire\Snmp;

use App\Models\Device;
use App\Services\SwitchService;
use App\Support\Audit;
use App\Support\LiveUpdates;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * The SNMP page: the devices read over SNMP (v1 or v2c) and the form that adds one. Only the address and the community are entered, the
 * agent of the network finds out the rest (the version, what the device is, its ports, the MAC table: SwitchService). A device is a ping-only
 * device with the community in its settings; an existing one is chosen in the form, or a new one is made.
 */
class Page extends Component
{
    /** The ping-only device the community is for: '' makes a new one. */
    public string $device = '';

    public string $name = '';

    public string $address = '';

    public int|string $prefix = 24;

    public string $community = '';

    /** The communities of the list being edited, by device id. */
    public array $communities = [];

    public function mount(): void
    {
        $this->fillCommunities();
    }

    private function fillCommunities(): void
    {
        $this->communities = SwitchService::snmpDevices()->mapWithKeys(fn (Device $device) => [$device->id => (string) SwitchService::password($device)])->all();
    }

    /** Sets SNMP on an existing ping-only device, or on a new one (an address, a name, the prefix of its network). */
    public function add(): void
    {
        $this->resetErrorBag();
        $community = $this->checkedCommunity($this->community, 'community');
        if ($community === null) {
            return;
        }
        if ($this->device !== '') {
            $device = Device::query()->where('kind', 'ping')->findOrFail((int) $this->device);
        } else {
            $this->validate(['name' => 'nullable|string|max:255']);
            // The MAC that network discovery sees at this address is adopted, so the device follows it when its IP changes (DHCP).
            $mac = Device::discoveredMacFor($this->address)['mac'] ?? null;
            $settings = Device::sanitizePingSettings($this->address, $this->prefix, $mac);
            if ($settings === null) {
                $this->addError('address', __('Enter an IPv4 address, a prefix of 8–30 and a valid MAC address (or none).'));

                return;
            }
            $device = new Device;
            $device->forceFill($settings + ['kind' => 'ping', 'name' => trim($this->name) !== '' ? trim($this->name) : $settings['ping_address'], 'os' => '', 'token' => hash('sha256', Str::random(60))]);
            $device->save();
            Audit::device($device, 'create', __('Added ping device :ip', ['ip' => $device->ping_address]), ['ip' => $device->ping_address, 'mac' => $device->ping_mac]);
        }
        SwitchService::setCommunity($device, $community);
        Audit::device($device, 'settings', __('SNMP settings changed'));
        LiveUpdates::device($device->id, 'settings');

        $this->reset(['device', 'name', 'address', 'community']);
        $this->prefix = 24;
        $this->fillCommunities();
    }

    /** Saves the community of a device of the list; empty turns SNMP off for it. */
    public function save(int $id): void
    {
        $device = SwitchService::snmpDevices()->firstWhere('id', $id);
        abort_if($device === null, 404);
        $community = trim((string) ($this->communities[$id] ?? ''));
        if ($community === '') {
            $this->remove($id);

            return;
        }
        $this->resetErrorBag();
        $community = $this->checkedCommunity($community, 'communities.'.$id);
        if ($community !== null && SwitchService::password($device) !== $community) {
            SwitchService::setCommunity($device, $community);
            Audit::device($device, 'settings', __('SNMP settings changed'));
            LiveUpdates::device($device->id, 'settings');
        }
    }

    /** SNMP off for a device: it stays a ping-only device. */
    public function remove(int $id): void
    {
        $device = SwitchService::snmpDevices()->firstWhere('id', $id);
        abort_if($device === null, 404);
        SwitchService::setCommunity($device, null);
        Audit::device($device, 'settings', __('SNMP settings changed'));
        LiveUpdates::device($device->id, 'settings');
        $this->fillCommunities();
    }

    private function checkedCommunity(string $community, string $field): ?string
    {
        $community = trim($community);
        if ($community === '' || mb_strlen($community) > 100 || preg_match('/[\x00-\x1f]/', $community)) {
            $this->addError($field, __('Enter the SNMP community.'));

            return null;
        }

        return $community;
    }

    public function render()
    {
        $devices = SwitchService::snmpDevices();

        return view('livewire.snmp.page', [
            'devices' => $devices->map(function (Device $device) {
                $ports = SwitchService::ports($device);

                return ['device' => $device, 'info' => SwitchService::info($device), 'ports' => $ports === null ? null : ['up' => collect($ports)->where('link', '>=', 2)->count(), 'total' => count($ports)]];
            }),
            // Ping-only devices that can get SNMP: the ones that read nothing yet.
            'candidates' => Device::query()->where('kind', 'ping')->get()->filter(fn (Device $device) => (string) $device->switch_type === '')
                ->sortBy(fn (Device $device) => mb_strtolower($device->displayName))->values(),
        ]);
    }
}
