<div>
    <div class="list-group list-group-flush">
        @foreach ($types as $type => $definition)
            @php
                $setting = $settings[$type];
                $hasUnit = \App\Models\AlertRule::usesUnit($type);
                $gb = $hasUnit && $setting['unit'] === 'gb';
            @endphp
            <div class="list-group-item px-0 py-2" wire:key="device-rule-{{ $type }}">
                <div class="d-flex align-items-center gap-3">
                    <i class="{{ $definition['icon'] }} fa-fw text-body-secondary"></i>
                    <label class="flex-grow-1 min-w-0 mb-0" for="device-rule-{{ $type }}">
                        <span class="d-block">{{ __($definition['label']) }}</span>
                        @unless ($setting['enabled'])
                            <span class="d-block small text-body-secondary">{{ __($definition['description']) }}</span>
                        @endunless
                    </label>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" id="device-rule-{{ $type }}" type="checkbox" wire:model.live="settings.{{ $type }}.enabled">
                    </div>
                </div>

                @if ($setting['enabled'] && ($definition['threshold'] !== null || $definition['minutes'] !== null))
                    {{-- The condition as one line, like in the alert form. --}}
                    <div class="d-flex flex-wrap align-items-center gap-2 small text-body-secondary mt-1" style="padding-left: 2.25rem">
                        @if ($definition['threshold'] !== null)
                            <span>
                                @if ($type === 'disk')
                                    {{ $gb ? __('less free than') : __('fuller than') }}
                                @elseif ($gb)
                                    {{ __('free below') }}
                                @else
                                    {{ __('above') }}
                                @endif
                            </span>
                            <div class="input-group input-group-sm" style="width: auto">
                                @if ($gb)
                                    <input class="form-control" aria-label="{{ __('Limit') }}" max="{{ \App\Models\AlertRule::MAX_LIMIT_GB }}" min="0.1" step="0.1" style="width: 5rem" type="number" wire:model.live.debounce.600ms="settings.{{ $type }}.limit_gb">
                                @else
                                    <input class="form-control" aria-label="{{ __('Threshold') }}" max="99" min="1" style="width: 4rem" type="number" wire:model.live.debounce.600ms="settings.{{ $type }}.threshold">
                                @endif
                                @if ($hasUnit)
                                    @foreach (\App\Models\AlertRule::UNITS as $unitValue => $unitLabel)
                                        <input autocomplete="off" class="btn-check" id="device-rule-{{ $type }}-{{ $unitValue }}" type="radio" value="{{ $unitValue }}" wire:model.live="settings.{{ $type }}.unit">
                                        <label class="btn btn-outline-secondary" for="device-rule-{{ $type }}-{{ $unitValue }}">{{ $unitLabel }}</label>
                                    @endforeach
                                @else
                                    <span class="input-group-text">%</span>
                                @endif
                            </div>
                        @endif
                        @if ($definition['minutes'] !== null)
                            <span>{{ $type === 'status' ? __('for at least') : __('over') }}</span>
                            <div class="input-group input-group-sm" style="width: auto">
                                <input class="form-control" aria-label="{{ __('Minutes') }}" max="{{ \App\Models\AlertRule::MAX_MINUTES }}" min="1" style="width: 4rem" type="number" wire:model.live.debounce.600ms="settings.{{ $type }}.minutes">
                                <span class="input-group-text">{{ __('min') }}</span>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    @if ($broader->isNotEmpty())
        <div class="small text-body-secondary mt-3">
            <div class="fw-semibold mb-1">{{ __('Also alerting for this device') }}</div>
            @foreach ($broader as $rule)
                <div class="d-flex flex-wrap align-items-center gap-1"><i class="{{ $rule->icon }} fa-fw"></i>{{ $rule->label }}: {{ $rule->condition }} · <x-target-summary :target="$rule->target ?? []" />{{ $rule->enabled ? '' : ' ('.__('disabled').')' }}</div>
            @endforeach
        </div>
    @endif
    <div class="small text-body-secondary mt-3">
        {{ __('Sent to the channels on the') }} <a href="{{ route('notifications') }}">{{ __('Notifications') }}</a> {{ __('page') }}.
    </div>
</div>
