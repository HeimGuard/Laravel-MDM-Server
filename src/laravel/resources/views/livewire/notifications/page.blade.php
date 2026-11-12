<div>
    <div class="container">
        <x-boilerplate::breadcrumb class="hide-mobile mb-2" :items="['' => __('Notifications')]" />
        <div class="page-header">
            <div class="me-auto min-w-0">
                <h1>{{ __('Notifications') }}</h1>
                <div class="text-body-secondary">{{ __('Get told when a device goes down, runs out of disk space or a service fails.') }}</div>
            </div>
            <button class="btn btn-primary" type="button" wire:click="addRule">
                <i class="fas fa-plus me-1"></i>{{ __('Add alert') }}
            </button>
        </div>
        <x-boilerplate::alerts />

        <ul class="nav nav-tabs mb-4" role="tablist">
            @foreach (['alerts' => __('Alerts'), 'channels' => __('Channels'), 'history' => __('History')] as $value => $label)
                <li class="nav-item" role="presentation">
                    <button class="nav-link d-flex align-items-center gap-2 {{ $tab === $value ? 'active' : '' }}" role="tab" type="button" aria-selected="{{ $tab === $value ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $value }}')">
                        {{ $label }}
                        @if ($value === 'alerts' && $firing->isNotEmpty())
                            <x-badge color="danger" size="sm" variant="subtle">{{ trans_choice(':count firing|:count firing', $firing->count(), ['count' => $firing->count()]) }}</x-badge>
                        @elseif ($value === 'channels')
                            <x-badge color="secondary" size="sm" variant="subtle">{{ count($channelOptions) }}</x-badge>
                        @endif
                    </button>
                </li>
            @endforeach
        </ul>

        @if ($tab === 'channels')
            <div class="row">
                <div class="col-12 col-xl-7">
                    <div class="card card-body">
                        <h2 class="h6 fw-semibold mb-3">{{ __('Where to send') }}</h2>
                        <form wire:submit="save">
                        <label class="form-label" for="notification-emails">{{ __('E-mail addresses') }}</label>
                        <div class="d-flex gap-2 align-items-start">
                            <textarea class="form-control @error('emails') is-invalid @enderror" id="notification-emails" placeholder="admin@example.com" rows="2" wire:model="emails"></textarea>
                            <button class="btn btn-light text-nowrap" type="button" wire:click="testEmail" wire:loading.attr="disabled" wire:target="testEmail" title="{{ __('Send a test e-mail') }}">
                                <i class="fas fa-paper-plane me-1"></i>{{ __('Test') }}
                            </button>
                        </div>
                        @error('emails') <div class="small text-danger mt-1">{{ $message }}</div> @enderror
                        @isset($tests['email'])
                            <div class="small mt-1 {{ $tests['email']['ok'] ? 'text-success' : 'text-danger' }}">{{ $tests['email']['message'] }}</div>
                        @endisset
                        <div class="form-text">{{ __('One per line. Uses the mail settings of the server (MAIL_*).') }}</div>

                        <label class="form-label mt-3">{{ __('Push and webhook URLs') }}</label>
                        <div class="vstack gap-2">
                            @foreach ($urls as $index => $url)
                                <div wire:key="notification-url-{{ $index }}">
                                    <div class="d-flex gap-2">
                                        <input class="form-control font-monospace @error('urls.' . $index) is-invalid @enderror" placeholder="ntfy://ntfy.sh/my-topic" type="text" wire:model="urls.{{ $index }}" autocomplete="off" spellcheck="false">
                                        <button class="btn btn-light text-nowrap" type="button" wire:click="testUrl({{ $index }})" wire:loading.attr="disabled" wire:target="testUrl({{ $index }})" title="{{ __('Send a test notification') }}">
                                            <span class="spinner-border spinner-border-sm me-1" wire:loading wire:target="testUrl({{ $index }})"></span>
                                            <i class="fas fa-paper-plane me-1" wire:loading.remove wire:target="testUrl({{ $index }})"></i>{{ __('Test') }}
                                        </button>
                                        <button class="btn btn-light btn-sq" type="button" wire:click="removeUrl({{ $index }})" title="{{ __('Remove') }}">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    @error('urls.' . $index) <div class="small text-danger mt-1">{{ $message }}</div> @enderror
                                    @isset($tests[$index])
                                        <div class="small mt-1 text-break {{ $tests[$index]['ok'] ? 'text-success' : 'text-danger' }}">
                                            <i class="fas {{ $tests[$index]['ok'] ? 'fa-check' : 'fa-times' }} me-1"></i>{{ $tests[$index]['message'] }}
                                        </div>
                                    @endisset
                                </div>
                            @endforeach
                        </div>
                        <button class="btn btn-sm btn-link px-0 mt-1" type="button" wire:click="addUrl" @disabled(count($urls) >= \App\Models\NotificationSetting::MAX_CHANNELS)>
                            <i class="fas fa-plus me-1"></i>{{ __('Add URL') }}
                        </button>

                        <details class="small text-body-secondary mt-2">
                            <summary>{{ __('URL formats (Shoutrrr, as in Beszel)') }}</summary>
                            <table class="table table-sm small mt-2 mb-0">
                                <tbody>
                                    @foreach (\App\Support\Notifier::EXAMPLES as $service => $example)
                                        <tr>
                                            <td class="text-nowrap">{{ $service }}</td>
                                            <td><code class="text-break">{{ $example }}</code></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <div class="mt-1">{{ __('?disabletls=yes sends over http (self-hosted ntfy, Gotify, webhooks in your network). A webhook gets a JSON POST with title and message.') }}</div>
                        </details>

                        <div class="d-flex justify-content-end mt-3">
                            <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
                        </div>
                    </form>
                    </div>
                </div>
            </div>
        @elseif ($tab === 'history')
            <div class="card overflow-hidden">
                <div class="list-group list-group-flush">
                    @forelse ($events as $event)
                        @include('livewire.notifications.partials.event', ['event' => $event])
                    @empty
                        <x-empty-state>{{ __('Nothing happened yet.') }}</x-empty-state>
                    @endforelse
                </div>
            </div>
        @else
            <div class="row g-4">
                <div class="col-12 col-xl-8 vstack gap-4">
                    <section>
                        <h2 class="h6 fw-semibold text-body-secondary mb-2">{{ __('Firing now') }}</h2>
                        @forelse ($firing as $event)
                            <div class="smart-alert mb-2 {{ $event->rule?->type === 'status' ? 'border-danger-subtle' : 'border-warning-subtle' }}" wire:key="firing-{{ $event->id }}">
                                <x-icon-tile :icon="$event->rule?->icon" :tone="$event->rule?->type === 'status' ? 'danger' : 'warning'" />
                                <div class="flex-grow-1 min-w-0">
                                    <div>
                                        <a class="fw-semibold text-body" wire:navigate href="{{ route('devices', ['selectedDeviceId' => $event->device_id]) }}">{{ $event->device?->displayName }}</a>
                                        <span class="text-body-secondary">· {{ $event->rule?->label }}</span>
                                    </div>
                                    <div class="small text-body-secondary text-break">{{ $event->message }}</div>
                                </div>
                                <div class="small text-body-secondary text-end text-nowrap" title="{{ $event->triggered_at }}">{{ __('since :time', ['time' => $event->triggered_at->diffForHumans()]) }}</div>
                            </div>
                        @empty
                            <div class="smart-alert text-body-secondary">
                                <x-icon-tile icon="fas fa-check" tone="success" />
                                <div class="align-self-center">{{ __('All quiet: no alert is firing.') }}</div>
                            </div>
                        @endforelse
                    </section>

                    <section class="card overflow-hidden">
                        <div class="d-flex align-items-center px-3 py-2 border-bottom">
                            <h2 class="h6 fw-semibold mb-0 me-auto">{{ __('Rules') }}</h2>
                            <span class="small text-body-secondary">{{ __('Checked every minute') }}</span>
                        </div>
                        @if (! $hasChannels && $rules->isNotEmpty())
                            <x-notice class="m-3 mb-0" type="warning">
                                {{ __('No e-mail address or URL saved yet: alerts are recorded, but not sent.') }}
                                <a href="#" wire:click.prevent="$set('tab', 'channels')">{{ __('Add a channel') }}</a>
                            </x-notice>
                        @endif
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr class="small text-body-secondary">
                                        <th class="ps-3">{{ __('Alert') }}</th>
                                        <th>{{ __('Condition') }}</th>
                                        <th class="d-none d-md-table-cell">{{ __('Devices') }}</th>
                                        <th class="d-none d-lg-table-cell">{{ __('Send to') }}</th>
                                        <th>{{ __('On') }}</th>
                                        <th class="pe-3"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($rules as $rule)
                                        <tr wire:key="alert-rule-{{ $rule->id }}" class="{{ $rule->enabled ? '' : 'opacity-50' }}">
                                            <td class="ps-3 text-nowrap fw-medium"><i class="{{ $rule->icon }} fa-fw text-body-secondary me-2"></i>{{ $rule->label }}</td>
                                            <td class="small">{{ $rule->condition }}</td>
                                            <td class="d-none d-md-table-cell small">
                                                @if (\App\Models\AlertRule::isEvent($rule->type))
                                                    {{-- Event rules have no target: they are about the whole fleet. --}}
                                                    <span class="text-body-secondary"><i class="fas fa-globe me-1"></i>{{ __('Whole fleet') }}</span>
                                                @else
                                                    <x-target-summary :target="$rule->target ?? []" />
                                                @endif
                                            </td>
                                            <td class="d-none d-lg-table-cell small text-body-secondary">
                                                {{ $rule->channels === null ? __('All channels') : ($rule->channels === [] ? __('None') : collect($rule->channels)->map(fn ($channel) => $channel === 'email' ? __('E-mail') : \App\Support\Notifier::serviceName($channel))->implode(', ')) }}
                                            </td>
                                            <td>
                                                <div class="form-check form-switch m-0">
                                                    <input aria-label="{{ __('Enabled') }}" class="form-check-input" type="checkbox" wire:click="toggleRule({{ $rule->id }})" @checked($rule->enabled)>
                                                </div>
                                            </td>
                                            <td class="pe-3 text-end text-nowrap">
                                                <button class="btn btn-sm btn-light" type="button" wire:click="editRule({{ $rule->id }})">{{ __('Edit') }}</button>
                                            </td>
                                        </tr>
                                    @empty
                                        <x-empty-state colspan="6">{{ __('No alerts yet. Add one, e.g. Status for all devices, or use the bell on a device.') }}</x-empty-state>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <aside class="col-12 col-xl-4">
                    <div class="card card-body">
                        <div class="d-flex align-items-center mb-2">
                            <h2 class="h6 fw-semibold mb-0 me-auto">{{ __('Channels') }}</h2>
                            <a href="#" class="small" wire:click.prevent="$set('tab', 'channels')">{{ __('Manage') }}</a>
                        </div>
                        <div class="vstack gap-2">
                            @forelse ($channelOptions as $channel => $label)
                                <div class="d-flex align-items-center gap-2 rounded bg-body-tertiary p-2">
                                    <x-icon-tile :icon="$channel === 'email' ? 'fas fa-envelope' : 'fas fa-paper-plane'" />
                                    <div class="min-w-0">
                                        <div class="fw-medium">{{ $channel === 'email' ? __('E-mail') : \App\Support\Notifier::serviceName($channel) }}</div>
                                        <div class="small text-body-secondary text-truncate">{{ \Illuminate\Support\Str::after($label, ' · ') }}</div>
                                    </div>
                                </div>
                            @empty
                                <div class="small text-body-secondary">{{ __('No channels yet.') }} <a href="#" wire:click.prevent="$set('tab', 'channels')">{{ __('Add one') }}</a></div>
                            @endforelse
                        </div>
                        <div class="small text-body-secondary mt-3">{{ __('Each alert sends one message when it starts and one when it is resolved, never in between.') }}</div>
                    </div>
                </aside>
            </div>
        @endif
    </div>
</div>
