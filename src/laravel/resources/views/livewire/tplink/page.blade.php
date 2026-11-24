{{-- TP-Link Easy Smart switches (no SNMP) read from their web page: offered for the devices with a TP-Link MAC address (App\Services\SwitchService). --}}
<div>
    <div class="container-fluid px-3 px-lg-4">
        <x-boilerplate::breadcrumb class="hide-mobile mb-2" :items="['' => __('TP-Link')]" />
        <div class="page-header">
            <h1 class="me-auto mb-0">{{ __('TP-Link') }}</h1>
        </div>
        <p class="text-body-secondary">{{ __('TP-Link Easy Smart switches have no SNMP: the agent of their network reads the ports from their web page (agents :version or newer). It is offered for the devices whose MAC address is one of TP-Link. Enter the user name and the password of the switch.', ['version' => \App\Services\SwitchService::VERSION]) }}</p>

        <div class="card mb-3">
            <x-card-header icon="fas fa-ethernet" tone="primary" :title="__('Add a TP-Link switch')" />
            <div class="card-body">
                @if ($candidates->isEmpty())
                    <div class="text-body-secondary">{{ __('No device with a TP-Link MAC address is waiting: add the switch to the portal first (Networks, or Devices).') }}</div>
                @else
                    <form class="row g-3" wire:submit="add">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="tplink-device">{{ __('Device') }}</label>
                            <select class="form-select @error('device') is-invalid @enderror" id="tplink-device" wire:model="device">
                                <option value="">{{ __('Choose a device') }}</option>
                                @foreach ($candidates as $candidate)
                                    <option value="{{ $candidate->id }}">{{ $candidate->displayName }} ({{ $candidate->ping_address }})</option>
                                @endforeach
                            </select>
                            @error('device') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="tplink-user">{{ __('User name') }}</label>
                            <input autocomplete="off" class="form-control @error('user') is-invalid @enderror" id="tplink-user" type="text" wire:model="user">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="tplink-password">{{ __('Password') }}</label>
                            <input autocomplete="new-password" class="form-control @error('user') is-invalid @enderror" id="tplink-password" type="password" wire:model="password">
                        </div>
                        <div class="col-12 col-md-auto align-self-end">
                            <x-button variant="primary" icon="fas fa-plus" type="submit" loading="add">{{ __('Add') }}</x-button>
                        </div>
                        @error('user') <div class="col-12 small text-danger">{{ $message }}</div> @enderror
                        <div class="col-12 small text-body-secondary">{{ __('The password is stored encrypted and sent to the agent encrypted with its own key, so only that agent can read it. Use a password made for this switch.') }}</div>
                    </form>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <x-card-header icon="fas fa-list" :title="__('TP-Link switches')" />
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr class="text-body-secondary small">
                            <th>{{ __('Device') }}</th>
                            <th>{{ __('Ports') }}</th>
                            <th>{{ __('User name') }}</th>
                            <th>{{ __('Password') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($devices as $row)
                            @php $item = $row['device']; @endphp
                            <tr wire:key="tplink-{{ $item->id }}">
                                <td>
                                    <a class="fw-medium text-decoration-none" href="{{ route('devices', ['selectedDeviceId' => $item->id]) }}">{{ $item->displayName }}</a>
                                    <div class="small text-body-secondary">{{ $item->ping_address }} · <span class="{{ $item->offline ? 'text-danger' : 'text-success' }}">{{ $item->offline ? __('offline') : __('online') }}</span></div>
                                </td>
                                <td class="small">
                                    @if ($row['ports'] !== null)
                                        {{ __(':up of :total connected', ['up' => $row['ports']['up'], 'total' => $row['ports']['total']]) }}
                                    @else
                                        <span class="text-body-secondary">{{ __('Not read yet') }}</span>
                                    @endif
                                </td>
                                <td style="min-width: 8rem">
                                    <input aria-label="{{ __('User name') }}" autocomplete="off" class="form-control form-control-sm @error('users.'.$item->id) is-invalid @enderror" type="text" wire:model="users.{{ $item->id }}">
                                </td>
                                <td style="min-width: 8rem">
                                    <input aria-label="{{ __('Password') }}" autocomplete="new-password" class="form-control form-control-sm" placeholder="{{ __('Leave empty to keep it') }}" type="password" wire:model="passwords.{{ $item->id }}">
                                    @error('users.'.$item->id) <div class="small text-danger">{{ $message }}</div> @enderror
                                </td>
                                <td class="text-end text-nowrap">
                                    <x-button compact icon="fas fa-save" :title="__('Save')" wire:click="save({{ $item->id }})">{{ __('Save') }}</x-button>
                                    <x-button compact icon="fas fa-times" :title="__('Stop reading this switch')" wire:click="remove({{ $item->id }})" wire:confirm="{{ __('Stop reading this switch? It stays in the portal.') }}">{{ __('Remove') }}</x-button>
                                </td>
                            </tr>
                        @empty
                            <x-empty-state colspan="5">{{ __('No TP-Link switch is read yet.') }}</x-empty-state>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
