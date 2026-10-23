<?php

namespace App\Livewire\Concerns;

use App\Models\Script;
use App\Models\SecurityFinding;
use App\Support\PowerShellCheck;
use App\Support\ScriptGenerator;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/** "Remediate with AI" of a finding: the AI writes a remediation script, the admin reviews it and runs it. */
trait RemediatesFindings
{
    public ?string $remediateError = null;

    /**
     * Writes a script for the finding and opens it. Only the rule, the message and the platform leave the
     * server. The script is saved as manual (it never runs by itself): it is AI written, so a system
     * admin checks it and starts it per device.
     */
    public function remediateWithAi(int $id)
    {
        Gate::authorize('is-system-admin');
        $this->remediateError = null;
        $finding = $this->findingForRemediation($id);
        $rule = $finding->rule;
        $platform = $finding->device?->platform;
        $request = "Remediate this security finding.\nRule: {$rule?->name}\nSeverity: {$finding->severity}\nFinding: {$finding->message}"
            .($rule?->definition['remediation'] ?? null ? "\nHint: {$rule->definition['remediation']}" : '')
            .(in_array($platform, ['windows', 'linux'], true) ? "\nPlatform: {$platform}" : '')
            ."\nThe detection exits 0 when the finding is fixed and 1 when it is still there.";

        try {
            $generated = ScriptGenerator::generate($request);
            foreach (['detection', 'remediation'] as $field) {
                $errors = trim($generated[$field]) === '' ? [] : PowerShellCheck::errors($generated[$field]);
                if ($errors !== [] || strlen($generated[$field]) > Script::MAX_CODE_BYTES) {
                    throw new RuntimeException(__('The AI did not return a valid script, try again.'));
                }
            }
            if (! PowerShellCheck::exits($generated['detection'], 0) || ! PowerShellCheck::exits($generated['detection'], 1)) {
                throw new RuntimeException(__('The AI did not return a valid script, try again.'));
            }
        } catch (RuntimeException $e) {
            $this->remediateError = $e->getMessage();

            return;
        }

        $script = new Script(array_merge($generated, ['manual_remediation' => true, 'name' => $generated['name'] ?: ($rule?->name ?? 'Remediation')]));
        $script->created_by = auth()->id();
        $script->save();

        return $this->redirectRoute('script.show', $script, navigate: true);
    }

    abstract protected function findingForRemediation(int $id): SecurityFinding;
}
