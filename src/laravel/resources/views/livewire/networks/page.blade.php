{{-- The networks of the fleet: the map (App\Support\NetworkMap, drawn by resources/js/network-map.js) and the list. --}}
@php
    $counts = collect($map['nodes'])->reject(fn ($node) => $node['kind'] === 'site' && $node['state'] === 'down')->countBy('kind');
    $kinds = [
        'lan' => ['icon' => 'fas fa-ethernet', 'label' => __('LAN'), 'tone' => 'primary'],
        'mixed' => ['icon' => 'fas fa-ethernet', 'label' => __('LAN + Wi-Fi'), 'tone' => 'primary'],
        'wifi' => ['icon' => 'fas fa-wifi', 'label' => __('Wi-Fi'), 'tone' => 'purple'],
        'cellular' => ['icon' => 'fas fa-signal', 'label' => __('Mobile'), 'tone' => 'primary'],
        'vpn' => ['icon' => 'fas fa-shield-alt', 'label' => __('VPN'), 'tone' => 'info'],
    ];
    $isolated = collect($map['nodes'])->where('kind', 'device')->where('isolated', true)->values();
    $unknownCount = collect($map['networks'])->sum(fn ($network) => count($network['unknown']));
    // The networks with unknown devices, the most first (the tile and the banner).
    $unknownBy = collect($map['networks'])->filter(fn ($network) => $network['unknown'])->sortByDesc(fn ($network) => count($network['unknown']))->values();
    $sites = collect($map['nodes'])->where('kind', 'site');
    $devices = collect($map['nodes'])->where('kind', 'device');
    $online = $devices->where('state', 'up')->count();
    $admin = auth()->user()?->can('is-system-admin');
@endphp
<div>
    <div class="container-fluid px-3 px-lg-4">
        <x-boilerplate::breadcrumb class="hide-mobile mb-2" :items="['' => __('Networks')]" />
        <div class="page-header">
            <h1 class="me-auto mb-0">{{ __('Networks') }}</h1>
            <div class="d-flex align-items-center flex-wrap gap-2">
                @include('livewire.networks.partials.port-scan')
                <button class="btn btn-primary" type="button" wire:click="scanAll" wire:loading.attr="disabled" @disabled(! $discovery)
                    title="{{ __('Ping every address of each network from an agent there and take what answers') }}">
                    <i class="fas fa-bullseye me-1"></i>{{ __('Run network scan') }}
                </button>
                @if ($admin)
                    {{-- Discovery and port scans of the whole portal (each agent also allows them in its config.json). --}}
                    <div class="dropdown">
                        <button class="btn btn-light" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="{{ __('Settings') }}" title="{{ __('Settings') }}"><i class="fas fa-cog"></i></button>
                        <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 16rem">
                            <div class="form-check form-switch mb-2" title="{{ __('Off: the portal takes no neighbours from the agents and sends no scans, whatever their config.json allows.') }}">
                                <input class="form-check-input" id="network-discovery" type="checkbox" role="switch" wire:click="toggleDiscovery" @checked($discovery)>
                                <label class="form-check-label" for="network-discovery">{{ __('Network discovery') }}</label>
                            </div>
                            <div class="form-check form-switch mb-0" title="{{ __('Off: no agent scans the ports of an address, whatever its config.json allows.') }}">
                                <input class="form-check-input" id="port-scan" type="checkbox" role="switch" wire:click="togglePortScan" @checked($portScanEnabled)>
                                <label class="form-check-label" for="port-scan">{{ __('Port scanning') }}</label>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
        @error('scanAll') <div class="text-danger small mb-2">{{ $message }}</div> @enderror

        <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-3">
            <div class="col">
                <x-stat :label="__('Networks')" :value="count($map['networks'])" icon="fas fa-project-diagram" tone="purple" />
            </div>
            <div class="col">
                <x-stat :label="__('Public addresses')" :value="$sites->where('state', 'up')->count()" icon="fas fa-globe" tone="purple" />
            </div>
            <div class="col">
                <x-stat :label="__('Devices')" :value="$devices->count()" icon="fas fa-desktop" :tone="$online > 0 ? 'green' : null" />
            </div>
            <div class="col">
                <x-stat :label="__('Unknown devices')" :value="$unknownCount" :icon="$unknownCount > 0 ? 'fas fa-exclamation-triangle' : 'fas fa-check'" :tone="$unknownCount > 0 ? 'yellow' : 'green'" :faint="false" />
            </div>
        </div>

        @unless ($discovery)
            {{-- Off for the whole portal: no neighbours are taken and no scans sent, the unknown devices go stale. --}}
            <div class="alert alert-warning d-flex align-items-start gap-2 py-2 small mb-3" role="status">
                <i class="fas fa-eye-slash mt-1"></i>
                <div>
                    <strong>{{ __('Network discovery is off.') }}</strong>
                    {{ __('The portal takes no ARP tables from the agents and sends no scans, so unknown devices are not updated.') }}
                    @if ($admin) {{ __('Turn it on in the settings (the cog above).') }} @endif
                </div>
            </div>
        @endunless

        @if ($unknownCount > 0)
            <div class="alert alert-warning d-flex align-items-start gap-3 mb-3" role="status">
                <i class="fas fa-exclamation-triangle mt-1"></i>
                <div class="me-auto">
                    <div class="fw-semibold">{{ trans_choice('Found :count unknown device in :networks networks.|Found :count unknown devices in :networks networks.', $unknownCount, ['networks' => $unknownBy->count()]) }}</div>
                    <div>{!! __('Most of them are in :network (:count). Assign them to devices or mark them as known.', ['network' => '<code>'.e($unknownBy[0]['cidr']).'</code>', 'count' => count($unknownBy[0]['unknown'])]) !!}</div>
                </div>
                <a class="btn btn-sm btn-outline-secondary flex-shrink-0" href="{{ route('networks.show', ['network' => $unknownBy[0]['anchor']]) }}">{{ __('Show') }}</a>
            </div>
        @endif

        <ul class="nav nav-tabs mb-3" role="tablist">
            @foreach (['map' => [__('Diagram'), null], 'list' => [__('Network table'), count($map['networks'])], 'internet' => [__('Internet'), $sites->count()]] as $key => [$label, $count])
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $view === $key ? 'active' : '' }}" type="button" role="tab" aria-selected="{{ $view === $key ? 'true' : 'false' }}" wire:click="$set('view', '{{ $key }}')">
                        {{ $label }}@if ($count !== null)<span class="text-body-secondary small ms-2">{{ $count }}</span>@endif
                    </button>
                </li>
            @endforeach
        </ul>

        @if (($counts['device'] ?? 0) === 0)
            <div class="card card-body text-center text-body-secondary py-5">
                <i class="fas fa-project-diagram fa-2x mb-3"></i>
                <div>{{ __('No devices yet: the map is built from what their agents report.') }}</div>
            </div>
        @elseif ($view === 'map')
            <div class="card card-body p-2 mb-3" wire:key="network-map-view">
                {{-- Drawn and kept by the script (live updates replace its data, not the DOM). As high as
                     the window leaves below it (the legend and the phone's bottom bar aside). --}}
                <div class="network-map" wire:ignore
                    x-data="{
                        map: null,
                        fill() {
                            const below = ($el.nextElementSibling?.offsetHeight ?? 0) + (document.querySelector('.layout-nav-mobile')?.offsetHeight ?? 0) + (window.innerWidth < 768 ? 56 : 48);
                            $el.style.height = Math.max(320, window.innerHeight - $el.getBoundingClientRect().top - below) + 'px';
                        },
                    }"
                    x-init="fill(); (window.mdmNetworkMap ? Promise.resolve() : new Promise((done) => window.addEventListener('load', done, { once: true }))).then(() => window.mdmNetworkMap($el, @js($map))).then((m) => map = m)"
                    x-on:resize.window.debounce.200ms="fill(); map?.fit()"
                    x-on:network-map-updated.window="map?.update($event.detail.map)"></div>
                <div class="d-flex flex-wrap gap-3 small text-body-secondary px-2 pt-2 pb-1 border-top mt-2">
                    <span><span class="nm-dot is-up me-1"></span>{{ __('online') }}</span>
                    <span><span class="nm-dot is-offline me-1"></span>{{ __('offline') }}</span>
                    <span><span class="nm-dot is-warning me-1"></span>{{ __('no answer') }}</span>
                    @foreach (['wan' => __('internet'), 'uplink' => __('uplink'), 'lan' => __('LAN'), 'wifi' => __('Wi-Fi'), 'vpn' => __('VPN'), 'tunnel' => __('tunnel')] as $type => $label)
                        <span><svg class="me-1" height="6" width="22"><path class="nm-link is-{{ $type }}" d="M1 3 L 21 3"></path></svg>{{ $label }}</span>
                    @endforeach
                    <span><svg class="me-1" height="6" width="22"><path class="nm-link is-lan is-down" d="M1 3 L 21 3"></path></svg>{{ __('down') }}</span>
                    <span><svg class="me-1" height="6" width="22"><path class="nm-link is-unknown" d="M1 3 L 21 3"></path></svg>{{ __('unknown device') }}</span>
                    <span class="ms-auto d-none d-md-inline">{{ __('Drag to move, wheel or pinch to zoom.') }}</span>
                </div>
            </div>

        @elseif ($view === 'internet')
            {{-- The public addresses the devices are behind (and were, faint), with their networks. --}}
            <div class="card" wire:key="network-internet-view">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Public address') }}</th>
                                <th>{{ __('Devices') }}</th>
                                <th>{{ __('Networks') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sites->sortBy(fn ($site) => $site['state'] === 'up' ? 0 : 1) as $site)
                                <tr class="{{ $site['state'] === 'up' ? '' : 'opacity-50' }}" wire:key="site-{{ $site['id'] }}">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="{{ $site['icon'] }} text-body-secondary"></i>
                                            <div>
                                                <div class="fw-medium">{{ $site['label'] }}</div>
                                                <div class="small text-body-secondary">{{ $site['sub'] }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-nowrap">{{ $site['badges'][0] ?? '' }}</td>
                                    <td>
                                        @foreach (collect($map['networks'])->where('site', $site['group']) as $network)
                                            <a class="badge text-bg-light text-decoration-none fw-normal font-monospace" href="{{ route('networks.show', ['network' => $network['anchor']]) }}">{{ $network['cidr'] }}</a>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

        @else
            {{-- One row per network; its devices and unknown devices are on its detail page. --}}
            <div class="card" wire:key="network-list-view">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 nm-table">
                        <thead>
                            <tr>
                                <th>{{ __('Network') }}</th>
                                <th>{{ __('Public address') }}</th>
                                <th>{{ __('Gateway') }}</th>
                                <th>{{ __('Devices') }}</th>
                                <th>{{ __('Unknown') }}</th>
                                <th>{{ __('Ping and wake') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($map['networks'] as $network)
                                @php
                                    $kind = $kinds[$network['kind']] ?? $kinds['lan'];
                                    // A scan by an agent here that allows it: what answers comes with its report.
                                    $scan = $network['scan'];
                                    $command = $scan && $scan['command'] ? \App\Models\DeviceCommand::find($scan['command']['id']) : null;
                                    $url = route('networks.show', ['network' => $network['anchor']]);
                                @endphp
                                <tr id="{{ $network['anchor'] }}" wire:key="network-{{ $network['id'] }}">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="icon-tile d-none d-sm-inline-flex bg-{{ $kind['tone'] }}-subtle text-{{ $kind['tone'] }}-emphasis"><i class="{{ $kind['icon'] }}"></i></span>
                                            <div class="min-w-0">
                                                <a class="fw-semibold text-body font-monospace" href="{{ $url }}">{{ $network['cidr'] }}</a>
                                                <div class="small text-body-secondary">{{ $kind['label'] }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="font-monospace small">{{ $network['site'] && $network['site'] !== '?' ? $network['site'] : '—' }}</td>
                                    <td class="small">
                                        @if ($network['gateway'])
                                            {{ $network['gatewayName'] ? $network['gatewayName'].' · ' : '' }}<span class="font-monospace">{{ $network['gateway'] }}</span>
                                        @else
                                            <span class="text-body-secondary">—</span>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        {{ count($network['devices']) }}
                                        <span class="small text-body-secondary">· {{ __(':count online', ['count' => $network['online']]) }}</span>
                                    </td>
                                    <td>
                                        @if ($network['unknown'])
                                            <a class="text-decoration-none" href="{{ $url }}"><x-badge color="warning" size="sm" variant="subtle" icon="fas fa-exclamation-triangle">{{ count($network['unknown']) }}</x-badge></a>
                                        @else
                                            <span class="text-body-secondary">0</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if ($network['kind'] === 'vpn' || count($network['devices']) < 2)
                                            <span class="text-body-secondary">—</span>
                                        @elseif ($network['relays'])
                                            <span title="{{ implode(', ', $network['relays']) }}">{{ implode(', ', array_slice($network['relays'], 0, 2)) }}@if (count($network['relays']) > 2) +{{ count($network['relays']) - 2 }}@endif</span>
                                        @else
                                            <span class="text-warning-emphasis">{{ __('No online agent') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        @if ($scan)
                                            <button class="btn btn-sm btn-outline-primary nm-scan-btn" type="button" wire:click="scan(@js($network['id']))" aria-label="{{ __('Scan') }}" wire:loading.attr="disabled"
                                                @disabled(! $scan['agent'] || $command?->active)
                                                title="{{ $scan['agent'] ? __('Ping every address of the network from :agent and take what answers', ['agent' => $scan['name']]) : $scan['refusal'] }}">
                                                @if ($command?->active)
                                                    <span aria-hidden="true" class="spinner-border spinner-border-sm me-sm-1"></span>
                                                @else
                                                    <i class="fas fa-search-location me-sm-1"></i>
                                                @endif
                                                <span class="d-none d-sm-inline">{{ __('Scan') }}</span>
                                            </button>
                                            @error('scan.'.$network['anchor']) <div class="text-danger small text-wrap">{{ $message }}</div> @enderror
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Agents with no other agent in their networks: nothing next to them can wake them or ping around them. --}}
            @foreach ($isolated as $node)
                <div class="alert alert-warning d-flex align-items-start gap-2 py-2 small mt-3 mb-0" wire:key="isolated-{{ $node['id'] }}">
                    <i class="fas fa-exclamation-triangle mt-1"></i>
                    <div><a class="fw-semibold" href="{{ $node['url'] }}">{{ $node['label'] }}</a> · {{ __('No other agent in its networks: nothing next to it can wake it or ping the devices around it.') }}</div>
                </div>
            @endforeach

            @if ($ignored->isNotEmpty())
                <details class="mt-4">
                    <summary class="small text-body-secondary">{{ trans_choice(':count ignored device|:count ignored devices', $ignored->count()) }}</summary>
                    <ul class="list-unstyled small mt-2 mb-0">
                        @foreach ($ignored as $neighbour)
                            <li class="d-flex align-items-center gap-2 py-1" wire:key="ignored-{{ $neighbour->id }}">
                                <span class="fw-medium">{{ $neighbour->ip }}</span>
                                <span class="text-body-secondary text-truncate">{{ collect([$neighbour->hostname, $neighbour->mac.(\App\Support\MacVendor::lookup($neighbour->mac) ? ' ('.\App\Support\MacVendor::lookup($neighbour->mac).')' : ''), $neighbour->network, __('last seen :time', ['time' => $neighbour->last_seen_at->diffForHumans()])])->filter()->implode(' · ') }}</span>
                                <button class="btn btn-sm btn-link p-0 ms-auto" type="button" wire:click="restore({{ $neighbour->id }})">{{ __('Show again') }}</button>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @endif
    </div>
</div>
