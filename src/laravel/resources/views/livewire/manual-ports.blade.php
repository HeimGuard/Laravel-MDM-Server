<form wire:submit="save">
    <div class="row g-3">
        @if ($reads)
            <div class="col-12 small text-warning">{{ __('The ports of this device are read (SNMP or TP-Link), so they cannot be set by hand: turn that off first.') }}</div>
        @else
            <div class="col-12">
                <label class="form-label" for="manual-ports-count">{{ __('Number of ports') }}</label>
                <input autocomplete="off" class="form-control @error('ports') is-invalid @enderror" id="manual-ports-count" inputmode="numeric" max="64" min="0" placeholder="8" type="number" wire:model="ports">
                @error('ports') <div class="invalid-feedback">{{ $message }}</div> @enderror
                <div class="form-text">{{ __('For a device that has no SNMP and no TP-Link page: a switch, a modem, a patch panel. Its ports show on the network map without a link, and you choose which device is plugged into each (click the port). Leave it empty for no ports.') }}</div>
            </div>
        @endif
    </div>
    <div class="d-flex justify-content-end gap-2 border-top pt-3 mt-4">
        <button class="btn btn-light" type="button" wire:click="$dispatch('closeModal')">{{ __('Cancel') }}</button>
        @unless ($reads)
            <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
        @endunless
    </div>
</form>
