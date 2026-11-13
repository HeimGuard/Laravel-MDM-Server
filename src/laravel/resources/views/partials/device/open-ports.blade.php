{{-- The open ports of a host and its last port scan ($portScan = Device::portScanState()): the card of the overview of
     a ping-only device and of a device with an agent alike. The scan itself is sent by DeviceCommands. --}}
@php
    $result = $portScan['result'];
    $scanning = $portScan['command'];
@endphp
{{-- Everything shown comes from the scanned host and is printed as escaped text. --}}
<div class="card card-body h-100">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
        <span class="fw-semibold">
            {{ __('Open ports') }}
            @if ($result)<span class="text-body-secondary fw-normal ms-1">{{ count($result->ports) }}</span>@endif
        </span>
        {{-- DeviceCommands sends the scan and shows its progress in the device card. --}}
        <x-button variant="outline-secondary" icon="fas fa-bullseye" :busy="(bool) $scanning" class="text-nowrap" x-on:click="Livewire.dispatch('scan-ports')"
            title="{{ $scanning ? ($scanning->displayMessage ?: $scanning->statusLabel) : ($portScan['scanner'] ? __('Scan the open ports of :ip and read what each service returns', ['ip' => $portScan['address']]) : __('No online agent in its network can scan it.')) }}"
            :disabled="$scanning || ! $portScan['scanner']">{{ __('Scan ports') }}</x-button>
    </div>
    @if ($result && $result->ports)
        <div class="table-responsive">
        <table class="table table-sm small mb-0">
            <tbody>
                @foreach ($result->ports as $port)
                    <tr>
                        <td class="font-monospace" style="width: 4rem">{{ $port['port'] }}</td>
                        <td class="text-body-secondary" style="width: 5rem">{{ $port['service'] ?? '' }}</td>
                        <td class="font-monospace text-body-secondary text-break">{{ $port['banner'] ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @elseif ($result)
        <div class="small text-body-secondary">{{ __('No open ports') }}</div>
    @else
        <div class="small text-body-secondary">{{ __('Not scanned yet') }}</div>
    @endif
    @if ($result && $result->findings)
        <div class="small text-body-secondary mt-2">
            @foreach ($result->findings as $finding)
                <div class="text-break">{{ $finding }}</div>
            @endforeach
        </div>
    @endif
    @if ($result)
        <div class="small text-body-secondary mt-2" title="{{ $result->scanned_at }}">{{ __('Scanned :time', ['time' => $result->scanned_at->diffForHumans()]) }}</div>
    @endif
</div>
