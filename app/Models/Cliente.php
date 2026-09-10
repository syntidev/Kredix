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
        'mensaje_pdf',
        'notas',
        'contacto_alterno_nombre',
        'contacto_alterno_telefono',
    ];
}
