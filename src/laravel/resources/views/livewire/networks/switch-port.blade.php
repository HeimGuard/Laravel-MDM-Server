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
            {{-- The port of the device itself the cable goes into: optional, only for a device that has ports (read or set by hand), the ports as boxes to click (a second click takes it back). --}}
            @if ($candidate['ports'] !== [] && in_array($candidate['id'], array_map('intval', $selected), true))
                <div class="ms-4 mt-1 mb-2 d-flex flex-wrap gap-1" role="group" aria-label="{{ __('Its port (optional)') }}">
                    @foreach ($candidate['ports'] as $remotePort)
                        @php($picked = (int) ($remote[$candidate['id']] ?? 0) === $remotePort['port'])
                        <button class="btn btn-sm rounded-pill px-2 py-0 {{ $picked ? 'btn-success' : 'btn-outline-secondary' }}" title="{{ $remotePort['name'] && $remotePort['name'] !== $remotePort['label'] ? $remotePort['name'] : __('Its port (optional)') }}" type="button" wire:click="pickRemote({{ $candidate['id'] }}, {{ $remotePort['port'] }})" wire:key="remote-{{ $candidate['id'] }}-{{ $remotePort['port'] }}">{{ $remotePort['label'] }}</button>
                    @endforeach
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
