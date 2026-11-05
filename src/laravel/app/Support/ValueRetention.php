<?php

namespace App\Support;

use App\Models\ValueDefinition;
use App\Models\ValueSample;
use Illuminate\Database\Eloquent\Builder;

/** Deletes the history of measured values that is older than config values.retention_days says (by key). */
class ValueRetention
{
    /** @return int samples deleted */
    public static function prune(): int
    {
        $configured = (array) config('values.retention_days', []);
        $default = (int) config('values.default_retention_days', 30);
        $deleted = 0;
        foreach ($configured as $key => $days) {
            $deleted += self::delete(fn (Builder $definitions) => $definitions->where('key', $key), (int) $days);
        }
        $deleted += self::delete(fn (Builder $definitions) => $configured === [] ? $definitions : $definitions->whereNotIn('key', array_keys($configured)), $default);

        // What is not measured any more (a disk that was removed) goes when its last sample would be pruned.
        $longest = max([$default, ...array_map('intval', $configured)]);
        ValueDefinition::query()->where('last_at', '<', now()->subDays($longest))->delete();

        return $deleted;
    }

    private static function delete(\Closure $definitions, int $days): int
    {
        return ValueSample::query()
            ->whereIn('definition_id', $definitions(ValueDefinition::query()->select('id')))
            ->where('recorded_at', '<', now()->subDays($days))
            ->delete();
    }
}
