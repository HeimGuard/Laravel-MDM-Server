<form wire:submit="save">
    <p class="small text-body-secondary">{{ __('Another agent in the same network sends the magic packet. Leave the fields empty to use what the agent of this device reports.') }}</p>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label" for="wake-settings-mac">{{ __('MAC address') }}</label>
            <input class="form-control font-monospace @error('mac') is-invalid @enderror" id="wake-settings-mac" placeholder="{{ $reportedMacs[0] ?? 'AA:BB:CC:DD:EE:FF' }}" type="text" wire:model="mac">
            <div class="form-text">{{ __('Reported') }}: @forelse ($reportedMacs as $reported)<x-mac :mac="$reported" />@if (! $loop->last), @endif @empty<span class="font-monospace">–</span>@endforelse</div>
        </div>
        <div class="col-8">
            <label class="form-label" for="wake-settings-address">{{ __('IPv4 address') }}</label>
            <input class="form-control font-monospace" id="wake-settings-address" placeholder="192.168.1.20" type="text" wire:model="address">
        </div>
        <div class="col-4">
            <label class="form-label" for="wake-settings-prefix">{{ __('Prefix') }}</label>
            <div class="input-group">
                <span class="input-group-text">/</span>
                <input class="form-control" id="wake-settings-prefix" max="30" min="8" type="number" wire:model="prefix">
            </div>
        </div>
        <div class="col-12 form-text mt-1">{{ __('Its network decides which agent sends the packet. Reported') }}: <span class="font-monospace">{{ $reportedNetworks === [] ? '–' : implode(', ', $reportedNetworks) }}</span></div>
        @error('mac') <div class="col-12 small text-danger">{{ $message }}</div> @enderror
    </div>
    <div class="d-flex justify-content-end gap-2 border-top pt-3 mt-4">
        <button class="btn btn-light" type="button" wire:click="$dispatch('closeModal')">{{ __('Cancel') }}</button>
        <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
    </div>
</form>
