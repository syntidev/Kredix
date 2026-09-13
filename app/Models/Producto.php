<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'veces_usado',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Mayusculas + espacios colapsados -- unica fuente de verdad para decidir si
     * dos descripciones escritas distinto (may/min, espacios de mas) son "el mismo"
     * producto. Usado tanto al poblar el catalogo como al buscarlo/guardarlo.
     */
    public static function normalizarNombre(string $texto): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $texto)), 'UTF-8');
    }
}
