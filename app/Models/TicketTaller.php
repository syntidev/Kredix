<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class TicketTaller extends Model implements HasMedia
{
    use SoftDeletes;
    use InteractsWithMedia;

    protected $table = 'tickets_taller';

    protected $fillable = [
        'tipo',
        'cliente_id',
        'bici_marca_modelo',
        'talla_rin',
        'categoria_bici',
        'es_electrica',
        'motivo_ingreso',
        'trabajo_realizado',
        'tipo_servicio',
        'domicilio_direccion',
        'monto_servicio',
        'diagnostico',
        'estado',
        'pagado_en_taller',
        'movimiento_cuenta_id',
        'mecanico_id',
        'registrado_por',
        'motivo_eliminacion',
        'eliminado_por',
    ];

    protected $casts = [
        'monto_servicio' => 'decimal:2',
        'diagnostico' => 'array',
        'es_electrica' => 'boolean',
        'pagado_en_taller' => 'boolean',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function mecanico(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mecanico_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function repuestos(): HasMany
    {
        return $this->hasMany(TicketRepuesto::class, 'ticket_id');
    }

    public function movimientoCuenta(): BelongsTo
    {
        return $this->belongsTo(MovimientoCuenta::class);
    }

    // mismo calculo que totalTicket/totalRepuestos en Taller/Show.vue --
    // portado a PHP porque el cargo automatico al cerrar necesita el monto
    // server-side, la version original solo existia como computed de Vue
    public function totalTicket(): float
    {
        $totalRepuestos = $this->repuestos->sum(fn (TicketRepuesto $r) => $r->cantidad * (float) $r->precio);

        return $totalRepuestos + ($this->tipo === 'servicio_cliente' ? (float) $this->monto_servicio : 0);
    }

    // Requiere `php artisan storage:link` corrido una vez en el servidor (ver
    // mismo comentario en MovimientoCuenta::registerMediaCollections)
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('entrada');
        $this->addMediaCollection('salida');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // nonQueued: sin worker de colas, mismo motivo que MovimientoCuenta
        $this->addMediaConversion('thumb')
            ->width(200)
            ->height(200)
            ->optimize()
            ->nonQueued()
            ->performOnCollections('entrada', 'salida');
    }
}
