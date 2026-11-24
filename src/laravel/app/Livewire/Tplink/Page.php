<?php

namespace App\Livewire\Tplink;

use App\Models\Device;
use App\Services\SwitchService;
use App\Support\Audit;
use App\Support\LiveUpdates;
use Livewire\Component;

/**
 * The TP-Link page: switches without SNMP (Easy Smart) read from their web page by the agent of their network. It is offered for the
 * ping-only devices whose MAC address is one of TP-Link (the menu item only when there is one, GenerateMenus); the user name and the
 * password are entered here, the ports are found out (SwitchService).
 */
class Page extends Component
{
    /** The ping-only device (with a TP-Link MAC address) the web login is for. */
    public string $device = '';

    public string $user = '';

    public string $password = '';

    /** The user names of the list being edited, by device id; the passwords typed there (empty keeps the stored one). */
    public array $users = [];

    public array $passwords = [];

    public function mount(): void
    {
        $this->fillUsers();
    }

    private function fillUsers(): void
    {
        $this->users = SwitchService::tplinkDevices()->mapWithKeys(fn (Device $device) => [$device->id => (string) $device->switch_user])->all();
        $this->passwords = [];
    }

    /** Turns the reading on for one of the devices with a TP-Link MAC address. */
    public function add(): void
    {
        $this->resetErrorBag();
        $device = SwitchService::tplinkCandidates()->firstWhere('id', (int) $this->device);
        if ($device === null) {
            $this->addError('device', __('Choose a device'));

            return;
        }
        if (! $this->validLogin($this->user, $this->password, 'user')) {
            return;
        }
        SwitchService::setTpLink($device, trim($this->user), $this->password);
        $this->done($device);
        $this->reset(['device', 'user', 'password']);
    }

    /** Saves the user name (and the password, when one is typed) of a switch of the list. */
    public function save(int $id): void
    {
        $device = SwitchService::tplinkDevices()->firstWhere('id', $id);
        abort_if($device === null, 404);
        $this->resetErrorBag();
        $password = (string) ($this->passwords[$id] ?? '');
        if (! $this->validLogin((string) ($this->users[$id] ?? ''), $password, 'users.'.$id, $password === '')) {
            return;
        }
        SwitchService::setTpLink($device, trim((string) $this->users[$id]), $password === '' ? null : $password);
        $this->done($device);
        $this->passwords[$id] = '';
    }

    /** Stops reading a switch: it stays a ping-only device. */
    public function remove(int $id): void
    {
        $device = SwitchService::tplinkDevices()->firstWhere('id', $id);
        abort_if($device === null, 404);
        SwitchService::setTpLink($device, null, null);
        $this->done($device);
        $this->fillUsers();
    }

    private function done(Device $device): void
    {
        // The password is never in the audit.
        Audit::device($device, 'settings', __('TP-Link settings changed'));
        LiveUpdates::device($device->id, 'settings');
        $this->fillUsers();
    }

    private function validLogin(string $user, string $password, string $field, bool $passwordKept = false): bool
    {
        $user = trim($user);
        if ($user === '' || mb_strlen($user) > 64 || ($password === '' && ! $passwordKept) || mb_strlen($password) > 100 || preg_match('/[\x00-\x1f]/', $password)) {
            $this->addError($field, __('Enter the user name and the password of the switch.'));

            return false;
        }

        return true;
    }

    public function render()
    {
        return view('livewire.tplink.page', [
            'devices' => SwitchService::tplinkDevices()->map(function (Device $device) {
                $ports = SwitchService::ports($device);

                return ['device' => $device, 'ports' => $ports === null ? null : ['up' => collect($ports)->where('link', '>=', 2)->count(), 'total' => count($ports)]];
            }),
            'candidates' => SwitchService::tplinkCandidates(),
        ]);
    }
}
