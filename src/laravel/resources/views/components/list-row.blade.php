{{-- A row of a list-group (<ul class="list-group">): an icon tile (x-icon-tile), the title, a subtitle
     (grey, small), the details under them (default slot) and the actions or the state on the right
     (slot "actions"). title: a text (bold), or a slot (<x-slot:title>) rendered as it is, for a title
     with a badge. iconTitle: the tooltip of the tile. Classes, wire:key, x-show and the like go to the row. --}}
@props(['icon', 'tone' => null, 'title', 'subtitle' => null, 'iconTitle' => null])
<li {{ $attributes->class(['list-group-item d-flex align-items-start gap-3']) }}>
    <x-icon-tile :icon="$icon" :tone="$tone" :title="$iconTitle" />
    <div class="min-w-0 flex-grow-1">
        @if ($title instanceof \Illuminate\View\ComponentSlot)
            {{ $title }}
        @else
            <div class="fw-semibold text-break">{{ $title }}</div>
        @endif
        @if (filled($subtitle))
            <div class="small text-body-secondary text-break">{{ $subtitle }}</div>
        @endif
        {{ $slot }}
    </div>
    @isset($actions)
        <div class="flex-shrink-0">{{ $actions }}</div>
    @endisset
</li>
