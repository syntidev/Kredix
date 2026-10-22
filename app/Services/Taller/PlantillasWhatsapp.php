<?php

namespace App\Services\Taller;

use App\Models\Configuracion;

// Plantillas de WhatsApp del Taller: el texto guardado en Configuracion (tabla
// configuraciones, mismo mecanismo que las plantillas 1-3) manda; si no hay,
// el original de config/taller.php. Fuente unica para la pantalla de
// Configuracion, la validacion y el armado de mensajes en TallerController
class PlantillasWhatsapp
{
    // campo => clave en configuraciones, clave del texto original en config, variables validas
    // ({fecha_entrega} = fecha de compromiso del ticket, o "por confirmar" si no tiene)
    public const CAMPOS = [
        'listo_por_cobrar' => ['clave' => 'whatsapp_taller_listo_por_cobrar', 'defecto' => 'taller.whatsapp_listo.por_cobrar', 'etiqueta' => 'Unidad lista — por cobrar', 'variables' => ['nombre', 'bici', 'monto', 'fecha_entrega']],
        'listo_pagado' => ['clave' => 'whatsapp_taller_listo_pagado', 'defecto' => 'taller.whatsapp_listo.pagado', 'etiqueta' => 'Unidad lista — ya pagado', 'variables' => ['nombre', 'bici', 'monto', 'fecha_entrega']],
        'repuesto' => ['clave' => 'whatsapp_taller_repuesto', 'defecto' => 'taller.whatsapp_repuesto', 'etiqueta' => 'Falta un repuesto', 'variables' => ['nombre', 'bici', 'fecha_entrega']],
        'danos' => ['clave' => 'whatsapp_taller_danos', 'defecto' => 'taller.whatsapp_danos', 'etiqueta' => 'Detalle en la revisión', 'variables' => ['nombre', 'bici', 'fecha_entrega']],
        'libre' => ['clave' => 'whatsapp_taller_libre', 'defecto' => 'taller.whatsapp_libre', 'etiqueta' => 'Mensaje libre', 'variables' => ['nombre', 'bici', 'fecha_entrega']],
        // las cifras del presupuesto ({monto}, {lista_repuestos}, {total_repuestos}, {monto_servicio}) solo existen aqui
        'presupuesto' => ['clave' => 'whatsapp_taller_presupuesto', 'defecto' => 'taller.whatsapp_presupuesto', 'etiqueta' => 'Presupuesto de repuestos', 'variables' => ['nombre', 'bici', 'fecha_entrega', 'monto', 'lista_repuestos', 'total_repuestos', 'monto_servicio']],
    ];

    public static function defecto(string $campo): string
    {
        return (string) config(self::CAMPOS[$campo]['defecto']);
    }

    public static function texto(string $campo): string
    {
        $guardado = Configuracion::valorDe(self::CAMPOS[$campo]['clave']);

        return filled($guardado) ? $guardado : self::defecto($campo);
    }

    // mensaje de error si el texto usa una variable que ese campo no admite; null si esta bien.
    // {monto} solo existe en "Unidad lista": en las demas plantillas no hay cifras
    public static function errorDeVariables(string $campo, string $texto): ?string
    {
        $validas = self::CAMPOS[$campo]['variables'];
        preg_match_all('/\{([^{}]*)\}/', $texto, $coincidencias);

        foreach (array_unique($coincidencias[1]) as $variable) {
            if (in_array($variable, $validas, true)) {
                continue;
            }
            if ($variable === 'monto') {
                return '{monto} solo se permite en «Unidad lista» (por cobrar y ya pagado); quítalo de «'.self::CAMPOS[$campo]['etiqueta'].'».';
            }

            return "La variable {{$variable}} no es válida en «".self::CAMPOS[$campo]['etiqueta'].'». Las válidas son: '.implode(', ', array_map(fn ($v) => '{'.$v.'}', $validas)).'.';
        }

        return null;
    }

    // si el total no se puede afirmar se elimina la linea COMPLETA que lleva {monto}
    public static function sinLineasConMonto(string $texto): string
    {
        return self::sinLineasCon($texto, ['{monto}']);
    }

    // elimina cada linea COMPLETA que lleve alguno de los marcadores (coincidencia exacta:
    // {monto} no toca {monto_servicio})
    public static function sinLineasCon(string $texto, array $marcadores): string
    {
        return implode("\n", array_filter(
            preg_split('/\R/', $texto),
            fn (string $linea) => collect($marcadores)->every(fn (string $m) => ! str_contains($linea, $m)),
        ));
    }
}
