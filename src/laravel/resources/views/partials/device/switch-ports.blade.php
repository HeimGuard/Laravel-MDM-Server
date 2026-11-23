{{-- The ports of a switch without SNMP, read by the agent of its network (App\Services\SwitchService). --}}
@php($ports = \App\Services\SwitchService::enabled($device) ? \App\Services\SwitchService::ports($device) : null)
@if (\App\Services\SwitchService::enabled($device))
    <div class="card mt-3">
        <div class="card-body">
            <div class="d-flex align-items-center mb-2">
                <h2 class="h6 fw-semibold mb-0 me-auto">{{ __('Ports') }}</h2>
                @if ($ports !== null)
                    <span class="small text-body-secondary">{{ __(':up of :total connected', ['up' => collect($ports)->where('link', '>=', 2)->count(), 'total' => count($ports)]) }}</span>
                @endif
            </div>
            @if ($ports === null)
                <div class="small text-body-secondary">{{ __('No ports read yet: the agent of its network reads them every minute (agents :version or newer), check the user name and the password if it stays empty.', ['version' => \App\Services\SwitchService::VERSION]) }}</div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <thead>
                            <tr class="text-body-secondary">
                                <th>{{ __('Port') }}</th>
                                <th>{{ __('Link') }}</th>
                                <th class="text-end">{{ __('Bad packets') }}</th>
                                <th class="d-none d-md-table-cell text-end">TX</th>
                                <th class="d-none d-md-table-cell text-end">RX</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ports as $port)
                                <tr wire:key="switch-port-{{ $port['port'] }}">
                                    <td class="fw-medium">{{ $port['port'] }}</td>
                                    <td>
                                        <span class="badge text-bg-{{ $port['link'] >= 2 ? 'success' : 'secondary' }} fw-medium">{{ __(\App\Services\SwitchService::LINKS[$port['link']] ?? 'Down') }}</span>
                                    </td>
                                    <td class="text-end {{ $port['bad'] > 0 ? 'text-warning' : 'text-body-secondary' }}">{{ number_format($port['bad'], 0, '', ' ') }}</td>
                                    <td class="d-none d-md-table-cell text-end text-body-secondary">{{ $port['tx'] === null ? '—' : number_format($port['tx'], 0, '', ' ') }}</td>
                                    <td class="d-none d-md-table-cell text-end text-body-secondary">{{ $port['rx'] === null ? '—' : number_format($port['rx'], 0, '', ' ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endif
