{{-- The header of a card or a section: an icon tile (x-icon-tile), the title with an optional subtitle
     and the actions on the right (buttons, a switch, a count, a badge) in the default slot.
     title / subtitle: a text, or a slot (<x-slot:title>) for markup; tag: "h2" and the like make the
     title a heading (small, bold). On phones the actions wrap below the title (no horizontal scroll).
     Classes (mb-0, gap-3, ...) and other attributes go to the row. --}}
@props(['icon', 'tone' => null, 'title', 'subtitle' => null, 'tag' => 'span'])
<div {{ $attributes->class(['d-flex flex-wrap flex-md-nowrap align-items-center gap-2 mb-2']) }}>
    <x-icon-tile :icon="$icon" :tone="$tone" />
    <div class="me-auto min-w-0">
        <{{ $tag }} @class(['d-block fw-semibold', 'h6 mb-0' => $tag !== 'span'])>{{ $title }}</{{ $tag }}>
        @if (filled($subtitle))
            <div class="small text-muted">{{ $subtitle }}</div>
        @endif
    </div>
    @if (trim((string) $slot) !== '')
        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 flex-shrink-0 ms-auto">{{ $slot }}</div>
    @endif
</div>
