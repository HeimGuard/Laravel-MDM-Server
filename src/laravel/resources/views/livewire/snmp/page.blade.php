{{-- The devices read over SNMP: only the address and the community are entered (App\Services\SwitchService finds out the rest). --}}
<div>
    <div class="container-fluid px-3 px-lg-4">
        <x-boilerplate::breadcrumb class="hide-mobile mb-2" :items="['' => __('SNMP')]" />
        <div class="page-header">
            <h1 class="me-auto mb-0">{{ __('SNMP') }}</h1>
        </div>
        <p class="text-body-secondary">{{ __('Enter the address and the SNMP community (version 1 or 2c) of a device. The agent of its network finds out the rest: the version, what the device is, its ports and, when it has the table, which device is plugged into which port (agents :version or newer).', ['version' => \App\Services\SwitchService::SNMP_VERSION]) }}</p>

        <div class="card mb-3">
            <x-card-header icon="fas fa-stream" tone="primary" :title="__('Add a device')" />
            <div class="card-body">
                <form class="row g-3" wire:submit="add">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="snmp-device">{{ __('Device') }}</label>
                        <select class="form-select" id="snmp-device" wire:model.live="device">
                            <option value="">{{ __('A new device') }}</option>
                            @foreach ($candidates as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->displayName }} ({{ $candidate->ping_address }})</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($device === '')
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="snmp-address">{{ __('IP address') }}</label>
                            <input class="form-control @error('address') is-invalid @enderror" id="snmp-address" type="text" inputmode="decimal" placeholder="192.168.1.2" wire:model="address">
                            @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label" for="snmp-prefix">{{ __('Prefix') }}</label>
                            <input class="form-control" id="snmp-prefix" type="number" min="8" max="30" wire:model="prefix">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="snmp-name">{{ __('Name') }}</label>
                            <input class="form-control @error('name') is-invalid @enderror" id="snmp-name" type="text" placeholder="{{ __('The address when empty') }}" wire:model="name">
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    @endif
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="snmp-community">{{ __('SNMP community') }}</label>
                        <input autocomplete="off" class="form-control @error('community') is-invalid @enderror" id="snmp-community" type="text" placeholder="public" wire:model="community">
                        @error('community') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12 col-md-auto align-self-end">
                        <x-button variant="primary" icon="fas fa-plus" type="submit" loading="add">{{ __('Add') }}</x-button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <x-card-header icon="fas fa-list" :title="__('Devices with SNMP')" />
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr class="text-body-secondary small">
                            <th>{{ __('Device') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('What it says') }}</th>
                            <th>{{ __('Ports') }}</th>
                            <th style="min-width: 12rem">{{ __('SNMP community') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($devices as $row)
                            @php $item = $row['device']; @endphp
                            <tr wire:key="snmp-{{ $item->id }}">
                                <td>
                                    <a class="fw-medium text-decoration-none" href="{{ route('devices', ['selectedDeviceId' => $item->id]) }}">{{ $item->displayName }}</a>
                                    <div class="small text-body-secondary">{{ $item->ping_address }} · <span class="{{ $item->offline ? 'text-danger' : 'text-success' }}">{{ $item->offline ? __('offline') : __('online') }}</span></div>
                                </td>
                                <td class="d-none d-md-table-cell small text-body-secondary">
                                    @if ($row['info'] !== null)
                                        SNMP {{ $row['info']['version'] }}@if ($row['info']['name']) · {{ $row['info']['name'] }}@endif
                                        @if ($row['info']['descr'])<div title="{{ $row['info']['descr'] }}">{{ \Illuminate\Support\Str::limit($row['info']['descr'], 80) }}</div>@endif
                                    @else
                                        {{ __('Not read yet') }}
                                    @endif
                                </td>
                                <td class="small">
                                    @if ($row['ports'] !== null)
                                        {{ __(':up of :total connected', ['up' => $row['ports']['up'], 'total' => $row['ports']['total']]) }}
                                    @else
                                        <span class="text-body-secondary">—</span>
                                    @endif
                                </td>
                                <td>
                                    <input aria-label="{{ __('SNMP community') }}" autocomplete="off" class="form-control form-control-sm @error('communities.'.$item->id) is-invalid @enderror" type="text" wire:model="communities.{{ $item->id }}" wire:change="save({{ $item->id }})">
                                    @error('communities.'.$item->id) <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </td>
                                <td class="text-end">
                                    <x-button compact icon="fas fa-times" :title="__('Turn SNMP off for this device')" wire:click="remove({{ $item->id }})" wire:confirm="{{ __('Turn SNMP off for this device? It stays in the portal.') }}">{{ __('Remove') }}</x-button>
                                </td>
                            </tr>
                        @empty
                            <x-empty-state colspan="5">{{ __('No device is read over SNMP yet.') }}</x-empty-state>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
