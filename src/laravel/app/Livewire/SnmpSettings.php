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

    /** The interfaces of the device that are no ports: names, `*` is any characters, separated by commas, spaces or lines (SwitchService::parseSnmp). */
    public string $hidden = '';

    public function mount(int $deviceId): void
    {
        $device = Device::query()->where('kind', 'ping')->findOrFail($deviceId);
        $this->deviceId = $device->id;
        // The community is shown (it is read only, every signed-in user is a trusted operator).
        $this->community = (string) $device->switch_type === SwitchService::SNMP ? (string) SwitchService::password($device) : '';
        $this->hidden = (string) $device->switch_hidden;
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
        $hidden = trim((string) preg_replace('/[ \t]*[\r\n,;]+[ \t]*/', ', ', trim($this->hidden)));
        if (mb_strlen($hidden) > 300 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $hidden)) {
            $this->addError('hidden', __('Enter the names of the interfaces separated by commas.'));

            return;
        }
        if ($hidden !== (string) $device->switch_hidden) {
            // The next reading takes the ports without them (their history stays).
            $device->forceFill(['switch_hidden' => $hidden === '' ? null : $hidden])->save();
            Audit::device($device, 'settings', __('SNMP settings changed'));
            LiveUpdates::device($device->id, 'settings');
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
            // What it reports as ports now (the name and the number of each): to see what to ignore.
            'ports' => (string) $device->switch_type === SwitchService::SNMP ? (SwitchService::ports($device) ?? []) : [],
        ]);
    }
}
