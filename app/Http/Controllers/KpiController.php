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

        $saldoDe = fn ($movs) => (float) $movs->sum(fn (MovimientoCuenta $m) => match ($m->tipo) {
            'cargo' => (float) $m->monto,
            'abono', 'ajuste_devolucion' => -(float) $m->monto,
            default => 0,
        });

        // 1. dinero en calle: suma de saldo pendiente de toda la cartera
        $dineroEnCalle = $porCliente->sum($saldoDe);

        // 2. recuperado mes actual vs mes anterior
        $recuperadoMesActual = (float) $todos->filter(
            fn (MovimientoCuenta $m) => $m->tipo === 'abono' && $m->fecha->between($inicioMes, $finMes)
        )->sum('monto');

        $recuperadoMesAnterior = (float) $todos->filter(
            fn (MovimientoCuenta $m) => $m->tipo === 'abono' && $m->fecha->between($inicioMesAnterior, $finMesAnterior)
        )->sum('monto');

        $cambioPorcentaje = $recuperadoMesAnterior > 0
            ? round((($recuperadoMesActual - $recuperadoMesAnterior) / $recuperadoMesAnterior) * 100, 1)
            : null;

        // 3. semanas del mes actual: otorgado (cargos) vs cobrado (abonos)
        $movimientosMes = $todos->filter(fn (MovimientoCuenta $m) => $m->fecha->between($inicioMes, $finMes));

        $semanasDelMes = $movimientosMes
            ->groupBy(fn (MovimientoCuenta $m) => $m->fecha->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d'))
            ->sortKeys()
            ->map(fn ($movs, $inicioSemanaKey) => [
                'semana' => 'Sem '.Carbon::parse($inicioSemanaKey)->format('d/m'),
                'otorgado' => (float) $movs->where('tipo', 'cargo')->sum('monto'),
                'cobrado' => (float) $movs->where('tipo', 'abono')->sum('monto'),
            ])
            ->values();

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

            $ultimoAbono = $movs->where('tipo', 'abono')->last();
            $referencia = $ultimoAbono?->fecha ?? $movs->where('tipo', 'cargo')->first()?->fecha ?? $hoy;
            $dias = $hoy->copy()->startOfDay()->diffInDays($referencia, true);

            $rango = match (true) {
                $dias <= 15 => '0-15',
                $dias <= 30 => '16-30',
                $dias <= 60 => '31-60',
                default => '60+',
            };

            $rangos[$rango] += $saldo;
        }

        return Inertia::render('Kpi/Index', [
            'dineroEnCalle' => $dineroEnCalle,
            'recuperadoMesActual' => $recuperadoMesActual,
            'recuperadoMesAnterior' => $recuperadoMesAnterior,
            'cambioPorcentaje' => $cambioPorcentaje,
            'semanasDelMes' => $semanasDelMes,
            'actividadCobradores' => $actividadCobradores,
            'antiguedadCartera' => $rangos,
        ]);
    }
}
