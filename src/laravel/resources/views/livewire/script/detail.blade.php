<div>
    <div class="container">
        <x-boilerplate::breadcrumb class="hide-mobile mb-2" :items="[route('script.index') => __('Scripts'), '' => $script->name]" />
        <div class="page-header">
            <div class="me-auto min-w-0">
                <h1 class="mb-1 text-break">{{ $script->name }}</h1>
                <div class="d-flex flex-wrap align-items-center gap-2 small text-body-secondary">
                    <x-badge color="secondary" size="sm" variant="subtle" :icon="match ($script->platform) { 'windows' => 'fab fa-windows', 'linux' => 'fab fa-linux', default => 'fas fa-desktop' }">{{ __(\App\Models\Script::PLATFORMS[$script->platform]) }}</x-badge>
                    <x-badge color="primary" size="sm" variant="subtle">v{{ $script->version }}</x-badge>
                    @if ($script->detectsOnly)
                        <x-badge color="warning" size="sm" variant="subtle" icon="fas fa-hand-pointer">{{ __('Manual remediation') }}</x-badge>
                    @endif
                    <span title="{{ $script->updated_at }}"><i class="far fa-clock me-1"></i>{{ __('Changed :time', ['time' => $script->updated_at->diffForHumans()]) }}</span>
                    @if ($script->scheduled)
                        <span class="d-inline-flex flex-wrap align-items-center gap-1" title="{{ $script->nextScheduledRun() }}">
                            <i class="fas fa-calendar-alt"></i><code>{{ $script->schedule }}</code> · {{ __('next :time', ['time' => $script->nextScheduledRun()?->diffForHumans()]) }} ·
                            <x-target-summary :target="$script->schedule_target ?? []" />
                        </span>
                    @endif
                    @if ($lastRun)
                        <span title="{{ $lastRun }}"><i class="fas fa-play me-1"></i>{{ __('Last run :time', ['time' => \Illuminate\Support\Carbon::parse($lastRun)->diffForHumans()]) }}</span>
                    @endif
                </div>
            </div>
            {{-- One group: the buttons wrap together, Run never alone on its own line. --}}
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-secondary" href="{{ route('script.index') }}">
                    <i class="me-2 fas fa-arrow-left"></i><span>{{ __('Back') }}</span>
                </a>
                <button class="btn btn-light" type="button" wire:click="edit">
                    <i class="me-2 fas fa-pen"></i><span>{{ __('Edit') }}</span>
                </button>
                <button class="btn btn-light" type="button" wire:click="schedule">
                    <i class="me-2 fas fa-calendar-alt"></i><span>{{ __('Schedule') }}</span>
                </button>
                @if ($failedDevices !== [])
                    @php
                        $shown = array_slice($failedDevices, 0, 15);
                        $confirm = trans_choice('Run the whole script again (the detection and the remediation) on :count failed device?|Run the whole script again (the detection and the remediation) on :count failed devices?', count($failedDevices), ['count' => count($failedDevices)])
                            ."\n\n".collect($shown)->map(fn ($name) => "• $name")->implode("\n")
                            .(count($failedDevices) > count($shown) ? "\n".__('and :count more', ['count' => count($failedDevices) - count($shown)]) : '');
                    @endphp
                    <button class="btn btn-danger" type="button" wire:click="rerunFailed" wire:confirm="{{ $confirm }}" wire:loading.attr="disabled" wire:target="rerunFailed" title="{{ __('Runs the script again on the devices where its latest run failed') }}">
                        <i class="me-2 fas fa-redo"></i><span>{{ __('Rerun failed') }} ({{ count($failedDevices) }})</span>
                    </button>
                @endif
                <button class="btn btn-primary" type="button" wire:click="run">
                    <i class="me-2 fas fa-play"></i><span>{{ __('Run') }}</span>
                </button>
            </div>
        </div>
        <x-boilerplate::alerts />

        @if ($script->description)
            <p class="text-body-secondary">{{ $script->description }}</p>
        @endif

        {{-- Where the script stands on the devices (the latest run of each device). --}}
        <div class="row g-3 mb-4">
            @foreach ([
                'compliant' => ['label' => __('Compliant'), 'tone' => 'green', 'icon' => 'fas fa-check'],
                'remediated' => ['label' => __('Remediated'), 'tone' => 'purple', 'icon' => 'fas fa-magic'],
                'noncompliant' => ['label' => __('Needs remediation'), 'tone' => 'orange', 'icon' => 'fas fa-hand-pointer'],
                'failed' => ['label' => __('Failed'), 'tone' => 'red', 'icon' => 'fas fa-times'],
                'waiting' => ['label' => __('Waiting'), 'tone' => null, 'icon' => 'fas fa-hourglass-half'],
            ] as $key => $stat)
                @continue($key === 'noncompliant' && ! $script->detectsOnly && $stats[$key] === 0)
                {{-- Equal widths, 4 or 5 of them. --}}
                <div class="col-6 col-md">
                    <x-stat :label="$stat['label']" :value="$stats[$key]" :icon="$stat['icon']" :tone="$stat['tone']" />
                </div>
            @endforeach
            @if ($outdated > 0)
                <div class="col-12">
                    <div class="small text-body-secondary"><i class="fas fa-info-circle me-1"></i>{{ trans_choice(':count device ran an older version, run it again to apply v:version.|:count devices ran an older version, run it again to apply v:version.', $outdated, ['version' => $script->version]) }}</div>
                </div>
            @endif
        </div>

        <div class="row g-4">
            <div class="col-12 col-lg-8">
                <div class="card card-body">
                    {{-- Plain Alpine tabs: x-boilerplate::tab.group moves its tabs around in the DOM,
                         which the Livewire refresh (live updates) undoes. --}}
                    <div x-data="{ code: 'detection' }">
                        <ul class="nav nav-switch mb-3" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" role="tab" type="button" x-bind:aria-selected="code === 'detection'" x-bind:class="{ active: code === 'detection' }" x-on:click="code = 'detection'">
                                    <i class="fas fa-search me-2"></i>{{ __('Detection') }}
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" role="tab" type="button" x-bind:aria-selected="code === 'remediation'" x-bind:class="{ active: code === 'remediation' }" x-on:click="code = 'remediation'">
                                    <i class="fas fa-wrench me-2"></i>{{ __('Remediation') }}
                                    @if ($script->remediation === null)
                                        <span class="small text-body-tertiary">({{ __('none') }})</span>
                                    @endif
                                </button>
                            </li>
                        </ul>

                        <div x-show="code === 'detection'">
                            <p class="small text-body-secondary">{{ __('Exit 0 = compliant, exit 1 = runs the remediation.') }}</p>
                            <x-code-viewer :code="$script->detection" wire:key="detection-{{ $script->fingerprint }}" />
                        </div>
                        <div x-cloak x-show="code === 'remediation'" style="display: none">
                            @if ($script->remediation !== null)
                                <p class="small text-body-secondary">{{ __('Runs when the detection exits with 1, then the detection runs again.') }}</p>
                                <x-code-viewer :code="$script->remediation" wire:key="remediation-{{ $script->fingerprint }}" />
                            @else
                                <p class="text-body-secondary mb-0">{{ __('No remediation script: the detection only reports whether the device is compliant.') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="card">
                    <div class="card-header">{{ __('Details') }}</div>
                    <div class="card-body">
                        <dl class="row small mb-0">
                            <dt class="col-5 fw-normal text-body-secondary">{{ __('Platform') }}</dt>
                            <dd class="col-7">{{ __(\App\Models\Script::PLATFORMS[$script->platform]) }}</dd>
                            <dt class="col-5 fw-normal text-body-secondary">{{ __('Version') }}</dt>
                            <dd class="col-7">v{{ $script->version }}</dd>
                            <dt class="col-5 fw-normal text-body-secondary">{{ __('Timeout') }}</dt>
                            <dd class="col-7">{{ $script->timeout }} s</dd>
                            <dt class="col-5 fw-normal text-body-secondary">{{ __('Devices') }}</dt>
                            <dd class="col-7">{{ $devices }}</dd>
                            <dt class="col-12 fw-normal text-body-secondary">{{ __('Fingerprint') }}</dt>
                            <dd class="col-12 mb-0"><code class="text-break">{{ $script->fingerprint }}</code></dd>
                        </dl>
                        <p class="small text-body-secondary mt-3 mb-0">{{ __('Runs as SYSTEM / root without network access. The agent checks the fingerprint signed by the server before it runs anything.') }}</p>
                    </div>
                </div>
            </div>
        </div>

        <h4 class="mt-4">{{ __('Runs') }}</h4>
        <div>
            @livewire('script-run.data-table', ['scriptId' => $script->id], key('script-runs-'.$script->id))
        </div>
    </div>
</div>
