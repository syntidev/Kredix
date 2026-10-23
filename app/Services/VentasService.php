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

    // ventas individuales por tanda ("Ver mas")
    public const LINEAS_POR_PAGINA = 50;

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
                'ventas' => $a['ventas'],
                'ultima' => $a['ultima'],
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
            'dias' => $this->subtotalesPorDia($inicio, $fin),
            ...$this->lineasPagina($inicio, $fin, 0),
        ];
    }

    /**
     * Una pagina de ventas individuales (sin agrupar), de la mas reciente a la mas antigua:
     * credito por fecha del cargo, taller por una linea por repuesto del ticket atendido.
     * Orden total (dia, hora, origen, id) para que paginar no duplique ni pierda filas.
     *
     * @return array{lineas: list<array<string, mixed>>, hay_mas: bool}
     */
    public function lineasPagina(string $inicio, string $fin, int $offset): array
    {
        $filas = DB::query()->fromSub($this->lineasUnidas($inicio, $fin), 'v')
            ->orderByDesc('dia')->orderByDesc('orden')->orderBy('origen')->orderByDesc('ref')
            ->offset($offset)->limit(self::LINEAS_POR_PAGINA + 1)
            ->get();

        return [
            'lineas' => $filas->take(self::LINEAS_POR_PAGINA)->map(fn ($f) => [
                'dia' => substr((string) $f->dia, 0, 10),
                'origen' => $f->origen,
                'nombre' => Producto::normalizarNombre((string) $f->nombre),
                'cantidad' => round((float) $f->cantidad, 2),
                'monto' => round((float) $f->monto, 2),
                'cliente' => $f->cliente,
                'ticket_id' => $f->ticket_id !== null ? (int) $f->ticket_id : null,
                'aprox' => (bool) $f->aprox,
            ])->values()->all(),
            'hay_mas' => $filas->count() > self::LINEAS_POR_PAGINA,
        ];
    }

    /**
     * Subtotal y cantidad de ventas de CADA dia del rango, calculados en SQL sobre todo el rango
     * (no sobre la pagina cargada). Suma credito + repuestos; el servicio del taller no es una linea.
     *
     * @return list<array{dia: string, ventas: int, monto: float}>
     */
    private function subtotalesPorDia(string $inicio, string $fin): array
    {
        return DB::query()->fromSub($this->lineasUnidas($inicio, $fin), 'v')
            ->selectRaw('dia, COUNT(*) as ventas, SUM(monto) as monto')
            ->groupBy('dia')->orderByDesc('dia')
            ->get()
            ->map(fn ($f) => ['dia' => substr((string) $f->dia, 0, 10), 'ventas' => (int) $f->ventas, 'monto' => round((float) $f->monto, 2)])
            ->all();
    }

    // mismas reglas que los agregados: credito sin cargo automatico de taller ni mora ni sin fecha;
    // taller solo tickets atendidos, y sin repuestos de precio 0 (trabajos de la tienda)
    private function lineasUnidas(string $inicio, string $fin)
    {
        $credito = $this->cargosDeVenta()
            ->whereNotNull('fecha')
            ->whereBetween('fecha', [$inicio, $fin])
            ->selectRaw("'tienda' as origen, DATE(movimientos_cuenta.fecha) as dia, movimientos_cuenta.created_at as orden, movimientos_cuenta.id as ref, movimientos_cuenta.descripcion as nombre, COALESCE(movimientos_cuenta.cantidad, 0) as cantidad, movimientos_cuenta.monto as monto, (select c.nombre from clientes c where c.id = movimientos_cuenta.cliente_id) as cliente, NULL as ticket_id, 0 as aprox")
            ->toBase();

        $taller = DB::table('ticket_repuestos as r')
            ->join('tickets_taller as t', 't.id', '=', 'r.ticket_id')
            ->whereNull('t.deleted_at')
            ->where('t.estado', 'atendido')
            ->whereBetween('t.atendido_en', [$inicio, $fin])
            ->whereRaw('r.cantidad * r.precio > 0')
            ->selectRaw("'taller' as origen, DATE(t.atendido_en) as dia, t.atendido_en as orden, r.id as ref, r.producto as nombre, r.cantidad as cantidad, r.cantidad * r.precio as monto, (select c.nombre from clientes c where c.id = t.cliente_id) as cliente, t.id as ticket_id, COALESCE(t.atendido_en_aprox, 0) as aprox");

        return $credito->unionAll($taller);
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
            ->selectRaw('descripcion as nombre, SUM(COALESCE(cantidad, 0)) as cantidad, SUM(monto) as monto, COUNT(*) as ventas, MAX(fecha) as ultima')
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
            ->selectRaw('r.producto as nombre, SUM(r.cantidad) as cantidad, SUM(r.cantidad * r.precio) as monto, COUNT(*) as ventas, MAX(t.atendido_en) as ultima')
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
                'ventas' => (int) $grupo->sum(fn ($l) => (int) $l->ventas),
                'ultima' => substr((string) $grupo->max('ultima'), 0, 10),
            ])
            ->values();
    }

    private function aCentavos(string|int|float|null $monto): int
    {
        return (int) round(((float) $monto) * 100);
    }
}
