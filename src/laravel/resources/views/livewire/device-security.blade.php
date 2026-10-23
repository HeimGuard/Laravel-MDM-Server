@use('App\Models\Device')
{{-- Findings of the scanner, then the inventory they come from (one list at a time). --}}
<div x-data="{ search: '' }">
    @php
        $posture = array_filter($inventory?->data['posture'] ?? [], fn ($value) => $value !== null);
        $lists = [
            'posture' => [__('Settings'), count($posture)],
            'listening' => [__('Open ports'), count($inventory?->data['listening'] ?? [])],
            'admins' => [__('Administrators'), count($inventory?->data['admins'] ?? [])],
            'startup' => [__('Startup'), count($inventory?->data['startup'] ?? [])],
            'software' => [__('Software'), count($inventory?->data['software'] ?? [])],
            'processes' => [__('Processes'), count($inventory?->data['processes'] ?? [])],
            'events' => [__('Events'), $events->count()],
        ];
        $columns = [
            'listening' => ['Protocol', 'Address', 'Port', 'Process'],
            'admins' => ['Name', 'Source', 'Enabled'],
            'startup' => ['Name', 'Location', 'Command'],
            'software' => ['Name', 'Version', 'Publisher', 'Source'],
            'processes' => ['Name', 'User', 'Path', 'CommandLine'],
        ];
        $yesNo = fn ($value) => is_bool($value) ? ($value ? __('Yes') : __('No')) : $value;
    @endphp

    @if ($remediateError ?? null)
    <div class="alert alert-danger small py-2" role="alert"><i class="fas fa-exclamation-triangle me-1"></i>{{ $remediateError }}</div>
@endif
@if ($remediateDone ?? null)
    <div class="alert alert-success small py-2" role="alert"><i class="fas fa-check me-1"></i>{{ $remediateDone }}</div>
@endif
<div class="d-flex flex-wrap justify-content-end gap-2 mb-2">
        @if ($findings->isNotEmpty() || $acknowledged->isNotEmpty())
            <button class="btn btn-sm btn-light" type="button" wire:click="exportMarkdown" title="{{ __('The open findings as a Markdown report') }}"><i class="fas fa-file-alt me-1"></i>{{ __('Export Markdown') }}</button>
        @endif
        @if ($inventory)
            <button class="btn btn-sm btn-light" type="button" wire:click="exportInventory" title="{{ __('The security inventory and the findings of this device as JSON, for an AI to analyse') }}"><i class="fas fa-file-code me-1"></i>{{ __('Export inventory (JSON)') }}</button>
        @endif
    </div>
    {{-- What needs attention; the acknowledged ones are folded away (and can be opened again). --}}
    <div class="card overflow-hidden mb-3">
        <div class="list-group list-group-flush">
            @forelse ($findings as $finding)
                @include('livewire.security-scan.partials.finding', ['finding' => $finding, 'showDevice' => false])
            @empty
                <div class="list-group-item text-muted py-3"><i class="fas fa-shield-alt text-success me-2"></i>{{ $acknowledged->isEmpty() ? __('No findings.') : __('Nothing needs attention.') }}</div>
            @endforelse
        </div>
    </div>

    @if ($acknowledged->isNotEmpty())
        <details class="card overflow-hidden mb-3" wire:key="security-acknowledged">
            <summary class="card-body py-2 d-flex align-items-center gap-2" style="cursor: pointer">
                <span class="fw-medium">{{ __('Acknowledged') }}</span><span class="small text-muted">{{ $acknowledged->count() }}</span>
            </summary>
            <div class="list-group list-group-flush">
                @foreach ($acknowledged as $finding)
                    @include('livewire.security-scan.partials.finding', ['finding' => $finding, 'showDevice' => false])
                @endforeach
            </div>
        </details>
    @endif

    @if ($inventory === null && $device->isPingOnly)
        {{-- No agent: only the findings of the server's own checks (port scans). --}}
    @elseif ($inventory === null)
        <div class="small text-muted">{{ __('No security inventory yet: it needs agent :version or newer.', ['version' => Device::SECURITY_VERSION]) }}</div>
    @else
        <div class="d-flex align-items-center mb-2">
            <div class="fw-medium me-auto">{{ __('What the agent reports') }}</div>
            <input class="form-control form-control-sm" placeholder="{{ __('Search') }}" style="max-width: 16rem;" type="search" x-model="search">
        </div>
        <div class="d-flex flex-column gap-2">
            @foreach ($lists as $key => [$label, $count])
                <details class="card" wire:key="security-list-{{ $key }}" @if ($key === 'posture') open @endif>
                    <summary class="card-body py-2 d-flex align-items-center gap-2" style="cursor: pointer">
                        <span class="fw-medium">{{ $label }}</span><span class="small text-muted">{{ $count }}</span>
                    </summary>
                    <div class="px-3 pb-3">
                        @if ($key === 'posture')
                            @if ($posture === [])
                                <div class="small text-muted">{{ __('Not reported.') }}</div>
                            @else
                                <div class="row g-2">
                                    @foreach ($posture as $field => $value)
                                        <div class="col-12 col-md-6 col-xl-4">
                                            <div class="d-flex justify-content-between gap-2 rounded bg-body-tertiary px-3 py-2 small">
                                                <span class="text-body-secondary">{{ \Illuminate\Support\Str::headline($field) }}</span>
                                                <span class="fw-medium text-end text-break">{{ $yesNo($value) }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @elseif ($key === 'events')
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0 small">
                                    <tbody>
                                        @forelse ($events as $event)
                                            <tr x-show="!search || @js(mb_strtolower($event->label.' '.$event->user.' '.$event->source.' '.$event->message)).includes(search.toLowerCase())">
                                                <td class="text-nowrap text-body-secondary" title="{{ $event->occurred_at }}">{{ $event->occurred_at->diffForHumans() }}</td>
                                                <td class="fw-medium text-nowrap">{{ $event->label }}</td>
                                                <td class="text-end">{{ $event->count }}×</td>
                                                <td class="text-break">{{ $event->user }}</td>
                                                <td class="d-none d-md-table-cell text-break text-muted">{{ $event->source }}</td>
                                                <td class="d-none d-lg-table-cell text-break text-muted">{{ $event->message }}</td>
                                            </tr>
                                        @empty
                                            <tr><td class="text-muted">{{ __('No events.') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <a class="small d-inline-block mt-2" href="{{ route('security', ['tab' => 'events']) }}">{{ __('All events') }}</a>
                        @else
                            <div class="table-responsive" style="max-height: 32rem">
                                <table class="table table-sm align-middle mb-0 small">
                                    <thead class="sticky-top">
                                        <tr>
                                            @foreach ($columns[$key] as $field)
                                                <th class="{{ $loop->index > 1 ? 'd-none d-md-table-cell' : '' }}">{{ __(\Illuminate\Support\Str::headline($field)) }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($inventory->data[$key] ?? [] as $item)
                                            @php($text = mb_strtolower(implode(' ', array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $item))))
                                            <tr wire:key="security-{{ $key }}-{{ $loop->index }}" x-show="!search || @js($text).includes(search.toLowerCase())">
                                                @foreach ($columns[$key] as $field)
                                                    <td class="text-break {{ $loop->index > 1 ? 'd-none d-md-table-cell text-muted' : '' }} {{ $loop->first ? 'fw-medium' : '' }}">{{ $yesNo($item[$field] ?? null) ?? '—' }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </details>
            @endforeach
        </div>

        <div class="small text-body-tertiary mt-3" title="{{ $inventory->collected_at }}">{{ __('Collected :time', ['time' => $inventory->collected_at->diffForHumans()]) }}</div>
    @endif
</div>
