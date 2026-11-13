{{-- Header of a chart section (performance of an agent, ping of a ping-only device): an icon tile
     (x-card-header, $tone: primary by default) with the title and what the data comes from, the range switch on the right. --}}
<x-card-header class="gap-3" :icon="$icon" tag="h2" :tone="$tone ?? 'primary'">
    <x-slot:title>{!! $title !!}</x-slot:title>
    @if (! empty($subtitle))
        <x-slot:subtitle>{!! $subtitle !!}</x-slot:subtitle>
    @endif
    {{-- Boilerplate segmented switch. --}}
    <ul class="nav nav-switch flex-shrink-0 ms-auto" role="tablist">
        @foreach ($ranges as $option)
            <li class="nav-item">
                <button class="nav-link {{ $range === $option ? 'active' : '' }}" type="button" wire:click="setRange('{{ $option }}')">{{ $option }}</button>
            </li>
        @endforeach
    </ul>
</x-card-header>
