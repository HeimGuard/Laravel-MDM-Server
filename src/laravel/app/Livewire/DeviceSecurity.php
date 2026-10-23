<?php

namespace App\Livewire;

use App\Livewire\Concerns\RemediatesFindings;
use App\Models\Device;
use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Models\SecurityInventory;
use App\Support\SecurityExport;
use Livewire\Attributes\On;
use Livewire\Component;

/** The Security tab of a device: the scanner's findings and the security inventory they come from. */
class DeviceSecurity extends Component
{
    use RemediatesFindings;

    public int $deviceId;

    public function acknowledge(int $id): void
    {
        $this->finding($id)?->acknowledge(auth()->user());
    }

    public function reopen(int $id): void
    {
        $this->finding($id)?->reopen();
    }

    /** The open findings of the device as a Markdown report. */
    public function exportMarkdown()
    {
        $device = Device::findOrFail($this->deviceId);
        $findings = $device->securityFindings()->with(['rule', 'device', 'acknowledger'])->open()->bySeverity()->get();

        return response()->streamDownload(
            fn () => print SecurityExport::markdown($findings, 'Security findings of '.$device->displayName),
            'security-findings-'.str($device->displayName)->slug().'-'.now()->format('Y-m-d').'.md',
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }

    /** The security inventory of the device (software, ports, settings, ...) and its findings as JSON for an AI to analyse. */
    public function exportInventory()
    {
        $device = Device::findOrFail($this->deviceId);
        $data = SecurityExport::inventory($device, SecurityInventory::query()->where('device_id', $device->id)->first());

        return response()->streamDownload(
            fn () => print json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'security-inventory-'.str($device->displayName)->slug().'-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    protected function findingForRemediation(int $id): SecurityFinding
    {
        return SecurityFinding::query()->with(['rule', 'device'])->where('device_id', $this->deviceId)->findOrFail($id);
    }

    private function finding(int $id): ?SecurityFinding
    {
        return SecurityFinding::query()->with('rule')->where('device_id', $this->deviceId)->find($id);
    }

    /** Live update (resources/js/live.js): this device changed. */
    #[On('device-changed.{deviceId}')]
    public function deviceChanged(): void {}

    public function render()
    {
        $device = Device::findOrFail($this->deviceId);
        $inventory = SecurityInventory::query()->where('device_id', $device->id)->first();
        $open = $device->securityFindings()->with(['rule', 'acknowledger'])->open()->bySeverity()->get();

        return view('livewire.device-security', [
            'device' => $device,
            'inventory' => $inventory,
            'findings' => $open->whereNull('acknowledged_at')->values(),
            'acknowledged' => $open->whereNotNull('acknowledged_at')->values(),
            'events' => SecurityEvent::query()->where('device_id', $device->id)->latest('occurred_at')->limit(20)->get(),
        ]);
    }
}
