@use('App\Livewire\SecurityScan\Page')
@use('App\Models\Device')
@use('App\Models\SecurityEvent')
@use('App\Support\SecurityRules')
<div>
    <div class="container-xl">
        <div class="page-header">
            <div class="me-auto min-w-0">
                <h1>{{ __('Security') }}</h1>
                <div class="text-muted">{{ __('Checks look at what the agents report: installed software, processes, open ports, startup items, administrators, security settings and events.') }}</div>
            </div>
            @if ($isAdmin)
                <div class="d-flex gap-2">
                    <button class="btn btn-light" type="button" wire:click="updateFeed" wire:loading.attr="disabled" wire:target="updateFeed" @disabled(! $feed['enabled']) @if (! $feed['enabled']) title="{{ __('No rules feed is set (MDM_SECURITY_FEED_URL).') }}" @endif>
                        <span class="spinner-border spinner-border-sm me-2" wire:loading wire:target="updateFeed"></span><i class="fas fa-cloud-download-alt me-2" wire:loading.remove wire:target="updateFeed"></i>{{ __('Sync feed') }}
                    </button>
                    <button class="btn btn-light" type="button" wire:click="rescan" wire:loading.attr="disabled" wire:target="rescan">
                        <span class="spinner-border spinner-border-sm me-2" wire:loading wire:target="rescan"></span><i class="fas fa-sync me-2" wire:loading.remove wire:target="rescan"></i>{{ __('Scan again') }}
                    </button>
                    <button class="btn btn-primary" type="button" wire:click="addRule">
                        <i class="fas fa-plus me-2"></i>{{ __('Add check') }}
                    </button>
                </div>
            @endif
        </div>
        <x-boilerplate::alerts />

        <div class="row g-3 mb-4">
            @foreach ($counts as $level => $count)
                <div class="col-6 col-md">
                    <button class="card card-body w-100 text-start py-2 {{ $severity === $level ? 'border-'.SecurityRules::SEVERITY_COLORS[$level] : '' }}" type="button"
                        wire:click="$set('severity', '{{ $severity === $level ? '' : $level }}'); $set('tab', 'findings'); $set('status', 'open')">
                        <div class="small text-body-secondary">{{ __(ucfirst($level)) }}</div>
                        <div class="fs-4 fw-semibold {{ $count > 0 ? 'text-'.SecurityRules::SEVERITY_COLORS[$level].'-emphasis' : 'text-body-tertiary' }}">{{ $count }}</div>
                    </button>
                </div>
            @endforeach
        </div>

        <ul class="nav nav-tabs mb-4" role="tablist">
            @foreach (['findings' => __('Findings'), 'events' => __('Events'), 'rules' => __('Checks')] as $value => $label)
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $tab === $value ? 'active' : '' }}" role="tab" type="button" aria-selected="{{ $tab === $value ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $value }}')">{{ $label }}</button>
                </li>
            @endforeach
        </ul>

        @if ($pending > 0)
            <div class="small text-muted mb-3" wire:key="security-pending"><i class="fas fa-hourglass-half me-1"></i>{{ trans_choice(':count collection waits to be processed|:count collections wait to be processed', $pending) }}</div>
        @endif

        @if ($scanned === 0)
            <div class="alert alert-info small" wire:key="security-no-inventory">
                <i class="fas fa-info-circle me-1"></i>{{ __('No device has sent a security inventory yet: it needs agent :version or newer (collected hourly, or now with Sync).', ['version' => Device::SECURITY_VERSION]) }}
            </div>
        @endif

        @if ($tab === 'events')
            <div class="d-flex flex-wrap gap-2 mb-3">
                <select class="form-select form-select-sm w-auto" wire:model.live="eventType" aria-label="{{ __('Type') }}">
                    <option value="">{{ __('All events') }}</option>
                    @foreach ($eventTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select class="form-select form-select-sm w-auto" wire:model.live="eventDevice" aria-label="{{ __('Device') }}">
                    <option value="">{{ __('All devices') }}</option>
                    @foreach ($eventDevices as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                <select class="form-select form-select-sm w-auto" wire:model.live="eventDays" aria-label="{{ __('Period') }}">
                    <option value="1">{{ __('Last 24 hours') }}</option>
                    <option value="7">{{ __('Last 7 days') }}</option>
                    <option value="0">{{ __('All (:days days)', ['days' => SecurityEvent::RETENTION_DAYS]) }}</option>
                </select>
                <input class="form-control form-control-sm w-auto flex-grow-1" placeholder="{{ __('Search user, source or detail') }}" type="search" wire:model.live.debounce.300ms="eventSearch" style="max-width: 20rem">
                <span class="small text-muted align-self-center">{{ __('Kept :days days.', ['days' => SecurityEvent::RETENTION_DAYS]) }}</span>
            </div>
            <div class="card overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr class="small text-body-secondary">
                                <th class="ps-3">{{ __('When') }}</th>
                                <th>{{ __('Device') }}</th>
                                <th>{{ __('Event') }}</th>
                                <th class="text-end">{{ __('Count') }}</th>
                                <th class="d-none d-md-table-cell">{{ __('User') }}</th>
                                <th class="d-none d-md-table-cell">{{ __('Source') }}</th>
                                <th class="d-none d-lg-table-cell pe-3">{{ __('Detail') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($events as $event)
                                <tr wire:key="security-event-{{ $event->id }}">
                                    <td class="ps-3 small text-nowrap text-body-secondary" title="{{ $event->occurred_at }}">{{ $event->occurred_at->diffForHumans() }}</td>
                                    <td class="small"><a href="{{ route('devices', ['selectedDeviceId' => $event->device_id, 'tab' => 'security']) }}">{{ $event->device?->displayName }}</a></td>
                                    <td class="small fw-medium text-nowrap"><span class="badge text-bg-{{ SecurityEvent::TONES[$event->type] ?? 'secondary' }} fw-medium">{{ $event->label }}</span></td>
                                    <td class="small text-end">{{ $event->count }}</td>
                                    <td class="d-none d-md-table-cell small text-break">{{ $event->user ?? '—' }}</td>
                                    <td class="d-none d-md-table-cell small text-break">{{ $event->source ?? '—' }}</td>
                                    <td class="d-none d-lg-table-cell small text-muted text-break pe-3">{{ $event->message }}</td>
                                </tr>
                            @empty
                                <tr><td class="text-muted small py-4 text-center" colspan="7">{{ __('No events.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($moreEvents)
                <div class="text-center mt-3"><button type="button" class="btn btn-sm btn-light" wire:click="showMoreEvents">{{ __('Show more') }}</button></div>
            @endif
        @elseif ($tab === 'rules')
            <div class="card overflow-hidden">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr class="small text-body-secondary">
                                <th class="ps-3">{{ __('Check') }}</th>
                                <th>{{ __('Severity') }}</th>
                                <th class="d-none d-md-table-cell">{{ __('Looks at') }}</th>
                                <th class="d-none d-md-table-cell">{{ __('Platform') }}</th>
                                <th class="text-end">{{ __('Found on') }}</th>
                                <th>{{ __('On') }}</th>
                                <th class="pe-3"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rules as $rule)
                                <tr wire:key="security-rule-{{ $rule->id }}" class="{{ $rule->enabled ? '' : 'opacity-50' }}">
                                    <td class="ps-3">
                                        <div class="fw-medium">{{ $rule->name }}
                                            @unless ($rule->built_in)<x-badge color="primary" size="sm" variant="subtle">{{ __('Custom') }}</x-badge>@endunless
                                        </div>
                                        @if ($rule->definition['description'] ?? null)
                                            <div class="small text-muted">{{ $rule->definition['description'] }}</div>
                                        @endif
                                        @if ($rule->urls)
                                            <div>@foreach ($rule->urls as $link)<a class="small me-2 text-break" href="{{ $link['url'] }}" rel="noopener noreferrer" target="_blank" title="{{ $link['url'] }}"><i class="fas fa-external-link-alt fa-xs me-1"></i>{{ $link['host'] }}</a>@endforeach</div>
                                        @endif
                                        <div class="small text-body-tertiary font-monospace">{{ $rule->key }}</div>
                                    </td>
                                    <td><x-badge :color="$rule->severityColor" size="sm" variant="subtle">{{ __(ucfirst($rule->severity)) }}</x-badge></td>
                                    <td class="d-none d-md-table-cell small">{{ $rule->sourceLabel }}</td>
                                    <td class="d-none d-md-table-cell small">{{ $rule->platform === 'any' ? __('All') : ucfirst($rule->platform) }}</td>
                                    <td class="text-end small">{{ $rule->open_count ? trans_choice(':count device|:count devices', $rule->open_count) : '—' }}</td>
                                    <td>
                                        <div class="form-check form-switch m-0">
                                            <input aria-label="{{ __('Enabled') }}" class="form-check-input" type="checkbox" wire:click="toggleRule({{ $rule->id }})" @checked($rule->enabled) @disabled(! $isAdmin)>
                                        </div>
                                    </td>
                                    <td class="pe-3 text-end text-nowrap">
                                        @if ($isAdmin)
                                            <button class="btn btn-sm btn-light" type="button" wire:click="editRule({{ $rule->id }})">{{ $rule->built_in ? __('Show') : __('Edit') }}</button>
                                            <button class="btn btn-sm btn-light" type="button" wire:click="duplicateRule({{ $rule->id }})" title="{{ __('Copy as a new rule') }}"><i class="far fa-copy"></i></button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <details class="mt-4" wire:key="security-advanced" @if ($parsers->isNotEmpty() && request()->query('advanced')) open @endif>
                <summary class="fw-medium">{{ __('Advanced') }} <span class="small text-muted fw-normal">{{ __('feed, log parsers and JSON') }}</span></summary>
                <div class="mt-3">
                <div class="card card-body mb-4">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <div class="me-auto">
                            <div class="fw-medium">{{ __('Checks feed') }}</div>
                            @if (! $feed['enabled'])
                                <div class="small text-muted">{{ __('No feed is set (MDM_SECURITY_FEED_URL): only the bundled checks are used.') }}</div>
                            @elseif (isset($feed['version']))
                                <div class="small text-muted">
                                    {{ __('Version :version', ['version' => $feed['version']]) }}@isset($feed['checked_at']), {{ __('checked :time', ['time' => \Illuminate\Support\Carbon::parse($feed['checked_at'])->diffForHumans()]) }}@endisset
                                    @if (! ($feed['ok'] ?? true))<span class="text-danger"> · {{ $feed['error'] }}</span>@endif
                                </div>
                            @else
                                <div class="small text-muted">{{ __('Not fetched yet.') }}</div>
                            @endif
                        </div>
                        @if ($isAdmin && $feed['enabled'])
                            <button class="btn btn-sm btn-light" type="button" wire:click="updateFeed">{{ __('Update now') }}</button>
                        @endif
                    </div>
                </div>
            <div class="d-flex align-items-center mt-4 mb-2">
                <h2 class="h6 fw-semibold mb-0 me-auto">{{ __('Log parsers') }}</h2>
                @if ($isAdmin)
                    <button class="btn btn-sm btn-light" type="button" wire:click="addParser"><i class="fas fa-plus me-1"></i>{{ __('Add parser') }}</button>
                @endif
            </div>
            <div class="small text-muted mb-2">{{ __('Agents send raw logs; parsers turn their records into the events the rules of the events source look at.') }}</div>
            <div class="card overflow-hidden">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr class="small text-body-secondary">
                                <th class="ps-3">{{ __('Parser') }}</th>
                                <th class="d-none d-md-table-cell">{{ __('Log') }}</th>
                                <th>{{ __('Event') }}</th>
                                <th>{{ __('On') }}</th>
                                <th class="pe-3"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($parsers as $parser)
                                <tr wire:key="security-parser-{{ $parser->id }}" class="{{ $parser->enabled ? '' : 'opacity-50' }}">
                                    <td class="ps-3">
                                        <div class="fw-medium">{{ $parser->name }}
                                            @unless ($parser->built_in)<x-badge color="primary" size="sm" variant="subtle">{{ __('Custom') }}</x-badge>@endunless
                                        </div>
                                        <div class="small text-body-tertiary font-monospace">{{ $parser->key }}</div>
                                    </td>
                                    <td class="d-none d-md-table-cell small">{{ $parser->sourceLabel }}</td>
                                    <td class="small font-monospace">{{ $parser->definition['event']['type'] ?? '' }}</td>
                                    <td>
                                        <div class="form-check form-switch m-0">
                                            <input aria-label="{{ __('Enabled') }}" class="form-check-input" type="checkbox" wire:click="toggleRule({{ $parser->id }})" @checked($parser->enabled) @disabled(! $isAdmin)>
                                        </div>
                                    </td>
                                    <td class="pe-3 text-end text-nowrap">
                                        @if ($isAdmin)
                                            <button class="btn btn-sm btn-light" type="button" wire:click="editRule({{ $parser->id }})">{{ $parser->built_in ? __('Show') : __('Edit') }}</button>
                                            <button class="btn btn-sm btn-light" type="button" wire:click="duplicateRule({{ $parser->id }})" title="{{ __('Copy as a new parser') }}"><i class="far fa-copy"></i></button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="small text-muted mt-2">
                {{ __('Built-in checks come from resources/security/rules.json and parsers.json; switch them off or copy one to change it. Inventory findings are resolved when the next inventory no longer matches, event findings when you acknowledge them.') }}
            </div>
                </div>
            </details>
        @else
            <div class="d-flex flex-wrap gap-2 mb-3">
                <div class="btn-group btn-group-sm" role="group">
                    @foreach (['open' => __('Needs attention'), 'acknowledged' => __('Acknowledged'), 'resolved' => __('Resolved')] as $value => $label)
                        <button class="btn {{ $status === $value ? 'btn-primary' : 'btn-outline-secondary' }}" type="button" wire:click="$set('status', '{{ $value }}')">{{ $label }}</button>
                    @endforeach
                </div>
                <select class="form-select form-select-sm w-auto" wire:model.live="severity" aria-label="{{ __('Severity') }}">
                    <option value="">{{ __('All severities') }}</option>
                    @foreach (array_keys(SecurityRules::SEVERITIES) as $level)
                        <option value="{{ $level }}">{{ __(ucfirst($level)) }}</option>
                    @endforeach
                </select>
                <input class="form-control form-control-sm w-auto flex-grow-1" placeholder="{{ __('Search device, rule or finding') }}" type="search" wire:model.live.debounce.300ms="search" style="max-width: 20rem">
                @if ($findings->isNotEmpty())
                    <a class="btn btn-sm btn-light ms-auto" href="{{ route('security.export', array_filter(['status' => $status, 'severity' => $severity, 'search' => $search], fn ($value) => $value !== '')) }}" download title="{{ __('The findings of the filters as a Markdown report') }}"><i class="fas fa-file-alt me-1"></i>{{ __('Export Markdown') }}</a>
                @endif
                @if ($status === 'open' && $findings->isNotEmpty())
                    <button class="btn btn-sm btn-light" type="button" wire:click="acknowledgeAll" wire:confirm="{{ __('Acknowledge the :count findings shown?', ['count' => $findings->count()]) }}">
                        <i class="fas fa-check-double me-1"></i>{{ __('Acknowledge shown') }}
                    </button>
                @endif
            </div>
            @if ($remediateError)
                <div class="alert alert-danger small py-2" role="alert"><i class="fas fa-exclamation-triangle me-1"></i>{{ $remediateError }}</div>
            @endif
@if ($remediateDone)
                <div class="alert alert-success small py-2" role="alert"><i class="fas fa-check me-1"></i>{{ $remediateDone }}</div>
            @endif
            <div class="card overflow-hidden">
                <div class="list-group list-group-flush">
                    @forelse ($findings as $finding)
                        @include('livewire.security-scan.partials.finding', ['finding' => $finding, 'showDevice' => true])
                    @empty
                        <div class="list-group-item text-muted py-4 text-center">
                            @if ($status === 'open')
                                <i class="fas fa-shield-alt text-success me-2"></i>{{ __('Nothing needs attention.') }}
                            @else
                                {{ __('Nothing here.') }}
                            @endif
                        </div>
                    @endforelse
                </div>
            </div>
            @if ($findings->count() >= Page::LIMIT)
                <div class="small text-muted mt-2">{{ __('The first :count are shown, narrow the search.', ['count' => Page::LIMIT]) }}</div>
            @endif
        @endif
    </div>
</div>
