<div class="list-group-item d-flex align-items-start gap-3" wire:key="alert-event-{{ $event->id }}">
    <i class="fas fa-circle small mt-1 {{ $event->resolved_at ? 'text-success' : 'text-danger' }}"></i>
    <div class="flex-grow-1 min-w-0">
        <div>
            <a wire:navigate href="{{ route('devices', ['selectedDeviceId' => $event->device_id]) }}">{{ $event->device?->displayName }}</a>
            · {{ $event->rule?->label }}
        </div>
        <div class="small text-body-secondary text-break">{{ $event->message }}</div>
    </div>
    <div class="small text-body-secondary text-end text-nowrap">
        <div title="{{ $event->triggered_at }}">{{ $event->triggered_at->diffForHumans() }}</div>
        @if ($event->resolved_at)
            <div title="{{ $event->resolved_at }}">{{ __('resolved after :duration', ['duration' => $event->triggered_at->diffForHumans($event->resolved_at, \Carbon\CarbonInterface::DIFF_ABSOLUTE, true, 2)]) }}</div>
        @else
            <x-badge color="danger" size="sm" variant="subtle">{{ __('Active') }}</x-badge>
        @endif
    </div>
</div>
