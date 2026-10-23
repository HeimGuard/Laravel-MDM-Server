<?php

namespace App\Livewire\Concerns;

use App\Models\Script;
use App\Models\SecurityFinding;
use App\Support\PowerShellCheck;
use App\Support\ScriptGenerator;
use App\Support\SecurityRules;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Collection;
use RuntimeException;

/** "Remediate with AI" of a finding: the AI writes a remediation script, the admin reviews it and runs it. */
trait RemediatesFindings
{
    /** At most this many other findings of the device go into the request. */
    private const RELATED_LIMIT = 100;

    public ?string $remediateError = null;

    public ?string $remediateDone = null;

    /**
     * Writes a script for the finding; any signed-in user may ask for it. A system admin lands on it to
     * review it; for the others it waits in the scripts for one. The script fixes the finding together with the
     * other open findings of the same device (updating php fixes all its packages, not one of them). Only the
     * rules, the messages and the platform leave the server. The script is saved as manual (it never runs by itself): it is AI written, so a system
     * admin checks it and starts it per device (the scripts are theirs, they run as SYSTEM / root).
     */
    public function remediateWithAi(int $id)
    {
        $this->remediateError = $this->remediateDone = null;
        $finding = $this->findingForRemediation($id);
        $rule = $finding->rule;
        $platform = $finding->device?->platform;
        $request = "Remediate this security finding.\nRule: {$rule?->name}\nSeverity: {$finding->severity}\nFinding: {$finding->message}"
            .($rule?->definition['remediation'] ?? null ? "\nHint: {$rule->definition['remediation']}" : '')
            .(in_array($platform, ['windows', 'linux'], true) ? "\nPlatform: {$platform}" : '');
        $related = $this->relatedFindings($finding);
        if ($related->isNotEmpty()) {
            $request .= "\n\nOther open findings on the same device:\n".$related->map(fn ($other) => '- ['.$other->severity.'] '.mb_strimwidth(preg_replace('/\s+/', ' ', ($other->rule?->name ?? '').': '.$other->message), 0, 250, '…'))->implode("\n")
                ."\n\nOne script has to fix the finding above together with every one of these that the same action resolves: update the whole product or package family (all php packages, not one module), not a single package, and refresh the package index first. Leave out the findings it cannot fix.";
        }
        $request .= "\nThe detection exits 0 when the findings it covers are fixed and 1 when any of them is still there.";

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

        if (Gate::allows('is-system-admin')) {
            return $this->redirectRoute('script.show', $script, navigate: true);
        }
        $this->remediateDone = __('The remediation script ":name" was written, a system admin reviews and runs it.', ['name' => $script->name]);
    }

    /** The other open findings of the same device (not events, the most severe first), at most RELATED_LIMIT. */
    private function relatedFindings(SecurityFinding $finding): Collection
    {
        if (! $finding->device_id) {
            return collect();
        }

        return SecurityFinding::query()->with('rule')->active()->where('device_id', $finding->device_id)->whereKeyNot($finding->id)
            ->whereHas('rule', fn ($rule) => $rule->whereNotIn('source', array_keys(array_filter(SecurityRules::SOURCES, fn ($source) => $source['event'] ?? false))))
            ->bySeverity()->limit(self::RELATED_LIMIT)->get();
    }

    abstract protected function findingForRemediation(int $id): SecurityFinding;
}
