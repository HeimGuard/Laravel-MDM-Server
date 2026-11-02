@php
    $hasData = ! empty($selectedDevice->data);
    // All commands are in the menu (the alerts above offer Install all and Restart when needed).
    $menuCommands = [
        'doUpdates' => ['icon' => 'fas fa-sync', 'label' => $pendingUpdates > 0 ? trans_choice('Install :count update|Install :count updates', $pendingUpdates, ['count' => $pendingUpdates]) : __('Install updates'), 'confirm' => null],
        'restart' => ['icon' => 'fas fa-redo', 'label' => __('Restart'), 'confirm' => __('Restart :device now? Unsaved work of its users is lost.', ['device' => $selectedDevice->displayName])],
        'turnOff' => ['icon' => 'fas fa-power-off', 'label' => __('Turn off'), 'confirm' => __('Turn off :device? It can only be started again on site (or with Wake-on-LAN).', ['device' => $selectedDevice->displayName])],
    ];
    // Wake beside the menu of every device a magic packet can wake (not virtual machines), disabled
    // with the reason while it cannot (online, no relay in its network, …).
    $canWake = $selectedDevice->isPingOnly || ($hasData && $selectedDevice->virtualization === null);
    // Sync collects updates, packages and disk health again now instead of on the next schedule.
    $syncRunning = $hasData && ! $selectedDevice->offline && ! $selectedDevice->isPingOnly ? \App\Models\Device::findActive($active, 'sync') : null;
    $syncRefusal = $hasData && ! $selectedDevice->offline && ! $selectedDevice->isPingOnly && ! $syncRunning ? $selectedDevice->commandRefusal('sync') : null;
@endphp
{{-- Reloads when the device reports (live updates over Reverb), e.g. the progress of a command.
     Wake, Sync and the menu sit in the top right corner of the device card (position-relative). --}}
<div>
    <div class="position-absolute top-0 end-0 p-3 d-flex align-items-center gap-2">
        @if (! $selectedDevice->offline && ! $selectedDevice->isPingOnly)
            @php
                $power = $selectedDevice->batteryLevel;
                $pluggedIn = $selectedDevice->pluggedIn;
            @endphp
            {{-- The power state, as high as the buttons beside it. --}}
            <span class="align-self-stretch d-none d-sm-inline-flex align-items-center gap-1 px-2 text-body-secondary text-nowrap"
                title="{{ $power === null ? __('Plugged in') : ($pluggedIn ? __('Charging') : ($pluggedIn === false ? __('On battery') : __('Battery'))) }}">
                @if ($power === null)
                    <i class="fas fa-plug"></i>
                @else
                    @if ($pluggedIn)
                        <i class="fas fa-bolt text-warning"></i>
                    @endif
                    <i class="fas {{ $power < 20 ? 'fa-battery-quarter' : ($power < 85 ? 'fa-battery-half' : 'fa-battery-full') }} {{ $power < 20 && ! $pluggedIn ? 'text-danger' : '' }}"></i>
                    <span class="text-body">{{ $power }} %</span>
                @endif
            </span>
        @endif
        @if ($canWake)
            <button class="btn btn-light btn-sq" type="button" wire:click="wake" aria-label="{{ __('Wake') }}"
                title="{{ $wakeRefusal ?? __('Wake') . ': ' . __('Magic packet sent by :relay', ['relay' => $wakeRelay?->displayName]) }}" @disabled($wakeRefusal || $recentWake?->active)>
                @if ($recentWake?->active)
                    <span aria-hidden="true" class="spinner-border spinner-border-sm"></span>
                @else
                    <i class="fas fa-power-off"></i>
                @endif
            </button>
        @endif
        @if ($selectedDevice->isPingOnly)
            {{-- Sync of a ping-only device: the agent that pings it pings it now. --}}
            <button class="btn btn-light btn-sq" type="button" wire:click="pingNow" aria-label="{{ __('Sync') }}"
                title="{{ $pingNowRefusal ?? __('Sync') . ': ' . __('Ping it now') }}" @disabled($pingNowRefusal || $recentPing?->active)>
                @if ($recentPing?->active)
                    <span aria-hidden="true" class="spinner-border spinner-border-sm"></span>
                @else
                    <i class="fas fa-sync-alt"></i>
                @endif
            </button>
        @elseif ($hasData && ! $selectedDevice->offline)
            <button class="btn btn-light btn-sq" type="button" wire:click="sendCommandToDevice('sync')" aria-label="{{ __('Sync') }}"
                title="{{ $syncRunning ? __('Sync') . ': ' . $syncRunning->statusLabel : ($syncRefusal ?? __('Sync') . ': ' . __('Collect all data on the device again now and report it')) }}"
                @disabled($syncRunning || $syncRefusal)>
                @if ($syncRunning)
                    <span aria-hidden="true" class="spinner-border spinner-border-sm"></span>
                @else
                    <i class="fas fa-sync-alt"></i>
                @endif
            </button>
        @endif
        {{-- Open state in Alpine (not Bootstrap's JS): a live update of the component keeps it open. --}}
        <div class="dropdown" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
            <button x-bind:aria-expanded="open" aria-label="{{ __('More actions') }}" class="btn btn-light btn-sq" title="{{ __('More actions') }}" type="button" x-on:click="open = ! open" x-bind:class="{ show: open }">
                <i class="fas fa-ellipsis-h"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" data-bs-popper="static" x-bind:class="{ show: open }" x-on:click="if ($event.target.closest('button:not([disabled])')) open = false">
                {{-- The agent update is in the "newer agent" alert, not repeated here. --}}
                <li>
                    <button class="dropdown-item" type="button" wire:click="$dispatch('rename-device')">
                        <i class="dropdown-ico fas fa-pen fa-fw"></i>{{ __('Rename') }}
                    </button>
                </li>
                <li>
                    <button class="dropdown-item" type="button" wire:click="$dispatch('edit-device-tags')">
                        <i class="dropdown-ico fas fa-tags fa-fw"></i>{{ __('Edit tags') }}
                    </button>
                </li>
                <li>
                    <button class="dropdown-item" type="button"
                        x-on:click="Livewire.dispatch('openModal', {livewireComponents: 'notifications.device-rules', title: @js(__('Alerts for :device', ['device' => $selectedDevice->displayName])), parameters: {deviceId: {{ $selectedDevice->id }}}})">
                        <i class="dropdown-ico far fa-bell fa-fw"></i>{{ __('Alerts') }}
                    </button>
                </li>
                @if ($selectedDevice->isPingOnly)
                    <li>
                        <button class="dropdown-item" type="button"
                            x-on:click="Livewire.dispatch('openModal', {livewireComponents: 'ping-settings', title: @js(__('Ping settings of :device', ['device' => $selectedDevice->displayName])), parameters: {deviceId: {{ $selectedDevice->id }}}})">
                            <i class="dropdown-ico fas fa-network-wired fa-fw"></i>{{ __('Ping settings') }}
                        </button>
                    </li>
                @endif
                @if ($canWake && ! $selectedDevice->isPingOnly)
                    <li>
                        <button class="dropdown-item" type="button"
                            x-on:click="Livewire.dispatch('openModal', {livewireComponents: 'wake-settings', title: @js(__('Wake-on-LAN of :device', ['device' => $selectedDevice->displayName])), parameters: {deviceId: {{ $selectedDevice->id }}}})">
                            <i class="dropdown-ico fas fa-sliders-h fa-fw"></i>{{ __('Wake-on-LAN settings') }}
                        </button>
                    </li>
                @endif
                @if ($hasData && ! $selectedDevice->offline)
                    <li><hr class="dropdown-divider"></li>
                    @foreach ($menuCommands as $command => $item)
                        @php
                            // A restart and a shutdown exclude each other.
                            $running = \App\Models\Device::findActive($active, $command)
                                ?? (in_array($command, ['restart', 'turnOff'], true) ? (\App\Models\Device::findActive($active, 'restart') ?? \App\Models\Device::findActive($active, 'turnOff')) : null);
                            $refusal = $selectedDevice->commandRefusal($command);
                        @endphp
                        <li>
                            <button class="dropdown-item d-flex align-items-center" type="button" wire:click="sendCommandToDevice('{{ $command }}')"
                                @if ($item['confirm']) wire:confirm="{{ $item['confirm'] }}" @endif
                                @if ($running) title="{{ $running->label }}: {{ $running->statusLabel }}" @elseif ($refusal) title="{{ $refusal }}" @elseif ($item['title'] ?? null) title="{{ $item['title'] }}" @endif
                                @disabled($running || $refusal)>
                                @if ($running && $running->command === $command)
                                    <span aria-hidden="true" class="dropdown-ico spinner-border spinner-border-sm"></span>
                                @else
                                    <i class="dropdown-ico {{ $item['icon'] }} fa-fw"></i>
                                @endif
                                {{ $item['label'] }}
                                @if ($running && $running->command === $command)
                                    <span class="small text-muted ms-2">{{ $running->statusLabel }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                    {{-- Scan of each of its networks (Networks page too): what answers shows as unknown devices there. --}}
                    @foreach ($selectedDevice->scannableNetworks as $network)
                        @php
                            $scanning = \App\Models\Device::findActive($active, 'scanNetwork', ['cidr' => $network]);
                            $scanRefusal = $scanning ? null : $selectedDevice->commandRefusal('scanNetwork', ['cidr' => $network]);
                        @endphp
                        <li>
                            <button class="dropdown-item d-flex align-items-center" type="button" wire:click="scan(@js($network))"
                                title="{{ $scanning ? $scanning->displayMessage ?: $scanning->statusLabel : ($scanRefusal ?? __('Ping every address of the network and show what answers on the Networks page')) }}"
                                @disabled($scanning || $scanRefusal)>
                                @if ($scanning)
                                    <span aria-hidden="true" class="dropdown-ico spinner-border spinner-border-sm"></span>
                                @else
                                    <i class="dropdown-ico fas fa-search-location fa-fw"></i>
                                @endif
                                {{ __('Scan :network', ['network' => $network]) }}
                                @if ($scanning)
                                    <span class="small text-muted ms-2">{{ $scanning->progress !== null ? $scanning->progress.' %' : $scanning->statusLabel }}</span>
                                @elseif ($scanRefusal)
                                    <span class="small text-muted ms-2 text-truncate" style="max-width: 12rem">{{ $scanRefusal }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                @endif
                {{-- Port scan of this host, only when an agent in its network can scan it (port_scan on). --}}
                @if ($portScan && $portScan['scanner'])
                    @php $scanningPorts = $portScan['command']; @endphp
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <button class="dropdown-item d-flex align-items-center" type="button" wire:click="scanPorts"
                            title="{{ $scanningPorts ? ($scanningPorts->displayMessage ?: $scanningPorts->statusLabel) : __('Scan the open ports of :ip and read what each service returns', ['ip' => $portScan['address']]) }}"
                            @disabled($scanningPorts)>
                            @if ($scanningPorts)
                                <span aria-hidden="true" class="dropdown-ico spinner-border spinner-border-sm"></span>
                            @else
                                <i class="dropdown-ico fas fa-plug fa-fw"></i>
                            @endif
                            {{ __('Scan ports') }}
                            @if ($scanningPorts)
                                <span class="small text-muted ms-2">{{ $scanningPorts->progress !== null ? $scanningPorts->progress.' %' : $scanningPorts->statusLabel }}</span>
                            @endif
                        </button>
                    </li>
                    <li>
                        <button class="dropdown-item d-flex align-items-center" type="button" wire:click="scanPorts(true)" @disabled($scanningPorts)>
                            <i class="dropdown-ico fas fa-search-location fa-fw"></i>{{ __('Scan all ports (1–65535)') }}
                        </button>
                    </li>
                @endif
                <li><hr class="dropdown-divider"></li>
                <li>
                    <button class="dropdown-item text-danger" type="button" wire:click.prevent="deleteDevice()" wire:confirm="{{ __('Do you really want to delete this device?') }}">
                        <i class="dropdown-ico fas fa-trash fa-fw text-danger"></i>{{ __('Delete device') }}
                    </button>
                </li>
            </ul>
        </div>
    </div>

    @if (! $recentWake && $selectedDevice->offline && ($hasData || $selectedDevice->isPingOnly) && $wakeRefusal)
        <div class="small text-muted mt-2"><i class="fas fa-power-off me-1"></i>{{ __('Wake-on-LAN') }}: {{ $wakeRefusal }}</div>
    @endif

    {{-- The result of the last Sync (ping now); while it runs it is with the commands below. --}}
    @if ($recentPing && ! $recentPing->active)
        <div class="small mt-2 {{ in_array($recentPing->status, ['failed', 'expired'], true) ? 'text-danger' : 'text-muted' }}">
            <i class="fas fa-network-wired me-1"></i>{{ __('Ping through :relay', ['relay' => $recentPing->device?->displayName]) }}:
            {{ in_array($recentPing->status, ['queued', 'sent'], true) ? __('waiting for :relay', ['relay' => $recentPing->device?->displayName]) : ($recentPing->displayMessage ?: $recentPing->statusLabel) }}
        </div>
    @endif

    {{-- The last port scan of this host (it runs on another agent); of a ping-only device in the Open
         ports card of its overview. Everything shown comes from the scanned host and is printed as escaped text. --}}
    @if ($portScan && $portScan['result'] && ! $portScan['command'] && ! $selectedDevice->isPingOnly)
        @php $result = $portScan['result']; @endphp
        <div class="small mt-2">
            <details>
                <summary class="text-body-secondary">
                    <i class="fas fa-plug me-1"></i>{{ trans_choice(':count open port|:count open ports', count($result->ports)) }}
                    @if ($result->findings)<span class="text-warning-emphasis ms-1"><i class="fas fa-triangle-exclamation me-1"></i>{{ trans_choice(':count note|:count notes', count($result->findings)) }}</span>@endif
                    · {{ $result->scanned_at->diffForHumans() }}
                </summary>
                @if ($result->ports)
                    <ul class="list-unstyled mb-0 mt-1 ms-3">
                        @foreach ($result->ports as $port)
                            <li class="text-break">
                                <span class="fw-medium">{{ $port['port'] }}</span>
                                @if (!empty($port['service']))<span class="text-body-secondary">{{ $port['service'] }}</span>@endif
                                @if (!empty($port['banner']))<span class="text-body-secondary">— {{ $port['banner'] }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($result->findings)
                    <ul class="list-unstyled mb-0 mt-1 ms-3 text-warning-emphasis">
                        @foreach ($result->findings as $finding)
                            <li class="text-break"><i class="fas fa-triangle-exclamation me-1"></i>{{ $finding }}</li>
                        @endforeach
                    </ul>
                @endif
            </details>
        </div>
    @endif

    @error('command')
        <div class="small text-danger mt-2">{{ $message }}</div>
    @enderror

    @if ($onItsWay->isNotEmpty())
        {{-- The device's commands on their way, and the wakes / pings other agents do for it (like a restart, with a progress bar). --}}
        <div class="vstack gap-2 mt-3">
            @foreach ($onItsWay as $command)
                @php
                    $relay = $command->device_id !== $selectedDevice->id ? $command->device?->displayName : null;
                    $note = match (true) {
                        $relay === null => null,
                        in_array($command->status, ['queued', 'sent'], true) => $command->command === 'wake'
                            ? __('waiting for :relay to send the magic packet', ['relay' => $relay])
                            : __('waiting for :relay', ['relay' => $relay]),
                        // Sent (agents before 1.13.2 are done then): the device is waited for; the
                        // interface and addresses are in the history.
                        $command->command === 'wake' => __('Magic packet sent by :relay, waiting for the device to come online', ['relay' => $relay]),
                        default => __('through :relay', ['relay' => $relay]),
                    };
                @endphp
                <div class="d-flex align-items-center gap-2" wire:key="active-command-{{ $command->id }}">
                    <div class="flex-grow-1 min-w-0">@include('partials.device.command-progress', ['command' => $command, 'note' => $note])</div>
                    @if (! $command->active)
                        {{-- A wake of an agent before 1.13.2: done on its side, the device is waited for. --}}
                    @elseif ($command->status === 'queued')
                        <button class="btn btn-sm btn-link text-body-secondary p-0" title="{{ __('Cancel') }}" type="button" wire:click="cancel({{ $command->id }})">
                            <i class="fas fa-times"></i>
                        </button>
                    @else
                        {{-- A command the device took can hang (an installer waiting for a window): giving it up frees the way for Restart or a new try. --}}
                        <button class="btn btn-sm btn-link text-body-secondary p-0" title="{{ __('Give up waiting') }}" type="button" wire:click="cancel({{ $command->id }})"
                            wire:confirm="{{ __('Stop waiting for :command? The device may still be working on it, but it no longer blocks other commands (e.g. Restart).', ['command' => $command->label]) }}">
                            <i class="fas fa-times"></i>
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
