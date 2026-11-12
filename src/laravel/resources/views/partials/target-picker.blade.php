{{-- Which devices: all, the ones with any of the tags, or picked ones. Binds targetMode,
     targetTags and targetDevices of the Livewire component (App\Livewire\Concerns\PicksTarget).
     $agentsOnly leaves out the ping-only devices (scripts need the agent). --}}
@php
    $agentsOnly ??= false;
    $pickerTags = \App\Models\Device::allTags($agentsOnly);
    $pickerDevices = $targetMode === 'devices' ? \App\Models\Device::query()->when($agentsOnly, fn ($query) => $query->where('kind', 'agent'))->get()->sortBy(fn ($device) => mb_strtolower($device->displayName)) : collect();
    $pickerId = 'target-'.$this->getId();
@endphp
<div>
    <div class="btn-group btn-group-sm mb-2" role="group" aria-label="{{ __('Devices') }}">
        @foreach (['all' => [__('All devices'), 'fas fa-desktop'], 'tags' => [__('Tags'), 'fas fa-tag'], 'devices' => [__('Pick devices'), 'fas fa-check-square']] as $mode => [$label, $icon])
            <input autocomplete="off" class="btn-check" id="{{ $pickerId }}-{{ $mode }}" type="radio" value="{{ $mode }}" wire:model.live="targetMode">
            <label class="btn btn-outline-secondary" for="{{ $pickerId }}-{{ $mode }}"><i class="{{ $icon }} me-1"></i>{{ $label }}</label>
        @endforeach
    </div>

    @if ($targetMode === 'all')
        <div class="small text-muted">{{ __('Every device, also the ones enrolled later.') }}</div>
    @elseif ($targetMode === 'tags')
        @if ($pickerTags === [])
            <div class="small text-muted">{{ __('No tags yet. Add them to devices in the device menu (Edit tags).') }}</div>
        @else
            <div class="mdm-tags">
                @foreach ($pickerTags as $pickerTag)
                    <x-tag as="label" :tag="$pickerTag" :active="in_array($pickerTag, $targetTags, true)" wire:key="target-tag-{{ $loop->index }}">
                        <input class="d-none" type="checkbox" value="{{ $pickerTag }}" wire:model.live="targetTags">
                    </x-tag>
                @endforeach
            </div>
            <div class="small text-muted mt-1">{{ __('Devices with any of the chosen tags, also the ones tagged later.') }}</div>
        @endif
    @else
        <div class="list-group" style="max-height: 14rem; overflow-y: auto;">
            @forelse ($pickerDevices as $pickerDevice)
                <label class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-1 small" wire:key="target-device-{{ $pickerDevice->id }}">
                    <input class="form-check-input m-0" type="checkbox" value="{{ $pickerDevice->id }}" wire:model.live="targetDevices">
                    <i class="{{ $pickerDevice->typeIcon }} fa-fw text-body-secondary"></i>
                    <span class="me-auto text-truncate">{{ $pickerDevice->displayName }}</span>
                    <x-tags class="justify-content-end" :tags="$pickerDevice->tagList" />
                </label>
            @empty
                <x-empty-state class="small">{{ __('No devices.') }}</x-empty-state>
            @endforelse
        </div>
    @endif
</div>
