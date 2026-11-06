<?php

namespace App\Support;

use App\Models\ValueDefinition;
use App\Models\ValueSample;
use Illuminate\Support\Facades\DB;
use SteelAnts\LaravelBoilerplate\Models\Setting;

/**
 * How long the history of measured values (CPU, memory, disks, ping, ...) is kept: one setting of the portal for all of it
 * (System > Settings, days, default 30), and the daily clean-up that deletes what is older.
 */
class ValueRetention
{
    public const SETTING = 'main.general.history_days';

    public const DEFAULT_DAYS = 30;

    /** The days the history is kept. */
    public static function days(): int
    {
        $value = Setting::query()->whereNull('settable_id')->where('index', self::SETTING)->value('value');

        return $value === null || $value === '' ? self::DEFAULT_DAYS : max(1, (int) $value);
    }

    /** @return int samples deleted */
    public static function prune(): int
    {
        $before = now()->subDays(self::days());
        $deleted = ValueSample::query()->where('recorded_at', '<', $before)->delete();

        // What is not measured any more (a disk that was removed) goes when its last sample would be pruned.
        ValueDefinition::query()->where('last_at', '<', $before)->delete();

        return $deleted;
    }
}
