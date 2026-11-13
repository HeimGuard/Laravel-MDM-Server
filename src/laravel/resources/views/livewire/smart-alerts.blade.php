<div>
    @if ($alerts->isEmpty())
        <div class="text-body-secondary"><i class="fas fa-check-circle text-success me-2"></i>{{ __('All devices are fine.') }}</div>
    @else
        <div class="d-flex flex-wrap gap-2 mb-2">
            @foreach (['danger' => __('Critical'), 'warning' => __('Warning'), 'info' => __('Info'), 'secondary' => __('Offline')] as $severity => $label)
                @if ($counts[$severity] ?? 0)
                    <x-badge :color="$severity" size="sm" variant="subtle">{{ $label }} {{ $counts[$severity] }}</x-badge>
                @endif
            @endforeach
        </div>

        @if ($bulk->isNotEmpty())
            <div class="d-flex flex-wrap gap-2 mb-2">
                @foreach ($bulk as $key => $group)
                    <x-button :icon="$group['action']['icon']"
                        wire:click="runForAll('{{ $key }}')" wire:loading.attr="disabled" wire:target="runForAll('{{ $key }}')"
                        wire:confirm="{{ __(':action on :count devices?', ['action' => $group['action']['label'], 'count' => $group['count']]) }}"
                        title="{{ $group['title'] }}">{{ __(':action (:count devices)', ['action' => $group['action']['label'], 'count' => $group['count']]) }}</x-button>
                @endforeach
            </div>
        @endif

        <div class="list-group list-group-flush">
            @foreach ($listed as $item)
                @include('partials.device.alert', ['alert' => $item['alert'], 'device' => $item['device'], 'showDevice' => true, 'deviceArgument' => true])
            @endforeach
        </div>
        @if ($alerts->count() > $listed->count())
            <small class="text-body-secondary">{{ __('and :count more', ['count' => $alerts->count() - $listed->count()]) }}</small>
        @endif
    @endif
</div>
