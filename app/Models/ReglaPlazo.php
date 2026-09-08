<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReglaPlazo extends Model
{
    protected $table = 'reglas_plazo';

    protected $fillable = [
        'monto_min',
        'monto_max',
        'plazo_min_meses',
        'plazo_max_meses',
        'requiere_abono',
    ];

    protected $casts = [
        'requiere_abono' => 'boolean',
    ];

    public static function paraMonto(float $monto): ?self
    {
        return static::where('monto_min', '<=', $monto)
            ->where('monto_max', '>=', $monto)
            ->orderBy('monto_min')
            ->first();
    }
}
