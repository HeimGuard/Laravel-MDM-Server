{{-- One finding of the scanner: severity, rule, message, since when; explain / remediate with AI, acknowledge / open again. --}}
<div class="list-group-item d-flex align-items-start gap-3 py-3" wire:key="security-finding-{{ $finding->id }}">
    <span class="icon-tile bg-{{ $finding->severityColor }}-subtle text-{{ $finding->severityColor }}-emphasis" title="{{ __(ucfirst($finding->severity)) }}"><i class="fas fa-shield-alt"></i></span>
    <div class="flex-grow-1 min-w-0">
        <div class="d-flex flex-wrap align-items-center column-gap-2">
            <x-badge :color="$finding->severityColor" size="sm" variant="subtle">{{ __(ucfirst($finding->severity)) }}</x-badge>
            <span class="fw-semibold">{{ $finding->title }}</span>
            @if ($showDevice ?? false)
                <span class="text-body-secondary">·</span>
                @if ($finding->device_id)
                    <a class="text-body" wire:navigate href="{{ route('devices', ['selectedDeviceId' => $finding->device_id, 'tab' => 'security']) }}">{{ $finding->device?->displayName }}</a>
                @elseif ($finding->targetUrl)
                    <a class="text-body" href="{{ $finding->targetUrl }}"><i class="fas fa-network-wired fa-xs me-1 text-body-tertiary"></i>{{ $finding->targetLabel }}</a>
                @else
                    <span class="text-body-secondary"><i class="fas fa-network-wired fa-xs me-1"></i>{{ $finding->targetLabel }}</span>
                @endif
                @if (! $finding->device_id && $finding->target_mac)
                    <span class="small text-body-tertiary">{{ $finding->target_mac }}</span>
                @endif
            @endif
        </div>
        <div class="small text-break mt-1">{{ $finding->message }}</div>
        @if ($finding->worstSeverity)
            <div class="small text-body-secondary mt-1"><i class="fas fa-info-circle me-1"></i>{{ __('Worst CVE: :severity (nothing can be done about it yet)', ['severity' => __(ucfirst($finding->worstSeverity))]) }}</div>
        @endif
        @if ($finding->remediation)
            <div class="small text-muted mt-1" style="white-space: pre-line"><i class="fas fa-wrench me-1"></i>{{ $finding->remediation }}</div>
        @endif
        @php($links = $finding->urls)
        @if ($links)
            <div class="mt-1">@foreach ($links as $link)<a class="small me-2 text-break" href="{{ $link['url'] }}" rel="noopener noreferrer" target="_blank" title="{{ $link['url'] }}"><i class="fas fa-external-link-alt fa-xs me-1"></i>{{ $link['host'] }}</a>@endforeach</div>
        @endif
        @if (isset($explanations[$finding->id]))
            <div class="small border-start border-3 border-primary-subtle bg-body-tertiary rounded-end px-3 py-2 mt-2" style="white-space: pre-line" wire:key="security-finding-explanation-{{ $finding->id }}"><div class="fw-semibold mb-1"><i class="fas fa-robot me-1 text-primary"></i>{{ __('Explained by AI') }}</div>{{ $explanations[$finding->id] }}</div>
        @endif
        <div class="small text-body-tertiary mt-1">
            <span title="{{ $finding->first_seen_at }}">{{ __('Found :time', ['time' => $finding->first_seen_at->diffForHumans()]) }}</span>
            @if ($finding->occurrences > 1)
                · {{ trans_choice(':count time|:count times', $finding->occurrences) }}, {{ __('last :time', ['time' => $finding->last_seen_at->diffForHumans()]) }}
            @endif
            @if ($finding->resolved_at && ! $finding->acknowledged_at)
                · <span title="{{ $finding->resolved_at }}">{{ __('resolved :time', ['time' => $finding->resolved_at->diffForHumans()]) }}</span>
            @endif
            @if ($finding->acknowledged_at)
                · <span title="{{ $finding->acknowledged_at }}">{{ __('acknowledged by :user :time', ['user' => $finding->acknowledger?->name ?? '?', 'time' => $finding->acknowledged_at->diffForHumans()]) }}</span>
            @endif
        </div>
    </div>
    @php($aiAvailable = \App\Services\AiService::available())
    <div class="d-flex flex-column gap-2 align-items-stretch">
        @if ($aiAvailable && ! $finding->acknowledged_at && ! $finding->resolved_at && ! $finding->rule?->isEvent)
            <button class="btn btn-sm btn-outline-primary text-nowrap text-start" type="button" wire:click="remediateWithAi({{ $finding->id }})" wire:loading.attr="disabled" wire:target="remediateWithAi({{ $finding->id }})" title="{{ __('The AI writes a remediation script for you to review') }}">
                {{-- Both labels share one cell, so the button (and the buttons stretched to it) keeps its width while it writes. --}}
                <span class="d-grid">
                    <span style="grid-area: 1 / 1" wire:loading.class="invisible" wire:target="remediateWithAi({{ $finding->id }})"><i class="fas fa-magic me-1"></i>{{ __('Remediate with AI') }}</span>
                    <span class="invisible" style="grid-area: 1 / 1" wire:loading.class.remove="invisible" wire:target="remediateWithAi({{ $finding->id }})"><span class="spinner-border spinner-border-sm me-1"></span>{{ __('Writing…') }}</span>
                </span>
            </button>
        @endif
        @if ($aiAvailable)
            <button class="btn btn-sm btn-outline-secondary text-nowrap text-start" type="button" wire:click="explainWithAi({{ $finding->id }})" wire:loading.attr="disabled" wire:target="explainWithAi({{ $finding->id }})" title="{{ __('The AI explains what the finding means and what to do about it') }}">
                <span class="d-grid">
                    <span style="grid-area: 1 / 1" wire:loading.class="invisible" wire:target="explainWithAi({{ $finding->id }})"><i class="fas {{ isset($explanations[$finding->id]) ? 'fa-eye-slash' : 'fa-comment-dots' }} me-1"></i>{{ isset($explanations[$finding->id]) ? __('Hide explanation') : __('Explain with AI') }}</span>
                    <span class="invisible" style="grid-area: 1 / 1" wire:loading.class.remove="invisible" wire:target="explainWithAi({{ $finding->id }})"><span class="spinner-border spinner-border-sm me-1"></span>{{ __('Explaining…') }}</span>
                </span>
            </button>
        @endif
        @if ($finding->acknowledged_at)
            @if (! $finding->resolved_at || $finding->rule?->isEvent)
                <button class="btn btn-sm btn-light text-nowrap" type="button" wire:click="reopen({{ $finding->id }})">{{ __('Open again') }}</button>
            @endif
        @elseif (! $finding->resolved_at)
            <button class="btn btn-sm btn-light text-nowrap" type="button" wire:click="acknowledge({{ $finding->id }})" title="{{ $finding->rule?->isEvent ? __('Done with it') : __('Known, stays quiet until it is gone') }}">
                <i class="fas fa-check me-1"></i>{{ __('Acknowledge') }}
            </button>
        @endif
    </div>
</div>
