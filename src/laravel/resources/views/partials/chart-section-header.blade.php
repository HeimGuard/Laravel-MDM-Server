{{-- Header of a chart section (performance of an agent, ping of a ping-only device): an icon tile
     (x-icon-tile, $tone: primary by default) with the title and what the data comes from, the range switch on the right. --}}
<div class="d-flex flex-wrap flex-md-nowrap align-items-center gap-3 mb-2">
    <x-icon-tile :icon="$icon" :tone="$tone ?? 'primary'" />
    <div class="me-auto min-w-0">
        <h2 class="h6 fw-semibold mb-0">{!! $title !!}</h2>
        @if (! empty($subtitle))
            <div class="small text-muted">{!! $subtitle !!}</div>
        @endif
    </div>
    {{-- Boilerplate segmented switch. --}}
    <ul class="nav nav-switch flex-shrink-0 ms-auto" role="tablist">
        @foreach ($ranges as $option)
            <li class="nav-item">
                <button class="nav-link {{ $range === $option ? 'active' : '' }}" type="button" wire:click="setRange('{{ $option }}')">{{ $option }}</button>
            </li>
        @endforeach
    </ul>
</div>
