<?php

namespace App\Livewire;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\Enrolment;
use App\Support\InstallCommands;
use App\Support\SmartAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The devices page: the list of devices (search, tags) beside the detail of the selected one (the
 * first one when none is selected), or the enrolment of a new device.
 */
class ShowDevices extends Component
{
    public $devices;

    #[Url]
    public $selectedDeviceId;

    /** Shows only the devices with this tag. */
    #[Url(except: '')]
    public string $tag = '';

    #[Url(except: '')]
    public string $search = '';

    /* Device Enrolment*/
    public $addDevice = false;

    /** agent (enrolment code, install commands) or ping (a ping-only device). */
    public string $addMode = 'agent';

    public string $pingName = '';

    public string $pingAddress = '';

    public $pingPrefix = 24;

    public string $pingMac = '';
    public $enrollmentCode;
    public $enrollmentCodeExpiration;

    /** Short labels of the smart alerts in the "Attention" column (updates have their own column). */
    private const FLAGS = [
        'restart' => 'Restart',
        'agent' => 'Agent update',
        'disk_space' => 'Disk',
        'disk_health' => 'S.M.A.R.T.',
        'services' => 'Services',
        'scripts' => 'Remediation',
    ];

    public function selectDevice($id)
    {
        $this->selectedDeviceId = $id ?: null;
        $this->addDevice = false;
    }

    public function updatedAddDevice($value)
    {
        if ($value) {
            Enrolment::Where('expire_at', '<', CarbonImmutable::now())->delete();

            $this->enrollmentCode = Enrolment::generateCode();
            $this->enrollmentCodeExpiration = CarbonImmutable::now()->add(15, 'min');

            $enrolment = new Enrolment();
            $enrolment->code = $this->enrollmentCode;
            $enrolment->expire_at = $this->enrollmentCodeExpiration;
            $enrolment->save();
        }
    }

    /** A device without an agent: pinged by an agent in its network, woken through it. */
    public function createPingDevice(): void
    {
        $this->resetErrorBag();
        $this->validate(['pingName' => 'required|string|max:255']);
        // No MAC given: adopt the one network discovery sees at this address, so the device is
        // known and follows its MAC when its IP changes (DHCP).
        if (trim($this->pingMac) === '' && ($found = Device::discoveredMacFor($this->pingAddress))) {
            $this->pingMac = $found['mac'];
        }
        $settings = Device::sanitizePingSettings($this->pingAddress, $this->pingPrefix, $this->pingMac);
        if ($settings === null) {
            $this->addError('pingAddress', __('Enter an IPv4 address, a prefix of 8–30 and a valid MAC address (or none).'));

            return;
        }

        $device = new Device();
        $device->forceFill($settings + ['kind' => 'ping', 'name' => $this->pingName, 'os' => '', 'token' => hash('sha256', \Illuminate\Support\Str::random(60))]);
        $device->save();
        \App\Support\Audit::device($device, 'create', __('Added ping device :ip', ['ip' => $device->ping_address]), ['ip' => $device->ping_address, 'mac' => $device->ping_mac]);

        $this->reset(['pingName', 'pingAddress', 'pingMac', 'addMode']);
        $this->pingPrefix = 24;
        $this->loadDevices();
        $this->selectDevice($device->id);
    }

    #[On('device-deleted')]
    public function deviceDeleted()
    {
        $this->loadDevices();
        $this->selectFirstDevice();
    }

    #[On('device-tags-changed')]
    public function loadDevices(): void
    {
        $this->devices = Device::all();
    }

    public function filterTag(string $tag): void
    {
        $this->tag = $this->tag === $tag ? '' : $tag;
    }

    public function mount()
    {
        $this->loadDevices();
        $this->selectFirstDevice();
    }

    /** Opens the first device of the list when none (or a deleted one) is selected. */
    private function selectFirstDevice(): void
    {
        if (! $this->devices->contains('id', (int) $this->selectedDeviceId)) {
            $this->selectedDeviceId = $this->devices->sortBy(fn (Device $device) => self::listOrder($device))->first()?->id;
        }
    }

    /**
     * One item of the device list: the device, what needs attention and what the search finds.
     */
    private function row(Device $device, Collection $firing): array
    {
        $alerts = empty($device->data) ? [] : SmartAlerts::for($device);
        $flags = [];
        foreach ($alerts as $alert) {
            $key = str_starts_with($alert['key'], 'failed:') ? 'failed' : $alert['key'];
            if ($key === 'failed') {
                $flags[] = ['label' => __('Failed command'), 'severity' => 'danger', 'title' => $alert['title']];
            } elseif (isset(self::FLAGS[$key])) {
                $label = $key === 'disk_space' ? __('Disk :percent %', ['percent' => collect($device->drives)->max('PercentUsed')]) : __(self::FLAGS[$key]);
                $flags[] = ['label' => $label, 'severity' => $alert['severity'] === 'secondary' ? 'info' : $alert['severity'], 'title' => $alert['title'].($alert['message'] ? ': '.$alert['message'] : '')];
            }
        }
        foreach ($firing->get($device->id, collect()) as $event) {
            $flags[] = ['label' => __('Alert: :rule', ['rule' => $event->rule->label]), 'severity' => 'danger', 'title' => $event->message];
        }
        $flags = collect($flags)->unique('label')->sortBy(fn ($flag) => SmartAlerts::SEVERITIES[$flag['severity']] ?? 9)->values()->all();

        $addresses = $device->isPingOnly ? $device->ping_address : collect($device->networks)->where('Connected', true)->whereIn('Type', ['lan', 'wifi', 'bridge'])->pluck('IPAddresses')->flatten()
            ->first(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));

        return [
            'device' => $device,
            'flags' => $flags,
            'attention' => $device->offline || collect($flags)->contains(fn ($flag) => in_array($flag['severity'], ['danger', 'warning'], true)),
            'ip' => $addresses,
            'searchText' => mb_strtolower(implode(' ', [$device->displayName, $device->name, $device->os, $addresses, implode(' ', $device->publicAddresses), implode(' ', $device->tagList)])),
        ];
    }

    /** Live update (resources/js/live.js): some device changed. */
    #[On('devices-changed')]
    public function devicesChanged(): void {}

    /** Devices with the agent first, then the ping-only ones, each by name. */
    private static function listOrder(Device $device): string
    {
        return ($device->isPingOnly ? '1' : '0').mb_strtolower($device->displayName);
    }

    public function render()
    {
        $selectedDevice = $this->selectedDeviceId ? Device::find($this->selectedDeviceId) : null;
        $view = ['selectedDevice' => $selectedDevice, 'tags' => Device::allTags()];

        if ($this->addDevice) {
            return view('livewire.show-devices', $view + [
                'installCommands' => $this->enrollmentCode ? InstallCommands::for($this->enrollmentCode) : [],
            ]);
        }

        // The user's open alerts per device (the attention mark in the list).
        $firing = AlertEvent::query()->with('rule')->whereNull('resolved_at')
            ->whereIn('alert_rule_id', AlertRule::query()->where('user_id', auth()->id())->select('id'))
            ->get()->groupBy('device_id');

        $rows = $this->devices->sortBy(fn (Device $device) => self::listOrder($device))->map(fn (Device $device) => $this->row($device, $firing))->values();
        $search = mb_strtolower(trim($this->search));

        return view('livewire.show-devices', $view + [
            'rows' => $rows->filter(fn (array $row) => ($this->tag === '' || $row['device']->hasTag($this->tag))
                && ($search === '' || str_contains($row['searchText'], $search)))->values(),
            'total' => $rows->count(),
        ]);
    }
}
