<?php

namespace App\Livewire\Script;

use App\Models\Device;
use App\Models\Script;
use App\Models\ScriptRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

/** Detail page of a script: its code, fingerprint and the runs. */
class Detail extends Component
{
    /** The statuses of a run that failed. */
    private const FAILED = ['failed', 'error', 'rejected'];

    public Script $script;

    public function mount(Script $script): void
    {
        Gate::authorize('is-system-admin');
        $this->script = $script;
    }

    public function run(): void
    {
        $this->dispatch('openModal', 'script.run', __('Run :name', ['name' => $this->script->name]), ['scriptId' => $this->script->id], 'lg');
    }

    public function schedule(): void
    {
        $this->dispatch('openModal', 'script.schedule', __('Schedule :name', ['name' => $this->script->name]), ['scriptId' => $this->script->id], 'lg');
    }

    public function edit(): void
    {
        $this->dispatch('openModal', 'script.form', __('Edit script'), ['scriptId' => $this->script->id], 'xl');
    }

    #[On('scriptSaved')]
    public function refresh(): void
    {
        $this->script->refresh();
    }

    /**
     * Runs the whole script (the detection and the remediation, also of a script with manual remediation) again on the
     * devices where it failed. A device has many runs: only its latest counts, one that failed once and went well
     * since is not one of them. A device that cannot run it now (another platform, an agent that does not sign, scripts
     * off) is left out, as in Run.
     */
    public function rerunFailed(): void
    {
        Gate::authorize('is-system-admin');
        $runs = $this->script->runOn($this->failedDevices()->pluck('id')->all(), auth()->user(), remediate: true);

        $this->dispatch('scriptSaved');
        alert()->success(trans_choice('Script queued on :count device|Script queued on :count devices', $runs->count(), ['count' => $runs->count()]))->now();
    }

    /** The latest run of this script on each device. */
    private function latestRuns(): Collection
    {
        return ScriptRun::query()->where('script_id', $this->script->id)
            ->whereIn('id', ScriptRun::query()->selectRaw('max(id)')->where('script_id', $this->script->id)->groupBy('device_id'))
            ->get(['id', 'device_id', 'status', 'version', 'finished_at']);
    }

    /** The devices whose latest run failed and that can run the script now, by name. */
    private function failedDevices(): Collection
    {
        $ids = $this->latestRuns()->whereIn('status', self::FAILED)->pluck('device_id');

        return Device::query()->whereIn('id', $ids)->get()
            ->filter(fn (Device $device) => $this->script->unavailableReason($device) === null)
            ->sortBy(fn (Device $device) => mb_strtolower($device->displayName))->values();
    }

    /** Live update (resources/js/live.js): some device changed. */
    #[On('devices-changed')]
    public function devicesChanged(): void {}

    public function render()
    {
        // The state of each device: its latest run of this script.
        $latest = $this->latestRuns();
        $groups = [
            'compliant' => ['compliant'],
            'remediated' => ['remediated'],
            'noncompliant' => ['noncompliant'],
            'failed' => self::FAILED,
            'waiting' => ['pending', 'sent'],
        ];

        return view('livewire.script.detail', [
            'stats' => collect($groups)->map(fn ($statuses) => $latest->whereIn('status', $statuses)->count()),
            'devices' => $latest->count(),
            'outdated' => $latest->where('version', '<', $this->script->version)->count(),
            'lastRun' => $this->script->runs()->max('issued_at'),
            'failedDevices' => $this->failedDevices()->map->displayName->all(),
        ])->title($this->script->name);
    }
}
