<?php

namespace App\Services\Taller;

use App\Models\TicketTaller;

// Mensaje "Presupuesto de repuestos": arma la lista de repuestos del ticket con el TOTAL de cada
// linea (cantidad x precio unitario), los totales y recorta la lista si el enlace de WhatsApp
// (wa.me/<telefono>?text=<texto codificado>) se pasa del tope.
class PresupuestoWhatsapp
{
    // largo maximo del enlace ya codificado
    public const URL_MAX = 1800;

    private const SIN_PRECIO = 'precio por confirmar';

    /**
     * El texto listo para enviar, o null si el ticket no tiene repuestos (no hay presupuesto que armar).
     *
     * @param  string  $plantilla  texto vigente de la plantilla "presupuesto"
     * @param  array<string, string>  $reemplazosBase  {nombre}, {bici}, {fecha_entrega}
     */
    public static function mensaje(TicketTaller $ticket, string $plantilla, array $reemplazosBase): ?string
    {
        $repuestos = $ticket->repuestos->sortBy('id')->values();

        if ($repuestos->isEmpty()) {
            return null;
        }

        // sin precio = 0: sin cifra el total no se puede afirmar, se quitan sus lineas (el servicio se conserva)
        $sinPrecio = $repuestos->contains(fn ($r) => (float) $r->precio <= 0);
        if ($sinPrecio) {
            $plantilla = PlantillasWhatsapp::sinLineasCon($plantilla, ['{total_repuestos}', '{monto}']);
        }

        $lineas = $repuestos->map(fn ($r) => '• '.$r->producto.' × '.$r->cantidad.' — '
            .((float) $r->precio <= 0 ? self::SIN_PRECIO : self::dinero($r->cantidad * (float) $r->precio)))->all();

        $cifras = [
            '{total_repuestos}' => self::dinero($ticket->totalRepuestos()),
            // el "servicio" de la pantalla del ticket: monto_servicio, sin el armado (que va aparte)
            '{monto_servicio}' => self::dinero((float) $ticket->monto_servicio),
            '{monto}' => self::dinero($ticket->totalTicket()),
        ];
        $telefono = preg_replace('/\D/', '', (string) $ticket->cliente?->telefono);

        $armar = fn (array $visibles, int $omitidos) => strtr($plantilla, [
            ...$reemplazosBase,
            ...$cifras,
            '{lista_repuestos}' => implode("\n", $omitidos > 0
                ? [...$visibles, "• y {$omitidos} más (te enviamos el detalle completo)"]
                : $visibles),
        ]);

        // recorta de a una linea desde el final hasta que el enlace quepa
        $visibles = $lineas;
        $omitidos = 0;
        while (true) {
            $texto = $armar($visibles, $omitidos);

            if (self::largoDelEnlace($telefono, $texto) <= self::URL_MAX || $visibles === []) {
                return $texto;
            }

            array_pop($visibles);
            $omitidos++;
        }
    }

    // mismo formato de dinero que el resto de las plantillas: USD, sin conversion
    private static function dinero(float $monto): string
    {
        return '$'.number_format($monto, 2);
    }

    // largo del enlace tal como lo arma Taller/Show.vue: encodeURIComponent(texto)
    public static function largoDelEnlace(string $telefonoDigitos, string $texto): int
    {
        return strlen("https://wa.me/{$telefonoDigitos}?text=".self::codificar($texto));
    }

    // encodeURIComponent deja sin codificar ! * ' ( ) ademas de lo que deja rawurlencode
    private static function codificar(string $texto): string
    {
        return strtr(rawurlencode($texto), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }
}
