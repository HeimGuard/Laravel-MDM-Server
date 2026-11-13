{{-- Refreshes the surrounding dashboard widget, so the counts stay current. --}}
<div class="card card-body h-100 overflow-auto" x-on:mdm-devices-changed.window="$wire.$refresh()">
    <div class="d-flex justify-content-between align-items-start gap-2 lh-1 mb-3">
        <div>
            <h5 class="mb-0">{{ $config['name'] }}</h5>
            <small class="text-body-secondary">{{ $config['description'] }}</small>
        </div>
        <a class="small text-nowrap" href="{{ route('security') }}">{{ __('Open') }}</a>
    </div>

    <div class="d-flex flex-wrap gap-4 mb-3">
        @foreach (\App\Support\SecurityRules::SEVERITY_COLORS as $level => $color)
            @if (in_array($level, ['critical', 'high', 'medium', 'low'], true) || $counts[$level] > 0)
                <div>
                    <div class="fs-2 fw-semibold lh-1 {{ $counts[$level] > 0 ? 'text-'.$color : 'text-body-secondary' }}">{{ $counts[$level] }}</div>
                    <small class="text-body-secondary">{{ __(ucfirst($level)) }}</small>
                </div>
            @endif
        @endforeach
    </div>

    @if ($counts->sum() === 0)
        <div class="text-body-secondary">{{ __('No open security findings.') }}</div>
    @elseif ($listed->isNotEmpty())
        <ul class="list-unstyled small mb-0">
            @foreach ($listed as $finding)
                <li class="d-flex justify-content-between gap-2 mb-1">
                    <span class="text-truncate">
                        <x-badge :color="$finding->severityColor" size="sm" variant="subtle">{{ __(ucfirst($finding->severity)) }}</x-badge>
                        <a wire:navigate href="{{ route('devices', ['selectedDeviceId' => $finding->device_id]) }}">{{ $finding->device?->DisplayName }}</a>
                        <span class="text-body-secondary">{{ $finding->message ?: $finding->rule?->name }}</span>
                    </span>
                    <span class="text-body-secondary text-nowrap">{{ $finding->last_seen_at?->diffForHumans() }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
