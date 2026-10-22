<?php

namespace App\Services\Taller;

// Unico punto de lectura de los interruptores de funciones del Taller (config/taller.php).
// Apagar una funcion no borra codigo, columnas ni datos: solo la oculta o la bloquea.
class Funciones
{
    public static function revisionTecnica(): bool
    {
        return (bool) config('taller.revision_tecnica_activa', false);
    }

    // solo CREAR tickets sin cliente; los que ya existen se listan, abren, editan y cierran igual
    public static function trabajoTienda(): bool
    {
        return (bool) config('taller.trabajo_tienda_activo', false);
    }

    /** @return array{revision_tecnica: bool, trabajo_tienda: bool} */
    public static function paraPantalla(): array
    {
        return [
            'revision_tecnica' => self::revisionTecnica(),
            'trabajo_tienda' => self::trabajoTienda(),
        ];
    }
}
