<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class MovimientoCuenta extends Model implements HasMedia
{
    use SoftDeletes;
    use InteractsWithMedia;

    protected $table = 'movimientos_cuenta';

    protected $fillable = [
        'cliente_id',
        'fecha',
        'tipo',
        'descripcion',
        'cantidad',
        'plazo_meses',
        'frecuencia_pago',
        'precio_unitario',
        'monto',
        'moneda',
        'tasa_cambio',
        'metodo_pago',
        'comentario',
        'registrado_por',
    ];

    protected $casts = [
        'fecha' => 'date',
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'monto' => 'decimal:2',
        'tasa_cambio' => 'decimal:4',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('comprobantes')->singleFile();
    }

    public static function saldoPendiente(int $clienteId): float
    {
        $cargos = static::where('cliente_id', $clienteId)->where('tipo', 'cargo')->sum('monto');
        $abonos = static::where('cliente_id', $clienteId)->where('tipo', 'abono')->sum('monto');
        $ajustes = static::where('cliente_id', $clienteId)->where('tipo', 'ajuste_devolucion')->sum('monto');

        return (float) $cargos - (float) $abonos - (float) $ajustes;
    }

    public static function totalCobrado(int $clienteId): float
    {
        return (float) static::where('cliente_id', $clienteId)->where('tipo', 'abono')->sum('monto');
    }
}
