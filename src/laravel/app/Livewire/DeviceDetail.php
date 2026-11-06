<?php

namespace App\Livewire;

use App\Models\Device;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class DeviceDetail extends Component
{
    public $selectedDeviceId;

    /** Selected tab, kept in the URL; the view falls back to the first tab the device has. */
    #[Url(except: 'overview')]
    public string $tab = 'overview';

    /**
     * False for the first render: only the shown tab is built, the page asks for the others right
     * after it appeared (wire:init), so opening a device is as fast as its first tab.
     */
    public bool $tabsLoaded = false;

    /* Edit Mode*/
    public $editMode = false;
    public $friendlyName = "";

    public bool $editTags = false;


    public string $tagsText = '';

    public function saveFriendlyName()
    {
        $device = Device::find($this->selectedDeviceId);
        $before = $device->displayName;
        $device->friendly_name = $this->friendlyName;
        $device->save();
        if ($device->displayName !== $before) {
            \App\Support\Audit::device($device, 'rename', __('Renamed :from to :to', ['from' => $before, 'to' => $device->displayName]), ['from' => $before, 'to' => $device->displayName]);
        }
        $this->editMode = false;
        \App\Support\LiveUpdates::device($device->id, 'settings');
    }

    public function mount()
    {
        $this->friendlyName = Device::find($this->selectedDeviceId)->DisplayName;
    }

    /** Rename from the device menu. */
    #[On('rename-device')]
    public function rename(): void
    {
        $this->editMode = true;
    }

    /** Tags from the device menu or the tag row. */
    #[On('edit-device-tags')]
    public function startEditTags(): void
    {
        $this->tagsText = implode(', ', Device::find($this->selectedDeviceId)?->tagList ?? []);
        $this->editTags = true;
    }

    public function saveTags(): void
    {
        $device = Device::find($this->selectedDeviceId);
        if ($device === null) {
            return;
        }
        $tags = Device::normalizeTags($this->tagsText);
        $before = $device->tagList;
        $device->tags = $tags === [] ? null : $tags;
        $device->save();
        if ($device->tagList !== $before) {
            \App\Support\Audit::device($device, 'tags', __('Tags: :tags', ['tags' => implode(', ', $device->tagList) ?: '—']), ['from' => $before, 'to' => $device->tagList]);
        }
        $this->editTags = false;
        \App\Support\LiveUpdates::device($device->id, 'settings');
        // The device list shows the tags and filters by them.
        $this->dispatch('device-tags-changed');
    }

    /**
     * Installs one update of the list ('os', 'app' or 'module'). The row is looked up again on the
     * server by its index and checked against the id the page showed, so a list that changed
     * meanwhile does not install something else.
     */
    public function installUpdate(string $section, int $index, string $id): void
    {
        $device = Device::find($this->selectedDeviceId);
        $rows = match ($section) {
            'os' => $device?->updates,
            'app' => $device?->apps_packages_updates,
            'module' => $device?->moduleUpdates,
            default => null,
        } ?? [];
        $target = isset($rows[$index]) ? $device->updateTarget($section, $rows[$index]) : null;
        if ($target === null || $target['id'] !== $id) {
            $this->addError('update', __('The list of updates changed, try again.'));

            return;
        }
        if (! $device->issueCommand('installUpdate', $target, auth()->user())) {
            $this->addError('update', $device->commandRefusal('installUpdate', $target) ?? __('Already on its way.'));
        }
    }

    public function installAll(): void
    {
        $device = Device::find($this->selectedDeviceId);
        if ($device && ! $device->issueCommand('doUpdates', [], auth()->user())) {
            $this->addError('update', $device->commandRefusal('doUpdates') ?? __('Already on its way.'));
        }
    }

    /** The agent registers a key again with its next request (trust on first use). */
    public function resetDeviceKey()
    {
        Gate::authorize('is-system-admin');

        Device::query()->whereKey($this->selectedDeviceId)->update(['public_key' => null, 'key_registered_at' => null]);
        if ($device = Device::find($this->selectedDeviceId)) {
            \App\Support\Audit::device($device, 'reset-key', __('Device key reset'));
        }
    }

    /** The other tabs, in the background after the first one is shown. */
    public function loadTabs(): void
    {
        $this->tabsLoaded = true;
    }

    /** Live update (resources/js/live.js): this device changed. */
    #[On('device-changed.{selectedDeviceId}')]
    public function deviceChanged(): void {}

    public function render()
    {
        $device = Device::find($this->selectedDeviceId);
        // What only a tab needs is not built before that tab is (or all of them are) rendered.
        $needs = fn (string $tab) => $this->tabsLoaded || $this->tab === $tab;

        return view('livewire.device-detail', [
            'selectedDevice' => $device,
            'activeCommands' => $device->activeCommands(),
            // The state of each container hour by hour for the last 12 hours (the bar in the table of the containers).
            'containerBeats' => $device->docker !== null && $needs('docker') ? \App\Services\InventoryTimelineService::beats($device, 'container') : [],
            // The audit log of the device: its commands (and the wakes / pings other agents did for
            // it), the scans of its ports by another agent, and what was changed on it.
            'history' => $needs('history') ? \App\Support\Audit::history($device, array_filter([($address = $device->portScanAddress()) ? 'ports:'.$address : null])) : collect(),
        ]);
    }
}
