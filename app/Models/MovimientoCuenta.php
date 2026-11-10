<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'compra_id',
        'fecha',
        'tipo',
        'tipo_contacto',
        'fecha_prometida',
        'descripcion',
        'cantidad',
        'plazo_meses',
        'cuota_id',
        'plan_financiamiento_id',
        'es_inicial',
        'frecuencia_pago',
        'precio_unitario',
        'modalidad_precio',
        'monto',
        'moneda',
        'tasa_cambio',
        'metodo_pago',
        'referencia',
        'comentario',
        'duplicado_confirmado',
        'registrado_por',
        'estado_validacion',
        'validado_por',
        'validado_en',
        'motivo_eliminacion',
        'eliminado_por',
        'pendiente_entrega',
        'entregado_en',
        'entregado_por',
        'es_contado',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_prometida' => 'date',
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'monto' => 'decimal:2',
        'tasa_cambio' => 'decimal:4',
        'validado_en' => 'datetime',
        'duplicado_confirmado' => 'boolean',
        'es_inicial' => 'boolean',
        'pendiente_entrega' => 'boolean',
        'entregado_en' => 'datetime',
        'es_contado' => 'boolean',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    // un renglon por unidad vendida; solo los cargos con "lleva serial" los tienen
    public function seriales(): HasMany
    {
        return $this->hasMany(SerialProducto::class, 'movimiento_cuenta_id')->orderBy('id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function validadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validado_por');
    }

    public function planCuotas(): HasMany
    {
        return $this->hasMany(PlanCuota::class)->orderBy('numero_cuota');
    }

    public function cuota(): BelongsTo
    {
        return $this->belongsTo(Cuota::class);
    }

    // plan al que se abona: filas nuevas via plan_financiamiento_id, filas
    // viejas (partidas por cuota) via cuota_id -> cuota -> plan
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanFinanciamiento::class, 'plan_financiamiento_id');
    }

    public function planDeAbono(): ?PlanFinanciamiento
    {
        return $this->plan ?? $this->cuota?->plan;
    }

    // solo tiene sentido en filas tipo=cargo -- el plan de financiamiento
    // cuelga de la compra que lo origino
    public function planFinanciamiento(): HasOne
    {
        return $this->hasOne(PlanFinanciamiento::class, 'movimiento_cuenta_id');
    }

    // solo tiene sentido en filas tipo=cargo generadas automaticamente al
    // cerrar un ticket de Taller sin pago en el momento
    public function ticketTaller(): HasOne
    {
        return $this->hasOne(TicketTaller::class, 'movimiento_cuenta_id');
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
        return static::saldosPendientes([$clienteId])[$clienteId];
    }

    /**
     * Misma formula que saldoPendiente (cargos - abonos - ajustes, solo vigentes) para varios clientes en UNA
     * consulta: listas que muestran el saldo de muchos clientes sin una consulta por fila.
     *
     * @param  list<int>  $clienteIds
     * @return array<int, float> clienteId => saldo (0.0 si no tiene movimientos)
     */
    public static function saldosPendientes(array $clienteIds): array
    {
        $saldos = array_fill_keys($clienteIds, 0.0);
        if ($clienteIds === []) {
            return $saldos;
        }

        $totales = static::whereIn('cliente_id', $clienteIds)->whereIn('tipo', ['cargo', 'abono', 'ajuste_devolucion'])
            ->selectRaw('cliente_id, tipo, SUM(monto) as total')->groupBy('cliente_id', 'tipo')->get();
        foreach ($totales as $fila) {
            $saldos[$fila->cliente_id] += $fila->tipo === 'cargo' ? (float) $fila->total : -(float) $fila->total;
        }

        return $saldos;
    }

    public static function totalCobrado(int $clienteId): float
    {
        return (float) static::where('cliente_id', $clienteId)->where('tipo', 'abono')->sum('monto');
    }

    /**
     * Fecha y dias sin abonar del ultimo abono real (nunca cargo), a partir de una
     * Collection ya cargada -- recibe la coleccion en vez de consultar la BD para
     * poder reutilizarse dentro de un loop sobre muchos clientes (CarteleraController)
     * sin introducir una query por cliente.
     */
    public static function ultimoAbonoInfo($movimientos): array
    {
        // un pago de contado no cuenta como "ultimo abono" (no reinicia la mora de otras deudas)
        $ultimoAbono = $movimientos->where('tipo', 'abono')->where('es_contado', false)->whereNotNull('fecha')->sortBy('fecha')->last();

        return [
            'fecha' => $ultimoAbono?->fecha,
            'dias' => $ultimoAbono ? now()->startOfDay()->diffInDays($ultimoAbono->fecha, true) : null,
        ];
    }
}
