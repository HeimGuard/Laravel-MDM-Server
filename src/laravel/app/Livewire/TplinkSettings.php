<?php

namespace App\Livewire;

use App\Models\Device;
use App\Services\SwitchService;
use App\Support\Audit;
use App\Support\LiveUpdates;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/**
 * The web login of a TP-Link Easy Smart switch (no SNMP; from the device menu, next to the Ping settings): offered only for a ping-only device
 * whose MAC address is one of TP-Link, or one that is read this way already. The ports are found out by the agent of its network (SwitchService).
 */
#[AllowInModal]
class TplinkSettings extends Component
{
    public int $deviceId;

    public string $user = '';

    public string $password = '';

    public bool $hasPassword = false;

    public function mount(int $deviceId): void
    {
        $device = $this->device($deviceId);
        $this->deviceId = $device->id;
        $reading = (string) $device->switch_type === SwitchService::TPLINK;
        $this->user = $reading ? (string) $device->switch_user : '';
        $this->hasPassword = $reading && (string) $device->switch_password !== '';
    }

    /** The ping-only device when it is a TP-Link one (its MAC address) or is read this way already, otherwise 404. */
    private function device(int $id): Device
    {
        $device = Device::query()->where('kind', 'ping')->findOrFail($id);
        abort_unless(SwitchService::isTpLink($device) || (string) $device->switch_type === SwitchService::TPLINK, 404);

        return $device;
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $device = $this->device($this->deviceId);
        $reading = (string) $device->switch_type === SwitchService::TPLINK;
        $user = trim($this->user);
        if ($user === '') {
            // No user name: not read any more (only what this set; SNMP stays).
            if ($reading) {
                SwitchService::setTpLink($device, null, null);
                $this->changed($device);
            }
            $this->dispatch('closeModal');

            return;
        }
        $password = $this->password !== '' ? $this->password : null;
        if (mb_strlen($user) > 64 || ($password === null && ! ($reading && $this->hasPassword)) || ($password !== null && (mb_strlen($password) > 100 || preg_match('/[\x00-\x1f]/', $password)))) {
            $this->addError('user', __('Enter the user name and the password of the switch.'));

            return;
        }
        SwitchService::setTpLink($device, $user, $password);
        $this->changed($device);
        $this->dispatch('closeModal');
    }

    private function changed(Device $device): void
    {
        // The password is never in the audit.
        Audit::device($device, 'settings', __('TP-Link settings changed'));
        LiveUpdates::device($device->id, 'settings');
        $this->dispatch('ping-settings-saved');
    }

    public function render()
    {
        return view('livewire.tplink-settings', [
            'replaces' => (string) Device::query()->find($this->deviceId)?->switch_type === SwitchService::SNMP,
        ]);
    }
}
