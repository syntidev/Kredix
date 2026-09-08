<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemVenta extends Model
{
    protected $table = 'items_venta';

    protected $fillable = [
        'venta_credito_id',
        'descripcion_libre',
        'precio_unitario',
        'cantidad',
    ];

    protected $casts = [
        'precio_unitario' => 'decimal:2',
    ];

    public function ventaCredito(): BelongsTo
    {
        return $this->belongsTo(VentaCredito::class, 'venta_credito_id');
    }
}
