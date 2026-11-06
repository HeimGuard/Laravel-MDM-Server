{{-- Refreshes the surrounding dashboard widget, so the counts stay current. --}}
<div class="card card-body h-100" x-on:mdm-devices-changed.window="$wire.$refresh()">
    <div class="lh-1 mb-3">
        <h5 class="mb-0">{{ $config['name'] }}</h5>
        <small class="text-body-tertiary">{{ $config['description'] }}</small>
    </div>

    @if ($total === 0)
        <div class="text-body-secondary">{{ __('No devices yet.') }}</div>
    @else
        <div class="d-flex gap-4 mb-2">
            <div>
                <div class="fs-2 fw-semibold text-success lh-1">{{ $online }}</div>
                <small class="text-body-secondary">{{ __('Online') }}</small>
            </div>
            <div>
                <div class="fs-2 fw-semibold {{ $offline->isEmpty() ? 'text-body-secondary' : 'text-danger' }} lh-1">{{ $offline->count() }}</div>
                <small class="text-body-secondary">{{ __('Offline') }}</small>
            </div>
            <div class="ms-auto text-end">
                <div class="fs-2 fw-semibold lh-1">{{ $total }}</div>
                <small class="text-body-secondary">{{ __('Total') }}</small>
            </div>
        </div>

        <div class="progress mb-3" role="progressbar" style="height: 6px;" aria-valuenow="{{ $online }}" aria-valuemin="0" aria-valuemax="{{ $total }}">
            <div class="progress-bar bg-success" style="width: {{ round($online / $total * 100) }}%"></div>
        </div>

        @if ($listed->isNotEmpty())
            <ul class="list-unstyled small mb-0">
                @foreach ($listed as $device)
                    <li class="d-flex justify-content-between gap-2">
                        <a class="text-truncate" wire:navigate href="{{ route('devices', ['selectedDeviceId' => $device->id]) }}">
                            <i class="{{ $device->typeIcon }} text-body-secondary me-1"></i>{{ $device->DisplayName }}
                        </a>
                        <span class="text-body-secondary text-nowrap">{{ ($device->last_seen_at ?? $device->updated_at)?->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($offline->count() > $listed->count())
                <small class="text-body-secondary">{{ __('and :count more', ['count' => $offline->count() - $listed->count()]) }}</small>
            @endif
        @endif
    @endif
</div>
