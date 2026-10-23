<div>
    <div class="d-flex flex-wrap align-items-center gap-2 small text-muted mb-2">
        <x-badge :color="$run->statusColor" size="sm" variant="subtle">{{ \App\Models\ScriptRun::statusLabel($run->status) }}</x-badge>
        <span>{{ $run->script?->name }} v{{ $run->version }}</span>
        <span>· {{ $run->device?->displayName }}</span>
        @if ($run->finished_at)
            <span title="{{ $run->finished_at }}">· {{ $run->finished_at->diffForHumans() }}</span>
        @endif
    </div>
    @if ($fixError)
        <div class="alert alert-danger small py-2" role="alert"><i class="fas fa-exclamation-triangle me-1"></i>{{ $fixError }}</div>
    @endif
    @if ($run->error)
        <div class="alert alert-danger small py-2">{{ $run->error }}</div>
    @endif
    @if ($run->script && in_array($run->status, ['failed', 'error'], true) && \App\Support\ScriptGenerator::available())
        @can('is-system-admin')
            <div class="mb-3">
                {{-- Both labels share one cell, so the button keeps its width while it writes. --}}
                <button class="btn btn-sm btn-outline-primary" type="button" wire:click="aiFix" wire:loading.attr="disabled" wire:target="aiFix" title="{{ __('The AI gets the script, the error and the end of the output and writes a fixed copy for you to review; it only runs by hand') }}">
                    <span class="d-grid">
                        <span style="grid-area: 1 / 1" wire:loading.class="invisible" wire:target="aiFix"><i class="fas fa-magic me-1"></i>{{ __('AI fix') }}</span>
                        <span class="invisible" style="grid-area: 1 / 1" wire:loading.class.remove="invisible" wire:target="aiFix"><span class="spinner-border spinner-border-sm me-1"></span>{{ __('Writing…') }}</span>
                    </span>
                </button>
            </div>
        @endcan
    @endif
    @if ($run->output)
        <pre class="small bg-body-tertiary p-3 rounded mb-0" style="max-height: 60vh; white-space: pre-wrap; word-break: break-word;"><code>{{ $run->output }}</code></pre>
    @else
        <p class="text-muted mb-0">{{ __('No output.') }}</p>
    @endif
</div>
