@use('App\Support\SecurityParsers')
@use('App\Support\SecurityRules')
{{-- A rule in JSON beside a short reference, checked while typing and tried on a device. --}}
<form wire:submit="save">
    <div class="row g-4">
        <div class="col-12 col-lg-7">
            @if ($readOnly)
                <div class="alert alert-info small py-2">{{ __('A built-in rule: switch it off in the list, or copy it to change it.') }}</div>
            @endif
            {{-- Ace editor (steelants/form) like the remediation scripts; it grows with the text. --}}
            <div x-init="$nextTick(() => $el.querySelector('.ace-editor')?.env?.editor?.setOptions({ minLines: 14, maxLines: 28, showPrintMargin: false, readOnly: @js($readOnly) }))">
                <x-form::ace id="rule-json" label="{{ $isParser ? __('Parser (JSON)') : __('Rule (JSON)') }}" language="json" theme="tomorrow_night" wire:model.live.debounce.500ms="json" />
            </div>
            @error('json') <div class="small text-danger mt-1">{{ $message }}</div> @enderror
            @if ($problems !== [])
                <ul class="small text-danger mt-2 mb-0 ps-3">
                    @foreach ($problems as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            @else
                <div class="small text-success mt-2"><i class="fas fa-check me-1"></i>{{ __('The rule is valid.') }}</div>
            @endif

            @if ($isParser)
                <div class="mt-3">
                    <label class="small fw-medium text-muted mb-1" for="parser-sample">{{ __('Try on a record (a log line, or a record in JSON)') }}</label>
                    <textarea class="form-control font-monospace small" id="parser-sample" rows="3" spellcheck="false" wire:model.live.debounce.500ms="sample"></textarea>
                    @if ($parsed !== null)
                        @if (isset($parsed['error']))
                            <div class="small text-danger mt-1">{{ $parsed['error'] }}</div>
                        @elseif ($parsed['event'] === null)
                            <div class="small text-muted mt-1">{{ __('The parser does not match this record.') }}</div>
                        @else
                            <table class="table table-sm small mt-2 mb-0">
                                <tbody>
                                    @foreach ($parsed['event'] as $field => $value)
                                        <tr><td class="text-muted text-nowrap">{{ $field }}</td><td class="text-break">{{ $value ?? '—' }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    @endif
                </div>
            @else
            <div class="mt-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="small fw-medium text-muted">{{ __('Try on') }}</span>
                    <select class="form-select form-select-sm w-auto" wire:model.live="previewDeviceId" aria-label="{{ __('Device') }}">
                        @forelse ($devices as $device)
                            <option value="{{ $device->id }}">{{ $device->displayName }}</option>
                        @empty
                            <option value="">{{ __('No device with an inventory yet') }}</option>
                        @endforelse
                    </select>
                </div>
                @if ($preview !== null)
                    @if ($preview === [])
                        <div class="small text-muted">{{ __('Nothing found on :device.', ['device' => $previewDevice->displayName]) }}</div>
                    @else
                        <div class="small fw-medium mb-1">{{ trans_choice(':count finding|:count findings', count($preview)) }}</div>
                        <ul class="small mb-0 ps-3" style="max-height: 12rem; overflow: auto">
                            @foreach (array_slice($preview, 0, 50) as $match)
                                <li class="text-break">{{ $match['message'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>
            @endif
        </div>

        <div class="col-12 col-lg-5 small">
            @if ($isParser)
            <div class="fw-medium mb-1">{{ __('Parser keys') }}</div>
            <ul class="ps-3 text-muted">
                <li><code>kind</code>: <code>parser</code>; <code>key</code>, <code>name</code>, <code>description</code></li>
                <li><code>source</code>: {{ __('which log, below') }}</li>
                <li><code>when</code>: {{ __('conditions on the record, as in detection rules (optional)') }}</li>
                <li><code>pattern</code>: {{ __('a regular expression with named groups') }} <code>(?&lt;user&gt;\S+)</code>; <code>field</code>: {{ __('what it is matched against (Message)') }}</li>
                <li><code>event</code>: <code>type</code>, <code>user</code>, <code>source</code>, <code>message</code>, <code>count</code> {{ __('with {group} or {Field} placeholders; {a|b} takes the first that is set') }}</li>
            </ul>
            <div class="text-muted mb-3">{{ __('The first parser that matches a record makes its event; events are grouped by type, user and source and go to the detection rules of the events source.') }}</div>
            <div class="fw-medium mb-1">{{ __('Logs and their fields') }}</div>
            <table class="table table-sm small mb-0">
                <tbody>
                    @foreach (SecurityParsers::LOG_SOURCES as $source => $definition)
                        <tr>
                            <td class="text-nowrap"><code>{{ $source }}</code></td>
                            <td class="text-muted">{{ implode(', ', $definition['fields']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <div class="fw-medium mb-1">{{ __('Keys') }}</div>
            <ul class="ps-3 text-muted">
                <li><code>key</code>, <code>name</code>, <code>description</code>, <code>remediation</code></li>
                <li><code>severity</code>: {{ implode(', ', array_keys(SecurityRules::SEVERITIES)) }}</li>
                <li><code>platform</code>: {{ implode(', ', SecurityRules::PLATFORMS) }}</li>
                <li><code>source</code>: {{ __('which list it looks at, below') }}</li>
                <li><code>when</code>: <code>{"field": "Name", "op": "contains", "value": "…"}</code>, {{ __('grouped with') }} <code>{"all": […]}</code>, <code>{"any": […]}</code>, <code>{"not": {…}}</code></li>
                <li><code>message</code>: {{ __('with {Field} placeholders') }}</li>
                <li><code>threshold</code>: {{ __('one finding when at least N items match ({count}, {items})') }}</li>
                <li><code>unseen_days</code>, <code>unseen_by</code>: {{ __('events: only a type with a User / Source no device had in the last N days') }}</li>
            </ul>
            <div class="fw-medium mb-1">{{ __('Operators') }}</div>
            <div class="text-muted mb-3"><code>{{ implode(' ', SecurityRules::OPERATORS) }}</code><br>{{ __('Texts ignore case; matches is a regular expression.') }}</div>
            <div class="fw-medium mb-1">{{ __('Sources and their fields') }}</div>
            <table class="table table-sm small mb-0">
                <tbody>
                    @foreach (SecurityRules::SOURCES as $source => $definition)
                        <tr>
                            <td class="text-nowrap"><code>{{ $source }}</code></td>
                            <td class="text-muted">{{ implode(', ', $definition['fields']) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td class="text-nowrap text-muted">{{ __('Event types') }}</td>
                        <td class="text-muted">{{ implode(', ', array_keys(SecurityRules::EVENT_TYPES)) }} {{ __('(and the ones of your parsers)') }}</td>
                    </tr>
                </tbody>
            </table>
            @endif
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 border-top pt-3 mt-4">
        @if ($ruleId && ! $readOnly)
            <button class="btn btn-link text-danger px-0 me-auto" type="button" wire:click="delete" wire:confirm="{{ __('Delete this rule and its findings?') }}">{{ __('Delete rule') }}</button>
        @else
            <span class="me-auto"></span>
        @endif
        <button class="btn btn-light" type="button" wire:click="$dispatch('closeModal')">{{ $readOnly ? __('Close') : __('Cancel') }}</button>
        @unless ($readOnly)
            <button class="btn btn-primary" type="submit" @disabled($problems !== [])>{{ __('Save') }}</button>
        @endunless
    </div>
</form>
