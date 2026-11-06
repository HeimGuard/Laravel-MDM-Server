<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One measured value of a definition (App\Models\ValueDefinition) at a time. */
class ValueSample extends Model
{
    public $timestamps = false;

    protected $fillable = ['definition_id', 'value', 'recorded_at'];

    protected $casts = [
        'value' => 'float',
        'recorded_at' => 'datetime',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ValueDefinition::class, 'definition_id');
    }
}
