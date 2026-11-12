<div>
    <div class="container">
        <x-boilerplate::breadcrumb class="hide-mobile mb-2" :items="$addDevice ? [route('devices') => __('Devices'), '' => __('Add device')] : ($selectedDevice ? [route('devices') => __('Devices'), '' => $selectedDevice->DisplayName] : ['' => __('Devices')])" />
        @if ($addDevice)
            <div class="btn-group mb-3" role="group" aria-label="{{ __('Kind of device') }}">
                <input autocomplete="off" class="btn-check" id="add-mode-agent" type="radio" value="agent" wire:model.live="addMode">
                <label class="btn btn-outline-secondary" for="add-mode-agent"><i class="fas fa-robot me-1"></i>{{ __('With the agent') }}</label>
                <input autocomplete="off" class="btn-check" id="add-mode-ping" type="radio" value="ping" wire:model.live="addMode">
                <label class="btn btn-outline-secondary" for="add-mode-ping"><i class="fas fa-network-wired me-1"></i>{{ __('Ping only') }}</label>
            </div>
            @if ($addMode === 'ping')
                <div class="card">
                    <form class="card-body" wire:submit="createPingDevice">
                        <h5 class="card-title">{{ __('Add a ping-only device') }}</h5>
                        <p class="text-body-secondary">{{ __('For a printer, a NAS or a PC without the agent: an agent (1.10.0+, not on a battery) in the same network pings it every 30 seconds for its online status and wakes it with Wake-on-LAN.') }}</p>
                        <div class="row g-3" style="max-width: 44rem">
                            <div class="col-12">
                                <label class="form-label" for="ping-name">{{ __('Name') }}</label>
                                <input class="form-control @error('pingName') is-invalid @enderror" id="ping-name" placeholder="{{ __('e.g. Printer') }}" type="text" wire:model="pingName">
                                @error('pingName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-8 col-md-6">
                                <label class="form-label" for="ping-address">{{ __('IPv4 address') }}</label>
                                <input class="form-control font-monospace @error('pingAddress') is-invalid @enderror" id="ping-address" placeholder="192.168.1.50" type="text" wire:model="pingAddress">
                            </div>
                            <div class="col-4 col-md-2">
                                <label class="form-label" for="ping-prefix">{{ __('Prefix') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text">/</span>
                                    <input class="form-control" id="ping-prefix" max="30" min="8" type="number" wire:model="pingPrefix">
                                </div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="ping-mac">{{ __('MAC address') }} <span class="text-body-secondary">({{ __('for Wake-on-LAN') }})</span></label>
                                <input class="form-control font-monospace" id="ping-mac" placeholder="AA:BB:CC:DD:EE:FF" type="text" wire:model="pingMac">
                            </div>
                            @error('pingAddress') <div class="col-12 small text-danger">{{ $message }}</div> @enderror
                        </div>
                        <button class="btn btn-primary mt-3" type="submit"><i class="fas fa-plus me-2"></i>{{ __('Add device') }}</button>
                    </form>
                </div>
            @else
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">{{ __('Enrol new device') }}</h5>
                    <p class="text-body-secondary mb-2">{{ __('Enter this code in the agent to register the device.') }}</p>
                    <h2 class="display-5 fw-semibold">{{ $enrollmentCode }}</h2>
                    <p class="text-body-secondary">
                        <i class="far fa-clock me-1"></i>{{ __('Expires') }} {{ $enrollmentCodeExpiration->diffForHumans() }} ({{ $enrollmentCodeExpiration }})
                    </p>

                    <h6 class="mt-4">{{ __('Install the agent') }}</h6>
                    <div x-data="{ tab: 'windows' }">
                        <ul class="nav nav-tabs mb-2">
                            <li class="nav-item">
                                <button class="nav-link" type="button" x-bind:class="{ active: tab === 'windows' }" x-on:click="tab = 'windows'">
                                    <i class="fab fa-windows me-2"></i>Windows PowerShell
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" type="button" x-bind:class="{ active: tab === 'pwsh' }" x-on:click="tab = 'pwsh'">
                                    <i class="fas fa-terminal me-2"></i>PowerShell 7 (Windows, Linux)
                                </button>
                            </li>
                        </ul>

                        @foreach (['windows' => __('Run in PowerShell as Administrator.'), 'pwsh' => __('Run in pwsh as Administrator on Windows, or with sudo pwsh on Debian / Ubuntu.')] as $variant => $hint)
                            <div x-show="tab === '{{ $variant }}'" @if ($variant !== 'windows') x-cloak style="display: none" @endif>
                                <x-copy-command :command="$installCommands[$variant]" />
                                <p class="small text-body-secondary mt-2 mb-0">{{ $hint }}</p>
                            </div>
                        @endforeach
                    </div>

                    <p class="small text-body-secondary mt-3 mb-0" title="{{ __('The agent accepts only commands, responses and updates signed with this key. It logs the fingerprint it pinned in agent.log.') }}">
                        <i class="fas fa-key me-1"></i>{{ __('Server key fingerprint') }}:
                        <code class="text-break">{{ \App\Support\Signing::fingerprint() }}</code>
                    </p>
                </div>
            </div>
            @endif
        @else
            <div class="row g-4">
                {{-- The device list: beside the detail on wide screens, behind a button on phones. --}}
                <aside class="col-12 col-lg-3" x-data="{ open: false }">
                    <button class="btn btn-light w-100 d-flex align-items-center d-lg-none" type="button" x-on:click="open = ! open" x-bind:aria-expanded="open">
                        <i class="fas fa-list me-2"></i>{{ __('Devices') }} <span class="text-body-secondary ms-1">{{ $total }}</span>
                        <i class="fas fa-chevron-down ms-auto small" x-bind:class="{ 'fa-rotate-180': open }"></i>
                    </button>
                    <div class="device-switcher d-lg-block mt-2 mt-lg-0" x-bind:class="{ 'd-none': ! open }">
                        <div class="position-relative mb-2">
                            <i class="fas fa-search position-absolute top-50 translate-middle-y text-body-secondary small" style="left: .75rem"></i>
                            <input aria-label="{{ __('Search devices') }}" class="form-control form-control-sm" placeholder="{{ __('Search devices, OS, IP…') }}" style="padding-left: 2rem" type="search" wire:model.live.debounce.300ms="search">
                        </div>
                        @if ($tags !== [])
                            <div class="mdm-tags mb-2">
                                @foreach ($tags as $tagOption)
                                    <x-tag as="button" :tag="$tagOption" :active="strcasecmp($tag, $tagOption) === 0" wire:click="filterTag('{{ $tagOption }}')" />
                                @endforeach
                            </div>
                        @endif
                        <div class="list-group">
                            @forelse ($rows as $row)
                                @php($item = $row['device'])
                                <a class="list-group-item list-group-item-action d-flex align-items-start gap-2 py-2 {{ $item->id === $selectedDevice?->id ? 'active' : '' }}"
                                    href="{{ route('devices', ['selectedDeviceId' => $item->id]) }}" wire:click.prevent="selectDevice({{ $item->id }})" x-on:click="open = false" wire:key="switch-device-{{ $item->id }}"
                                    @if ($item->id === $selectedDevice?->id) aria-current="true" @endif>
                                    <span class="device-dot mt-2 {{ $item->offline ? 'is-offline' : 'is-online' }}" title="{{ $item->offline ? __('Offline') : __('Online') }}"></span>
                                    <span class="min-w-0 flex-grow-1">
                                        <span class="d-block text-truncate fw-medium"><i class="{{ $item->typeIcon }} fa-fw me-1 opacity-75"></i>{{ $item->displayName }}</span>
                                        @if ($item->tagList !== [])
                                            <x-tags class="mt-1" :tags="$item->tagList" />
                                        @elseif ($subtitle = $item->isPingOnly ? $item->ping_address : $item->os)
                                            {{-- No tags: the system (the address of a ping-only device) in their place. --}}
                                            <span class="d-block small text-truncate opacity-75 mt-1" style="line-height: 1.375rem">{{ $subtitle }}</span>
                                        @else
                                            {{-- Same height as a row with tags. --}}
                                            <span aria-hidden="true" class="mdm-tags mt-1 invisible"><x-tag tag="-" /></span>
                                        @endif
                                    </span>
                                    @if ($item->sendsSyslog)
                                        <i class="fas fa-stream fa-fw text-body-secondary mt-1" title="{{ __('Sends syslog to the server') }}"></i>
                                    @endif
                                    @if ($row['attention'] && ! $item->offline)
                                        <i class="fas fa-exclamation-circle text-warning mt-1" title="{{ collect($row['flags'])->pluck('label')->implode(', ') }}"></i>
                                    @endif
                                </a>
                            @empty
                                <x-empty-state>{{ $total === 0 ? __('No devices enrolled yet.') : __('No device matches the filters.') }}</x-empty-state>
                            @endforelse
                        </div>
                        <div class="d-grid mt-2">
                            <button class="btn btn-primary" type="button" wire:click="$set('addDevice', true)">
                                <i class="fas fa-plus me-2"></i>{{ __('Add device') }}
                            </button>
                        </div>
                    </div>
                </aside>
                <div class="col-12 col-lg-9">
                    @if ($selectedDevice)
                        @livewire('device-detail', ['selectedDeviceId' => $selectedDevice->id], key($selectedDevice->id))
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
