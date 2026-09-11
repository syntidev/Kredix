<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class KpiController extends Controller
{
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

        // 2. recuperado mes actual vs mes anterior
        $recuperadoMesActual = (float) $todosConFecha->filter(
            fn (MovimientoCuenta $m) => $m->tipo === 'abono' && $m->fecha->between($inicioMes, $finMes)
        )->sum('monto');

        $recuperadoMesAnterior = (float) $todosConFecha->filter(
            fn (MovimientoCuenta $m) => $m->tipo === 'abono' && $m->fecha->between($inicioMesAnterior, $finMesAnterior)
        )->sum('monto');

        $cambioPorcentaje = $recuperadoMesAnterior > 0
            ? round((($recuperadoMesActual - $recuperadoMesAnterior) / $recuperadoMesAnterior) * 100, 1)
            : null;

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

        // 7. totales acumulados desde el inicio del sistema (sin filtro de fecha)
        $totalOtorgadoHistorico = (float) $todos->where('tipo', 'cargo')->sum('monto');
        $totalCobradoHistorico = (float) $todos->where('tipo', 'abono')->sum('monto');

        return Inertia::render('Kpi/Index', [
            'dineroEnCalle' => $dineroEnCalle,
            'recuperadoMesActual' => $recuperadoMesActual,
            'recuperadoMesAnterior' => $recuperadoMesAnterior,
            'cambioPorcentaje' => $cambioPorcentaje,
            'semanasDelMes' => $semanasDelMes,
            'ultimos6Meses' => $ultimos6Meses,
            'totalOtorgadoHistorico' => $totalOtorgadoHistorico,
            'totalCobradoHistorico' => $totalCobradoHistorico,
            'actividadCobradores' => $actividadCobradores,
            'antiguedadCartera' => $rangos,
        ]);
    }
}
