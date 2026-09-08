<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Abono extends Model implements HasMedia
{
    use SoftDeletes;
    use InteractsWithMedia;

    protected $fillable = [
        'venta_credito_id',
        'monto',
        'moneda',
        'tasa_cambio',
        'metodo_pago',
        'tipo',
        'comentario',
        'registrado_por',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'tasa_cambio' => 'decimal:4',
    ];

    public function ventaCredito(): BelongsTo
    {
        return $this->belongsTo(VentaCredito::class, 'venta_credito_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('comprobantes')->singleFile();
    }
}
