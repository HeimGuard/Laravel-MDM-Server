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

    /** The names of the interfaces ticked as ports (the ones it has read: SwitchService::interfaces). */
    public array $include = [];

    public function mount(int $deviceId): void
    {
        $device = Device::query()->where('kind', 'ping')->findOrFail($deviceId);
        $this->deviceId = $device->id;
        // The community is shown (it is read only, every signed-in user is a trusted operator).
        $this->community = (string) $device->switch_type === SwitchService::SNMP ? (string) SwitchService::password($device) : '';
        $this->include = collect(SwitchService::interfaces($device))->where('is_port', true)->pluck('name')->filter()->values()->all();
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
        $this->saveInterfaces($device);
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

    /**
     * What the user ticked against the default of each interface it has read: the ones that are ports by default and are not ticked are switched off, the
     * others that are ticked on (names; nothing changes before the first reading).
     */
    private function saveInterfaces(Device $device): void
    {
        $interfaces = SwitchService::interfaces($device);
        if ($interfaces === []) {
            return;
        }
        $off = [];
        $on = [];
        foreach ($interfaces as $row) {
            $name = $row['name'];
            if ($name === null) {
                continue;
            }
            $ticked = in_array($name, $this->include, true);
            // A name as a pattern of its own (a space is any character, the characters of the patterns are escaped).
            $pattern = str_replace(' ', '?', addcslashes($name, '*?[]\\'));
            if ($row['port_by_default'] && ! $ticked) {
                $off[] = $pattern;
            } elseif (! $row['port_by_default'] && $ticked) {
                $on[] = $pattern;
            }
        }
        $changes = ['switch_hidden' => $off === [] ? null : implode(', ', $off), 'switch_shown' => $on === [] ? null : implode(', ', $on)];
        if ($changes['switch_hidden'] !== ((string) $device->switch_hidden === '' ? null : $device->switch_hidden) || $changes['switch_shown'] !== ((string) $device->switch_shown === '' ? null : $device->switch_shown)) {
            // The next reading takes the ports with them (the history of the ones that go stays).
            $device->forceFill($changes)->save();
            Audit::device($device, 'settings', __('SNMP settings changed'));
            LiveUpdates::device($device->id, 'settings');
        }
    }

    public function render()
    {
        $device = Device::query()->where('kind', 'ping')->findOrFail($this->deviceId);

        return view('livewire.snmp-settings', [
            'info' => (string) $device->switch_type === SwitchService::SNMP ? SwitchService::info($device) : null,
            'replaces' => (string) $device->switch_type === SwitchService::TPLINK,
            // The Ethernet interfaces it has read, each ticked when it is a port.
            'interfaces' => (string) $device->switch_type === SwitchService::SNMP ? SwitchService::interfaces($device) : [],
        ]);
    }
}
