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
    $subtitle = ($relay ? e(__('Every 30 s by :relay', ['relay' => $relay->displayName])) : '<span title="'.e(__('It needs an online agent 1.10.0+ in its network that does not run on a battery, or one chosen in its ping settings (another subnet).')).'">'.e(__('No agents available to send ping')).'</span>')
        .($device->ping_mac ? ' · MAC <span class="font-monospace">'.e($device->ping_mac).'</span>'.(($vendor = \App\Support\MacVendor::lookup($device->ping_mac)) ? ' <span class="text-body-secondary">· '.e($vendor).'</span>' : '') : '');
@endphp
{{-- Uptime Kuma style monitor of a ping-only device, laid out like the performance of an agent. --}}
<div class="mt-4">
    @include('partials.chart-section-header', [
        'icon' => 'fas fa-network-wired',
        'tile' => 'bg-success-subtle text-success-emphasis',
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
</div>
