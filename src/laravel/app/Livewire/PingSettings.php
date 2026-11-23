<?php

namespace App\Livewire;

use App\Models\Device;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/** The address, prefix and MAC of a ping-only device (from the device menu). */
#[AllowInModal]
class PingSettings extends Component
{
    public int $deviceId;

    public string $address = '';

    public $prefix = 24;

    public string $mac = '';

    /** The agent that pings it ('' for one in its network): for a device in another subnet. */
    public string $via = '';

    /** A switch without SNMP whose ports the agent reads (SwitchService): the kind ('' none), the user and the password (blank keeps the stored one). */
    public string $switchType = '';

    public string $switchUser = '';

    public string $switchPassword = '';

    public bool $switchHasPassword = false;

    public function mount(int $deviceId): void
    {
        $device = Device::query()->where('kind', 'ping')->findOrFail($deviceId);
        $this->deviceId = $device->id;
        $this->address = (string) $device->ping_address;
        $this->prefix = $device->ping_prefix ?? 24;
        $this->mac = (string) $device->ping_mac;
        $this->via = (string) ($device->ping_via_id ?? '');
        $this->switchType = (string) $device->switch_type;
        $this->switchUser = (string) $device->switch_user;
        $this->switchHasPassword = (string) $device->switch_password !== '';
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $device = Device::query()->where('kind', 'ping')->findOrFail($this->deviceId);
        $settings = Device::sanitizePingSettings($this->address, $this->prefix, $this->mac);
        if ($settings === null) {
            $this->addError('address', __('Enter an IPv4 address, a prefix of 8–30 and a valid MAC address (or none).'));

            return;
        }
        $via = $this->via === '' ? null : Device::query()->where('kind', 'agent')->find((int) $this->via);
        if ($this->via !== '' && $via === null) {
            $this->addError('via', __('The agent does not exist anymore.'));

            return;
        }
        $settings['ping_via_id'] = $via?->id;
        $switch = $this->switchSettings($device);
        if ($switch === null) {
            return;
        }
        // Another address: the old answers say nothing about it.
        $addressChanged = $settings['ping_address'] !== $device->ping_address;
        if ($addressChanged) {
            $settings += ['last_seen_at' => null];
        }
        $device->forceFill($settings + $switch);
        $switchChanged = array_intersect_key($device->getDirty(), ['switch_type' => 1, 'switch_user' => 1, 'switch_password' => 1]) !== [];
        $changes = array_intersect_key($device->getDirty(), array_flip(['ping_address', 'ping_prefix', 'ping_mac', 'ping_via_id']));
        $device->save();
        if ($addressChanged) {
            \App\Support\PingUsage::forgetCurrent($device->id);
        }
        if ($changes !== [] || $switchChanged) {
            // The password is never in the audit.
            \App\Support\Audit::device($device, 'settings', __('Ping settings changed'), ['changes' => $changes]);
        }
        \App\Support\LiveUpdates::device($device->id, 'settings');

        $this->dispatch('ping-settings-saved');
        $this->dispatch('closeModal');
    }

    /**
     * The settings of the switch (null and an error when they are not valid): none without a kind, the password stays when
     * the field is blank. The password is stored encrypted.
     *
     * @return array<string, ?string>|null
     */
    private function switchSettings(Device $device): ?array
    {
        if ($this->switchType === '') {
            return ['switch_type' => null, 'switch_user' => null, 'switch_password' => null];
        }
        $user = trim($this->switchUser);
        $password = $this->switchPassword !== '' ? $this->switchPassword : null;
        $valid = isset(\App\Services\SwitchService::TYPES[$this->switchType]) && $user !== '' && mb_strlen($user) <= 64
            && ($password !== null || (string) $device->switch_password !== '')
            && ($password === null || (mb_strlen($password) <= 100 && ! preg_match('/[\x00-\x1f]/', $password)));
        if (! $valid) {
            $this->addError('switchUser', __('Enter the user name and the password of the switch.'));

            return null;
        }

        return [
            'switch_type' => $this->switchType,
            'switch_user' => $user,
            'switch_password' => $password !== null ? \App\Services\SwitchService::storePassword($password) : $device->switch_password,
        ];
    }

    public function render()
    {
        return view('livewire.ping-settings', [
            'switchTypes' => \App\Services\SwitchService::TYPES,
            // Agents that can ping (1.10.0+, signing).
            'agents' => Device::query()->where('kind', 'agent')->whereNotNull('public_key')->get()
                ->filter(fn (Device $agent) => version_compare((string) $agent->agent_version, Device::PING_VERSION, '>='))
                ->sortBy(fn (Device $agent) => mb_strtolower($agent->displayName))->values(),
        ]);
    }
}
