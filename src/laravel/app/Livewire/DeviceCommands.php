<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\DeviceCommand;
use Livewire\Attributes\On;
use Livewire\Component;

/** The device's actions (restart, turn off, install updates ...) and the commands on their way. */
class DeviceCommands extends Component
{
    public $selectedDeviceId;

    /** Commands the toolbar offers; the others come from the alerts and the update list. */
    public const TOOLBAR = ['restart', 'turnOff', 'doUpdates', 'sync'];

    public function sendCommandToDevice($command)
    {
        $command = (string) $command;
        $device = Device::find($this->selectedDeviceId);
        if ($device === null || ! in_array($command, self::TOOLBAR, true)) {
            return;
        }
        if (! $device->issueCommand($command, [], auth()->user())) {
            $this->addError('command', $device->commandRefusal($command) ?? __('Already on its way.'));
        }
    }

    /** Sync of a ping-only device: the agent that pings it pings it now. */
    public function pingNow()
    {
        $device = Device::find($this->selectedDeviceId);
        if ($device && ! $device->pingNow(auth()->user())) {
            $this->addError('command', $device->pingNowRefusal() ?? __('Already on its way.'));
        }
    }

    /** Scans one of the device's own networks (network_discovery "scan" on the device). */
    public function scan(string $network)
    {
        $device = Device::find($this->selectedDeviceId);
        if ($device === null || ! in_array($network, $device->scannableNetworks, true)) {
            return;
        }
        if (! $device->issueCommand('scanNetwork', ['cidr' => $network], auth()->user())) {
            $this->addError('command', $device->commandRefusal('scanNetwork', ['cidr' => $network]) ?? __('Already on its way.'));
        }
    }

    /**
     * Scans this host's own ports from an agent that can reach it (the agent that pings it, or one in
     * its network). Also from the Open ports card of a ping-only device (PingMonitor).
     */
    #[On('scan-ports')]
    public function scanPorts(bool $all = false)
    {
        $device = Device::find($this->selectedDeviceId);
        $address = $device?->portScanAddress();
        $scanner = $device?->portScanner();
        if ($device === null || $address === null || $scanner === null) {
            return;
        }
        $params = ['ip' => $address] + ($all ? ['all' => true] : []);
        if ($scanner->issueCommand('scanPorts', $params, auth()->user()) === null) {
            $this->addError('command', $scanner->commandRefusal('scanPorts', $params) ?? __('Already on its way.'));
        }
    }

        /** Wake-on-LAN: another agent in the same network sends the magic packet. */
    public function wake()
    {
        $device = Device::find($this->selectedDeviceId);
        if ($device && ! $device->wake(auth()->user())) {
            $this->addError('command', $device->wakeRefusal() ?? __('Already on its way.'));
        }
    }

    /**
     * Cancels a command not taken yet, or gives up one the device took (a hanging update): it no
     * longer blocks a new one (Restart, Install updates again). The device may still be working
     * on it; what it reports later is ignored.
     */
    public function cancel(int $commandId)
    {
        // Its own commands, the wakes / pings another agent does for it, and a port scan an agent
        // runs for this host's address.
        $device = Device::find($this->selectedDeviceId);
        $targets = ['device:'.$this->selectedDeviceId, 'ping:'.$this->selectedDeviceId];
        if ($device !== null && ($address = $device->portScanAddress()) !== null) {
            $targets[] = 'ports:'.$address;
        }
        $command = DeviceCommand::query()->whereKey($commandId)->active()
            ->where(fn ($query) => $query->where('device_id', $this->selectedDeviceId)->orWhereIn('target', $targets))
            ->first();
        if ($command === null) {
            return;
        }
        $user = auth()->user()?->name ?? '?';
        DeviceCommand::query()->whereKey($command->id)->active()->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'message' => $command->status === 'queued' ? __('Cancelled by :user', ['user' => $user]) : __('Given up by :user', ['user' => $user]),
        ]);
        \App\Support\Audit::command($command, 'cancel', auth()->user());
        DeviceCommand::announce($command->device_id, $command->target);
    }

    public function deleteDevice()
    {
        if ($device = Device::find($this->selectedDeviceId)) {
            \App\Support\Audit::device($device, 'delete', __('Deleted device :device', ['device' => $device->displayName]));
            $device->delete();
        }
        \App\Support\LiveUpdates::device((int) $this->selectedDeviceId, 'deleted');

        $this->dispatch('device-deleted');
    }

    /** Live update (resources/js/live.js): this device changed. */
    #[On('device-changed.{selectedDeviceId}')]
    public function deviceChanged(): void {}

    public function render()
    {
        $device = Device::find($this->selectedDeviceId);
        if ($device === null) {
            // Deleted meanwhile, the page selects another device.
            return '<div></div>';
        }
        $active = $device->activeCommands();
        $recentWake = $device->offline ? $device->recentWake() : null;
        $recentPing = $device->isPingOnly ? $device->recentPingNow() : null;

        // Port scan of this host (a scanner of null hides the menu action).
        $portScan = $device->portScanState();

        // With the device's own commands: a wake or ping another agent does for it, a wake an agent
        // before 1.13.2 finished on its side while the device is still waited for, and a port scan
        // another agent is running for this host's address (shown as a task with a progress bar).
        $onItsWay = $active->concat(array_filter([
            $recentWake && ($recentWake->active || $recentWake->status === 'succeeded') ? $recentWake : null,
            $recentPing?->active ? $recentPing : null,
            $portScan['command'] ?? null,
        ]));

        return view('livewire.device-commands', [
            'selectedDevice' => $device,
            'active' => $active,
            'onItsWay' => $onItsWay,
            'portScan' => $portScan,
            'wakeRefusal' => $device->wakeRefusal(),
            'wakeRelay' => $device->offline ? $device->wakeRelay()[0] ?? null : null,
            'recentWake' => $recentWake,
            'pingNowRefusal' => $device->isPingOnly ? $device->pingNowRefusal() : null,
            'recentPing' => $recentPing,
            'pendingUpdates' => count($device->installableUpdates) + count($device->apps_packages_updates) + count($device->moduleUpdates),
        ]);
    }
}
