<?php

namespace App\Support;

use App\Models\ValueDefinition;
use App\Models\ValueSample;
use Illuminate\Support\Carbon;

/**
 * Stores measured values of any model (see App\Models\Concerns\HasValue): finds or creates the definition of each value
 * (model, key, name), adds the samples and keeps the newest one on the definition.
 */
class ValueRecorder
{
    /**
     * @param  string  $type  the morph class of the model ($model->getMorphClass())
     * @param  array  $values  ['cpu' => 41.5] (what there is one of) or [['key' => 'disk.used_pct', 'name' => 'C:', 'value' => 61, 'unit' => '%']]
     * @return int samples stored
     */
    public static function record(string $type, int $id, array $values, ?\DateTimeInterface $at = null): int
    {
        return self::recordMany($type, $id, [[$at ?? now(), $values]]);
    }

    /**
     * Several moments at once (a backfill): [[time, values], ...]. A definition's newest value is only replaced by a
     * newer sample.
     *
     * @param  array<int, array{0: \DateTimeInterface, 1: array}>  $moments
     */
    public static function recordMany(string $type, int $id, array $moments): int
    {
        $timed = [];
        $all = [];
        foreach ($moments as [$at, $values]) {
            $entries = self::normalize($values);
            if ($entries !== []) {
                $timed[] = [Carbon::instance($at), $entries];
                array_push($all, ...$entries);
            }
        }
        if ($all === []) {
            return 0;
        }
        $definitions = self::definitions($type, $id, $all);

        $rows = [];
        $newest = [];
        foreach ($timed as [$at, $entries]) {
            foreach ($entries as $entry) {
                $definition = $definitions[$entry['key']."\0".$entry['name']];
                $rows[] = ['definition_id' => $definition->id, 'value' => $entry['value'], 'recorded_at' => $at->toDateTimeString()];
                if (! isset($newest[$definition->id]) || $at->gte($newest[$definition->id][1])) {
                    $newest[$definition->id] = [$entry['value'], $at];
                }
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            ValueSample::query()->insert($chunk);
        }
        foreach ($newest as $definitionId => [$value, $at]) {
            // One UPDATE by primary key, only when nothing newer is there.
            ValueDefinition::query()->whereKey($definitionId)->where(fn ($query) => $query->whereNull('last_at')->orWhere('last_at', '<=', $at))
                ->update(['last_value' => $value, 'last_at' => $at]);
        }

        return count($rows);
    }

    /** @return array<int, array{key: string, name: string, value: float, unit: ?string}> */
    private static function normalize(array $values): array
    {
        $entries = [];
        foreach ($values as $key => $value) {
            $entry = is_array($value) ? $value : ['key' => $key, 'value' => $value];
            $number = is_bool($entry['value'] ?? null) ? (int) $entry['value'] : ($entry['value'] ?? null);
            if (! is_string($entry['key'] ?? null) || $entry['key'] === '' || ! is_numeric($number) || ! is_finite((float) $number)) {
                continue;
            }
            $entries[] = [
                'key' => mb_substr($entry['key'], 0, 64),
                'name' => mb_substr((string) ($entry['name'] ?? ''), 0, 128),
                'value' => (float) $number,
                'unit' => isset($entry['unit']) ? mb_substr((string) $entry['unit'], 0, 16) : null,
            ];
        }

        return $entries;
    }

    /**
     * The definitions of these values, the missing ones created.
     *
     * @return array<string, ValueDefinition> by "key\0name"
     */
    private static function definitions(string $type, int $id, array $entries): array
    {
        $find = fn (array $keys) => ValueDefinition::query()->where('valuable_type', $type)->where('valuable_id', $id)->whereIn('key', $keys)->get()
            ->mapWithKeys(fn (ValueDefinition $definition) => [$definition->key."\0".$definition->name => $definition])->all();
        $keys = array_values(array_unique(array_column($entries, 'key')));
        $found = $find($keys);

        $missing = [];
        foreach ($entries as $entry) {
            if (! isset($found[$entry['key']."\0".$entry['name']])) {
                $missing[$entry['key']."\0".$entry['name']] = ['valuable_type' => $type, 'valuable_id' => $id, 'key' => $entry['key'], 'name' => $entry['name'], 'unit' => $entry['unit']];
            }
        }
        if ($missing !== []) {
            // Two heartbeats at once create it once.
            ValueDefinition::query()->insertOrIgnore(array_values($missing));
            $found = $find($keys);
        }

        return $found;
    }
}
