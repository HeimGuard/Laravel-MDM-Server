<?php

namespace App\Livewire\Script;

use App\Models\Script;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use SteelAnts\DataTable\Livewire\DataTableComponent;
use SteelAnts\DataTable\Traits\UseDatabaseEloquent;

class DataTable extends DataTableComponent
{
    use UseDatabaseEloquent;

    /** Columns hidden on narrow screens (dt-hide-*, resources/sass/app.scss). */
    public string $tableClass = 'table align-middle dt-hide-md-2 dt-hide-md-3 dt-hide-lg-4';

    public $listeners = ['scriptSaved' => '$refresh'];

    public bool $searchable = true;

    public array $searchableColumns = ['name', 'description'];

    public array $sortableColumns = ['name', 'platform', 'version', 'updated_at'];

    /** Newest on top. */
    public string $sortBy = 'updated_at';

    public string $sortDirection = 'desc';

    public function mount()
    {
        Gate::authorize('is-system-admin');
        parent::mount();
    }

    /**
     * Search: every word has to be in the name or the description, in any order and without regard to case
     * (the package searches the whole text as one phrase with LIKE, which is case-sensitive on PostgreSQL). `*` stands for any text.
     */
    protected function applyFilters($query)
    {
        $words = preg_split('/\s+/', trim((string) $this->searchValue), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $word) {
            $query->where(function ($q) use ($word) {
                foreach ($this->searchableColumns as $column) {
                    $q->orWhereLike($column, '%'.str_replace('*', '%', $word).'%');
                }
            });
        }

        return $query;
    }

    public function query(): Builder
    {
        return Script::query();
    }

    public function headers(): array
    {
        return [
            'name' => __('Name'),
            'platform' => __('Platform'),
            'version' => __('Version'),
            'fingerprint' => __('Fingerprint'),
            'updated_at' => __('Changed'),
        ];
    }

    public function renderColumnName($value, $row): string
    {
        $html = '<a class="fw-semibold" href="'.e(route('script.show', $row->id)).'">'.e($value).'</a>';
        if ($row->description) {
            // Wraps (at most two lines) instead of widening the table.
            $html .= '<div class="small text-muted text-wrap mdm-clamp-2" style="max-width: 40rem">'.e($row->description).'</div>';
        }

        return $html;
    }

    public function renderColumnPlatform($value): string
    {
        return e(__(Script::PLATFORMS[$value] ?? $value));
    }

    public function renderColumnVersion($value): string
    {
        return 'v'.e($value);
    }

    public function renderColumnFingerprint($value): string
    {
        return '<code title="'.e($value).'">'.e(substr((string) $value, 0, 12)).'…</code>';
    }

    public function renderColumnUpdatedAt($value): string
    {
        return $value ? '<span title="'.e($value).'">'.e($value->diffForHumans()).'</span>' : '';
    }

    public function actions($item): array
    {
        return [
            [
                'type' => 'url',
                'url' => route('script.show', $item['id']),
                'text' => __('Detail'),
                'iconClass' => 'fas fa-eye',
            ],
            [
                'type' => 'livewire',
                'action' => 'run',
                'parameters' => $item['id'],
                'text' => __('Run'),
                'iconClass' => 'fas fa-play',
            ],
            [
                'type' => 'livewire',
                'action' => 'edit',
                'parameters' => $item['id'],
                'text' => __('Edit'),
                'iconClass' => 'fas fa-pen',
            ],
            [
                'type' => 'livewire',
                'action' => 'remove',
                'parameters' => $item['id'],
                'text' => __('Remove'),
                'actionClass' => 'text-danger',
                'iconClass' => 'fas fa-trash',
                'confirm' => __('Remove the script and its results?'),
            ],
        ];
    }

    public function run($id)
    {
        $this->dispatch('openModal', 'script.run', __('Run :name', ['name' => Script::find($id)?->name]), ['scriptId' => (int) $id], 'lg');
    }

    public function edit($id)
    {
        $this->dispatch('openModal', 'script.form', __('Edit script'), ['scriptId' => (int) $id], 'xl');
    }

    public function remove($id)
    {
        Gate::authorize('is-system-admin');
        Script::find($id)?->delete();
        alert()->success(__('Script removed'))->now();
    }
}
