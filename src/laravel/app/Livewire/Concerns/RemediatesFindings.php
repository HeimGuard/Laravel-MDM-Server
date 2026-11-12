<?php

namespace App\Livewire\Concerns;

use App\Models\Script;
use App\Models\SecurityFinding;
use App\Services\FindingExplanationService;
use App\Services\ScriptGeneratorService;
use App\Support\SecurityRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * "Remediate with AI" of a finding: the AI writes a remediation script (one per rule, asking again updates it), the admin reviews it and runs it.
 * "Explain with AI": the AI explains the finding under it, in the language of the user.
 */
trait RemediatesFindings
{
    /** At most this many other findings of the device go into the request. */
    private const RELATED_LIMIT = 100;

    public ?string $remediateError = null;

    public ?string $remediateDone = null;

    /** @var array<int, string> the explanations shown, by the id of the finding (not stored: asking again is a new answer) */
    public array $explanations = [];

    /** Explains the finding under it; any signed-in user may ask. Asking again for a shown one hides it. */
    public function explainWithAi(int $id): void
    {
        $this->remediateError = $this->remediateDone = null;
        if (isset($this->explanations[$id])) {
            unset($this->explanations[$id]);

            return;
        }
        // Found before the try: a finding that is not there (ModelNotFoundException is a RuntimeException too) is a 404, not an error of the AI.
        $finding = $this->findingForRemediation($id);
        try {
            $this->explanations[$id] = FindingExplanationService::explain($finding);
        } catch (RuntimeException $e) {
            $this->remediateError = $e->getMessage();
        }
    }

    /**
     * Writes a script for the finding; any signed-in user may ask for it. A system admin lands on it to
     * review it; for the others it waits in the scripts for one. The script fixes the finding together with the
     * other open findings of the same device (updating php fixes all its packages, not one of them). Only the
     * rules (with the links of the detection), the messages and the facts of the device that name no person, address or
     * secret (FindingExplanationService::describe: system, kernel, CPU architecture, settings) leave the server. The script is saved as manual (it never runs by itself): it is AI written, so a system
     * admin checks it and starts it per device (the scripts are theirs, they run as SYSTEM / root).
     */
    public function remediateWithAi(int $id)
    {
        $this->remediateError = $this->remediateDone = null;
        $finding = $this->findingForRemediation($id);
        $rule = $finding->rule;
        $platform = $finding->device?->platform;
        $request = "Remediate this security finding.\n".FindingExplanationService::describe($finding);
        $related = $this->relatedFindings($finding);
        if ($related->isNotEmpty()) {
            $request .= "\n\nOther open findings on the same device:\n".$related->map(fn ($other) => '- ['.$other->severity.'] '.mb_strimwidth(preg_replace('/\s+/', ' ', ($other->rule?->name ?? '').': '.$other->message), 0, 250, '…'))->implode("\n")
                ."\n\nOne script has to fix the finding above together with every one of these that the same action resolves: update the whole product or package family (all php packages, not one module), not a single package, and refresh the package index first. Leave out the findings it cannot fix.";
        }
        $request .= "\nThe detection exits 0 only when the findings it covers are gone (the software is fixed or removed) and 1 as long as any of them is still there: an update that cannot be installed, or none to be had in the channel, is not a fix, so then it exits 1 and says why. When no fix exists, the remediation applies the mitigation of the advisory or the hint, or changes nothing and says what a person has to decide.";

        // The script written for this rule before (for this platform) is updated, not copied: one script per rule.
        $existing = $rule ? Script::query()->where('security_rule_id', $rule->id)
            ->when(in_array($platform, ['windows', 'linux'], true), fn ($query) => $query->whereIn('platform', [$platform, 'all']))->latest('id')->first() : null;
        if ($existing) {
            $request .= "\n\nA script for this rule exists (the current script): update that one, keep what works and keep its name and the language of its texts; do not write a new one.";
        }

        try {
            $generated = ScriptGeneratorService::checked(ScriptGeneratorService::generate($request, $existing ? [
                'name' => $existing->name, 'description' => $existing->description, 'platform' => $existing->platform, 'timeout' => $existing->timeout,
                'detection' => $existing->detection, 'remediation' => $existing->remediation, 'manual_remediation' => $existing->manual_remediation,
            ] : []));
        } catch (RuntimeException $e) {
            $this->remediateError = $e->getMessage();

            return;
        }

        if ($existing) {
            // A change of what runs is a new version of the script; the runs keep theirs.
            $script = $existing;
            $script->fill(['manual_remediation' => true] + Arr::except($generated, ['name', 'description']))->save();
        } else {
            $script = new Script(array_merge($generated, ['manual_remediation' => true, 'name' => $generated['name'] ?: ($rule?->name ?? 'Remediation')]));
            $script->created_by = auth()->id();
            $script->security_rule_id = $rule?->id;
            $script->save();
        }

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
