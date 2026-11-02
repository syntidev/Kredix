<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nombre',
        'telefono',
        'email',
        'cedula',
        'direccion',
        'mensaje_pdf',
        'notas',
        'contacto_alterno_nombre',
        'contacto_alterno_telefono',
        'usuario_responsable_id',
        // TEMPORAL -- ver ClienteController::cuentasEnRevision()
        'estado_revision',
        'revisado_por',
        'revisado_en',
    ];

    protected $casts = [
        'revisado_en' => 'datetime',
    ];

    // seriales vigentes de lo que se le ha vendido (garantia); los liberados quedan fuera por el soft-delete
    public function seriales(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SerialProducto::class);
    }

    public function usuarioResponsable()
    {
        return $this->belongsTo(User::class, 'usuario_responsable_id');
    }

    // TEMPORAL -- ver ClienteController::cuentasEnRevision()
    public function revisadoPor()
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }
}
