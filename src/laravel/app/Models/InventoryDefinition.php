<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One thing a model has (a service, a container, a process, a port, a user, ...): `key` is the kind, `name` which one.
 * last_data, last_hash and last_at are its current state (null data: it is gone), the samples are the history, only
 * the changes. The same shape as ValueDefinition, for structured data instead of numbers.
 */
class InventoryDefinition extends Model
{
    public $timestamps = false;

    protected $fillable = ['inventorable_type', 'inventorable_id', 'key', 'name', 'position', 'last_data', 'last_hash', 'last_at'];

    protected $casts = [
        'last_data' => 'array',
        'last_at' => 'datetime',
    ];

    public function inventorable(): MorphTo
    {
        return $this->morphTo();
    }

    public function samples(): HasMany
    {
        return $this->hasMany(InventorySample::class, 'definition_id');
    }
}
