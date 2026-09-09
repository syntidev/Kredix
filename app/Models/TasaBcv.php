<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TasaBcv extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'tasas_bcv';

    protected $fillable = [
        'rate',
        'source',
        'effective_from',
        'effective_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
