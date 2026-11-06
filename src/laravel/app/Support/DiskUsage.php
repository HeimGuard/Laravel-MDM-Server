<?php

namespace App\Support;

use App\Models\Device;

/**
 * The usage of each disk of a device from its report, as measured values: disk.used_pct (%) and disk.free_bytes, each
 * named by the disk (C: on Windows, the mount point on Linux), so every disk has a history of its own.
 */
class DiskUsage
{
    /** @return array<int, array{key: string, name: string, value: float|int, unit: string}> */
    public static function values(Device $device): array
    {
        $values = [];
        foreach ($device->drives as $drive) {
            $size = (float) ($drive['Size'] ?? 0);
            $letter = trim((string) ($drive['DriveLetter'] ?? ''));
            if ($size <= 0 || $letter === '') {
                continue;
            }
            // A Windows letter is C:, a Linux mount point stays as it is.
            $name = mb_substr(preg_match('/^[A-Za-z]$/', $letter) ? strtoupper($letter).':' : $letter, 0, 128);
            $free = max(0, min($size, (float) ($drive['SizeRemaining'] ?? 0)));
            $values[] = ['key' => 'disk.used_pct', 'name' => $name, 'value' => round(($size - $free) / $size * 100, 1), 'unit' => '%'];
            $values[] = ['key' => 'disk.free_bytes', 'name' => $name, 'value' => (int) $free, 'unit' => 'bytes'];
        }

        return $values;
    }

    /** Stores the usage of the disks of the report just received. */
    public static function record(Device $device): int
    {
        return $device->recordValues(self::values($device));
    }
}
