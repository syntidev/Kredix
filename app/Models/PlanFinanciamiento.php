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

    // withTrashed: si se anula el cargo que sostiene el plan, el plan no debe
    // quedar huerfano -- sin esto la relacion devuelve null y la mora revienta
    // (y el plan desaparecia de la ficha mientras sus cuotas seguian vivas)
    public function cargo(): BelongsTo
    {
        return $this->belongsTo(MovimientoCuenta::class, 'movimiento_cuenta_id')->withTrashed();
    }

    // Total pactado de la compra que este plan financia.
    // Con compra_id: la suma de TODAS las lineas del carrito, incluidas las
    // anuladas -- el contrato no cambia porque se anule una linea despues de
    // firmarlo; lo que baja es el saldo del cliente (SoftDeletes ya las excluye
    // de ahi). Renegociar es un acto explicito, no el efecto de un soft-delete.
    // Sin compra_id (compras anteriores al hotfix): el monto del unico cargo,
    // identico al comportamiento previo.
    public static function montoTotalDeCompra(MovimientoCuenta $cargo): float
    {
        if (blank($cargo->compra_id)) {
            return (float) $cargo->monto;
        }

        return (float) MovimientoCuenta::withTrashed()
            ->where('compra_id', $cargo->compra_id)
            ->where('tipo', 'cargo')
            ->sum('monto');
    }

    public function montoTotalCompra(): float
    {
        return $this->cargo ? self::montoTotalDeCompra($this->cargo) : 0.0;
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(Cuota::class)->orderBy('numero');
    }
}
