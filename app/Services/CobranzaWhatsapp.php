<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\MovimientoCuenta;
use App\Models\Producto;
use App\Services\Taller\PlantillasWhatsapp;
use App\Services\Taller\PresupuestoWhatsapp;
use Illuminate\Support\Carbon;

// Mensajes de WhatsApp de cobranza para la ficha del cliente, con el mismo motor del Taller
// (PlantillasWhatsapp, espacio "cobranza"). Abono y saldo son SIEMPRE los de la cuenta del cliente
// (MovimientoCuenta::totalCobrado / saldoPendiente, las mismas funciones de la ficha), nunca por compra.
class CobranzaWhatsapp
{
    private const ESPACIO = 'cobranza';

    public static function tipos(): array
    {
        return array_keys(PlantillasWhatsapp::COBRANZA);
    }

    /**
     * @return array{tiene_telefono: bool, preseleccion: string, tipos: list<array{tipo: string, etiqueta: string, habilitado: bool, pista: ?string, texto: string}>}
     */
    public static function paraCliente(Cliente $cliente): array
    {
        $telefono = preg_replace('/\D/', '', (string) $cliente->telefono);
        $cuota = self::proximaCuota($cliente);
        $abono = MovimientoCuenta::where('cliente_id', $cliente->id)->where('tipo', 'abono')->where('es_contado', false)
            ->orderByDesc('fecha')->orderByDesc('id')->first();
        $compras = MovimientoCuenta::where('cliente_id', $cliente->id)->where('tipo', 'cargo')->where('es_contado', false)
            ->orderBy('fecha')->orderBy('id')->get()
            ->map(fn (MovimientoCuenta $c) => '• '.Producto::normalizarNombre((string) $c->descripcion).' — '.self::dinero((float) $c->monto).($c->fecha ? ' ('.$c->fecha->format('d/m').')' : ''));
        $articulos = MovimientoCuenta::where('cliente_id', $cliente->id)->where('tipo', 'cargo')->where('pendiente_entrega', true)
            ->orderBy('fecha')->orderBy('id')->get()
            ->map(fn (MovimientoCuenta $c) => '• '.Producto::normalizarNombre((string) $c->descripcion));

        $base = [
            'nombre' => $cliente->nombre,
            'saldo' => self::dinero(MovimientoCuenta::saldoPendiente($cliente->id)),
        ];
        $hoy = Carbon::now()->startOfDay();
        $vencida = $cuota && $cuota->fecha_vencimiento->lt($hoy);

        $valores = [
            'recordatorio' => $cuota ? [...$base,
                'cuota_fecha' => $cuota->fecha_vencimiento->format('d/m/Y'),
                'cuota_monto' => self::dinero(max(0, (float) $cuota->monto_pactado - (float) $cuota->monto_abonado)),
                'dias_atraso' => $vencida ? 'Lleva '.self::dias((int) $cuota->fecha_vencimiento->diffInDays($hoy)).' de atraso.' : null,
            ] : $base,
            'estado_cuenta' => [...$base, 'total_abonado' => self::dinero(MovimientoCuenta::totalCobrado($cliente->id))],
            'abono_recibido' => $abono ? [...$base, 'abono_monto' => self::dinero((float) $abono->monto), 'abono_fecha' => $abono->fecha?->format('d/m/Y')] : $base,
            'saldo' => $base,
            'por_entregar' => $base,
            'libre' => ['nombre' => $cliente->nombre],
        ];
        $listas = ['estado_cuenta' => ['lista_compras', $compras->all()], 'por_entregar' => ['lista_articulos', $articulos->all()]];
        $pistas = [
            'abono_recibido' => $abono ? null : 'El cliente no tiene abonos registrados.',
            'por_entregar' => $articulos->isEmpty() ? 'El cliente no tiene artículos por entregar.' : null,
        ];

        $tipos = [];
        foreach (self::tipos() as $tipo) {
            // sin cuotas el recordatorio cae al texto del saldo
            $plantilla = PlantillasWhatsapp::texto($tipo === 'recordatorio' && ! $cuota ? 'saldo' : $tipo, self::ESPACIO);
            $texto = isset($listas[$tipo])
                ? self::conLista($plantilla, $valores[$tipo], $listas[$tipo][0], $listas[$tipo][1], $telefono)
                : PlantillasWhatsapp::armar($plantilla, $valores[$tipo]);

            $tipos[] = [
                'tipo' => $tipo,
                'etiqueta' => PlantillasWhatsapp::COBRANZA[$tipo]['etiqueta'],
                'habilitado' => ($pistas[$tipo] ?? null) === null,
                'pista' => $pistas[$tipo] ?? null,
                'texto' => $texto,
            ];
        }

        return [
            'tiene_telefono' => $telefono !== '',
            'preseleccion' => $vencida ? 'recordatorio' : 'estado_cuenta',
            'tipos' => $tipos,
        ];
    }

    // la cuota pendiente de menor fecha de los planes vivos del cliente
    private static function proximaCuota(Cliente $cliente): ?Cuota
    {
        return Cuota::whereHas('plan', fn ($q) => $q->whereHas('cargo', fn ($c) => $c->where('cliente_id', $cliente->id)))
            ->whereColumn('monto_abonado', '<', 'monto_pactado')
            ->orderBy('fecha_vencimiento')->orderBy('numero')->first();
    }

    // arma el mensaje con una lista y la recorta ("• y N más") hasta que el enlace de WhatsApp quepa en URL_MAX
    private static function conLista(string $plantilla, array $valores, string $clave, array $lineas, string $telefono): string
    {
        $omitidos = 0;
        while (true) {
            $visibles = $omitidos > 0 ? [...array_slice($lineas, 0, count($lineas) - $omitidos), "• y {$omitidos} más"] : $lineas;
            $texto = PlantillasWhatsapp::armar($plantilla, [...$valores, $clave => $lineas === [] ? null : implode("\n", $visibles)]);

            if ($lineas === [] || $omitidos >= count($lineas) || PresupuestoWhatsapp::largoDelEnlace($telefono, $texto) <= PresupuestoWhatsapp::URL_MAX) {
                return $texto;
            }
            $omitidos++;
        }
    }

    private static function dias(int $n): string
    {
        return $n.($n === 1 ? ' día' : ' días');
    }

    private static function dinero(float $monto): string
    {
        return '$'.number_format($monto, 2);
    }
}
