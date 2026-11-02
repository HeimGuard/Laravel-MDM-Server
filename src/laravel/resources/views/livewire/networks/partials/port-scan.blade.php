{{-- The open ports of any address an agent's network contains (the unknown devices have their own button). --}}
<div class="dropdown">
    <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" @disabled(! $portScanEnabled)
        title="{{ $portScanEnabled ? __('Scan the open ports of an address in the networks of the agents') : __('Port scanning is turned off in the portal') }}">
        {{ __('Scan ports…') }}
    </button>
    <form class="dropdown-menu dropdown-menu-end p-3" style="min-width: 18rem" wire:submit="scanAddress">
        <label class="form-label small" for="port-scan-ip">{{ __('IP address') }}</label>
        <input class="form-control form-control-sm @error('portScanIp') is-invalid @enderror" id="port-scan-ip" placeholder="192.168.1.20" type="text" wire:model="portScanIp">
        @error('portScanIp') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <div class="d-flex gap-2 mt-2">
            <button class="btn btn-sm btn-primary" type="submit">{{ __('Scan common ports') }}</button>
            <button class="btn btn-sm btn-light" type="button" wire:click="scanAddress(true)">{{ __('All ports') }}</button>
        </div>
    </form>
</div>
