<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What is measured on a model: one row per model, key and name (device 9, disk.used_pct, C:). name is empty for what
 * there is one of (cpu). last_value and last_at are the newest sample, so the current value costs no query of the history.
 */
class ValueDefinition extends Model
{
    public $timestamps = false;

    protected $fillable = ['valuable_type', 'valuable_id', 'key', 'name', 'unit', 'last_value', 'last_at'];

    protected $casts = [
        'last_value' => 'float',
        'last_at' => 'datetime',
    ];

    public function valuable(): MorphTo
    {
        return $this->morphTo();
    }

    public function samples(): HasMany
    {
        return $this->hasMany(ValueSample::class, 'definition_id');
    }
}
