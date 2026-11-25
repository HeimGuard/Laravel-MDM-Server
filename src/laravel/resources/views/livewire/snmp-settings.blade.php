<form wire:submit="save">
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label" for="snmp-settings-community">{{ __('SNMP community') }}</label>
            <input autocomplete="off" class="form-control @error('community') is-invalid @enderror" id="snmp-settings-community" placeholder="public" type="text" wire:model="community">
            @error('community') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <div class="form-text">{{ __('The SNMP community (version 1 or 2c) of the device; leave it empty to turn SNMP off. The agent of its network finds out the rest: the version, what the device is, its ports and, when it has the table, which device is plugged into which port (agents :version or newer).', ['version' => \App\Services\SwitchService::SNMP_VERSION]) }}</div>
        </div>
        <div class="col-12">
            <label class="form-label" for="snmp-settings-hidden">{{ __('Interfaces to ignore') }}</label>
            <textarea class="form-control font-monospace @error('hidden') is-invalid @enderror" id="snmp-settings-hidden" placeholder="eth*, br-lan" rows="2" wire:model="hidden"></textarea>
            @error('hidden') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <div class="form-text">{{ __('Names of the interfaces that are no ports, separated by commas (* is any characters). Bridges, VLANs, Wi-Fi and virtual interfaces are left out without it; a router often has its CPU as eth0, eth1…') }}</div>
            @if (count($ports) > 0)
                <div class="form-text">{{ __('Read as ports: :ports', ['ports' => collect($ports)->map(fn ($port) => ($port['name'] ?? '#'.$port['port']).' ('.$port['port'].')')->implode(', ')]) }}</div>
            @endif
        </div>
        @if ($replaces)
            <div class="col-12 small text-warning">{{ __('The device is read from its TP-Link web page now: a community replaces that.') }}</div>
        @endif
        @if ($info !== null)
            <div class="col-12 small text-body-secondary">
                SNMP {{ $info['version'] }}@if ($info['name']) · {{ $info['name'] }}@endif
                @if ($info['descr'])<div title="{{ $info['descr'] }}">{{ \Illuminate\Support\Str::limit($info['descr'], 160) }}</div>@endif
            </div>
        @endif
    </div>
    <div class="d-flex justify-content-end gap-2 border-top pt-3 mt-4">
        <button class="btn btn-light" type="button" wire:click="$dispatch('closeModal')">{{ __('Cancel') }}</button>
        <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
    </div>
</form>
