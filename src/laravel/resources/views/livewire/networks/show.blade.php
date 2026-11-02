{{-- One network (App\Livewire\Networks\Show): its devices and the unknown devices its agents see. --}}
@php
    $kinds = ['lan' => __('LAN'), 'mixed' => __('LAN + Wi-Fi'), 'wifi' => __('Wi-Fi'), 'cellular' => __('Mobile'), 'vpn' => __('VPN')];
    $states = [
        'up' => ['label' => __('Online'), 'color' => 'success', 'icon' => 'far fa-check-circle'],
        'offline' => ['label' => __('Unreachable'), 'color' => 'secondary', 'icon' => 'fas fa-minus-circle'],
        'warning' => ['label' => __('Warning'), 'color' => 'warning', 'icon' => 'fas fa-exclamation-triangle', 'title' => __('no answer')],
    ];
    $scan = $net['scan'];
    $command = $scan && $scan['command'] ? \App\Models\DeviceCommand::find($scan['command']['id']) : null;
@endphp
<div>
    <div class="container">
        <x-boilerplate::breadcrumb class="hide-mobile mb-2" :items="[route('networks') => __('Networks'), '' => $net['cidr']]" />
        <div class="page-header">
            <div class="me-auto d-flex align-items-center flex-wrap gap-2 min-w-0">
                <h1 class="mb-0 font-monospace">{{ $net['cidr'] }}</h1>
                <x-badge color="secondary" size="sm" variant="subtle">{{ $kinds[$net['kind']] ?? $kinds['lan'] }}</x-badge>
                @if ($net['site'] && $net['site'] !== '?')
                    <x-badge color="success" size="sm" variant="subtle">{{ $net['site'] }}</x-badge>
                @endif
            </div>
            <div class="d-flex align-items-center flex-wrap gap-2">
                @if ($scan)
                    <button class="btn btn-outline-secondary" type="button" wire:click="scan(@js($net['id']))" wire:loading.attr="disabled" @disabled(! $scan['agent'] || $command?->active)
                        title="{{ $scan['agent'] ? __('Ping every address of the network from :agent and take what answers', ['agent' => $scan['name']]) : $scan['refusal'] }}">
                        @if ($command?->active)
                            <span aria-hidden="true" class="spinner-border spinner-border-sm me-1"></span>
                        @else
                            <i class="fas fa-bullseye me-1"></i>
                        @endif
                        {{ __('Scan network') }}
                    </button>
                @endif
                @include('livewire.networks.partials.port-scan')
            </div>
        </div>
        @if ($scan)
            {{-- The scan: its progress, its last result, or why nothing here can scan. --}}
            <div class="small mb-3">
                @if ($command?->active)
                    @include('partials.device.command-progress', ['command' => $command, 'compact' => true, 'note' => $command->displayMessage ?: __('Scanning through :agent', ['agent' => $scan['command']['by']])])
                @elseif ($command)
                    <span class="{{ $command->status === 'succeeded' ? 'text-body-secondary' : 'text-danger' }}"><i class="fas fa-search-location me-1"></i>{{ $command->displayMessage ?: $command->statusLabel }} · {{ $command->updated_at->diffForHumans() }}</span>
                @endif
                @error('scan.'.$net['anchor']) <div class="text-danger">{{ $message }}</div> @enderror
            </div>
        @endif

        <div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
            <div class="col">
                <x-stat :label="__('Devices')" :value="count($net['members'])" icon="fas fa-desktop" tone="green" />
            </div>
            <div class="col">
                <x-stat :label="__('Unknown')" :value="count($net['unknown'])" :icon="$net['unknown'] ? 'fas fa-exclamation-triangle' : 'fas fa-check'" :tone="$net['unknown'] ? 'yellow' : 'green'" :faint="false" />
            </div>
            <div class="col">
                <x-stat :label="__('Gateway')" :value="$net['gateway'] ? collect([$net['gatewayName'], $net['gateway']])->filter()->implode(' · ') : '—'" icon="fas fa-route" tone="purple" :faint="! $net['gateway']" />
            </div>
        </div>

        <h2 class="h6 fw-semibold mb-2">{{ __('Devices in the network') }}</h2>
        <div class="card mb-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 nm-table">
                    <thead>
                        <tr>
                            <th>{{ __('Device') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('IP address') }}</th>
                            <th>{{ __('MAC') }}</th>
                            <th>{{ __('Vendor') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($net['members'] as $member)
                            @php $state = $states[$member['state']]; @endphp
                            <tr wire:key="member-{{ $member['id'] }}">
                                <td><a class="text-body text-decoration-none" href="{{ route('devices', ['selectedDeviceId' => $member['id']]) }}">{{ $member['name'] }}</a></td>
                                <td><x-badge :color="$state['color']" size="sm" variant="subtle" :icon="$state['icon']" :title="$state['title'] ?? null">{{ $state['label'] }}</x-badge></td>
                                <td class="font-monospace">{{ $member['address'] }}</td>
                                <td class="font-monospace text-body-secondary">{{ $member['mac'] ?? '—' }}</td>
                                <td class="text-body-secondary">{{ \App\Support\MacVendor::lookup($member['mac']) ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <h2 class="h6 fw-semibold mb-0 me-auto">{{ __('Unknown devices') }} <span class="text-body-secondary fw-normal small ms-1">{{ count($net['unknown']) }}</span></h2>
                    @if ($net['unknown'])
                        <button class="btn btn-sm btn-outline-secondary" type="button" wire:click="ignoreAll" wire:confirm="{{ __('Mark all :count unknown devices of the network as known? They are hidden and send no alerts.', ['count' => count($net['unknown'])]) }}">{{ __('Mark as known…') }}</button>
                    @endif
                </div>
                <div class="small text-body-secondary mb-2">{{ __('Devices the agents see in their ARP table that have neither an agent nor a ping.') }}</div>
                @if ($net['unknown'])
                    @include('livewire.networks.partials.unknown', ['unknown' => $net['unknown']])
                @endif
            </div>
        </div>
    </div>
</div>
