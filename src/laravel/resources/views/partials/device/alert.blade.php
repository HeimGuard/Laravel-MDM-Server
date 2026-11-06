{{--
    One smart alert (App\Support\SmartAlerts) with its action. $device is the alert's device;
    $showDevice lists the device name (dashboard widget); the action calls runAlert($key) or,
    with $deviceArgument, runAlert($deviceId, $key).
--}}
@php
    $action = $alert['action'];
    $active = $alert['active'];
    $tone = $alert['severity'];
    $call = ($deviceArgument ?? false) ? "runAlert({$device->id}, '{$alert['key']}')" : "runAlert('{$alert['key']}')";
    $dismiss = ($deviceArgument ?? false) ? "dismiss({$device->id}, '{$alert['key']}')" : "dismiss('{$alert['key']}')";
    // $asAlert: a standalone Bootstrap alert (device detail), otherwise a list row (widget).
    $asAlert = $asAlert ?? false;
@endphp
<div class="{{ $asAlert ? 'smart-alert' : 'list-group-item d-flex gap-3 align-items-start px-0' }}" role="{{ $asAlert ? 'alert' : 'listitem' }}" wire:key="alert-{{ $device->id }}-{{ $alert['key'] }}">
    <span class="icon-tile bg-{{ $tone }}-subtle text-{{ $tone }}-emphasis"><i class="{{ $alert['icon'] }}"></i></span>
    {{-- Text and actions wrap: on narrow screens the buttons go below the text. --}}
    {{-- Actions sit in the top right corner, next to the title. --}}
    <div class="flex-grow-1 min-w-0 d-flex flex-wrap align-items-start column-gap-3 row-gap-2">
    <div class="flex-grow-1 min-w-0" style="flex-basis: 14rem;">
        @if ($showDevice ?? false)
            <a class="small fw-semibold text-decoration-none d-block text-truncate" wire:navigate href="{{ route('devices', array_filter(['selectedDeviceId' => $device->id, 'tab' => $alert['tab']])) }}">
                <i class="{{ $device->typeIcon }} me-1 text-body-secondary"></i>{{ $device->displayName }}
                @if ($device->offline && $alert['key'] !== 'offline')
                    <x-badge class="ms-1" color="secondary" size="sm" variant="subtle">{{ __('Offline') }}</x-badge>
                @endif
            </a>
        @endif
        <div class="fw-semibold">{{ $alert['title'] }}</div>
        @if ($alert['message'])
            <div class="small text-body-secondary text-break">{{ $alert['message'] }}</div>
        @endif
        @if ($alert['details'] ?? [])
            <details class="small text-body-secondary">
                <summary>{{ trans_choice(':count more|:count more', count($alert['details'])) }}</summary>
                <ul class="mb-0 ps-3 text-break">
                    @foreach ($alert['details'] as $detail)
                        <li>{{ $detail }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
        @if ($alert['failure'])
            <div class="small text-danger"><i class="fas fa-times-circle me-1"></i>{{ __('Last attempt failed: :reason', ['reason' => $alert['failure']]) }}</div>
        @endif
        @error('alert.'.($deviceArgument ?? false ? $device->id.'.' : '').$alert['key'])
            <div class="small text-danger">{{ $message }}</div>
        @enderror
        @if ($alert['copy'] && ! ($showDevice ?? false))
            <details class="mt-2">
                <summary class="small text-body-secondary">{{ __('Or run on the device (:how)', ['how' => $device->platform === 'linux' ? 'sudo pwsh' : __('PowerShell as Administrator')]) }}</summary>
                <div class="mt-2"><x-copy-command :command="$alert['copy']" /></div>
            </details>
        @endif
    </div>
    <div class="d-flex flex-wrap justify-content-end gap-2 flex-shrink-0 ms-auto">
        @if ($action)
            <button class="btn btn-sm btn-light text-nowrap" type="button"
                wire:click="{{ $call }}"
                wire:loading.attr="disabled" wire:target="{{ $call }}"
                @if ($action['confirm']) wire:confirm="{{ $action['confirm'] }}" @endif
                {{-- On its way: only the spinner in the button, the state in its tooltip. --}}
                @if ($active) title="{{ $active->displayMessage ?: $active->statusLabel }}{{ $active->progress !== null && $active->status === 'running' ? ' · ' . $active->progress . ' %' : '' }}" @elseif ($alert['refusal']) title="{{ $alert['refusal'] }}" @endif
                @disabled($active || $alert['refusal'])>
                @if ($active)
                    <span aria-hidden="true" class="spinner-border spinner-border-sm me-1 text-body-secondary"></span>
                @else
                    <i class="{{ $action['icon'] }} me-1 text-{{ $tone === 'secondary' ? 'body-secondary' : $tone }}" wire:loading.remove wire:target="{{ $call }}"></i>
                    <span aria-hidden="true" class="spinner-border spinner-border-sm me-1" wire:loading wire:target="{{ $call }}"></span>
                @endif
                {{ $action['label'] }}
            </button>
        @endif
        {{-- Hidden until the alert changes, or goes away and comes back. --}}
        <button class="btn btn-sm btn-sq text-body-secondary" type="button" title="{{ __('Dismiss') }}" aria-label="{{ __('Dismiss') }}"
            wire:click="{{ $dismiss }}" wire:loading.attr="disabled" wire:target="{{ $dismiss }}">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>
</div>
