<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MovimientoCuenta extends Model implements HasMedia
{
    use SoftDeletes;
    use InteractsWithMedia;

    protected $table = 'movimientos_cuenta';

    protected $fillable = [
        'cliente_id',
        'fecha',
        'tipo',
        'tipo_contacto',
        'fecha_prometida',
        'descripcion',
        'cantidad',
        'plazo_meses',
        'frecuencia_pago',
        'precio_unitario',
        'modalidad_precio',
        'monto',
        'moneda',
        'tasa_cambio',
        'metodo_pago',
        'comentario',
        'registrado_por',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_prometida' => 'date',
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

    public function planCuotas(): HasMany
    {
        return $this->hasMany(PlanCuota::class)->orderBy('numero_cuota');
    }

    // Requiere `php artisan storage:link` corrido una vez en el servidor (crea
    // public/storage -> storage/app/public); sin el symlink las URLs de media
    // devuelven 404 aunque el archivo exista en disco.
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('comprobantes')->singleFile();
        $this->addMediaCollection('producto')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // nonQueued: no hay worker de colas corriendo (app de 3 usuarios, sin
        // infraestructura de colas) -- sin esto el thumb nunca se genera porque
        // el job queda pendiente en la tabla jobs para siempre.
        $this->addMediaConversion('thumb')
            ->width(200)
            ->height(200)
            ->optimize()
            ->nonQueued()
            ->performOnCollections('comprobantes', 'producto');
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
