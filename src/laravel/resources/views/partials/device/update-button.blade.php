{{-- Installs one update ($section, $index of the row, $target from Device::updateTarget, $groupKey from Device::updateGroupKey), or shows its progress; while "Install all" runs ($updatesRunning), where the row stands. --}}
@php
    $running = $target ? \App\Models\Device::findActive($activeCommands, 'installUpdate', $target) : null;
    $rowState = $target && $updatesRunning ? \App\Models\Device::updateRowState($updatesRunning, $groupKey ?? '', $target) : null;
    $call = "installUpdate('{$section}', {$index}, " . \Illuminate\Support\Js::from($target['id'] ?? '') . ")";
@endphp
@if ($running)
    <div style="min-width: 8rem;">@include('partials.device.command-progress', ['command' => $running, 'compact' => true])</div>
@elseif ($rowState === 'running')
    <span class="small text-primary text-nowrap" title="{{ __('Installing now') }}"><span aria-hidden="true" class="spinner-border spinner-border-sm me-1"></span>{{ __('Installing') }}</span>
@elseif ($rowState === 'done')
    <span class="small text-success text-nowrap" title="{{ __('Installed') }}"><i class="fas fa-check me-1"></i>{{ __('Done') }}</span>
@elseif ($rowState === 'queued')
    <span class="small text-muted text-nowrap" title="{{ __('Waiting for its turn') }}"><i class="far fa-clock me-1"></i>{{ __('Queued') }}</span>
@elseif ($target && ! $updatesRunning)
    <button class="btn btn-sm btn-light text-nowrap" type="button" title="{{ __('Install only this update') }}" aria-label="{{ __('Update') }}"
        wire:click="{{ $call }}" wire:loading.attr="disabled" wire:target="{{ $call }}" @disabled($selectedDevice->offline)>
        <i class="fas fa-download me-sm-1 text-primary" wire:loading.remove wire:target="{{ $call }}"></i>
        <span aria-hidden="true" class="spinner-border spinner-border-sm me-sm-1" wire:loading wire:target="{{ $call }}"></span>
        {{-- An icon only on phones. --}}
        <span class="d-none d-sm-inline">{{ __('Update') }}</span>
    </button>
@endif
