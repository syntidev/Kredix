<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProspectoDescarte extends Model
{
    protected $fillable = [
        'cliente_id',
        'prospecto_id',
    ];
}
