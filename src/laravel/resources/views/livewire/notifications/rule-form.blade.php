{{-- An alert in four short steps: what, when (one sentence), on which devices, where to send. --}}
@php
    $types = \App\Models\AlertRule::TYPES;
    $definition = $types[$type] ?? null;
    $hasUnit = \App\Models\AlertRule::usesUnit($type);
    $gb = $hasUnit && $unit === 'gb';
@endphp
<form wire:submit="save">
    <div class="vstack gap-4">
        <div>
            <div class="small fw-medium text-body-secondary mb-2">{{ __('What') }}</div>
            <div class="d-flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('Alert') }}">
                @foreach ($types as $value => $option)
                    <input autocomplete="off" class="btn-check" id="rule-type-{{ $value }}" type="radio" value="{{ $value }}" wire:model.live="type">
                    <label class="btn btn-sm rounded-pill {{ $type === $value ? 'btn-primary' : 'btn-outline-secondary' }}" for="rule-type-{{ $value }}">
                        <i class="{{ $option['icon'] }} me-1"></i>{{ __($option['label']) }}
                    </label>
                @endforeach
            </div>
        </div>

        <div>
            <div class="small fw-medium text-body-secondary mb-2">{{ __('When') }}</div>
            <div class="rounded border bg-body-tertiary p-3">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    @switch($type)
                        @case('status')
                            <span>{{ __('Offline for at least') }}</span>
                            @break
                        @case('cpu')
                            <span>{{ __('Average usage above') }}</span>
                            @break
                        @case('memory')
                            <span>{{ $gb ? __('Average free memory below') : __('Average usage above') }}</span>
                            @break
                        @case('disk')
                            <span>{{ $gb ? __('A drive with less free space than') : __('A drive fuller than') }}</span>
                            @break
                        @default
                            <span>{{ __($definition['description'] ?? '') }}</span>
                    @endswitch

                    @if (\App\Models\AlertRule::usesThreshold($type))
                        <div class="input-group input-group-sm" style="width: auto">
                            @if ($gb)
                                <input class="form-control @error('limitGb') is-invalid @enderror" aria-label="{{ __('Limit') }}" max="{{ \App\Models\AlertRule::MAX_LIMIT_GB }}" min="0.1" step="0.1" style="width: 5.5rem" type="number" wire:model.live.debounce.500ms="limitGb">
                            @else
                                <input class="form-control @error('threshold') is-invalid @enderror" aria-label="{{ __('Threshold') }}" max="99" min="1" style="width: 4.5rem" type="number" wire:model.live.debounce.500ms="threshold">
                            @endif
                            @if ($hasUnit)
                                @foreach (\App\Models\AlertRule::UNITS as $unitValue => $unitLabel)
                                    <input autocomplete="off" class="btn-check" id="rule-unit-{{ $unitValue }}" type="radio" value="{{ $unitValue }}" wire:model.live="unit">
                                    <label class="btn btn-outline-secondary" for="rule-unit-{{ $unitValue }}">{{ $unitLabel }}</label>
                                @endforeach
                            @else
                                <span class="input-group-text">%</span>
                            @endif
                        </div>
                    @endif

                    @if (\App\Models\AlertRule::usesMinutes($type))
                        @if ($type !== 'status')
                            <span>{{ __('over') }}</span>
                        @endif
                        <div class="input-group input-group-sm" style="width: auto">
                            <input class="form-control @error('minutes') is-invalid @enderror" aria-label="{{ __('Minutes') }}" max="{{ \App\Models\AlertRule::MAX_MINUTES }}" min="1" style="width: 4.5rem" type="number" wire:model.live.debounce.500ms="minutes">
                            <span class="input-group-text">{{ __('min') }}</span>
                        </div>
                    @endif
                </div>
                @foreach (['threshold', 'limitGb', 'minutes'] as $field)
                    @error($field) <div class="small text-danger mt-1">{{ $message }}</div> @enderror
                @endforeach
            </div>
            @if ($preview)
                <div class="small mt-2 {{ $preview['matching'] ? 'text-warning-emphasis' : 'text-body-secondary' }}">
                    @if ($preview['matching'])
                        <i class="fas fa-exclamation-circle me-1"></i>{{ __('Would fire now on: :devices.', ['devices' => implode(', ', array_slice($preview['matching'], 0, 6)).(count($preview['matching']) > 6 ? ' …' : '')]) }}
                    @else
                        <i class="fas fa-check me-1"></i>{{ trans_choice('Holds on none of the :count device now.|Holds on none of the :count devices now.', $preview['checked'], ['count' => $preview['checked']]) }}
                    @endif
                </div>
            @endif
        </div>

        @if (\App\Models\AlertRule::isEvent($type))
            <div>
                <div class="small fw-medium text-body-secondary mb-2">{{ __('On') }}</div>
                <div class="small text-body-secondary"><i class="fas fa-globe me-1"></i>{{ match ($type) {
                    'unknown_device' => __('Every device an agent sees in its network (its ARP table or a scan) that no device of the portal has the MAC address of, from now on, once each. Ignored ones on the Networks page are left out.'),
                    'address_changed' => __('Every time a ping-only device with a MAC address is seen at another IP address (DHCP): the portal follows it, the alert says to reserve the address in the router.'),
                    default => __('Every device enrolled with the agent or added as ping-only from now on, once each.'),
                } }}</div>
            </div>
        @else
            <div>
                <div class="small fw-medium text-body-secondary mb-2">{{ __('On') }}</div>
                @include('partials.target-picker')
                @error('target') <div class="small text-danger mt-1">{{ $message }}</div> @enderror
            </div>
        @endif

        <div>
            <div class="small fw-medium text-body-secondary mb-2">{{ __('Send to') }}</div>
            @if ($channelOptions === [])
                <div class="small text-body-secondary">
                    {{ __('No channels yet: the alert is recorded but not sent.') }}
                    <a href="{{ route('notifications', ['tab' => 'channels']) }}">{{ __('Add a channel') }}</a>
                </div>
            @else
                <div class="d-flex flex-wrap gap-3">
                    @foreach ($channelOptions as $channel => $label)
                        <div class="form-check m-0">
                            <input class="form-check-input" id="rule-channel-{{ $loop->index }}" type="checkbox" value="{{ $channel }}" wire:model="channels">
                            <label class="form-check-label small" for="rule-channel-{{ $loop->index }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>
                @error('channels') <div class="small text-danger mt-1">{{ $message }}</div> @enderror
            @endif
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 border-top pt-3 mt-4">
        @if ($ruleId)
            <button class="btn btn-link text-danger px-0 me-auto" type="button" wire:click="delete" wire:confirm="{{ __('Delete this alert?') }}">{{ __('Delete alert') }}</button>
        @else
            <span class="me-auto"></span>
        @endif
        <button class="btn btn-light" type="button" wire:click="$dispatch('closeModal')">{{ __('Cancel') }}</button>
        <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
    </div>
</form>
