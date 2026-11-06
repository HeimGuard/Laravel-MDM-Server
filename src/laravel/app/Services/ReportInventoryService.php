<?php

namespace App\Services;

use App\Models\Device;
use App\Models\InventoryDefinition;
use Illuminate\Support\Collection;

/**
 * The lists of the report of an agent (services, containers, updates, disk health) are the inventory of the device, not part of
 * devices.data: stored when the report is, read in the shape they were sent (Device::data->services, ...), so
 * everything that reads them stays as it was. A list is a kind of inventory (one definition per row, in the order of the
 * report), docker and disk_health also have a definition of their own for what is not a row (the error, the disks).
 */
class ReportInventoryService
{
    /** Sections of the report that are the inventory, and ARP of the agent that is not kept (the network pages have a table of it). */
    public const SECTIONS = ['services', 'docker', 'os_updates', 'packages_updates', 'module_updates', 'disk_health'];

    public const DROPPED = ['neighbours'];

    /** section => the kind of its rows */
    private const ROWS = ['services' => 'service', 'os_updates' => 'os_update', 'packages_updates' => 'package_update', 'module_updates' => 'module_update'];

    /**
     * The report split: [what stays in devices.data, the sections that are the inventory].
     *
     * @return array{0: array, 1: array<string, mixed>}
     */
    public static function split(array $report): array
    {
        $sections = [];
        foreach (self::SECTIONS as $section) {
            if (array_key_exists($section, $report) && $report[$section] !== null) {
                $sections[$section] = $report[$section];
            }
        }

        return [array_diff_key($report, array_flip([...self::SECTIONS, ...self::DROPPED])), $sections];
    }

    /**
     * Stores the sections of a report: a list that is not there is gone, a row that is not in it is gone.
     *
     * @param  array<string, mixed>  $sections  from split(); a section that is missing is gone
     */
    public static function store(Device $device, array $sections): void
    {
        $type = $device->getMorphClass();
        foreach (self::SECTIONS as $section) {
            $value = $sections[$section] ?? null;
            if (isset(self::ROWS[$section])) {
                InventoryService::apply($type, $device->id, self::ROWS[$section], self::rows($section, $value), [], true);

                continue;
            }
            // docker: the error (if any) and the containers; disk_health: the one record with the disks.
            if ($section === 'docker') {
                $marker = is_array($value) ? array_diff_key($value, ['containers' => 1]) : null;
                InventoryService::apply($type, $device->id, 'docker', $marker === null ? [] : [['', InventoryService::hash($marker), $marker]], [], true);
                InventoryService::apply($type, $device->id, 'container', self::rows('docker', is_array($value) ? ($value['containers'] ?? []) : null), [], true);
            } else {
                InventoryService::apply($type, $device->id, 'disk_health', is_array($value) ? [['', InventoryService::hash($value), $value]] : [], [], true);
            }
        }
    }

    /** @return array<int, array{0: string, 1: string, 2: array, 3: int}> [name, hash, row, position] */
    private static function rows(string $section, mixed $list): array
    {
        $items = [];
        $names = [];
        foreach (self::asList($list) as $position => $row) {
            $row = is_array($row) ? $row : ['Value' => $row];
            $hash = InventoryService::hash($row);
            // A service or a container is named by its Name; an update by what it is (a row twice is one).
            $name = in_array($section, ['services', 'docker'], true) && is_string($row['Name'] ?? null) && $row['Name'] !== '' ? mb_substr($row['Name'], 0, 128) : $hash;
            if (isset($names[$name])) {
                $name = $hash.'-'.$position;
            }
            $names[$name] = true;
            $items[] = [$name, $hash, $row, $position];
        }

        return $items;
    }

    /** The rows of a section as the agents send them: a list, or (PowerShell turns a list of one into the row itself) one row. */
    private static function asList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        return array_is_list($list) ? $list : [$list];
    }

    /**
     * One section of a device as it was reported (decoded like json_decode: objects), null when the device has none.
     */
    public static function read(Device $device, string $section): mixed
    {
        return self::compose($section, self::definitions([$device->id], $section)->get($device->id, collect()));
    }

    /**
     * The sections of many devices in one query: [device id => [section => value]].
     *
     * @param  Collection<int, Device>  $devices
     * @param  array<int, string>  $sections
     * @return array<int, array<string, mixed>>
     */
    public static function readMany(iterable $devices, array $sections = self::SECTIONS): array
    {
        $ids = collect($devices)->pluck('id')->all();
        $definitions = self::definitions($ids, ...$sections);
        $all = [];
        foreach ($ids as $id) {
            foreach ($sections as $section) {
                $all[$id][$section] = self::compose($section, $definitions->get($id, collect()));
            }
        }

        return $all;
    }

    /** @return Collection<int, Collection<int, InventoryDefinition>> the definitions of the kinds of the sections, by device */
    private static function definitions(array $ids, string ...$sections): Collection
    {
        $kinds = [];
        foreach ($sections as $section) {
            array_push($kinds, ...(isset(self::ROWS[$section]) ? [self::ROWS[$section]] : ($section === 'docker' ? ['docker', 'container'] : ['disk_health'])));
        }
        if ($ids === [] || $kinds === []) {
            return collect();
        }
        $found = collect();
        foreach (array_chunk($ids, 500) as $chunk) {
            $found = $found->merge(InventoryDefinition::query()->where('inventorable_type', (new Device)->getMorphClass())->whereIn('inventorable_id', $chunk)
                ->whereIn('key', array_unique($kinds))->whereNotNull('last_data')->orderBy('position')->orderBy('id')->get(['inventorable_id', 'key', 'position', 'last_data']));
        }

        return $found->groupBy('inventorable_id');
    }

    /** @param  Collection<int, InventoryDefinition>  $definitions  of one device */
    private static function compose(string $section, Collection $definitions): mixed
    {
        $rows = fn (string $kind) => $definitions->where('key', $kind)->map(fn (InventoryDefinition $definition) => $definition->last_data)->values()->all();
        $decode = fn (mixed $value) => json_decode(json_encode($value));

        return match ($section) {
            'docker' => ($marker = $definitions->firstWhere('key', 'docker')) === null ? null : $decode($marker->last_data + ['containers' => $rows('container')]),
            'disk_health' => ($record = $definitions->firstWhere('key', 'disk_health')) === null ? null : $decode($record->last_data),
            default => ($list = $rows(self::ROWS[$section])) === [] ? null : $decode($list),
        };
    }
}
