<?php

namespace App\Models\Concerns;

use App\Models\InventoryDefinition;
use App\Models\InventorySample;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The inventory of a model (what it has: services, containers, processes, ports, users, ...): a definition per thing
 * (`key` the kind, `name` which one) with its current state and a history of the changes. The same shape as HasValue.
 *
 *   $device->recordInventory('service', 'nginx', ['DisplayName' => 'nginx', 'State' => 'running']);
 *   $device->latestInventory('service', 'nginx');           // the data, null when it is gone or unknown
 *   $device->inventoryHistory('service', 'nginx')->get();    // the samples
 */
trait HasInventory
{
    /** A polymorphic relation has no foreign key to cascade: the inventory goes with the model (its samples with the definitions). */
    public static function bootHasInventory(): void
    {
        static::deleted(fn ($model) => $model->inventoryDefinitions()->delete());
    }

    public function inventoryDefinitions(): MorphMany
    {
        return $this->morphMany(InventoryDefinition::class, 'inventorable');
    }

    /** @see InventoryService::record() */
    public function recordInventory(string $key, string $name, ?array $data, ?string $hash = null, ?\DateTimeInterface $at = null): bool
    {
        return InventoryService::record($this->getMorphClass(), (int) $this->getKey(), $key, $name, $data, $hash, $at);
    }

    public function inventoryDefinition(string $key, string $name = ''): ?InventoryDefinition
    {
        return $this->inventoryDefinitions()->where('key', $key)->where('name', $name)->first();
    }

    /** The current data of a thing; null when it is gone or was never there. */
    public function latestInventory(string $key, string $name = ''): ?array
    {
        return $this->inventoryDefinition($key, $name)?->last_data;
    }

    /** The samples (the states after each change) of a thing since a time, oldest first. */
    public function inventoryHistory(string $key, string $name = '', ?\DateTimeInterface $since = null): Builder
    {
        $definition = $this->inventoryDefinition($key, $name);

        return InventorySample::query()->where('definition_id', $definition?->id ?? 0)
            ->when($since !== null, fn (Builder $query) => $query->where('recorded_at', '>=', $since))->orderBy('recorded_at')->orderBy('id');
    }
}
