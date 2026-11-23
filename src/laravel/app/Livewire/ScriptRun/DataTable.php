<?php

namespace App\Livewire\ScriptRun;

use App\Models\ScriptRun;
use App\Support\LiveUpdates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use SteelAnts\DataTable\Livewire\DataTableComponent;
use SteelAnts\DataTable\Traits\UseDatabaseEloquent;

/** Runs of one script (its detail page) or of one device (its Scripts tab), newest first. */
class DataTable extends DataTableComponent
{
    use UseDatabaseEloquent;

    /** Columns hidden on narrow screens (dt-hide-*, resources/sass/app.scss). */
    public string $tableClass = 'table align-middle dt-hide-md-2 dt-hide-md-4';

    public ?int $scriptId = null;

    public ?int $deviceId = null;

    public bool $filterable = true;

    public array $sortableColumns = ['script_id', 'status', 'version', 'issued_at', 'finished_at'];

    public string $sortBy = 'issued_at';

    public string $sortDirection = 'desc';

    public int $itemsPerPage = 25;

    public function mount()
    {
        // The script pages are for system admins; a device's runs are shown to whoever sees it.
        if ($this->deviceId === null) {
            Gate::authorize('is-system-admin');
        }
        // In a device's tab (next to the device list) also without Finished on phones and Version below xl.
        if ($this->deviceId !== null) {
            $this->tableClass .= ' dt-hide-sm-5 dt-hide-xl-2';
        }
        parent::mount();
    }

    /** Refreshed with the script (saved) or the device (live updates, resources/js/live.js). */
    protected function getListeners(): array
    {
        return $this->deviceId === null
            ? ['scriptSaved' => '$refresh']
            : ["device-changed.{$this->deviceId}" => '$refresh'];
    }

    public function query(): Builder
    {
        return $this->deviceId === null
            ? ScriptRun::query()->with(['device', 'script'])->where('script_id', $this->scriptId)
            : ScriptRun::query()->with(['script', 'device'])->where('device_id', $this->deviceId);
    }

    public function headers(): array
    {
        // On a device: which script ran.
        if ($this->deviceId !== null) {
            return [
                'script_id' => __('Script'),
                'version' => __('Version'),
                'status' => __('Status'),
                'issued_at' => __('Issued'),
                'finished_at' => __('Finished'),
                'output' => __('Output'),
            ];
        }

        return [
            'device_id' => __('Device'),
            'version' => __('Version'),
            'status' => __('Status'),
            'issued_at' => __('Issued'),
            'finished_at' => __('Finished'),
            'output' => __('Output'),
        ];
    }

    public function headerFilters(): array
    {
        return [
            'status' => ['type' => 'select', 'values' => collect(ScriptRun::STATUSES)->mapWithKeys(fn ($status) => [$status => ScriptRun::statusLabel($status)])->all()],
        ];
    }

    public function renderColumnDeviceId($value, $row): string
    {
        return '<a href="'.e(route('devices', ['selectedDeviceId' => $value, 'tab' => 'scripts'])).'">'.e($row->device?->displayName ?? "#$value").'</a>';
    }

    public function renderColumnScriptId($value, $row): string
    {
        return '<a href="'.e(route('script.show', $value)).'">'.e($row->script?->name ?? "#$value").'</a>';
    }

    public function renderColumnVersion($value, $row): string
    {
        return '<span title="'.e($row->fingerprint).'">v'.e($value).' · <code>'.e(substr($row->fingerprint, 0, 8)).'</code></span>';
    }

    public function renderColumnStatus($value, $row): string
    {
        $html = '<span class="badge border border-'.$row->statusColor.'-subtle bg-'.$row->statusColor.'-subtle text-'.$row->statusColor.'-emphasis">'.e(ScriptRun::statusLabel($value)).'</span>';
        if ($row->error) {
            $html .= '<div class="small text-danger">'.e($row->error).'</div>';
        }

        return $html;
    }

    public function renderColumnIssuedAt($value): string
    {
        return '<span title="'.e($value).'">'.e($value?->diffForHumans()).'</span>';
    }

    public function renderColumnFinishedAt($value): string
    {
        return $value ? '<span title="'.e($value).'">'.e($value->diffForHumans()).'</span>' : '';
    }

    /**
     * Remediate on the device a run that only detected found needs it (manual remediation): the
     * whole script runs there, detection, remediation and the detection again.
     */
    public function remediate(int $runId): void
    {
        Gate::authorize('is-system-admin');
        $run = ScriptRun::query()->with(['script', 'device'])->whereKey($runId)
            ->when($this->deviceId !== null, fn ($query) => $query->where('device_id', $this->deviceId))
            ->when($this->scriptId !== null, fn ($query) => $query->where('script_id', $this->scriptId))
            ->first();
        if ($run === null || ! $this->canRemediate($run)) {
            return;
        }
        if (($reason = $run->script->unavailableReason($run->device)) !== null) {
            // The table shows no validation errors: a snackbar says why.
            $this->dispatch('snackbar', ['message' => e($reason), 'type' => 'danger', 'icon' => 'fas fa-exclamation-triangle']);

            return;
        }
        $run->script->runOn([$run->device_id], auth()->user(), false, true);
        LiveUpdates::device($run->device_id, 'script');
    }

    /** The latest run of the script on the device needs a remediation and the script has one. */
    private function canRemediate(ScriptRun $run): bool
    {
        return $run->status === 'noncompliant' && $run->script?->remediation !== null && $run->device !== null
            && $run->id === ScriptRun::query()->where('script_id', $run->script_id)->where('device_id', $run->device_id)->max('id');
    }

    /** A button opening the output in a modal (script-run.output); Remediate where it needs it. */
    public function renderColumnOutput($value, $row): string
    {
        $remediate = $this->canRemediate($row) && Gate::allows('is-system-admin')
            ? '<button class="btn btn-sm btn-warning text-nowrap" type="button" wire:click="remediate('.(int) $row->id.')" wire:loading.attr="disabled"'
                .' wire:confirm="'.e(__('Run the remediation of :script on :device now?', ['script' => $row->script->name, 'device' => $row->device->displayName])).'"'
                .' title="'.e(__('Remediate')).'" aria-label="'.e(__('Remediate')).'">'
                .'<i class="fas fa-magic me-sm-1"></i><span class="d-none d-sm-inline">'.e(__('Remediate')).'</span></button>'
            : '';
        if (! $value && ! $row->error) {
            return $remediate;
        }
        $modal = json_encode([
            'livewireComponents' => 'script-run.output',
            'title' => __('Output of :script on :device', ['script' => $row->script?->name ?? '#'.$row->script_id, 'device' => $row->device?->displayName ?? '#'.$row->device_id]),
            'parameters' => ['runId' => $row->id],
        ]);

        return '<button class="btn btn-sm btn-light text-nowrap" type="button" x-on:click="Livewire.dispatch(\'openModal\', '.e($modal).')"'
            .' title="'.e(__('Output')).'" aria-label="'.e(__('Output')).'">'
            .'<i class="fas fa-terminal me-sm-1"></i><span class="d-none d-sm-inline">'.e(__('Output')).'</span></button>'
            .($remediate ? ' '.$remediate : '');
    }
}
