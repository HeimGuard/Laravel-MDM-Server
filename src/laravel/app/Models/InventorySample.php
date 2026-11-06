<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The state of an inventory definition after a change (null data: it was removed). */
class InventorySample extends Model
{
    public $timestamps = false;

    protected $fillable = ['definition_id', 'data', 'hash', 'recorded_at'];

    protected $casts = [
        'data' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(InventoryDefinition::class, 'definition_id');
    }
}
