<form wire:submit="save">
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label" for="snmp-settings-community">{{ __('SNMP community') }}</label>
            <input autocomplete="off" class="form-control @error('community') is-invalid @enderror" id="snmp-settings-community" placeholder="public" type="text" wire:model="community">
            @error('community') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <div class="form-text">{{ __('The SNMP community (version 1 or 2c) of the device; leave it empty to turn SNMP off. The agent of its network finds out the rest: the version, what the device is, its ports and, when it has the table, which device is plugged into which port (agents :version or newer).', ['version' => \App\Services\SwitchService::SNMP_VERSION]) }}</div>
        </div>
        @if (count($interfaces) > 0)
            <div class="col-12">
                <div class="form-label">{{ __('Interfaces') }}</div>
                <div class="border rounded p-2" style="max-height: 14rem; overflow: auto">
                    @foreach ($interfaces as $row)
                        <div class="form-check d-flex align-items-center gap-2">
                            <input class="form-check-input mt-0" id="snmp-if-{{ $row['port'] }}" type="checkbox" value="{{ $row['name'] }}" wire:model="include" @disabled($row['name'] === null)>
                            <label class="form-check-label small" for="snmp-if-{{ $row['port'] }}">
                                <span class="fw-medium">{{ $row['name'] ?? '#'.$row['port'] }}</span>
                                <span class="text-body-secondary">({{ $row['port'] }}) · {{ __(\App\Services\SwitchService::LINKS[$row['link']] ?? 'Down') }}@if (! $row['port_by_default']) · {{ __('not a port by default') }}@endif</span>
                            </label>
                        </div>
                    @endforeach
                </div>
                <div class="form-text">{{ __('Tick what is a port. Bridges, VLANs, Wi-Fi and virtual interfaces are off by default, and so are the eth0, eth1… of a router that names its ports lan0, lan1…') }}</div>
            </div>
        @endif
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
