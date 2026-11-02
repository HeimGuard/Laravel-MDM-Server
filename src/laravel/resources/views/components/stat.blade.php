{{-- A stat tile (boilerplate .stat, .stat-ico): always an icon in a coloured tile, the label and the
     value. tone: green, red, orange, yellow, purple or none (neutral); a zero is faint. --}}
@props(['label', 'value', 'icon', 'tone' => null, 'faint' => null])
<div {{ $attributes->class(['card card-body h-100']) }}>
    <div class="stat gap-3">
        <div class="stat-ico flex-shrink-0 {{ $tone ? 'is-'.$tone : '' }} {{ ($faint ?? $value === 0) ? 'opacity-50' : '' }}"><i class="{{ $icon }}"></i></div>
        <div class="min-w-0">
            <div class="stat-name">{{ $label }}</div>
            <div class="stat-value text-truncate">{{ $value }}</div>
        </div>
    </div>
</div>
