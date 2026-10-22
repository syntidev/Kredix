<?php

namespace App\Services;

use App\Models\MovimientoCuenta;
use App\Models\Producto;
use App\Models\TicketTaller;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que se VENDIO en un rango de fechas (no lo cobrado), en USD:
 * - credito (tienda): cargos con fecha en el rango.
 * - taller: tickets atendidos con atendido_en en el rango; servicio (+ armado) aparte de repuestos.
 *
 * Los agregados se calculan en SQL (un query por origen), no en memoria.
 */
class VentasService
{
    public const RANGOS = ['hoy', '7_dias', '14_dias', 'mes', 'libre'];

    public const ORIGEN_TIENDA = 'tienda';

    public const ORIGEN_TALLER = 'taller';

    // los cargos de mora (PlanFinanciamientoController::aplicarMora) no tienen marca
    // estructural: se reconocen por su descripcion "Mora (n%) - ..." y por no venir
    // de una compra del formulario (esas siempre llevan compra_id)
    private const PREFIJO_MORA = 'Mora (%';

    /**
     * Fechas del rango, en la zona horaria de la app; ambos extremos inclusivos.
     * 'libre' exige $desde y $hasta.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolverRango(string $rango, ?string $desde = null, ?string $hasta = null): array
    {
        $hoy = Carbon::today();

        return match ($rango) {
            '7_dias' => [$hoy->copy()->subDays(6), $hoy],
            '14_dias' => [$hoy->copy()->subDays(13), $hoy],
            'mes' => [$hoy->copy()->startOfMonth(), $hoy],
            'libre' => [Carbon::parse($desde)->startOfDay(), Carbon::parse($hasta)->startOfDay()],
            default => [$hoy, $hoy],
        };
    }

    /**
     * @return array{
     *   desde: string, hasta: string,
     *   credito: float, taller_servicio: float, taller_repuestos: float, total: float,
     *   tickets_atendidos: int, tickets_fecha_aprox: int, cargos_sin_fecha: int,
     *   articulos: list<array{nombre: string, origen: string, cantidad: float, monto: float}>
     * }
     */
    public function resumen(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $inicio = $desde->copy()->startOfDay()->toDateTimeString();
        $fin = $hasta->copy()->endOfDay()->toDateTimeString();

        $articulosTienda = $this->agrupar($this->lineasCredito($inicio, $fin), self::ORIGEN_TIENDA);
        $articulosTaller = $this->agrupar($this->lineasRepuestos($inicio, $fin), self::ORIGEN_TALLER)
            // trabajos de la tienda (ticket sin cliente) llevan repuestos con precio 0: no aportan
            ->filter(fn (array $a) => $a['centavos'] > 0);

        $servicio = $this->servicioTaller($inicio, $fin);

        $creditoCent = (int) $articulosTienda->sum('centavos');
        $repuestosCent = (int) $articulosTaller->sum('centavos');

        $articulos = $articulosTienda->concat($articulosTaller)
            ->sort(fn (array $a, array $b) => [$b['centavos'], $a['nombre'], $a['origen']] <=> [$a['centavos'], $b['nombre'], $b['origen']])
            ->map(fn (array $a) => [
                'nombre' => $a['nombre'],
                'origen' => $a['origen'],
                'cantidad' => round($a['cantidad'], 2),
                'monto' => round($a['centavos'] / 100, 2),
            ])
            ->values()
            ->all();

        return [
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'credito' => round($creditoCent / 100, 2),
            'taller_servicio' => round($servicio['centavos'] / 100, 2),
            'taller_repuestos' => round($repuestosCent / 100, 2),
            'total' => round(($creditoCent + $servicio['centavos'] + $repuestosCent) / 100, 2),
            'tickets_atendidos' => $servicio['tickets'],
            'tickets_fecha_aprox' => $servicio['aprox'],
            'cargos_sin_fecha' => $this->cargosSinFecha(),
            'articulos' => $articulos,
        ];
    }

    /**
     * Cargos de credito que SI son venta de la tienda: sin los cargos automaticos de
     * taller (ya se cuentan por el lado del taller) y sin mora. Soft-deleted queda
     * fuera por el scope del modelo.
     */
    private function cargosDeVenta()
    {
        return MovimientoCuenta::query()
            ->where('tipo', 'cargo')
            ->whereNotIn('id', DB::table('tickets_taller')->whereNotNull('movimiento_cuenta_id')->select('movimiento_cuenta_id'))
            ->whereNot(fn ($q) => $q->whereNull('compra_id')->where('descripcion', 'like', self::PREFIJO_MORA));
    }

    private function lineasCredito(string $inicio, string $fin): Collection
    {
        return $this->cargosDeVenta()
            ->whereNotNull('fecha')
            ->whereBetween('fecha', [$inicio, $fin])
            ->groupBy('descripcion')
            ->selectRaw('descripcion as nombre, SUM(COALESCE(cantidad, 0)) as cantidad, SUM(monto) as monto')
            ->toBase()
            ->get();
    }

    private function lineasRepuestos(string $inicio, string $fin): Collection
    {
        return DB::table('ticket_repuestos as r')
            ->join('tickets_taller as t', 't.id', '=', 'r.ticket_id')
            ->whereNull('t.deleted_at')
            ->where('t.estado', 'atendido')
            ->whereBetween('t.atendido_en', [$inicio, $fin])
            ->groupBy('r.producto')
            ->selectRaw('r.producto as nombre, SUM(r.cantidad) as cantidad, SUM(r.cantidad * r.precio) as monto')
            ->get();
    }

    /**
     * @return array{tickets: int, aprox: int, centavos: int}
     */
    private function servicioTaller(string $inicio, string $fin): array
    {
        $fila = TicketTaller::query()
            ->where('estado', 'atendido')
            ->whereBetween('atendido_en', [$inicio, $fin])
            ->selectRaw('COUNT(*) as tickets, COALESCE(SUM(CASE WHEN atendido_en_aprox = 1 THEN 1 ELSE 0 END), 0) as aprox, COALESCE(SUM(COALESCE(monto_servicio, 0) + COALESCE(monto_armado, 0)), 0) as servicio')
            ->toBase()
            ->first();

        return ['tickets' => (int) $fila->tickets, 'aprox' => (int) $fila->aprox, 'centavos' => $this->aCentavos($fila->servicio)];
    }

    // cargos de credito sin fecha: no se pueden ubicar en ningun rango, se cuentan para avisar
    private function cargosSinFecha(): int
    {
        return $this->cargosDeVenta()->whereNull('fecha')->count();
    }

    /**
     * Junta nombres que solo difieren en mayusculas/espacios (Producto::normalizarNombre).
     * Dentro de un origen; el mismo nombre en dos origenes queda en dos filas.
     */
    private function agrupar(Collection $lineas, string $origen): Collection
    {
        return $lineas
            ->groupBy(fn ($l) => Producto::normalizarNombre((string) $l->nombre))
            ->map(fn (Collection $grupo, string $nombre) => [
                'nombre' => $nombre,
                'origen' => $origen,
                'cantidad' => (float) $grupo->sum(fn ($l) => (float) $l->cantidad),
                'centavos' => (int) $grupo->sum(fn ($l) => $this->aCentavos($l->monto)),
            ])
            ->values();
    }

    private function aCentavos(string|int|float|null $monto): int
    {
        return (int) round(((float) $monto) * 100);
    }
}
