<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VentaCredito extends Model
{
    use SoftDeletes;

    protected $table = 'ventas_credito';

    protected $fillable = [
        'cliente_id',
        'monto_total',
        'moneda',
        'tasa_cambio',
        'abono_inicial',
        'plazo_meses',
        'frecuencia_pago',
        'fecha_venta',
        'estado',
    ];

    protected $casts = [
        'fecha_venta' => 'date',
        'monto_total' => 'decimal:2',
        'tasa_cambio' => 'decimal:4',
        'abono_inicial' => 'decimal:2',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItemVenta::class, 'venta_credito_id');
    }

    public function abonos(): HasMany
    {
        return $this->hasMany(Abono::class, 'venta_credito_id');
    }

    public function totalCobrado(): float
    {
        return (float) $this->abonos()->where('tipo', 'abono')->sum('monto');
    }

    public function totalAjustes(): float
    {
        return (float) $this->abonos()->where('tipo', 'ajuste_devolucion')->sum('monto');
    }

    public function saldoPendiente(): float
    {
        return (float) $this->monto_total - $this->totalCobrado() - $this->totalAjustes();
    }
}
