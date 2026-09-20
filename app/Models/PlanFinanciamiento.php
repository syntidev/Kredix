<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlanFinanciamiento extends Model
{
    use SoftDeletes;

    protected $table = 'planes_financiamiento';

    protected $fillable = [
        'movimiento_cuenta_id',
        'monto_inicial',
        'porcentaje_mora',
        'frecuencia_pago',
        'numero_cuotas',
        'aplicado_mora',
    ];

    protected $casts = [
        'monto_inicial' => 'decimal:2',
        'porcentaje_mora' => 'decimal:2',
        'aplicado_mora' => 'boolean',
    ];

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(MovimientoCuenta::class, 'movimiento_cuenta_id');
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(Cuota::class)->orderBy('numero');
    }
}
