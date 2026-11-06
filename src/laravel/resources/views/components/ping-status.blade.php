{{-- The last pings of a ping-only device (Uptime Kuma style) with the response and the uptime.
     Not on the device detail (its chart shows the same); for overviews such as the dashboard.
     $beats: the last PingResult rows, oldest first; $stats: current, average, uptime24, uptime30. --}}
@props(['beats', 'stats', 'range' => '24h'])
@php
    $format = fn ($value, $unit) => $value === null ? '–' : rtrim(rtrim(number_format($value, $unit === '%' ? 2 : 1, '.', ''), '0'), '.').' '.$unit;
    $uptimeColor = fn ($value) => $value === null ? '' : ($value >= 99 ? 'text-success' : ($value >= 95 ? 'text-warning-emphasis' : 'text-danger'));
@endphp
<div {{ $attributes->class('card') }}>
    <div class="card-body">
        {{-- The last checks, oldest left; empty slots until there are enough. --}}
        <div class="ping-beats mb-1" role="img" aria-label="{{ __('Last :count pings', ['count' => $beats->count()]) }}">
            @for ($i = $beats->count(); $i < \App\Livewire\PingMonitor::BEATS; $i++)
                <span class="ping-beat"></span>
            @endfor
            @foreach ($beats as $beat)
                <span class="ping-beat {{ $beat->up ? 'is-up' : 'is-down' }}" title="{{ $beat->created_at->format('j. n. H:i:s') }} · {{ $beat->up ? $format($beat->rtt, 'ms') : __('No answer') }}"></span>
            @endforeach
        </div>
        <div class="d-flex justify-content-between small text-muted mb-3">
            <span>{{ $beats->first()?->created_at->diffForHumans() ?? '' }}</span>
            <span>{{ __('now') }}</span>
        </div>

        <div class="row g-3 text-center">
            @foreach ([
                [__('Response'), __('now'), $format($stats['current'] ?? null, 'ms'), ''],
                [__('Average'), $range, $format($stats['average'] ?? null, 'ms'), ''],
                [__('Uptime'), '24 h', $format($stats['uptime24'] ?? null, '%'), $uptimeColor($stats['uptime24'] ?? null)],
                [__('Uptime'), '30 d', $format($stats['uptime30'] ?? null, '%'), $uptimeColor($stats['uptime30'] ?? null)],
            ] as [$label, $period, $value, $class])
                <div class="col-6 col-md-3">
                    <div class="small text-muted">{{ $label }} <span class="opacity-75">({{ $period }})</span></div>
                    <div class="fs-5 fw-semibold {{ $class }}">{{ $value }}</div>
                </div>
            @endforeach
        </div>
    </div>
</div>
