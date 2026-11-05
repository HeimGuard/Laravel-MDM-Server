<?php

/** Measured values (App\Models\ValueDefinition): how long their history is kept, by key. */
return [
    'retention_days' => [
        'cpu' => 30,
        'memory.used' => 30,
        'memory.total' => 30,
        'ping.up' => 30,
        'ping.rtt' => 30,
        'disk.used_pct' => 90,
        'disk.free_bytes' => 90,
        'battery.level' => 30,
    ],
    // A key that is not above.
    'default_retention_days' => 30,
];
