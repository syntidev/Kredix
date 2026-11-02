<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Un serial por unidad vendida (garantia). Liberado = soft-delete: el serial puede volver a registrarse.
class SerialProducto extends Model
{
    use SoftDeletes;

    protected $table = 'seriales_producto';

    protected $fillable = ['movimiento_cuenta_id', 'cliente_id', 'serial', 'registrado_por'];

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoCuenta::class, 'movimiento_cuenta_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
