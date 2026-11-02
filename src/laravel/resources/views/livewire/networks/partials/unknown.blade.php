{{-- The unknown devices of a network ($unknown) with their port scans ($portScans): the list of the networks page and the network detail. --}}
<ul class="list-unstyled mb-0 nm-unknown-list">
    @foreach ($unknown as $neighbour)
        @php $portScan = $portScans[$neighbour['id']] ?? null; @endphp
        <li class="d-flex align-items-start gap-2 py-2 border-top" wire:key="neighbour-{{ $neighbour['id'] }}">
            <span class="nm-dot mt-2 {{ $neighbour['fresh'] ? 'is-up' : 'is-offline' }}" title="{{ $neighbour['fresh'] ? __('seen now') : __('not seen for a while') }}"></span>
            <div class="min-w-0 flex-grow-1" title="{{ $neighbour['title'] }}">
                {{-- Its name (or address) first, as on the map; the address and MAC under it. --}}
                <div class="text-truncate fw-medium">{{ $neighbour['hostname'] ?: $neighbour['ip'] }}</div>
                {{-- Wraps on phones instead of cutting the MAC address. --}}
                <div class="text-body-secondary text-break" style="font-size: .75rem">
                    @if ($neighbour['hostname'])<span>{{ $neighbour['ip'] }}</span> · @endif<x-mac :mac="$neighbour['mac']" />
                </div>
                {{-- The port scan: its progress, the open ports it found, or why nothing can scan it.
                     Everything below comes from the scanned host and is printed as escaped text. --}}
                @if ($portScan)
                    @php $command = $portScan['command']; $result = $portScan['result']; @endphp
                    <div class="small mt-1">
                        @if ($command)
                            @include('partials.device.command-progress', ['command' => $command, 'compact' => true, 'note' => $command->displayMessage ?: __('Scanning the ports')])
                        @elseif ($result)
                            <details>
                                <summary class="text-body-secondary">
                                    <i class="fas fa-plug me-1"></i>{{ trans_choice(':count open port|:count open ports', count($result->ports)) }}
                                    @if ($result->findings)<span class="text-warning-emphasis ms-1"><i class="fas fa-triangle-exclamation me-1"></i>{{ trans_choice(':count note|:count notes', count($result->findings)) }}</span>@endif
                                    · {{ $result->scanned_at->diffForHumans() }}
                                </summary>
                                @if ($result->ports)
                                    <ul class="list-unstyled mb-0 mt-1 ms-3">
                                        @foreach ($result->ports as $port)
                                            <li class="text-break">
                                                <span class="fw-medium">{{ $port['port'] }}</span>
                                                @if (!empty($port['service']))<span class="text-body-secondary">{{ $port['service'] }}</span>@endif
                                                @if (!empty($port['banner']))<span class="text-body-secondary">— {{ $port['banner'] }}</span>@endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($result->findings)
                                    <ul class="list-unstyled mb-0 mt-1 ms-3 text-warning-emphasis">
                                        @foreach ($result->findings as $finding)
                                            <li class="text-break"><i class="fas fa-triangle-exclamation me-1"></i>{{ $finding }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </details>
                        @endif
                        @error('portscan.'.$neighbour['id']) <div class="text-danger">{{ $message }}</div> @enderror
                    </div>
                @endif
            </div>
            @if ($portScan)
                @if ($portScan['command']?->active)
                    {{-- A scan is running: a plain disabled button with a spinner. --}}
                    <button class="btn btn-sm btn-outline-primary nm-icon-btn" type="button" disabled aria-label="{{ __('Scanning ports') }}" title="{{ __('Scanning the ports') }}">
                        <span aria-hidden="true" class="spinner-border spinner-border-sm"></span>
                    </button>
                @elseif (! $portScan['agent'])
                    <button class="btn btn-sm btn-outline-primary nm-icon-btn" type="button" disabled aria-label="{{ __('Scan ports') }}" title="{{ $portScan['refusal'] }}"><i class="fas fa-plug"></i></button>
                @else
                    {{-- Quick scan of the common ports, or the full 1–65535 sweep. --}}
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-primary nm-icon-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('Scan ports') }}" title="{{ __('Scan the open ports of :ip and read what each service returns', ['ip' => $neighbour['ip']]) }}">
                            <i class="fas fa-plug"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><button class="dropdown-item" type="button" wire:click="scanPorts({{ $neighbour['id'] }})"><i class="fas fa-plug fa-fw me-1"></i>{{ __('Scan common ports') }}</button></li>
                            <li><button class="dropdown-item" type="button" wire:click="scanPorts({{ $neighbour['id'] }}, true)"><i class="fas fa-search-location fa-fw me-1"></i>{{ __('Scan all ports (1–65535)') }}</button></li>
                        </ul>
                    </div>
                @endif
            @endif
            <button class="btn btn-sm btn-outline-primary nm-icon-btn" type="button" wire:click="add({{ $neighbour['id'] }})" aria-label="{{ __('Add') }}" title="{{ __('Add as a ping-only device (it follows its MAC address to a new IP)') }}"><i class="fas fa-plus"></i></button>
            <button class="btn btn-sm btn-outline-secondary nm-icon-btn" type="button" wire:click="ignore({{ $neighbour['id'] }})" title="{{ __('Ignore') }}" aria-label="{{ __('Ignore') }}"><i class="fas fa-eye-slash"></i></button>
        </li>
    @endforeach
</ul>
