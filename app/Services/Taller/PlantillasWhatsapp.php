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

    // Espacio "cobranza" (ficha del cliente): mismo motor, otras plantillas. El texto original vive en
    // codigo ('texto'); en Configuracion se guarda solo si el admin lo edita (clave whatsapp_cobranza_<tipo>).
    public const COBRANZA = [
        'recordatorio' => ['clave' => 'whatsapp_cobranza_recordatorio', 'etiqueta' => 'Recordatorio de cuota', 'variables' => ['nombre', 'saldo', 'cuota_fecha', 'cuota_monto', 'dias_atraso'],
            'texto' => "Hola {nombre} 👋 Te recordamos tu cuota de {cuota_monto}, con vencimiento el {cuota_fecha}.\n{dias_atraso}\nSaldo pendiente: {saldo}\nQuedamos atentos, gracias por su preferencia."],
        'estado_cuenta' => ['clave' => 'whatsapp_cobranza_estado_cuenta', 'etiqueta' => 'Estado de cuenta', 'variables' => ['nombre', 'lista_compras', 'total_abonado', 'saldo'],
            'texto' => "Hola {nombre} 👋 Este es el estado de tu cuenta:\n{lista_compras}\nAbonado: {total_abonado} · Saldo: {saldo}\nQuedamos atentos, gracias por su preferencia."],
        'abono_recibido' => ['clave' => 'whatsapp_cobranza_abono_recibido', 'etiqueta' => 'Abono recibido', 'variables' => ['nombre', 'abono_monto', 'abono_fecha', 'saldo'],
            'texto' => "Hola {nombre} 👋 Recibimos tu abono de {abono_monto} el {abono_fecha}. ¡Gracias!\nSaldo pendiente: {saldo}"],
        // texto del mensaje de saldo de siempre (intro + "Saldo pendiente" + cierre), sin los dias sin abonar
        'saldo' => ['clave' => 'whatsapp_cobranza_saldo', 'etiqueta' => 'Saldo pendiente', 'variables' => ['nombre', 'saldo'],
            'texto' => "Hola {nombre},\n\nSaldo pendiente: {saldo}\n\nQuedamos atentos, gracias por su preferencia."],
        'por_entregar' => ['clave' => 'whatsapp_cobranza_por_entregar', 'etiqueta' => 'Por entregar', 'variables' => ['nombre', 'lista_articulos'],
            'texto' => "Hola {nombre} 👋 Tienes pendiente de entrega:\n{lista_articulos}\nCoordinemos la entrega por aquí."],
        // promocion mensual: {mes} y {fecha_limite} (ultimo dia del mes, America/Caracas) se calculan al abrir; el 10 % es texto editable
        'promocion' => ['clave' => 'whatsapp_cobranza_promocion', 'etiqueta' => 'Promoción del mes', 'variables' => ['nombre', 'saldo', 'mes', 'fecha_limite'],
            'texto' => "¡Hola, {nombre}! 👋 Te saludamos de parte del equipo de OnBike Margarita.

Este mes tenemos un beneficio para ti: 10% de descuento sobre tu saldo pendiente de {saldo} si lo cancelas en su totalidad hasta el {fecha_limite}. 🚴‍♂️✨

Puedes pagar en la modalidad que prefieras:
💵 Divisas (Efectivo, Zelle, Binance).
🇻🇪 Bolívares (calculados a la tasa del Euro).
Para activar tu descuento, escríbenos por aquí y coordinamos tu pago. ¡Estamos a la orden! 📲"],
        'libre' => ['clave' => 'whatsapp_cobranza_libre', 'etiqueta' => 'Mensaje libre', 'variables' => ['nombre'],
            'texto' => 'Hola {nombre} 👋 '],
    ];

    private static function definicion(string $campo, string $espacio): array
    {
        return $espacio === 'cobranza' ? self::COBRANZA[$campo] : self::CAMPOS[$campo];
    }

    public static function defecto(string $campo, string $espacio = 'taller'): string
    {
        $d = self::definicion($campo, $espacio);

        return $espacio === 'cobranza' ? $d['texto'] : (string) config($d['defecto']);
    }

    public static function texto(string $campo, string $espacio = 'taller'): string
    {
        $guardado = Configuracion::valorDe(self::definicion($campo, $espacio)['clave']);

        return filled($guardado) ? $guardado : self::defecto($campo, $espacio);
    }

    // Reemplaza {variables}: un valor null quita la linea COMPLETA que lo lleva y cualquier {x} que
    // quede sin reemplazar se elimina, asi un mensaje nunca sale con llaves.
    public static function armar(string $plantilla, array $valores): string
    {
        $faltantes = collect($valores)->filter(fn ($v) => $v === null)->keys()->map(fn ($k) => '{'.$k.'}')->all();
        $texto = strtr(self::sinLineasCon($plantilla, $faltantes), collect($valores)->reject(fn ($v) => $v === null)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => $v])->all());

        return preg_replace('/\{[^{}]*\}/', '', $texto);
    }

    // mensaje de error si el texto usa una variable que ese campo no admite; null si esta bien.
    // {monto} solo existe en "Unidad lista": en las demas plantillas no hay cifras
    public static function errorDeVariables(string $campo, string $texto, string $espacio = 'taller'): ?string
    {
        $d = self::definicion($campo, $espacio);
        $validas = $d['variables'];
        preg_match_all('/\{([^{}]*)\}/', $texto, $coincidencias);

        foreach (array_unique($coincidencias[1]) as $variable) {
            if (in_array($variable, $validas, true)) {
                continue;
            }
            if ($variable === 'monto' && $espacio === 'taller') {
                return '{monto} solo se permite en «Unidad lista» (por cobrar y ya pagado); quítalo de «'.$d['etiqueta'].'».';
            }

            return "La variable {{$variable}} no es válida en «".$d['etiqueta'].'». Las válidas son: '.implode(', ', array_map(fn ($v) => '{'.$v.'}', $validas)).'.';
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
