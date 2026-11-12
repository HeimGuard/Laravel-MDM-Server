{{-- Installs one update ($section, $index of the row, $target from Device::updateTarget, $groupKey from Device::updateGroupKey), or shows where it stands (Queued, Installing, Done), for its own command and while "Install all" runs ($updatesRunning) alike. --}}
@php
    $running = $target ? \App\Models\Device::findActive($activeCommands, 'installUpdate', $target) : null;
    $rowState = $target && $updatesRunning ? \App\Models\Device::updateRowState($updatesRunning, $groupKey ?? '', $target) : null;
    $call = "installUpdate('{$section}', {$index}, " . \Illuminate\Support\Js::from($target['id'] ?? '') . ")";
@endphp
{{-- A row's own command looks like a row of "Install all": Queued until the agent runs it, then Installing (with its percent). --}}
@if ($running && $running->status === 'running')
    <x-status-label spinner title="{{ $running->label }} · {{ $running->displayMessage ?: __('Installing now') }}" tone="primary">{{ __('Installing') }}{{ $running->progress !== null ? ' '.$running->progress.' %' : '' }}</x-status-label>
@elseif ($running)
    <x-status-label icon="far fa-clock" title="{{ $running->label }} · {{ $running->statusLabel }}">{{ __('Queued') }}</x-status-label>
@elseif ($rowState === 'running')
    <x-status-label spinner title="{{ __('Installing now') }}" tone="primary">{{ __('Installing') }}</x-status-label>
@elseif ($rowState === 'done')
    <x-status-label icon="fas fa-check" title="{{ __('Installed') }}" tone="success">{{ __('Done') }}</x-status-label>
@elseif ($rowState === 'queued')
    <x-status-label icon="far fa-clock" title="{{ __('Waiting for its turn') }}">{{ __('Queued') }}</x-status-label>
@elseif ($target && ! $updatesRunning)
    <button class="btn btn-sm btn-light text-nowrap" type="button" title="{{ __('Install only this update') }}" aria-label="{{ __('Update') }}"
        wire:click="{{ $call }}" wire:loading.attr="disabled" wire:target="{{ $call }}" @disabled($selectedDevice->offline)>
        <i class="fas fa-download me-sm-1 text-primary" wire:loading.remove wire:target="{{ $call }}"></i>
        <span aria-hidden="true" class="spinner-border spinner-border-sm me-sm-1" wire:loading wire:target="{{ $call }}"></span>
        {{-- An icon only on phones. --}}
        <span class="d-none d-sm-inline">{{ __('Update') }}</span>
    </button>
@endif
