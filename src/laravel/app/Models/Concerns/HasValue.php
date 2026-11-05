<?php

namespace App\Models\Concerns;

use App\Models\ValueDefinition;
use App\Models\ValueSample;
use App\Support\ValueRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Measured values of a model (cpu, memory, ping, a disk's usage): what is measured (ValueDefinition: key and name) and
 * its history (ValueSample). Nothing to add to the database for a new kind of value: a new key is a new definition.
 *
 *   $device->recordValues(['cpu' => 41.5, 'memory.used' => 3221225472]);
 *   $device->recordValues([['key' => 'disk.used_pct', 'name' => 'C:', 'value' => 61, 'unit' => '%']]);
 *   $device->latestValue('disk.used_pct', 'C:');
 */
trait HasValue
{
    /** A polymorphic relation has no foreign key to cascade: the values go with the model (their samples with the definitions). */
    public static function bootHasValue(): void
    {
        static::deleted(fn ($model) => $model->valueDefinitions()->delete());
    }

    public function valueDefinitions(): MorphMany
    {
        return $this->morphMany(ValueDefinition::class, 'valuable');
    }

    /** @see ValueRecorder::record() */
    public function recordValues(array $values, ?\DateTimeInterface $at = null): int
    {
        return ValueRecorder::record($this->getMorphClass(), (int) $this->getKey(), $values, $at);
    }

    public function valueDefinition(string $key, string $name = ''): ?ValueDefinition
    {
        return $this->valueDefinitions()->where('key', $key)->where('name', $name)->first();
    }

    /** The newest value; null when it was never measured. */
    public function latestValue(string $key, string $name = ''): ?float
    {
        return $this->valueDefinition($key, $name)?->last_value;
    }

    /** The samples of a value since a time (all of them without one), oldest first; none when it was never measured. */
    public function valueHistory(string $key, string $name = '', ?\DateTimeInterface $since = null): Builder
    {
        $definition = $this->valueDefinition($key, $name);

        return ValueSample::query()->where('definition_id', $definition?->id ?? 0)
            ->when($since !== null, fn (Builder $query) => $query->where('recorded_at', '>=', $since))->orderBy('recorded_at');
    }
}
