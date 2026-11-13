@props([
    'title',
    'values' => [],
    'labels' => [],
    'from' => null,
    'to' => null,
    'current' => null,
    'subtitle' => null,
    'color' => 'var(--bs-primary)',
    'unit' => '%',
    // The value at the top of the chart (100 for percentages).
    'max' => 100,
    // Per point: true marks it (e.g. a ping without an answer), shaded red.
    'marks' => [],
])

@php
    // Values from 0 to $max; null means no data for that point.
    $max = max((float) $max, 0.001);
    $count = max(count($values), 2);
    $x = fn ($i) => round($i / ($count - 1) * 300, 2);
    $y = fn ($v) => round(100 - max(0, min(100, $v / $max * 100)), 2);
    $gridLabel = function ($grid) use ($max, $unit) {
        $value = number_format($max * $grid / 100, $max >= 20 ? 0 : 1, '.', '');
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

        return $value.($unit === '%' ? '%' : ' '.$unit);
    };

    $segments = [];
    $run = [];
    foreach ($values as $i => $value) {
        if ($value === null) {
            if ($run) { $segments[] = $run; }
            $run = [];
            continue;
        }
        $run[] = [$x($i), $y($value)];
    }
    if ($run) { $segments[] = $run; }
@endphp

<div {{ $attributes->class('card h-100') }}>
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-baseline mb-2">
            <div>
                <div class="text-body-secondary small">{{ $title }}</div>
                <div class="fs-4 fw-semibold">{{ $current ?? '–' }}</div>
            </div>
            @if ($subtitle)
                <div class="text-body-secondary small text-end">{{ $subtitle }}</div>
            @endif
        </div>

        <div class="position-relative" style="padding-right: 2.75rem;">
        @foreach ([75, 50, 25] as $grid)
            <small class="position-absolute end-0 text-body-secondary lh-1" style="top: calc({{ 100 - $grid }}% - 0.35rem); font-size: 0.65rem;">{{ $gridLabel($grid) }}</small>
        @endforeach
        <svg class="w-100 d-block" height="110" preserveAspectRatio="none" role="img" viewBox="0 0 300 100" aria-label="{{ $title }}" style="overflow: visible;">
            @foreach ([25, 50, 75] as $grid)
                <line stroke="var(--bs-border-color)" stroke-dasharray="2 3" stroke-width="1" vector-effect="non-scaling-stroke" x1="0" x2="300" y1="{{ 100 - $grid }}" y2="{{ 100 - $grid }}" />
            @endforeach
            <line stroke="var(--bs-border-color)" stroke-width="1" vector-effect="non-scaling-stroke" x1="0" x2="300" y1="100" y2="100" />
            @foreach ($marks as $i => $mark)
                @if ($mark)
                    <rect fill="var(--bs-danger)" fill-opacity="0.15" height="100" width="{{ round(300 / $count, 2) }}" x="{{ max(0, $x($i) - 150 / $count) }}" y="0" />
                @endif
            @endforeach

            @foreach ($segments as $segment)
                @php
                    $line = collect($segment)->map(fn ($p) => $p[0].','.$p[1])->implode(' ');
                    $area = 'M'.$segment[0][0].',100 L'.str_replace(' ', ' L', $line).' L'.end($segment)[0].',100 Z';
                @endphp
                <path d="{{ $area }}" fill="{{ $color }}" fill-opacity="0.15" />
                @if (count($segment) > 1)
                    <polyline fill="none" points="{{ $line }}" stroke="{{ $color }}" stroke-linejoin="round" stroke-width="2" vector-effect="non-scaling-stroke" />
                @else
                    <circle cx="{{ $segment[0][0] }}" cy="{{ $segment[0][1] }}" fill="{{ $color }}" r="1.5" />
                @endif
            @endforeach

            @foreach ($values as $i => $value)
                <rect fill="transparent" height="100" width="{{ round(300 / $count, 2) }}" x="{{ max(0, $x($i) - 150 / $count) }}" y="0">
                    <title>{{ $labels[$i] ?? '' }}: {{ $value === null ? '–' : $value.' '.$unit }}</title>
                </rect>
            @endforeach
        </svg>
        </div>

        <div class="d-flex justify-content-between text-body-secondary small mt-1" style="padding-right: 2.75rem;">
            <span>{{ $from ?? ($labels[0] ?? '') }}</span>
            <span>{{ $to ?? (end($labels) ?: '') }}</span>
        </div>
    </div>
</div>
