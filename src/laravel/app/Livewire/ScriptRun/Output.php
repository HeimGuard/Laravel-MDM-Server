<?php

namespace App\Livewire\ScriptRun;

use App\Models\Script;
use App\Models\ScriptRun;
use App\Support\ScriptGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use RuntimeException;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/** The output (and error) of one script run, in a modal from the runs tables; a failed one can be fixed with AI. */
#[AllowInModal]
class Output extends Component
{
    /** The end of the output that goes to the AI (the error is at the end), in characters. */
    private const OUTPUT_FOR_AI = 6000;

    public int $runId;

    public ?string $fixError = null;

    /**
     * The AI rewrites the script of a failed run according to its error and output. The result is a new script that
     * only runs by hand (the scripts run as SYSTEM / root on the devices, an AI written change is checked by a system
     * admin first and never goes out on a schedule or in place of the script that exists); the system admin lands on
     * it. The AI gets the script, the error and the end of the output of the run: what the script printed may name
     * the device or its users, so it is for system admins, as the scripts are.
     */
    public function aiFix()
    {
        Gate::authorize('is-system-admin');
        $this->fixError = null;
        $run = ScriptRun::query()->with(['script', 'device'])->findOrFail($this->runId);
        $script = $run->script;
        if (! $script || ! in_array($run->status, ['failed', 'error'], true)) {
            return;
        }

        $codes = collect(['detection' => $run->detection_exit, 'remediation' => $run->remediation_exit, 'detection after the remediation' => $run->post_detection_exit])
            ->filter(fn ($code) => $code !== null)->map(fn ($code, $step) => "$step $code")->implode(', ');
        $output = trim((string) $run->output);
        $request = "Fix this script: its run on a device failed.\nRun: status {$run->status}, version {$run->version}"
            .($run->detectsOnly ? ', only the detection ran' : '')
            .($run->device?->platform ? ", platform {$run->device->platform}" : '')
            .(filled($run->device?->os) ? ", operating system ".mb_strimwidth(preg_replace('/\s+/', ' ', (string) $run->device->os), 0, 200, '…') : '')
            .($codes !== '' ? "\nExit codes: $codes" : '')
            .($run->error ? "\nError: {$run->error}" : '')
            .($output !== '' ? "\nOutput of the run".(mb_strlen($output) > self::OUTPUT_FOR_AI ? ' (the end of it)' : '').":\n".mb_substr($output, -self::OUTPUT_FOR_AI) : "\nThe run printed nothing.")
            ."\n\nChange the script so that this does not happen again: fix what the error and the output show, keep what works and do not make it do more than it did. Keep the exit codes: the detection ends with exit 0 and exit 1.";

        try {
            $generated = ScriptGenerator::checked(ScriptGenerator::generate($request, [
                'name' => $script->name, 'description' => $script->description, 'platform' => $script->platform, 'timeout' => $script->timeout,
                'detection' => $script->detection, 'remediation' => $script->remediation, 'manual_remediation' => $script->manual_remediation,
            ]));
        } catch (RuntimeException $e) {
            $this->fixError = $e->getMessage();

            return;
        }

        // The script itself is fixed (a new version, the runs keep theirs), no copy: its name, description and schedule stay.
        // The AI wrote it, so it runs only by hand until a system admin has read it.
        $script->fill(['manual_remediation' => true] + Arr::except($generated, ['name', 'description']))->save();

        return $this->redirectRoute('script.show', $script, navigate: true);
    }

    public function render()
    {
        return view('livewire.script-run.output', ['run' => ScriptRun::query()->with(['script', 'device'])->findOrFail($this->runId)]);
    }
}
