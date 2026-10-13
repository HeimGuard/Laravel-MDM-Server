<?php

namespace App\Livewire\Script;

use App\Models\Script;
use App\Support\PowerShellCheck;
use App\Support\ScriptGenerator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use RuntimeException;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

#[AllowInModal(ability: 'is-system-admin')]
class Form extends Component
{
    public ?int $scriptId = null;

    public string $name = '';

    public string $description = '';

    public string $platform = 'all';

    public int $timeout = 60;

    public string $detection = '';

    public string $remediation = '';

    /** Runs only detect; the remediation is started per device (an alert with Remediate). */
    public bool $manualRemediation = false;

    /** The AI view: one text describing the script instead of the fields. */
    public bool $aiMode = false;

    public string $aiPrompt = '';

    public function mount(?int $scriptId = null): void
    {
        Gate::authorize('is-system-admin');
        $this->scriptId = $scriptId;
        if ($script = Script::find($scriptId)) {
            $this->fill($script->only(['name', 'platform', 'timeout', 'detection']));
            $this->description = (string) $script->description;
            $this->remediation = (string) $script->remediation;
            $this->manualRemediation = (bool) $script->manual_remediation;
        }
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'platform' => ['required', Rule::in(array_keys(Script::PLATFORMS))],
            'timeout' => 'required|integer|min:5|max:'.Script::MAX_TIMEOUT,
            'detection' => 'required|string',
            'remediation' => 'nullable|string',
            'manualRemediation' => 'boolean',
        ];
    }

    public function save(): void
    {
        Gate::authorize('is-system-admin');
        $this->validate();
        // At most MAX_CODE_BYTES each (bytes as stored, not characters); a detection says compliant
        // (exit 0) or not (exit 1); both scripts have to parse.
        $failed = false;
        foreach (['detection' => $this->detection, 'remediation' => $this->remediation] as $field => $code) {
            if (trim($code) === '') {
                continue;
            }
            if (strlen($code) > Script::MAX_CODE_BYTES) {
                $this->addError($field, __('The script has :size kB, at most :max kB.', ['size' => number_format(strlen($code) / 1024, 1), 'max' => Script::MAX_CODE_BYTES / 1024]));
                $failed = true;

                continue;
            }
            $errors = PowerShellCheck::errors($code);
            if ($errors !== []) {
                $this->addError($field, __('Not valid PowerShell: :errors', ['errors' => implode('; ', array_slice($errors, 0, 3))]));
                $failed = true;
            }
        }
        $missing = $failed ? [] : array_values(array_filter([0, 1], fn ($exit) => ! PowerShellCheck::exits($this->detection, $exit)));
        if ($missing !== []) {
            $this->addError('detection', __('The detection has to end with exit 0 (compliant) and exit 1 (needs remediation), :missing is missing.', ['missing' => implode(', ', array_map(fn ($exit) => "exit $exit", $missing))]));
            $failed = true;
        }
        if ($failed) {
            return;
        }

        $script = Script::findOrNew($this->scriptId);
        $script->fill([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'platform' => $this->platform,
            'timeout' => $this->timeout,
            // Byte for byte as entered: the fingerprint and the device check these bytes.
            'detection' => $this->detection,
            'remediation' => $this->remediation,
            'manual_remediation' => $this->manualRemediation,
        ]);
        $script->exists ? $script->updated_by = auth()->id() : $script->created_by = auth()->id();
        $script->save();

        $this->dispatch('scriptSaved');
        $this->dispatch('closeModal');
    }

    /** Writes the script from the description into the fields; it is checked when saved, as typed ones. */
    public function generate(): void
    {
        Gate::authorize('is-system-admin');
        $this->validate(['aiPrompt' => 'required|string|max:4000']);
        try {
            $script = ScriptGenerator::generate($this->aiPrompt, [
                'name' => $this->name, 'description' => $this->description, 'platform' => $this->platform, 'timeout' => $this->timeout,
                'detection' => $this->detection, 'remediation' => $this->remediation, 'manual_remediation' => $this->manualRemediation,
            ]);
        } catch (RuntimeException $e) {
            $this->addError('aiPrompt', $e->getMessage());

            return;
        }
        $this->fill([
            'name' => $script['name'] ?: $this->name,
            'description' => $script['description'],
            'platform' => $script['platform'],
            'timeout' => $script['timeout'],
            'detection' => $script['detection'],
            'remediation' => $script['remediation'],
            'manualRemediation' => $script['manual_remediation'],
        ]);
        $this->resetValidation();
        $this->aiMode = false;
    }

    public function render()
    {
        return view('livewire.script.form', [
            'script' => Script::find($this->scriptId),
            'aiAvailable' => ScriptGenerator::available(),
        ]);
    }
}
