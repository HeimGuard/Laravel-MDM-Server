{{-- The ports of a switch (without SNMP, or with it: found out by the agent of its network), App\Services\SwitchService. --}}
@php
    $ports = \App\Services\SwitchService::enabled($device) ? \App\Services\SwitchService::ports($device) : null;
    // The devices plugged into the ports by hand (the network map), by the port.
    $snmp = (string) $device->switch_type === \App\Services\SwitchService::SNMP;
    // What an SNMP device says about itself (name, description) and the version that answered.
    $info = $snmp ? \App\Services\SwitchService::info($device) : null;
    // Set by hand (nothing reads it): the ports and what is plugged into them, no link and no packets.
    $manual = ! \App\Services\SwitchService::reads($device);
    $plugged = $ports === null ? collect() : \App\Models\Device::query()->whereSettingIn('lan.switch_device_id', [$device->id])->get()->groupBy(fn ($other) => $other->switch_port);
@endphp
@if (\App\Services\SwitchService::enabled($device))
    <div class="card mt-3" x-on:devices-changed.window="$wire.$refresh()">
        <div class="card-body">
            <div class="d-flex align-items-center mb-2">
                <h2 class="h6 fw-semibold mb-0 me-auto">{{ __('Ports') }}</h2>
                @if ($ports !== null && $manual)
                    <span class="small text-body-secondary">{{ __('Set by hand') }}</span>
                @elseif ($ports !== null)
                    <span class="small text-body-secondary">{{ __(':up of :total connected', ['up' => collect($ports)->where('link', '>=', 2)->count(), 'total' => count($ports)]) }}</span>
                @endif
            </div>
            @if ($info !== null)
                <div class="small text-body-secondary mb-2" title="{{ $info['descr'] }}">SNMP {{ $info['version'] }}@if ($info['name']) · {{ $info['name'] }}@endif @if ($info['descr']) · {{ \Illuminate\Support\Str::limit($info['descr'], 120) }}@endif</div>
            @endif
            @if ($ports === null && $snmp)
                <div class="small text-body-secondary">{{ __('No ports read yet: the agent of its network asks for them every minute (agents :version or newer), check the community and that the device answers SNMP from its network if it stays empty.', ['version' => \App\Services\SwitchService::SNMP_VERSION]) }}</div>
            @elseif ($ports === null)
                <div class="small text-body-secondary">{{ __('No ports read yet: the agent of its network reads them every minute (agents :version or newer), check the user name and the password if it stays empty.', ['version' => \App\Services\SwitchService::VERSION]) }}</div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <thead>
                            <tr class="text-body-secondary">
                                <th>{{ __('Port') }}</th>
                                @unless ($manual)
                                    <th>{{ __('Link') }}</th>
                                    <th class="text-end">{{ __('Bad packets') }}</th>
                                    <th class="d-none d-md-table-cell text-end">TX</th>
                                    <th class="d-none d-md-table-cell text-end">RX</th>
                                @endunless
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ports as $port)
                                <tr wire:key="switch-port-{{ $port['port'] }}">
                                    <td class="fw-medium text-nowrap">
                                        {{ $port['label'] }}@if (! empty($port['name']) && $port['name'] !== $port['label'])<span class="text-body-secondary fw-normal ms-1">{{ $port['name'] }}</span>@endif
                                        {{-- Always a box after the port: the device (a link to it) or an empty one with a question mark (the dialog of the port opens). --}}
                                        @forelse ($plugged->get($port['port'], collect()) as $other)
                                            <a class="text-decoration-none ms-1" href="{{ route('devices', ['selectedDeviceId' => $other->id]) }}" title="{{ __('Plugged into :switch, port :port', ['switch' => $device->displayName, 'port' => $port['label']]) }}">
                                                <x-badge color="secondary" size="sm" variant="subtle" icon="fas fa-link">{{ $other->displayName }}</x-badge>
                                            </a>
                                        @empty
                                            <button class="btn btn-sm btn-outline-secondary ms-2 py-0 px-3 lh-base" style="border-style: dashed" title="{{ __('Click to choose the devices on this port') }}" type="button"
                                                x-on:click="Livewire.dispatch('openModal', {livewireComponents: 'networks.switch-port', title: @js(__(':switch: port :port', ['switch' => $device->displayName, 'port' => $port['label']])), parameters: {deviceId: {{ $device->id }}, port: {{ (int) $port['port'] }}}})">?</button>
                                        @endforelse
                                    </td>
                                    @unless ($manual)
                                        <td>
                                            <span class="badge text-bg-{{ $port['link'] >= 2 ? 'success' : 'secondary' }} fw-medium">{{ \App\Services\SwitchService::linkName($port['link']) }}</span>
                                        </td>
                                        <td class="text-end {{ $port['bad'] > 0 ? 'text-warning' : 'text-body-secondary' }}">{{ number_format($port['bad'], 0, '', ' ') }}</td>
                                        <td class="d-none d-md-table-cell text-end text-body-secondary">{{ $port['tx'] === null ? '—' : number_format($port['tx'], 0, '', ' ') }}</td>
                                        <td class="d-none d-md-table-cell text-end text-body-secondary">{{ $port['rx'] === null ? '—' : number_format($port['rx'], 0, '', ' ') }}</td>
                                    @endunless
                                </tr>
                            @endforeach
                            {{-- Devices plugged into a port this device does not have (any more): a row each, with the device to take off it (the map has a button). --}}
                            @foreach ($plugged->filter(fn ($others, $number) => $number !== '' && ! in_array((int) $number, array_column($ports, 'port'), true)) as $number => $others)
                                <tr class="text-body-secondary" wire:key="switch-port-missing-{{ $number }}">
                                    <td class="fw-medium text-nowrap" colspan="{{ $manual ? 1 : 5 }}">
                                        {{ $number }}
                                        <button class="btn btn-link btn-sm p-0 fw-normal text-decoration-none align-baseline ms-1" title="{{ __('Click to choose the devices on this port') }}" type="button"
                                            x-on:click="Livewire.dispatch('openModal', {livewireComponents: 'networks.switch-port', title: @js(__(':switch: port :port', ['switch' => $device->displayName, 'port' => $number])), parameters: {deviceId: {{ $device->id }}, port: {{ (int) $number }}}})">{{ __('Not a port now') }}</button>
                                        @foreach ($others as $other)
                                            <a class="text-decoration-none ms-1" href="{{ route('devices', ['selectedDeviceId' => $other->id]) }}" title="{{ __('Not a port of this device now: click to take the device off it') }}">
                                                <x-badge color="warning" size="sm" variant="subtle" icon="fas fa-link">{{ $other->displayName }}</x-badge>
                                            </a>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endif
