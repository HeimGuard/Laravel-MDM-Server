<?php

namespace App\Services;

use App\Models\InventoryDefinition;
use App\Models\InventorySample;
use App\Support\ValueRetention;
use Illuminate\Support\Carbon;

/**
 * Stores the inventory of any model (see App\Models\Concerns\HasInventory): a thing is a definition (model, key = the
 * kind, name = which one) with its current state, and a sample is added only when the state changes (a different hash).
 * A thing that is gone has null data. The same shape as the measured values (ValueRecorder), for structured data.
 */
class InventoryService
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** The hash of the content of a thing when its source does not give one. */
    public static function hash(array $data): string
    {
        return substr(hash('sha256', json_encode($data, self::JSON)), 0, 16);
    }

    /**
     * Records one thing (null data: it is gone).
     *
     * @return bool whether it changed (a sample was added)
     */
    public static function record(string $type, int $id, string $key, string $name, ?array $data, ?string $hash = null, ?\DateTimeInterface $at = null): bool
    {
        $upsert = $data === null ? [] : [[$name, $hash ?? self::hash($data), $data]];

        return self::apply($type, $id, $key, $upsert, $data === null ? [$name] : [], false, $at)['changed'] > 0;
    }

    /**
     * What a model has now of a kind: name => hash of the things that are not gone.
     *
     * @return array<string, string>
     */
    public static function current(string $type, int $id, string $key): array
    {
        return InventoryDefinition::query()->where('inventorable_type', $type)->where('inventorable_id', $id)->where('key', $key)
            ->whereNotNull('last_data')->pluck('last_hash', 'name')->all();
    }

    /**
     * A change of a kind of things of a model, as the agents send it: the things that are new or changed, the ones that are
     * gone, or (full) everything, so what is not in it is gone. Only what differs is written.
     *
     * @param  array<int, array{0: string, 1: string, 2: array}>  $upsert  [[name, hash, data], ...]
     * @param  array<int, string>  $remove  names
     * @return array{old: array<int, array>, changed: int} old: the data of the things before (not for a full one)
     */
    public static function apply(string $type, int $id, string $key, array $upsert, array $remove, bool $full, ?\DateTimeInterface $at = null): array
    {
        $at = Carbon::instance($at ?? now())->toDateTimeString();
        $upsertNames = array_map(fn (array $item) => (string) $item[0], $upsert);
        $base = InventoryDefinition::query()->where('inventorable_type', $type)->where('inventorable_id', $id)->where('key', $key);

        // What is there of the things concerned.
        $existing = collect();
        if ($full) {
            $existing = (clone $base)->get(['id', 'name', 'last_data', 'last_hash']);
        } else {
            foreach (array_chunk(array_values(array_unique([...$remove, ...$upsertNames])), 500) as $names) {
                $existing = $existing->merge((clone $base)->whereIn('name', $names)->get(['id', 'name', 'last_data', 'last_hash']));
            }
        }
        $existing = $existing->keyBy('name');
        $old = $full ? [] : $existing->filter(fn (InventoryDefinition $definition) => $definition->last_data !== null)->map(fn (InventoryDefinition $definition) => $definition->last_data)->values()->all();

        $gone = $full ? $existing->filter(fn (InventoryDefinition $definition) => $definition->last_data !== null)->keys()->diff($upsertNames)->all() : $remove;
        $encode = fn (?array $data) => $data === null ? null : json_encode($data, self::JSON);
        $definitions = [];
        $samples = [];
        $row = fn (string $name, ?array $data, ?string $hash) => [
            'inventorable_type' => $type, 'inventorable_id' => $id, 'key' => $key, 'name' => $name,
            'last_data' => $encode($data), 'last_hash' => $hash, 'last_at' => $at,
        ];
        foreach ($gone as $name) {
            if (($definition = $existing->get($name)) !== null && $definition->last_data !== null) {
                $definitions[$name] = $row($name, null, null);
                $samples[$name] = ['data' => null, 'hash' => null];
            }
        }
        foreach ($upsert as [$name, $hash, $data]) {
            $definition = $existing->get($name);
            if ($definition !== null && $definition->last_data !== null && $definition->last_hash === $hash) {
                continue;
            }
            $definitions[$name] = $row((string) $name, $data, $hash);
            $samples[$name] = ['data' => $encode($data), 'hash' => $hash];
        }
        if ($definitions === []) {
            return ['old' => $old, 'changed' => 0];
        }

        foreach (array_chunk(array_values($definitions), 200) as $chunk) {
            InventoryDefinition::query()->upsert($chunk, ['inventorable_type', 'inventorable_id', 'key', 'name'], ['last_data', 'last_hash', 'last_at']);
        }
        $ids = [];
        foreach (array_chunk(array_keys($samples), 500) as $names) {
            $ids += (clone $base)->whereIn('name', $names)->pluck('id', 'name')->all();
        }
        $rows = [];
        foreach ($samples as $name => $sample) {
            $rows[] = ['definition_id' => $ids[$name]] + $sample + ['recorded_at' => $at];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            InventorySample::query()->insert($chunk);
        }

        return ['old' => $old, 'changed' => count($definitions)];
    }

    /**
     * The history older than the setting of the portal (history_days) is deleted, and so are the things that have been gone
     * since then. What exists keeps its current state in the definition.
     *
     * @return int samples deleted
     */
    public static function prune(): int
    {
        $before = now()->subDays(ValueRetention::days());
        $deleted = InventorySample::query()->where('recorded_at', '<', $before)->delete();
        InventoryDefinition::query()->whereNull('last_data')->where('last_at', '<', $before)->delete();

        return $deleted;
    }
}
