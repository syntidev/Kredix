<?php

namespace App\Models;

use App\Jobs\RedactarInforme;
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
        'texto_cliente_estado',
        'texto_cliente_origen',
        'texto_cliente_aprobado_por',
        'texto_cliente_aprobado_en',
        'texto_cliente_hash',
        'revision_tecnica',
        'revisado_por',
        'revisado_en',
        'sugerencias_ia',
        'informe_ia',
        'informe_ia_estado',
        'informe_ia_generado_en',
        'informe_ia_aprobado_por',
        'informe_ia_hash',
        'informe_ia_desactualizado',
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
        'revision_tecnica' => 'array',
        'revisado_en' => 'datetime',
        'sugerencias_ia' => 'array',
        'informe_ia_generado_en' => 'datetime',
        'informe_ia_desactualizado' => 'boolean',
        'texto_cliente_aprobado_en' => 'datetime',
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

    public function textoClienteAprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'texto_cliente_aprobado_por');
    }

    // Compuerta "Texto para el cliente". Estado null con texto = ticket anterior
    // a la compuerta: texto manual aprobado, se imprime igual que siempre
    public function textoClienteAprobado(): bool
    {
        return filled($this->trabajo_realizado) && in_array($this->texto_cliente_estado, [null, 'aprobado'], true);
    }

    // la revision cambio despues de aprobar el texto
    public function textoClienteDesactualizado(): bool
    {
        return $this->textoClienteAprobado()
            && $this->texto_cliente_hash !== null
            && $this->texto_cliente_hash !== RedactarInforme::hash($this->revision_tecnica);
    }

    // lo unico que el PDF imprime: aprobado por una persona y al dia con la revision
    public function textoClienteImprimible(): ?string
    {
        return $this->textoClienteAprobado() && ! $this->textoClienteDesactualizado() ? $this->trabajo_realizado : null;
    }

    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
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
    public function totalRepuestos(): float
    {
        return (float) $this->repuestos->sum(fn (TicketRepuesto $r) => $r->cantidad * (float) $r->precio);
    }

    public function totalTicket(): float
    {
        return $this->totalRepuestos() + ($this->tipo === 'servicio_cliente' ? (float) $this->monto_servicio : 0);
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

        // para el PDF de atencion -- 'thumb' (200x200, recortado a cuadrado)
        // se ve pixelado al estirarse en el reporte. Solo width(): sin forzar
        // alto, respeta la orientacion real de la foto (no recorta)
        $this->addMediaConversion('print')
            ->width(1200)
            ->quality(85)
            ->optimize()
            ->nonQueued()
            ->performOnCollections('entrada', 'salida');
    }
}
