{{-- Uptime Kuma style monitor of a ping-only device. --}}
@php
    $format = fn ($value, $unit) => $value === null ? '–' : rtrim(rtrim(number_format($value, $unit === '%' ? 2 : 1, '.', ''), '0'), '.').' '.$unit;
    $uptimeColor = fn ($value) => $value === null ? '' : ($value >= 99 ? 'text-success' : ($value >= 95 ? 'text-warning-emphasis' : 'text-danger'));
@endphp
@php
    // The top of the response time chart: a round value above the highest point.
    $peak = max(1, (float) $max * 1.15);
    $step = 10 ** floor(log10($peak));
    $top = collect([1, 2, 5, 10])->map(fn ($m) => $m * $step)->first(fn ($v) => $v >= $peak);
    $noRelay = '<span title="'.e(__('It needs an online agent 1.10.0+ in its network that does not run on a battery, or one chosen in its ping settings (another subnet).')).'">'.e(__('No agents available to send ping')).'</span>';
    $checkedBy = $relay ? e(__('Every 30 s by :relay', ['relay' => $relay->displayName])) : $noRelay;
    // The MAC and its vendor are in the Ping card below.
    $subtitle = $checkedBy;
    $vendor = $device->ping_mac ? \App\Support\MacVendor::lookup($device->ping_mac) : null;
@endphp
{{-- Uptime Kuma style monitor of a ping-only device, laid out like the performance of an agent. --}}
<div class="mt-4">
    @include('partials.chart-section-header', [
        'icon' => 'fas fa-network-wired',
        'tone' => 'success',
        'title' => e(__('Ping')).' · <span class="font-monospace">'.e($device->ping_address).'</span>',
        'subtitle' => $subtitle,
        'ranges' => array_keys(\App\Livewire\PingMonitor::RANGES),
        'range' => $range,
    ])

    {{-- One chart over both columns of the performance section. --}}
    <div class="row g-3">
        <div class="col-12">
            <x-metric-chart
                :current="$format($stats['current'], 'ms')"
                :from="$from->format($range === '1h' || $range === '24h' ? 'H:i' : 'j. n.')"
                :labels="$labels"
                :marks="$downs"
                :max="$top"
                :subtitle="__('Average :value', ['value' => $format($stats['average'], 'ms')]).' · '.__('Uptime :value', ['value' => $format($stats['uptime'], '%')]).' ('.$range.')'"
                :title="__('Response time')"
                :to="now()->format($range === '1h' || $range === '24h' ? 'H:i' : 'j. n.')"
                :values="$points"
                color="var(--bs-success)"
                unit="ms"
            />
        </div>
    </div>

    {{-- What is pinged and by whom, and the open ports an agent in its network found on it. --}}
    <div class="row g-3 mt-1">
        <div class="col-12 col-lg-6">
            <div class="card card-body h-100">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                    <span class="fw-semibold">{{ __('Ping') }}</span>
                    <x-button variant="outline-secondary" class="text-nowrap"
                        x-on:click="Livewire.dispatch('openModal', {livewireComponents: 'ping-settings', title: @js(__('Ping settings of :device', ['device' => $device->displayName])), parameters: {deviceId: {{ $device->id }}}})">{{ __('Ping settings') }}</x-button>
                </div>
                <dl class="row small mb-0 row-gap-2">
                    <dt class="col-4 col-sm-3 fw-normal text-body-secondary">{{ __('Address') }}</dt>
                    <dd class="col-8 col-sm-9 mb-0 font-monospace text-break">{{ $device->ping_address }}</dd>
                    <dt class="col-4 col-sm-3 fw-normal text-body-secondary">{{ __('Checked by') }}</dt>
                    <dd class="col-8 col-sm-9 mb-0">{!! $checkedBy !!}</dd>
                    @if ($device->ping_mac)
                        <dt class="col-4 col-sm-3 fw-normal text-body-secondary">MAC</dt>
                        <dd class="col-8 col-sm-9 mb-0 font-monospace">{{ $device->ping_mac }}</dd>
                        @if ($vendor)
                            <dt class="col-4 col-sm-3 fw-normal text-body-secondary">{{ __('Vendor') }}</dt>
                            <dd class="col-8 col-sm-9 mb-0 text-break">{{ $vendor }}</dd>
                        @endif
                    @endif
                </dl>
            </div>
        </div>
        @if ($portScan)
            <div class="col-12 col-lg-6">
                @include('partials.device.open-ports', ['portScan' => $portScan])
            </div>
        @endif
    </div>
</div>
