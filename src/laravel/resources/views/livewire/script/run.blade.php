<div x-data="{ search: '' }">
    <p class="small text-body-secondary">
        {{ __('Platform') }}: {{ __(\App\Models\Script::PLATFORMS[$script->platform]) }} · v{{ $script->version }} · <code title="{{ $script->fingerprint }}">{{ substr($script->fingerprint, 0, 12) }}…</code>.
        {{ __('Offline devices run it when they come back within 24 hours.') }}
    </p>

    <div class="d-flex gap-2 mb-2">
        <x-search-input :width="null" x-model="search" />
        <button class="btn btn-sm btn-light text-nowrap" type="button" wire:click="$set('selected', {{ json_encode($devices->whereNull('reason')->pluck('id')->map(fn ($id) => (string) $id)->values()) }})">{{ __('Select all') }}</button>
        <button class="btn btn-sm btn-light text-nowrap" type="button" wire:click="$set('selected', [])">{{ __('None') }}</button>
    </div>

    <div class="list-group mb-3" style="max-height: 24rem; overflow-y: auto;">
        @forelse ($devices as $device)
            <label class="list-group-item d-flex align-items-center gap-2 {{ $device['reason'] ? 'text-body-secondary' : '' }}" wire:key="run-device-{{ $device['id'] }}" x-show="{{ json_encode(mb_strtolower($device['name'])) }}.includes(search.toLowerCase())">
                <input class="form-check-input m-0" type="checkbox" value="{{ $device['id'] }}" wire:model.live="selected" @disabled($device['reason'])>
                <i class="fab fa-{{ $device['platform'] === 'linux' ? 'linux' : 'windows' }}"></i>
                <span class="me-auto">{{ $device['name'] }}</span>
                @if ($device['reason'])
                    <span class="small">{{ $device['reason'] }}</span>
                @elseif ($device['offline'])
                    <x-badge color="secondary" size="sm" variant="subtle">{{ __('Offline') }}</x-badge>
                @endif
            </label>
        @empty
            <x-empty-state>{{ __('No devices.') }}</x-empty-state>
        @endforelse
    </div>

    <div class="d-flex justify-content-end">
        <button class="btn btn-primary" type="button" wire:click="start" @disabled(count($selected) === 0)>
            <i class="fas fa-play me-1"></i>{{ trans_choice('Run on :count device|Run on :count devices', count($selected), ['count' => count($selected)]) }}
        </button>
    </div>
</div>
