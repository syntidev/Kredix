<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class KpiController extends Controller
{
    // piso donde empieza el volumen real del negocio -- sin techo de año, asi el
    // sistema sigue funcionando correcto en 2027, 2028, etc. sin tocar este filtro
    private const PISO_FECHA_KPI = '2025-01-01';

    public function index()
    {
        $hoy = now();
        $inicioMes = $hoy->copy()->startOfMonth();
        $finMes = $hoy->copy()->endOfMonth();
        $inicioMesAnterior = $hoy->copy()->subMonthNoOverflow()->startOfMonth();
        $finMesAnterior = $hoy->copy()->subMonthNoOverflow()->endOfMonth();
        $inicioSemana = $hoy->copy()->startOfWeek(Carbon::MONDAY);

        $todos = MovimientoCuenta::orderBy('fecha')->get();
        $porCliente = $todos->groupBy('cliente_id');
        // saldo/totales historicos usan $todos completo (el dinero no deja de existir
        // por no tener fecha); solo las metricas por rango de fecha excluyen los
        // movimientos sin fecha -- no cuentan como "hoy" ni rompen el filtro
        $todosConFecha = $todos->whereNotNull('fecha');

        $saldoDe = fn ($movs) => (float) $movs->sum(fn (MovimientoCuenta $m) => match ($m->tipo) {
            'cargo' => (float) $m->monto,
            'abono', 'ajuste_devolucion' => -(float) $m->monto,
            default => 0,
        });

        // 1. dinero en calle: suma de saldo pendiente de toda la cartera
        $dineroEnCalle = $porCliente->sum($saldoDe);

        // 2. KPI segmentado por periodo -- solo movimientos desde PISO_FECHA_KPI en
        // adelante (asi los 12 movimientos aislados pre-2025 y los sin fecha nunca
        // entran a ninguna de estas 4 secciones, permanentemente, sin techo de año)
        $movsDesdePiso = $todosConFecha->filter(fn (MovimientoCuenta $m) => $m->fecha->gte(self::PISO_FECHA_KPI));

        // el periodo anterior siempre corta en el mismo numero de dias
        // transcurridos del periodo actual (no el periodo anterior completo) --
        // asi "mes actual" (parcial) se compara contra un "mes anterior" igual de
        // parcial, nunca contra el mes completo
        $finMesAnterior = $this->finEquivalente($inicioMesAnterior, $inicioMes, $hoy);

        $inicioTrimestre = $hoy->copy()->startOfQuarter();
        $finTrimestre = $hoy->copy()->endOfQuarter();
        $inicioTrimestreAnterior = $hoy->copy()->subMonthsNoOverflow(3)->startOfQuarter();
        $finTrimestreAnterior = $this->finEquivalente($inicioTrimestreAnterior, $inicioTrimestre, $hoy);

        $inicioAnio = $hoy->copy()->startOfYear();
        $inicioAnioAnterior = $hoy->copy()->subYearNoOverflow()->startOfYear();
        $finAnioAnterior = $this->finEquivalente($inicioAnioAnterior, $inicioAnio, $hoy);

        $resumenMes = $this->resumenPeriodo($movsDesdePiso, $inicioMes, $finMes);
        $resumenMesAnterior = $this->resumenPeriodo($movsDesdePiso, $inicioMesAnterior, $finMesAnterior);
        $resumenTrimestre = $this->resumenPeriodo($movsDesdePiso, $inicioTrimestre, $finTrimestre);
        $resumenTrimestreAnterior = $this->resumenPeriodo($movsDesdePiso, $inicioTrimestreAnterior, $finTrimestreAnterior);
        $resumenAnio = $this->resumenPeriodo($movsDesdePiso, $inicioAnio, $hoy);
        $resumenAnioAnterior = $this->resumenPeriodo($movsDesdePiso, $inicioAnioAnterior, $finAnioAnterior);
        $resumenHistorico = [
            'otorgado' => (float) $movsDesdePiso->where('tipo', 'cargo')->sum('monto'),
            'cobrado' => (float) $movsDesdePiso->where('tipo', 'abono')->sum('monto'),
        ];
        $resumenHistorico['neto'] = $resumenHistorico['cobrado'] - $resumenHistorico['otorgado'];

        $periodos = [
            'mes' => [
                'etiqueta' => 'Este mes',
                'etiquetaAnterior' => 'Mes anterior',
                'actual' => $resumenMes,
                'anterior' => $resumenMesAnterior,
                'variacion' => $this->variacionPorcentaje($resumenMes['cobrado'], $resumenMesAnterior['cobrado']),
            ],
            'trimestre' => [
                'etiqueta' => 'Este trimestre',
                'etiquetaAnterior' => 'Trimestre anterior',
                'actual' => $resumenTrimestre,
                'anterior' => $resumenTrimestreAnterior,
                'variacion' => $this->variacionPorcentaje($resumenTrimestre['cobrado'], $resumenTrimestreAnterior['cobrado']),
            ],
            'anio' => [
                'etiqueta' => 'Este año (YTD)',
                'etiquetaAnterior' => 'Mismo periodo año anterior',
                'actual' => $resumenAnio,
                'anterior' => $resumenAnioAnterior,
                'variacion' => $this->variacionPorcentaje($resumenAnio['cobrado'], $resumenAnioAnterior['cobrado']),
            ],
            'historico' => [
                'etiqueta' => 'Historico (desde '.self::PISO_FECHA_KPI.')',
                'etiquetaAnterior' => null,
                'actual' => $resumenHistorico,
                'anterior' => null,
                'variacion' => null,
            ],
        ];

        // 3. ultimas 4 semanas rodantes (no mes calendario): otorgado vs cobrado
        $movimientosMes = $todosConFecha->filter(fn (MovimientoCuenta $m) => $m->fecha->between($inicioMes, $finMes));

        $semanasDelMes = collect(range(3, 0))->map(function (int $i) use ($hoy, $todosConFecha) {
            $inicioSemana = $hoy->copy()->subWeeks($i)->startOfWeek(Carbon::MONDAY);
            $finSemana = $inicioSemana->copy()->endOfWeek(Carbon::SUNDAY);
            $movs = $todosConFecha->filter(fn (MovimientoCuenta $m) => $m->fecha->between($inicioSemana, $finSemana));

            return [
                'semana' => 'Sem '.$inicioSemana->format('d/m'),
                'otorgado' => (float) $movs->where('tipo', 'cargo')->sum('monto'),
                'cobrado' => (float) $movs->where('tipo', 'abono')->sum('monto'),
            ];
        })->values();

        // 4. actividad por cobrador (mes actual)
        $actividadCobradores = User::all()
            ->map(function (User $u) use ($movimientosMes, $inicioSemana) {
                $abonosUsuario = $movimientosMes->where('registrado_por', $u->id)->where('tipo', 'abono');
                $gestionesUsuario = $movimientosMes->where('registrado_por', $u->id)->where('tipo', 'gestion');

                return [
                    'nombre' => $u->name,
                    'abonos_count' => $abonosUsuario->count(),
                    'abonos_monto' => (float) $abonosUsuario->sum('monto'),
                    'gestiones_count' => $gestionesUsuario->count(),
                    'gestiones_semana' => $gestionesUsuario->filter(fn (MovimientoCuenta $m) => $m->fecha->gte($inicioSemana))->count(),
                ];
            })
            ->filter(fn (array $a) => $a['abonos_count'] > 0 || $a['gestiones_count'] > 0)
            ->values();

        // 5. antiguedad de cartera: saldo pendiente agrupado por dias desde el ultimo abono
        $rangos = ['0-15' => 0.0, '16-30' => 0.0, '31-60' => 0.0, '60+' => 0.0];

        foreach (Cliente::all() as $cliente) {
            $movs = $porCliente->get($cliente->id, collect());
            $saldo = $saldoDe($movs);

            if ($saldo <= 0) {
                continue;
            }

            $ultimoAbono = $movs->where('tipo', 'abono')->whereNotNull('fecha')->last();
            $referencia = $ultimoAbono?->fecha ?? $movs->where('tipo', 'cargo')->whereNotNull('fecha')->first()?->fecha;
            if ($referencia === null) {
                // sin ningun movimiento con fecha real no hay forma de saber la
                // antiguedad -- se excluye del reparto por rango en vez de contarlo
                // como "hoy" (que lo mostraria falsamente como cartera fresca)
                continue;
            }
            $dias = $hoy->copy()->startOfDay()->diffInDays($referencia, true);

            $rango = match (true) {
                $dias <= 15 => '0-15',
                $dias <= 30 => '16-30',
                $dias <= 60 => '31-60',
                default => '60+',
            };

            $rangos[$rango] += $saldo;
        }

        // 6. ultimos 6 meses: otorgado (cargos) vs cobrado (abonos), meses sin
        // actividad quedan en 0 en vez de ausentes
        $nombresMes = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

        $ultimos6Meses = collect(range(5, 0))->map(function (int $i) use ($hoy, $todosConFecha, $nombresMes) {
            $mes = $hoy->copy()->subMonthsNoOverflow($i);
            $movs = $todosConFecha->filter(fn (MovimientoCuenta $m) => $m->fecha->between($mes->copy()->startOfMonth(), $mes->copy()->endOfMonth()));

            return [
                'mes' => $nombresMes[$mes->month - 1].' '.$mes->format('y'),
                'otorgado' => (float) $movs->where('tipo', 'cargo')->sum('monto'),
                'cobrado' => (float) $movs->where('tipo', 'abono')->sum('monto'),
            ];
        })->values();

        return Inertia::render('Kpi/Index', [
            'dineroEnCalle' => $dineroEnCalle,
            'periodos' => $periodos,
            'semanasDelMes' => $semanasDelMes,
            'ultimos6Meses' => $ultimos6Meses,
            'actividadCobradores' => $actividadCobradores,
            'antiguedadCartera' => $rangos,
        ]);
    }

    /**
     * Otorgado (cargos), cobrado (abonos) y neto (cobrado - otorgado) de $movs
     * dentro de [$desde, $hasta].
     */
    private function resumenPeriodo($movs, $desde, $hasta): array
    {
        $enRango = $movs->filter(fn (MovimientoCuenta $m) => $m->fecha->between($desde, $hasta));
        $otorgado = (float) $enRango->where('tipo', 'cargo')->sum('monto');
        $cobrado = (float) $enRango->where('tipo', 'abono')->sum('monto');

        return ['otorgado' => $otorgado, 'cobrado' => $cobrado, 'neto' => $cobrado - $otorgado];
    }

    private function variacionPorcentaje(float $actual, float $anterior): ?float
    {
        return $anterior > 0 ? round((($actual - $anterior) / $anterior) * 100, 1) : null;
    }

    /**
     * Corta el periodo anterior en el mismo numero de dias transcurridos del
     * periodo actual -- "mes actual" parcial se compara contra "mes anterior"
     * igual de parcial, nunca contra el periodo anterior completo.
     */
    private function finEquivalente(Carbon $inicioAnterior, Carbon $inicioActual, Carbon $hoy): Carbon
    {
        $diasTranscurridos = $inicioActual->diffInDays($hoy);

        return $inicioAnterior->copy()->addDays($diasTranscurridos)->endOfDay();
    }
}
