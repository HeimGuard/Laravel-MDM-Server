<form wire:submit="save">
    @if ($aiMode)
        {{-- AI: one text instead of the fields; the script it writes goes into them to be checked and saved. --}}
        <label class="form-label" for="script-ai-prompt">{{ $detection === '' ? __('Describe the remediation you need') : __('Describe what to change in the script') }}</label>
        <textarea class="form-control @error('aiPrompt') is-invalid @enderror" id="script-ai-prompt" maxlength="4000" rows="6" wire:model="aiPrompt" wire:loading.attr="disabled" wire:target="generate"
            placeholder="{{ __('E.g. check that the firewall is on and turn it on when it is not, Windows and Linux.') }}" autofocus></textarea>
        @error('aiPrompt') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <div class="form-text">{{ __('The AI writes the detection and remediation scripts and fills in the form; check them before saving. Only this text and the script in the form are sent.') }}</div>
        <div class="d-flex justify-content-end gap-2 mt-4">
            <button class="btn btn-light" type="button" wire:click="$set('aiMode', false)" wire:loading.attr="disabled" wire:target="generate">{{ __('Back to the form') }}</button>
            <button class="btn btn-primary" type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate">
                <span aria-hidden="true" class="spinner-border spinner-border-sm me-1" wire:loading wire:target="generate"></span>
                <i class="fas fa-magic me-1" wire:loading.remove wire:target="generate"></i>{{ __('Generate') }}
            </button>
        </div>
    @else
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label" for="script-name">{{ __('Name') }}</label>
                <input class="form-control @error('name') is-invalid @enderror" id="script-name" maxlength="255" type="text" wire:model="name">
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="script-platform">{{ __('Platform') }}</label>
                <select class="form-select" id="script-platform" wire:model="platform">
                    @foreach (\App\Models\Script::PLATFORMS as $value => $label)
                        <option value="{{ $value }}">{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="script-timeout">{{ __('Timeout (s)') }}</label>
                <input class="form-control @error('timeout') is-invalid @enderror" id="script-timeout" max="{{ \App\Models\Script::MAX_TIMEOUT }}" min="5" type="number" wire:model="timeout">
                @error('timeout') <div class="invalid-feedback">{{ $message }}</div> @enderror
                <div class="form-text">{{ __('5 to :max s', ['max' => \App\Models\Script::MAX_TIMEOUT]) }}</div>
            </div>
            <div class="col-12">
                <label class="form-label" for="script-description">{{ __('Description') }}</label>
                <input class="form-control @error('description') is-invalid @enderror" id="script-description" maxlength="2000" type="text" wire:model="description">
                @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            {{-- Empty editors would be a single line: give them room (the component only sets maxLines). --}}
            <div class="col-12" x-init="$nextTick(() => $el.querySelector('.ace-editor')?.env?.editor?.setOptions({ minLines: 12, maxLines: 40, showPrintMargin: false }))">
                {{-- Ace editor (steelants/form), PowerShell highlighting; the scripts run in PowerShell on every platform. --}}
                <x-form::ace id="script-detection" label="{{ __('Detection script') }}" language="powershell" theme="tomorrow_night" wire:model="detection"
                    help="{{ __('Exit 0 = compliant, exit 1 = runs the remediation; both are required. Up to :max kB, checked as PowerShell when saved.', ['max' => \App\Models\Script::MAX_CODE_BYTES / 1024]) }}" />
            </div>
            <div class="col-12" x-init="$nextTick(() => $el.querySelector('.ace-editor')?.env?.editor?.setOptions({ minLines: 8, maxLines: 40, showPrintMargin: false }))">
                <x-form::ace id="script-remediation" label="{{ __('Remediation script') }} ({{ __('optional') }})" language="powershell" theme="tomorrow_night" wire:model="remediation"
                    help="{{ __('Runs when the detection exits with 1, then the detection runs again. Up to :max kB. Scripts run as SYSTEM / root, without network access; of their output the first :output kB are kept.', ['max' => \App\Models\Script::MAX_CODE_BYTES / 1024, 'output' => \App\Models\ScriptRun::MAX_OUTPUT / 1024]) }}" />
            </div>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" id="script-manual" type="checkbox" wire:model="manualRemediation">
                    <label class="form-check-label" for="script-manual">{{ __('Remediate manually') }}</label>
                </div>
                <div class="form-text">{{ __('Runs and schedules only detect (the device does not get the remediation script). A device that needs it shows an alert with Remediate, also in its runs.') }}</div>
            </div>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4">
            <div class="small text-muted">
                @if ($script)
                    {{ __('Version') }} {{ $script->version }} · {{ __('Fingerprint') }} <code class="text-break">{{ $script->fingerprint }}</code>
                    <div>{{ __('Saving changed code creates a new version with a new fingerprint.') }}</div>
                @endif
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-primary" type="button" wire:click="$set('aiMode', true)" @disabled(! $aiAvailable)
                    title="{{ $aiAvailable ? __('Write the script from a description') : __('AI is not set up (MDM_AI_API_KEY).') }}">
                    <i class="fas fa-magic me-1"></i>AI
                </button>
                <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
            </div>
        </div>
    @endif
</form>
