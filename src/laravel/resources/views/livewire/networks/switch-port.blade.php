{{-- The devices plugged into a port of a switch: a multiselect of the devices of its network that have no port yet. --}}
<form wire:submit="save">
    <p class="small text-body-secondary mb-3">{{ __('Devices of this network without a port, and the ones already on this port. Tick the ones plugged into the port by a cable.') }}</p>
    @forelse ($candidates as $candidate)
        <div class="form-check py-1" wire:key="switch-port-device-{{ $candidate['id'] }}">
            <input class="form-check-input" id="switch-port-{{ $candidate['id'] }}" type="checkbox" value="{{ $candidate['id'] }}" wire:model.live="selected">
            <label class="form-check-label d-flex gap-2" for="switch-port-{{ $candidate['id'] }}">
                <span class="fw-medium">{{ $candidate['name'] }}</span>
                <span class="text-body-secondary font-monospace small">{{ $candidate['address'] }}</span>
            </label>
            {{-- The port of the device itself the cable goes into: optional, asked when the device is ticked. --}}
            @if (in_array($candidate['id'], array_map('intval', $selected), true))
                <div class="ms-4 mt-1 mb-2">
                    @if ($candidate['ports'] !== [])
                        <select aria-label="{{ __('Its port (optional)') }}" class="form-select form-select-sm w-auto" wire:model="remote.{{ $candidate['id'] }}">
                            <option value="">{{ __('Its port (optional)') }}</option>
                            @foreach ($candidate['ports'] as $remotePort)
                                <option value="{{ $remotePort['port'] }}">{{ $remotePort['label'] }}@if ($remotePort['name'] && $remotePort['name'] !== $remotePort['label']) · {{ $remotePort['name'] }}@endif</option>
                            @endforeach
                        </select>
                    @else
                        <input aria-label="{{ __('Its port (optional)') }}" class="form-control form-control-sm w-auto" max="64" min="1" placeholder="{{ __('Its port (optional)') }}" type="number" wire:model="remote.{{ $candidate['id'] }}">
                    @endif
                </div>
            @endif
        </div>
    @empty
        <div class="small text-body-secondary">{{ __('No device of this network is without a port.') }}</div>
    @endforelse
    <div class="d-flex justify-content-end gap-2 border-top pt-3 mt-4">
        <button class="btn btn-light" type="button" wire:click="$dispatch('closeModal')">{{ __('Cancel') }}</button>
        <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
    </div>
</form>
