<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanCuota extends Model
{
    protected $fillable = [
        'movimiento_cuenta_id',
        'numero_cuota',
        'monto_sugerido',
        'fecha_esperada',
    ];

    protected $casts = [
        'monto_sugerido' => 'decimal:2',
        'fecha_esperada' => 'date',
    ];

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoCuenta::class, 'movimiento_cuenta_id');
    }
}
