{{-- Polls faster while a command is on its way (install buttons, history). data-keep-tab: see tabs-state.js. --}}
<div data-keep-tab>
    @php
        $moduleUpdates = $selectedDevice->moduleUpdates;
        $hasUpdates = count($selectedDevice->updates) > 0 || count($selectedDevice->apps_packages_updates) > 0 || count($moduleUpdates) > 0;
        // Phased and held back updates cannot be installed now, they do not raise a warning.
        $pendingUpdates = count($selectedDevice->installableUpdates) + count($selectedDevice->apps_packages_updates) + count($moduleUpdates);
        $power = $selectedDevice->batteryLevel;
        $pluggedIn = $selectedDevice->pluggedIn;
        $services = $selectedDevice->services;
        $failedServices = collect($services)->where('State', '!=', 'running')->count();
        $docker = $selectedDevice->docker;
        $stoppedContainers = collect($docker['containers'] ?? [])->where('State', '!=', 'running')->count();
        $diskHealth = $selectedDevice->showDiskHealth ? $selectedDevice->diskHealth : null;
        // Latest run of each script on this device.
        $scriptRuns = $selectedDevice->scriptRuns()->with('script')
            ->whereIn('id', \App\Models\ScriptRun::query()->selectRaw('max(id)')->where('device_id', $selectedDevice->id)->groupBy('script_id'))
            ->latest('id')->get();
        $failedScripts = $scriptRuns->whereIn('status', ['failed', 'error', 'rejected'])->count();
        // Security inventory (agents 1.17.0+) and the scanner's findings that need attention.
        $hasSecurity = $selectedDevice->securityInventory()->exists();
        $securityFindings = $selectedDevice->securityFindings()->active()->get();
        $securitySevere = $securityFindings->whereIn('severity', ['critical', 'high'])->count();
        // The tab from the URL (?tab=), or the first one this device has.
        $tabs = array_keys(array_filter([
            // Volumes and the health of the disks under them.
            'drives' => !empty($selectedDevice->drives) || $diskHealth !== null,
            'updates' => $hasUpdates,
            'networks' => count($selectedDevice->networks) > 0,
            'services' => count($services) > 0,
            'docker' => $docker !== null,
            'scripts' => $scriptRuns->isNotEmpty(),
            'security' => $hasSecurity || $securityFindings->isNotEmpty(),
            'history' => $history->isNotEmpty(),
            'agent' => ! $selectedDevice->isPingOnly,
        ]));
        $updatesRunning = \App\Models\Device::findActive($activeCommands, 'doUpdates');
        // Disk health was a tab of its own (old links: ?tab=health).
        $tab = $tab === 'health' ? 'drives' : $tab;
        $activeTab = in_array($tab, $tabs, true) ? $tab : ($tabs[0] ?? null);
    @endphp

    <div class="card">
        <div class="card-body position-relative">
            {{-- Room for the power state, the bell and the menu of device-commands in the top right corner. --}}
            <div class="device-head d-flex align-items-start gap-3 {{ $selectedDevice->offline || $selectedDevice->isPingOnly ? '' : ($power !== null ? 'has-battery' : 'has-plug') }}">
                <div class="flex-grow-1 min-w-0">
                    @if ($editMode)
                        <div class="d-flex gap-2">
                            <input class="form-control" id="friendlyName" type="text" wire:model="friendlyName" wire:keydown.enter="saveFriendlyName" wire:keydown.escape="$set('editMode', false)">
                            <button class="btn btn-primary" type="button" wire:click="saveFriendlyName">{{ __('Save') }}</button>
                            <button class="btn btn-light" type="button" wire:click="$set('editMode', false)">{{ __('Cancel') }}</button>
                        </div>
                    @else
                        {{-- Renamed from the device menu (⋯). --}}
                        <h2 class="d-flex align-items-center gap-3 mb-2 text-break">
                            <span class="icon-tile icon-tile-lg bg-primary-subtle text-primary-emphasis" title="{{ __(ucfirst($selectedDevice->type)) }}"><i class="{{ $selectedDevice->typeIcon }}"></i></span>
                            <span class="min-w-0">{{ $selectedDevice->DisplayName }}</span>
                        </h2>
                    @endif

                    <div class="text-muted small d-flex flex-wrap align-items-center column-gap-3 row-gap-1">
                        @if ($selectedDevice->offline)
                            <x-badge color="secondary" size="sm" variant="subtle" title="{{ ($selectedDevice->last_seen_at ?? $selectedDevice->updated_at)?->toDateTimeString() }}">
                                {{ __('Offline') }} · {{ ($selectedDevice->last_seen_at ?? $selectedDevice->updated_at)?->diffForHumans() }}
                            </x-badge>
                        @else
                            <x-badge color="success" size="sm" variant="subtle">{{ __('Online') }}</x-badge>
                        @endif
                        @if (! $selectedDevice->offline && $power !== null)
                            {{-- On phones here, beside the buttons on wider screens. --}}
                            <span class="d-sm-none" title="{{ $pluggedIn ? __('Charging') : ($pluggedIn === false ? __('On battery') : __('Battery')) }}">
                                @if ($pluggedIn)<i class="fas fa-bolt text-warning me-1"></i>@endif<i class="fas {{ $power < 20 ? 'fa-battery-quarter' : ($power < 85 ? 'fa-battery-half' : 'fa-battery-full') }} me-1"></i>{{ $power }} %
                            </span>
                        @endif
                        @if (!empty($selectedDevice->os))
                            <span><i class="fas fa-info-circle me-1"></i>{{ $selectedDevice->os }}</span>
                        @endif
                        @if (!$selectedDevice->offline)
                            @if (!empty($selectedDevice->lastLogonUser))
                                <span title="{{ __('Last logged on user') }}"><i class="fas fa-user me-1"></i>{{ $selectedDevice->lastLogonUser }}</span>
                            @endif
                            @if (!empty($selectedDevice->NiceUptime))
                                <span title="{{ __('Uptime') }}: {{ \Carbon\CarbonInterval::seconds($selectedDevice->data->machine->uptime)->cascade()->forHumans() }}"><i class="far fa-clock me-1"></i>{{ $selectedDevice->NiceUptime }}</span>
                            @endif
                        @endif
                        @if (!empty($selectedDevice->data->machine->Processor))
                            <span><i class="fas fa-microchip me-1"></i>{{ $selectedDevice->data->machine->Processor }} ({{ __(':count cores', ['count' => $selectedDevice->data->machine->Cores ?? '?']) }})</span>
                        @endif
                        @if ($virtualization = $selectedDevice->virtualization)
                            <span title="{{ $virtualization['type'] === 'container' ? __('Runs in a container') : __('Runs in a virtual machine') }}">
                                <i class="{{ $virtualization['type'] === 'container' ? 'fas fa-box' : 'fas fa-clone' }} me-1"></i>{{ $virtualization['label'] }}
                            </span>
                        @endif
                    </div>

                    @if ($editTags)
                        <div class="d-flex gap-2 mt-2">
                            <input class="form-control form-control-sm" list="device-tag-options" placeholder="{{ __('servers, family, …') }}" type="text" wire:model="tagsText" wire:keydown.enter="saveTags" wire:keydown.escape="$set('editTags', false)" autofocus>
                            <datalist id="device-tag-options">
                                @foreach (\App\Models\Device::allTags() as $tagOption)
                                    <option value="{{ $tagOption }}"></option>
                                @endforeach
                            </datalist>
                            <button class="btn btn-sm btn-primary" type="button" wire:click="saveTags">{{ __('Save') }}</button>
                            <button class="btn btn-sm btn-light" type="button" wire:click="$set('editTags', false)">{{ __('Cancel') }}</button>
                        </div>
                        <div class="form-text">{{ __('Separate tags with commas.') }}</div>
                    @elseif ($selectedDevice->tagList !== [])
                        <x-tags class="align-items-center mt-2" :tags="$selectedDevice->tagList" links>
                            <button class="btn btn-sm btn-link text-body-secondary p-0 ms-1" type="button" title="{{ __('Edit tags') }}" wire:click="startEditTags">
                                <i class="fas fa-pen small"></i>
                            </button>
                        </x-tags>
                    @else
                        {{-- Without tags the row stays (same height as with them), with a way to add some. --}}
                        <div class="mdm-tags align-items-center mt-2">
                            <span aria-hidden="true" class="invisible" style="width: 0; overflow: hidden"><x-tag tag="-" /></span>
                            <button class="btn btn-sm btn-link text-body-secondary text-decoration-none p-0 small" type="button" wire:click="startEditTags">
                                <i class="fas fa-tag me-1"></i>{{ __('Add tags') }}
                            </button>
                        </div>
                    @endif
                </div>

            </div>

            @livewire('device-commands', ['selectedDeviceId' => $selectedDevice->id], key('device-commands' . $selectedDevice->id))
        </div>
    </div>

    @if ($selectedDevice->isPingOnly)
        @livewire('ping-monitor', ['deviceId' => $selectedDevice->id], key('ping-monitor' . $selectedDevice->id))
    @endif

    {{-- Ping-only devices have no report, but alerts of their own (another address, a failed wake). --}}
    @if (!empty($selectedDevice->data) || $selectedDevice->isPingOnly)
        @livewire('device-alerts', ['selectedDeviceId' => $selectedDevice->id], key('device-alerts' . $selectedDevice->id))
    @endif

    @unless ($selectedDevice->isPingOnly)
    <div class="mt-3">
        @livewire('device-metrics', ['selectedDeviceId' => $selectedDevice->id], key('device-metrics' . $selectedDevice->id))
    </div>
    @endunless

    {{-- Ping-only devices only get the tabs they have data for (security findings, history). --}}
    @if ($tabs !== [])
    <div class="mt-4">
        {{-- Scrolls sideways when the tabs do not fit; the active one is kept in view. The wrapper
             scrolls, not the list: it would clip the underline of the active tab. --}}
        <div class="nav-tabs-scroll" x-init="$nextTick(() => { const tab = $el.querySelector('.nav-link.active'); if (tab) $el.scrollLeft = Math.max(0, tab.getBoundingClientRect().right - $el.getBoundingClientRect().right + $el.scrollLeft + 16) })">
        <ul class="nav nav-tabs flex-nowrap text-nowrap" role="tablist">
            @if (!empty($selectedDevice->drives) || $diskHealth !== null)
                <li class="nav-item" role="presentation">
                    <button aria-controls="drives-tab-pane" aria-selected="{{ $activeTab === 'drives' ? 'true' : 'false' }}" class="nav-link {{ $activeTab === 'drives' ? 'active' : '' }}" x-on:click="$wire.tab = 'drives'" data-bs-target="#drives-tab-pane" data-bs-toggle="tab" id="drives-tab" role="tab" type="button">
                        <i class="fas fa-hdd me-2"></i>{{ __('Drives') }}
                        @if ($diskHealth !== null && $selectedDevice->diskHealthProblem)
                            <i class="fas fa-exclamation-triangle text-danger ms-1" title="{{ __('A disk reports a problem') }}"></i>
                        @endif
                    </button>
                </li>
            @endif
            @if ($hasUpdates)
                <li class="nav-item" role="presentation">
                    <button aria-controls="updates-tab-pane" aria-selected="{{ $activeTab === 'updates' ? 'true' : 'false' }}" class="nav-link {{ $activeTab === 'updates' ? 'active' : '' }}" x-on:click="$wire.tab = 'updates'" data-bs-target="#updates-tab-pane" data-bs-toggle="tab" id="updates-tab" role="tab" type="button">
                        <i class="fas fa-sync me-2"></i>{{ __('Updates') }}
                        @if ($pendingUpdates > 0)
                            <x-badge class="ms-1" :color="count($selectedDevice->installableUpdates) > 0 ? 'warning' : 'secondary'" size="sm" variant="subtle" title="{{ __('Installable updates') }}">{{ $pendingUpdates }}</x-badge>
                        @endif
                    </button>
                </li>
            @endif
            @if (count($selectedDevice->networks) > 0)
                <li class="nav-item" role="presentation">
                    <button aria-controls="networks-tab-pane" aria-selected="{{ $activeTab === 'networks' ? 'true' : 'false' }}" class="nav-link {{ $activeTab === 'networks' ? 'active' : '' }}" x-on:click="$wire.tab = 'networks'" data-bs-target="#networks-tab-pane" data-bs-toggle="tab" id="networks-tab" role="tab" type="button">
                        <i class="fas fa-network-wired me-2"></i>{{ __('Networks') }}
                    </button>
                </li>
            @endif
            @if (count($services) > 0)
                <li class="nav-item" role="presentation">
                    <button aria-controls="services-tab-pane" aria-selected="{{ $activeTab === 'services' ? 'true' : 'false' }}" class="nav-link {{ $activeTab === 'services' ? 'active' : '' }}" x-on:click="$wire.tab = 'services'" data-bs-target="#services-tab-pane" data-bs-toggle="tab" id="services-tab" role="tab" type="button">
                        <i class="fas fa-cogs me-2"></i>{{ __('Services') }}
                        @if ($failedServices > 0)
                            <x-badge class="ms-1" color="danger" size="sm" variant="subtle" title="{{ __('Not running') }}">{{ $failedServices }}</x-badge>
                        @endif
                    </button>
                </li>
            @endif
            @if ($docker !== null)
                <li class="nav-item" role="presentation">
                    <button aria-controls="docker-tab-pane" aria-selected="{{ $activeTab === 'docker' ? 'true' : 'false' }}" class="nav-link {{ $activeTab === 'docker' ? 'active' : '' }}" x-on:click="$wire.tab = 'docker'" data-bs-target="#docker-tab-pane" data-bs-toggle="tab" id="docker-tab" role="tab" type="button">
                        <i class="fab fa-docker me-2"></i>{{ __('Docker') }}
                        @if ($stoppedContainers > 0)
                            <x-badge class="ms-1" color="secondary" size="sm" variant="subtle" title="{{ __('Not running') }}">{{ $stoppedContainers }}</x-badge>
                        @endif
                    </button>
                </li>
            @endif
            @if ($scriptRuns->isNotEmpty())
                <li class="nav-item" role="presentation">
                    <button aria-controls="scripts-tab-pane" aria-selected="{{ $activeTab === 'scripts' ? 'true' : 'false' }}" class="nav-link {{ $activeTab === 'scripts' ? 'active' : '' }}" x-on:click="$wire.tab = 'scripts'" data-bs-target="#scripts-tab-pane" data-bs-toggle="tab" id="scripts-tab" role="tab" type="button">
                        <i class="fas fa-scroll me-2"></i>{{ __('Scripts') }}
                        @if ($failedScripts > 0)
                            <x-badge class="ms-1" color="danger" size="sm" variant="subtle" title="{{ __('Failed') }}">{{ $failedScripts }}</x-badge>
                        @endif
                    </button>
                </li>
            @endif
            @if ($hasSecurity || $securityFindings->isNotEmpty())
                <li class="nav-item" role="presentation">
                    <button aria-controls="security-tab-pane" aria-selected="{{ $activeTab === 'security' ? 'true' : 'false' }}" class="nav-link {{ $activeTab === 'security' ? 'active' : '' }}" x-on:click="$wire.tab = 'security'" data-bs-target="#security-tab-pane" data-bs-toggle="tab" id="security-tab" role="tab" type="button">
                        <i class="fas fa-shield-alt me-2"></i>{{ __('Security') }}
                        @if ($securityFindings->isNotEmpty())
                            <x-badge class="ms-1" :color="$securitySevere > 0 ? 'danger' : 'warning'" size="sm" variant="subtle" title="{{ __('Findings') }}">{{ $securityFindings->count() }}</x-badge>
                        @endif
                    </button>
                </li>
            @endif
            @if ($history->isNotEmpty())
                <li class="nav-item" role="presentation">
                    <button aria-controls="history-tab-pane" aria-selected="{{ $activeTab === 'history' ? 'true' : 'false' }}" class="nav-link {{ $activeTab === 'history' ? 'active' : '' }}" x-on:click="$wire.tab = 'history'" data-bs-target="#history-tab-pane" data-bs-toggle="tab" id="history-tab" role="tab" type="button">
                        <i class="fas fa-history me-2"></i>{{ __('History') }}
                    </button>
                </li>
            @endif
            @unless ($selectedDevice->isPingOnly)
            <li class="nav-item" role="presentation">
                <button aria-controls="agent-tab-pane" aria-selected="{{ $activeTab === 'agent' ? 'true' : 'false' }}" class="nav-link {{ $activeTab === 'agent' ? 'active' : '' }}" x-on:click="$wire.tab = 'agent'" data-bs-target="#agent-tab-pane" data-bs-toggle="tab" id="agent-tab" role="tab" type="button">
                    <i class="fas fa-robot me-2"></i>{{ __('Agent') }}
                    @if (!empty($selectedDevice->data) && ($selectedDevice->agentOutdated || ! $selectedDevice->signsRequests))
                        <i class="fas fa-exclamation-triangle text-warning ms-1"></i>
                    @endif
                </button>
            </li>
            @endunless
        </ul>
        </div>

        <div class="tab-content pt-3">
            @if (!empty($selectedDevice->drives) || $diskHealth !== null)
                <div aria-labelledby="drives-tab" class="tab-pane fade {{ $activeTab === 'drives' ? 'show active' : '' }}" id="drives-tab-pane" role="tabpanel" tabindex="0">
                    @if (!empty($selectedDevice->drives))
                    <div class="row g-3">
                        @foreach ($selectedDevice->drives as $drive)
                            <div class="col-12 col-md-6">
                                <div class="d-flex align-items-center gap-3">
                                    <i class="fas {{ $drive['DriveType'] == 5 ? 'fa-compact-disc' : 'fa-hdd' }} fa-2x text-muted"></i>
                                    <div class="flex-grow-1">
                                        <div>{{ $drive['FriendlyName'] ?? '' }} ({{ $drive['DriveLetter'] }})</div>
                                        @if (isset($drive['Size']) && isset($drive['PercentUsed']))
                                            <div class="progress my-1" style="height: 6px;">
                                                <div aria-valuemax="100" aria-valuemin="0" aria-valuenow="{{ $drive['PercentUsed'] }}" class="progress-bar {{ $drive['PercentUsed'] > 90 ? 'bg-danger' : '' }}" role="progressbar" style="width: {{ $drive['PercentUsed'] }}%"></div>
                                            </div>
                                            <small class="text-muted">
                                                {{ __(':free free of :total', ['free' => \App\Support\Bytes::format($drive['SizeRemaining']), 'total' => \App\Support\Bytes::format($drive['Size'])]) }}
                                            </small>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @endif
                    @if ($diskHealth !== null)
                        <h6 class="{{ empty($selectedDevice->drives) ? '' : 'mt-4 pt-3 border-top' }} mb-3"><i class="fas fa-heartbeat me-2 text-muted"></i>{{ __('Disk health') }}</h6>
                    @if ($diskHealth['error'])
                        <div class="alert alert-warning mb-3" role="alert">
                            <i class="fas fa-heartbeat me-2"></i>{{ $diskHealth['error'] }}
                        </div>
                    @endif
                    @if (count($diskHealth['disks']) > 0)
                        <div class="row g-3">
                            @foreach ($diskHealth['disks'] as $disk)
                                @php
                                    $health = $disk['Health'] ?? 'unknown';
                                    $values = array_filter([
                                        __('Temperature') => isset($disk['Temperature']) ? $disk['Temperature'] . ' °C' : null,
                                        __('Power on') => isset($disk['PowerOnHours']) ? __(':hours h (:days days)', ['hours' => number_format($disk['PowerOnHours'], 0, ',', ' '), 'days' => intdiv((int) $disk['PowerOnHours'], 24)]) : null,
                                        __('Wear') => isset($disk['WearPercent']) ? $disk['WearPercent'] . ' %' : null,
                                        __('Reallocated sectors') => $disk['Reallocated'] ?? null,
                                        __('Pending sectors') => $disk['Pending'] ?? null,
                                        __('Media errors') => $disk['MediaErrors'] ?? null,
                                    ], fn ($value) => $value !== null);
                                @endphp
                                <div class="col-12 col-md-6" wire:key="disk-{{ $loop->index }}">
                                    <div class="card h-100">
                                        <div class="card-body">
                                            <div class="d-flex align-items-start gap-3">
                                                <i class="fas {{ ($disk['MediaType'] ?? '') === 'HDD' ? 'fa-hdd' : 'fa-memory' }} fa-2x text-muted"></i>
                                                <div class="flex-grow-1 min-w-0">
                                                    <div class="d-flex justify-content-between gap-2">
                                                        <span class="fw-semibold text-break">{{ $disk['Model'] ?? $disk['Device'] }}</span>
                                                        <x-badge class="align-self-start flex-shrink-0" :color="match ($health) { 'passed' => 'success', 'failed' => 'danger', 'warning' => 'warning', default => 'secondary' }" variant="subtle">
                                                            {{ match ($health) { 'passed' => __('Healthy'), 'failed' => __('Failing'), 'warning' => __('Warning'), default => __('Unknown') } }}
                                                        </x-badge>
                                                    </div>
                                                    <div class="small text-muted text-break">
                                                        {{ collect([$disk['Device'] ?? null, $disk['MediaType'] ?? null, $disk['Protocol'] ?? null, isset($disk['Size']) ? \App\Support\Bytes::format($disk['Size']) : null, $disk['Serial'] ?? null])->filter()->implode(' · ') }}
                                                    </div>
                                                    @if (! empty($disk['Standby']))
                                                        <div class="small text-muted mt-1"><i class="fas fa-moon me-1"></i>{{ __('Disk is asleep, showing the last known values.') }}</div>
                                                    @endif
                                                    @if ($values)
                                                        <dl class="row small mb-0 mt-2">
                                                            @foreach ($values as $label => $value)
                                                                <dt class="col-6 fw-normal text-muted">{{ $label }}</dt>
                                                                <dd class="col-6 mb-1 text-end">{{ $value }}</dd>
                                                            @endforeach
                                                        </dl>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @elseif (! $diskHealth['error'])
                        <p class="text-muted mb-0">{{ __('No disks with S.M.A.R.T. support found.') }}</p>
                    @endif
                    <p class="small text-muted mt-3 mb-0">{{ __('Checked by the agent every hour; sleeping disks are not woken up.') }}</p>
                    @endif
                </div>
            @endif

            @if ($hasUpdates)
                @php
                    // Lowercase search text per row, for the client-side filter.
                    $osSearch = array_map(fn ($update) => strtolower($update['Title'] ?? ''), $selectedDevice->updates);
                    $appSearch = array_map(fn ($update) => strtolower(($update['Id'] ?? '') . ' ' . ($update['Source'] ?? '')), $selectedDevice->apps_packages_updates);
                    $moduleSearch = array_map(fn ($module) => strtolower(($module['Name'] ?? '') . ' ' . ($module['Edition'] ?? '') . ' ' . ($module['User'] ?? '')), $moduleUpdates);
                @endphp
                <div aria-labelledby="updates-tab" class="tab-pane fade {{ $activeTab === 'updates' ? 'show active' : '' }}" id="updates-tab-pane" role="tabpanel" tabindex="0" x-data="{ search: '' }">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <input class="form-control form-control-sm flex-grow-1 min-w-0" placeholder="{{ __('Search') }}" style="max-width: 20rem;" type="search" x-model="search">
                        <div class="ms-auto d-flex align-items-center gap-2 flex-shrink-0">
                            @if ($updatesRunning)
                                <div style="min-width: 14rem;">@include('partials.device.command-progress', ['command' => $updatesRunning])</div>
                            @elseif ($pendingUpdates > 0)
                                <button class="btn btn-sm btn-light" type="button" wire:click="installAll" wire:loading.attr="disabled" wire:target="installAll" @disabled($selectedDevice->offline)>
                                    <i class="fas fa-download me-1 text-primary"></i>{{ __('Install all (:count)', ['count' => $pendingUpdates]) }}
                                </button>
                            @endif
                        </div>
                    </div>
                    @error('update')
                        <div class="alert alert-danger py-2 small">{{ $message }}</div>
                    @enderror
                    @unless ($selectedDevice->commandTracking)
                        <p class="small text-muted">{{ __('Updating single packages needs agent :version or newer.', ['version' => \App\Models\Device::TRACKING_VERSION]) }}</p>
                    @endunless
                    @if (count($selectedDevice->updates) > 0)
                        <div x-show="!search || @js($osSearch).some(text => text.includes(search.toLowerCase()))">
                        <h5>{{ $selectedDevice->platform === 'linux' ? __('Operating system (APT)') : __('Operating system (Windows Update)') }}</h5>
                        <ul class="list-group mb-3">
                            @foreach ($selectedDevice->updates as $update)
                                @php
                                    $deferred = match ($update['Status']) {
                                        'phased' => ['icon' => 'fas fa-hourglass-half', 'label' => __('Phased'), 'title' => __('Rolled out gradually by the distribution, apt installs it later on its own.')],
                                        'restart' => ['icon' => 'fas fa-redo', 'label' => __('Waiting for a restart'), 'title' => __('Installed, the restart of the device finishes it.')],
                                        'held' => ['icon' => 'fas fa-pause-circle', 'label' => __('Held back'), 'title' => __('apt does not install it now (held, pinned, or it needs other packages to change).')],
                                        default => null,
                                    };
                                @endphp
                                <li class="list-group-item d-flex align-items-center gap-2 {{ $deferred ? 'text-body-secondary' : '' }}" wire:key="os-update-{{ $loop->index }}" x-show="!search || @js($osSearch[$loop->index]).includes(search.toLowerCase())">
                                    <span class="flex-grow-1 min-w-0 text-break">
                                        @if ($deferred)
                                            <i class="{{ $deferred['icon'] }} me-2" title="{{ $deferred['title'] }}"></i>
                                        @endif
                                        {{ $update['Title'] }}
                                    </span>
                                    <span class="flex-shrink-0">
                                        @if ($deferred)
                                            <x-badge color="secondary" title="{{ $deferred['title'] }}" variant="subtle">{{ $deferred['label'] }}</x-badge>
                                        @else
                                            @include('partials.device.update-button', ['section' => 'os', 'index' => $loop->index, 'target' => $selectedDevice->updateTarget('os', $update), 'groupKey' => $selectedDevice->updateGroupKey('os', $update)])
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        </div>
                    @endif
                    @foreach ($selectedDevice->appUpdateGroups() as $groupName => $groupRows)
                        @php
                            $groupSearch = array_merge(
                                array_map(fn ($index) => $appSearch[$index], array_keys($groupRows)),
                                $groupName === 'powershell' ? $moduleSearch : [],
                            );
                        @endphp
                        <div x-show="!search || @js($groupSearch).some(text => text.includes(search.toLowerCase()))">
                        <h5 class="mt-3">{{ $groupName === 'other' ? __('Other') : \App\Models\Device::UPDATE_SECTIONS[$groupName] }}</h5>
                        <ul class="list-group">
                            @foreach ($groupRows as $appIndex => $appUpdate)
                                @php
                                    $versions = collect([($appUpdate['Version'] ?? '') ?: '?', $appUpdate['Avaliable'] ?? null])->filter()->implode(' → ');
                                @endphp
                                <li class="list-group-item d-flex align-items-center gap-2" wire:key="app-update-{{ $appIndex }}" x-show="!search || @js($appSearch[$appIndex]).includes(search.toLowerCase())">
                                    <span class="flex-grow-1 min-w-0">
                                        <span class="fw-semibold text-break">{{ $appUpdate['Id'] ?? '' }}</span>
                                        @if (! empty($appUpdate['Source']))
                                            <span class="small text-muted ms-1">{{ $appUpdate['Source'] }}</span>
                                        @endif
                                        @if (($appUpdate['Scope'] ?? null) === 'user')
                                            <span class="small text-muted ms-1" title="{{ __('Installed only for the logged-on user: updated in their session') }}"><i class="fas fa-user me-1"></i>{{ __('user') }}</span>
                                        @endif
                                        {{-- On phones the versions go under the name, the button keeps its place. --}}
                                        <span class="d-block d-sm-none small text-primary-emphasis">{{ $versions }}</span>
                                    </span>
                                    <span class="d-flex align-items-center gap-2 flex-shrink-0">
                                        <x-badge class="d-none d-sm-inline-flex" color="primary" variant="subtle">{{ $versions }}</x-badge>
                                        @include('partials.device.update-button', ['section' => 'app', 'index' => $appIndex, 'target' => $selectedDevice->updateTarget('app', $appUpdate), 'groupKey' => $selectedDevice->updateGroupKey('app', $appUpdate)])
                                    </span>
                                </li>
                            @endforeach
                            @if ($groupName === 'powershell')
                                @foreach ($moduleUpdates as $module)
                                    <li class="list-group-item d-flex align-items-center gap-2" wire:key="module-{{ $loop->index }}" x-show="!search || @js($moduleSearch[$loop->index]).includes(search.toLowerCase())">
                                        <span class="flex-grow-1 min-w-0">
                                            <span class="fw-semibold text-break">{{ $module['Name'] ?? '' }}</span>
                                            <span class="small text-muted ms-1">{{ __('module') }} · {{ $module['Edition'] ?? '' }}</span>
                                            @if (! empty($module['User']))
                                                <span class="small text-muted" title="{{ __('Installed in the user profile, the user updates it (Update-Module).') }}"><i class="fas fa-user ms-1 me-1"></i>{{ $module['User'] }}</span>
                                            @endif
                                            <span class="d-block d-sm-none small text-primary-emphasis">{{ $module['Version'] ?? '?' }} → {{ $module['Available'] ?? '?' }}</span>
                                        </span>
                                        <span class="d-flex align-items-center gap-2 flex-shrink-0">
                                            <x-badge class="d-none d-sm-inline-flex" color="primary" variant="subtle">{{ $module['Version'] ?? '?' }} → {{ $module['Available'] ?? '?' }}</x-badge>
                                            @include('partials.device.update-button', ['section' => 'module', 'index' => $loop->index, 'target' => $selectedDevice->updateTarget('module', $module), 'groupKey' => 'powershell'])
                                        </span>
                                    </li>
                                @endforeach
                            @endif
                        </ul>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (count($selectedDevice->networks) > 0)
                <div aria-labelledby="networks-tab" class="tab-pane fade {{ $activeTab === 'networks' ? 'show active' : '' }}" id="networks-tab-pane" role="tabpanel" tabindex="0">
                    <ul class="list-group">
                        @if (\App\Models\Device::isPublicIp($selectedDevice->public_ip))
                            <li class="list-group-item d-flex align-items-start gap-3">
                                <span class="icon-tile bg-primary-subtle text-primary-emphasis"><i class="fas fa-globe"></i></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold">{{ __('Public address') }}</div>
                                    <div class="small text-muted">{{ __('The address the device reaches the server from, behind NAT the one of its router.') }}</div>
                                    <div class="small font-monospace">{{ $selectedDevice->public_ip }}</div>
                                </div>
                            </li>
                        @endif
                        @foreach (\App\Models\Device::sortNetworks($selectedDevice->networks) as $network)
                            @php $type = \App\Models\Device::NETWORK_TYPES[$network['Type']]; @endphp
                            <li class="list-group-item d-flex align-items-start gap-3 {{ $network['Connected'] ? '' : 'text-body-secondary' }}" wire:key="network-{{ $loop->index }}">
                                <span class="icon-tile {{ $network['Connected'] ? 'bg-primary-subtle text-primary-emphasis' : '' }}" title="{{ __($type['label']) }}"><i class="{{ $type['icon'] }}"></i></span>
                                <div class="flex-grow-1 min-w-0">
                                    {{-- Name and type in one line that wraps; the state is on the right of the row. --}}
                                    <div class="d-flex flex-wrap align-items-center column-gap-2 row-gap-1">
                                        <span class="fw-semibold text-break">{{ $network['Name'] }}</span>
                                        <x-badge color="secondary" size="sm" variant="subtle">{{ __($type['label']) }}</x-badge>
                                    </div>
                                    @if ($network['Description'] && $network['Description'] !== $network['Name'])
                                        <div class="small text-muted text-break">{{ $network['Description'] }}</div>
                                    @endif
                                    @foreach ($network['IPAddresses'] as $ipAddress)
                                        <div class="small text-muted">
                                            <span class="font-monospace">{{ $ipAddress }}</span>
                                            @if (\App\Models\Device::isPublicIp($ipAddress))
                                                <x-badge class="ms-1" color="primary" size="sm" variant="subtle" title="{{ __('Reachable from the internet') }}">{{ __('Public') }}</x-badge>
                                            @endif
                                        </div>
                                    @endforeach
                                    @if ($network['Mac'])
                                        <x-mac :mac="$network['Mac']" class="d-block small text-body-tertiary" title="{{ __('MAC address') }}" />
                                    @endif
                                </div>
                                {{-- On phones only the icon (its text in the tooltip). --}}
                                <span class="flex-shrink-0 small text-nowrap {{ $network['Connected'] ? 'text-success' : 'text-body-secondary' }}" title="{{ $network['Connected'] ? __('Connected') : __('Disconnected') }}{{ $network['Status'] ? ' · ' . $network['Status'] : '' }}">
                                    <i class="fas {{ $network['Connected'] ? 'fa-check-circle' : 'fa-times-circle' }}"></i><span class="d-none d-sm-inline ms-1">{{ $network['Connected'] ? __('Connected') : __('Disconnected') }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (count($services) > 0)
                <div aria-labelledby="services-tab" class="tab-pane fade {{ $activeTab === 'services' ? 'show active' : '' }}" id="services-tab-pane" role="tabpanel" tabindex="0" x-data="{ search: '' }">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <input class="form-control form-control-sm" placeholder="{{ __('Search') }}" style="max-width: 20rem;" type="search" x-model="search">
                        <span class="small text-muted ms-auto">{{ __(':running running, :other not running', ['running' => count($services) - $failedServices, 'other' => $failedServices]) }}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Service') }}</th>
                                    <th class="d-none d-md-table-cell">{{ __('Description') }}</th>
                                    <th class="text-end">{{ __('State') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($services as $service)
                                    @php
                                        $state = $service['State'] ?? 'unknown';
                                        $search = strtolower(($service['Name'] ?? '') . ' ' . ($service['DisplayName'] ?? ''));
                                    @endphp
                                    <tr wire:key="service-{{ $loop->index }}" x-show="!search || @js($search).includes(search.toLowerCase())">
                                        <td class="text-break">
                                            <span class="fw-semibold">{{ $service['Name'] ?? '' }}</span>
                                            <div class="small text-muted d-md-none">{{ $service['DisplayName'] ?? '' }}</div>
                                        </td>
                                        <td class="d-none d-md-table-cell text-muted">{{ $service['DisplayName'] ?? '' }}</td>
                                        <td class="text-end text-nowrap">
                                            <x-badge :color="$state === 'running' ? 'success' : ($state === 'failed' ? 'danger' : 'warning')" variant="subtle">
                                                {{ __(ucfirst($state)) }}
                                            </x-badge>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($docker !== null)
                <div aria-labelledby="docker-tab" class="tab-pane fade {{ $activeTab === 'docker' ? 'show active' : '' }}" id="docker-tab-pane" role="tabpanel" tabindex="0" x-data="{ search: '' }">
                    @if ($docker['error'])
                        <div class="alert alert-warning mb-3" role="alert">
                            <i class="fab fa-docker me-2"></i>{{ __('Docker is installed but the agent cannot read the containers: :error', ['error' => $docker['error']]) }}
                        </div>
                    @endif
                    @if (count($docker['containers']) > 0)
                        <input class="form-control form-control-sm mb-3" placeholder="{{ __('Search') }}" style="max-width: 20rem;" type="search" x-model="search">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ __('Container') }}</th>
                                        <th class="d-none d-md-table-cell">{{ __('Image') }}</th>
                                        <th class="d-none d-lg-table-cell">{{ __('Ports') }}</th>
                                        <th class="text-end">{{ __('State') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (collect($docker['containers'])->sortBy(fn ($c) => [($c['State'] ?? '') === 'running' ? 1 : 0, $c['Name'] ?? '']) as $container)
                                        @php
                                            $state = $container['State'] ?? 'unknown';
                                            $color = match (true) {
                                                $state === 'running' && ($container['Health'] ?? null) === 'unhealthy' => 'danger',
                                                $state === 'running' => 'success',
                                                in_array($state, ['restarting', 'paused'], true) => 'warning',
                                                $state === 'dead' => 'danger',
                                                default => 'secondary',
                                            };
                                        @endphp
                                        <tr wire:key="container-{{ $loop->index }}" x-show="!search || @js(strtolower(($container['Name'] ?? '') . ' ' . ($container['Image'] ?? '') . ' ' . ($container['Ports'] ?? '') . ' ' . $state)).includes(search.toLowerCase())">
                                            <td class="text-break">
                                                <span class="fw-semibold">{{ $container['Name'] ?? '' }}</span>
                                                <div class="small text-muted d-md-none">{{ $container['Image'] ?? '' }}</div>
                                            </td>
                                            <td class="d-none d-md-table-cell text-muted text-break">{{ $container['Image'] ?? '' }}</td>
                                            <td class="d-none d-lg-table-cell small text-muted text-break">{{ $container['Ports'] ?? '' }}</td>
                                            <td class="text-end">
                                                <x-badge :color="$color" variant="subtle">{{ ($container['Health'] ?? null) === 'unhealthy' ? __('Unhealthy') : __(ucfirst($state)) }}</x-badge>
                                                <div class="small text-muted text-nowrap">{{ $container['Status'] ?? '' }}</div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @elseif (! $docker['error'])
                        <p class="text-muted mb-0">{{ __('No containers.') }}</p>
                    @endif
                </div>
            @endif

            @if ($scriptRuns->isNotEmpty())
                <div aria-labelledby="scripts-tab" class="tab-pane fade {{ $activeTab === 'scripts' ? 'show active' : '' }}" id="scripts-tab-pane" role="tabpanel" tabindex="0">
                    @livewire('script-run.data-table', ['deviceId' => $selectedDevice->id], key('device-script-runs-'.$selectedDevice->id))
                    @unless ($selectedDevice->scriptsEnabled)
                        <p class="small text-muted mt-3 mb-0">{{ __('Remediation scripts are disabled on this device.') }}</p>
                    @endunless
                </div>
            @endif

            @if ($hasSecurity || $securityFindings->isNotEmpty())
                <div aria-labelledby="security-tab" class="tab-pane fade {{ $activeTab === 'security' ? 'show active' : '' }}" id="security-tab-pane" role="tabpanel" tabindex="0">
                    @livewire('device-security', ['deviceId' => $selectedDevice->id], key('device-security-'.$selectedDevice->id))
                </div>
            @endif

            @if ($history->isNotEmpty())
                <div aria-labelledby="history-tab" class="tab-pane fade {{ $activeTab === 'history' ? 'show active' : '' }}" id="history-tab-pane" role="tabpanel" tabindex="0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Command') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th class="d-none d-md-table-cell">{{ __('Issued by') }}</th>
                                    <th class="d-none d-sm-table-cell">{{ __('Duration') }}</th>
                                    <th class="text-end">{{ __('Issued') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($history as $command)
                                    <tr wire:key="history-{{ $command->id }}">
                                        <td class="text-break" style="min-width: 9rem;">
                                            <i class="{{ $command->icon }} fa-fw text-body-secondary me-1"></i>{{ $command->label }}
                                            @if ($command->device_id !== $selectedDevice->id)
                                                <div class="small text-body-secondary">{{ __('through :relay', ['relay' => $command->device?->displayName ?? '?']) }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($command->active)
                                                <div style="min-width: 10rem; max-width: 22rem;" title="{{ $command->displayMessage }}">@include('partials.device.command-progress', ['command' => $command, 'compact' => true])</div>
                                            @else
                                                <x-badge :color="$command->statusColor" size="sm" variant="subtle" :title="$command->statusHint">{{ $command->statusLabel }}</x-badge>
                                                @if ($command->resultNote)
                                                    <div class="small text-body-secondary text-break">{{ $command->resultNote }}</div>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="d-none d-md-table-cell small text-body-secondary">{{ $command->issuer?->name ?? '—' }}</td>
                                        <td class="d-none d-sm-table-cell small text-body-secondary text-nowrap">{{ $command->duration ?? '—' }}</td>
                                        <td class="text-end small text-nowrap" title="{{ $command->created_at }}{{ $command->finished_at ? ' → ' . $command->finished_at : '' }}">{{ $command->created_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
            @unless ($selectedDevice->isPingOnly)
            <div aria-labelledby="agent-tab" class="tab-pane fade {{ $activeTab === 'agent' ? 'show active' : '' }}" id="agent-tab-pane" role="tabpanel" tabindex="0">
                @php
                    $lastReport = $selectedDevice->last_http_at ?? $selectedDevice->updated_at;
                    // Three cards: what runs, how it talks to the server, how the talk is protected.
                    $row = 'd-flex justify-content-between align-items-center gap-3 py-2 border-top';
                @endphp
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="card card-body h-100">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="icon-tile bg-primary-subtle text-primary-emphasis"><i class="fas fa-robot"></i></span>
                                <span class="fw-semibold">{{ __('Agent') }}</span>
                            </div>
                            <div class="{{ $row }}">
                                <span class="small text-body-secondary">{{ __('Version') }}</span>
                                <span class="d-flex align-items-center gap-2">
                                    <span class="fw-semibold">{{ $selectedDevice->agentVersion ?? __('unknown') }}</span>
                                    @if ($selectedDevice->agentOutdated)
                                        <x-badge color="warning" size="sm" variant="subtle">{{ __('Newer: :version', ['version' => \App\Support\AgentScript::version()]) }}</x-badge>
                                    @else
                                        <x-badge color="success" size="sm" variant="subtle">{{ __('Up to date') }}</x-badge>
                                    @endif
                                </span>
                            </div>
                            <div class="{{ $row }}">
                                <span class="small text-body-secondary">{{ __('Command progress') }}</span>
                                @if ($selectedDevice->commandTracking)
                                    <x-badge color="success" size="sm" variant="subtle">{{ __('Reported') }}</x-badge>
                                @else
                                    <x-badge color="secondary" size="sm" variant="subtle">{{ __('Needs :version', ['version' => \App\Models\Device::TRACKING_VERSION]) }}</x-badge>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="card card-body h-100">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="icon-tile {{ $selectedDevice->connectedViaWebsocket || $selectedDevice->connectedViaApi ? 'bg-success-subtle text-success-emphasis' : '' }}"><i class="fas fa-plug"></i></span>
                                <span class="fw-semibold">{{ __('Connection') }}</span>
                            </div>
                            <div class="{{ $row }}">
                                <span>
                                    <span class="small text-body-secondary d-block">WebSocket</span>
                                    <span class="small text-body-tertiary text-nowrap">{{ $selectedDevice->last_ws_at ? __('Heartbeat :time', ['time' => $selectedDevice->last_ws_at->diffForHumans()]) : __('Never connected') }}</span>
                                </span>
                                <x-badge :color="$selectedDevice->connectedViaWebsocket ? 'success' : 'secondary'" icon="fas fa-bolt" size="sm" variant="subtle">{{ $selectedDevice->connectedViaWebsocket ? __('Connected') : __('Not connected') }}</x-badge>
                            </div>
                            <div class="{{ $row }}">
                                <span>
                                    <span class="small text-body-secondary d-block">REST API</span>
                                    <span class="small text-body-tertiary text-nowrap">{{ __('Report :time', ['time' => $lastReport->diffForHumans()]) }}</span>
                                </span>
                                <x-badge :color="$selectedDevice->connectedViaApi ? 'success' : 'secondary'" icon="fas fa-exchange-alt" size="sm" variant="subtle">{{ $selectedDevice->connectedViaApi ? __('Reporting') : __('Inactive') }}</x-badge>
                            </div>
                        </div>
                    </div>

                    @php $features = $selectedDevice->features; @endphp
                    <div class="col-12">
                        <div class="card card-body">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="icon-tile bg-primary-subtle text-primary-emphasis"><i class="fas fa-sliders-h"></i></span>
                                <span class="fw-semibold me-auto">{{ __('Features') }}</span>
                                <span class="small text-body-secondary">{{ __(':on of :count on', ['on' => collect($features)->where('on', true)->count(), 'count' => count($features)]) }}</span>
                            </div>
                            @foreach ($features as $feature)
                                <div class="{{ $row }}" wire:key="feature-{{ $feature['key'] }}">
                                    <span class="min-w-0">
                                        <span class="d-block">{{ $feature['label'] }}</span>
                                        <span class="small text-body-secondary d-block">{{ $feature['description'] }}@if ($feature['setting']) <code class="small ms-1">{{ $feature['setting'] }}</code>@endif</span>
                                    </span>
                                    <span class="d-flex align-items-center gap-2 text-nowrap">
                                        @if ($feature['detail'])
                                            <span class="small text-body-secondary">{{ $feature['detail'] }}</span>
                                        @endif
                                        <x-badge :color="$feature['on'] ? 'success' : 'secondary'" size="sm" variant="subtle">{{ $feature['on'] ? __('On') : __('Off') }}</x-badge>
                                    </span>
                                </div>
                            @endforeach
                            <div class="small text-body-secondary border-top pt-2">{{ __('Read only. Features with a key are set on the device itself in config.json (or with the install parameters); the portal cannot change them.') }}</div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="card card-body h-100">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="icon-tile {{ $selectedDevice->signsRequests ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis' }}"><i class="fas {{ $selectedDevice->signsRequests ? 'fa-lock' : 'fa-unlock' }}"></i></span>
                                <span class="fw-semibold">{{ __('Security') }}</span>
                                @can('is-system-admin')
                                    @if ($selectedDevice->signsRequests)
                                        <button class="btn btn-sm btn-light ms-auto text-nowrap" type="button" title="{{ __('The agent registers a new key with its next request, e.g. after it was reinstalled with a new key.') }}" wire:click="resetDeviceKey" wire:confirm="{{ __('Reset the device key? Until the agent registers a key again, the device only gets the agent update.') }}">
                                            <i class="fas fa-undo me-1 text-body-secondary"></i>{{ __('Reset device key') }}
                                        </button>
                                    @endif
                                @endcan
                            </div>
                            <div class="{{ $row }}">
                                <span class="small text-body-secondary">{{ __('Signing') }}</span>
                                @if ($selectedDevice->signsRequests)
                                    <x-badge color="success" icon="fas fa-lock" size="sm" variant="subtle">{{ __('Signed') }}</x-badge>
                                @else
                                    <x-badge color="warning" icon="fas fa-unlock" size="sm" variant="subtle">{{ __('Unsigned agent') }}</x-badge>
                                @endif
                            </div>
                            @if ($selectedDevice->signsRequests)
                                @if ($selectedDevice->key_registered_at)
                                    <div class="{{ $row }}">
                                        <span class="small text-body-secondary">{{ __('Key registered') }}</span>
                                        <span class="small" title="{{ $selectedDevice->key_registered_at }}">{{ $selectedDevice->key_registered_at->diffForHumans() }}</span>
                                    </div>
                                @endif
                                <div class="{{ $row }} flex-wrap">
                                    <span class="small text-body-secondary">{{ __('Device key fingerprint') }}</span>
                                    <code class="small text-break">{{ \App\Support\Signing::fingerprint($selectedDevice->public_key) }}</code>
                                </div>
                            @else
                                <div class="small text-body-secondary py-2 border-top">{{ __('The agent does not sign its communication (older than 1.7.0): only the agent update can be sent to it.') }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            @endunless
        </div>
    </div>
    @endif
</div>
