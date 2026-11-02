<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\User;
use App\Services\Taller\AjustesTaller;
use App\Services\Taller\ComisionesTaller;
use App\Services\VentasService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class KpiController extends Controller
{
    // piso donde empieza el volumen real del negocio -- sin techo de año, asi el
    // sistema sigue funcionando correcto en 2027, 2028, etc. sin tocar este filtro
    private const PISO_FECHA_KPI = '2025-01-01';

    public function index(Request $request)
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

        // 1. dinero en calle: suma de saldo pendiente de toda la cartera --
        // excluye clientes soft-deleted (mismo scope que Cliente::all() usa
        // ClienteController::clientesConMetricasCartera() para Cartera/export);
        // antes sumaba sobre $porCliente completo e incluia deuda de clientes
        // borrados, dando una cifra distinta a la de Cartera para "cuanto se debe"
        // filter(), no only() -- $porCliente es Eloquent\Collection (hereda el
        // tipo via groupBy() sobre el resultado de MovimientoCuenta::get()), y
        // el only() de Eloquent\Collection espera modelos con getKey(), no
        // grupos; aqui las claves son cliente_id simples, filter() es generico
        $idsClientesActivos = Cliente::pluck('id')->all();
        $dineroEnCalle = $porCliente->filter(fn ($movs, $clienteId) => in_array($clienteId, $idsClientesActivos, true))->sum($saldoDe);

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

        // 7. salud de cartera: 3 buckets sobre clientes con saldo activo, una sola
        // base de "dias sin actividad" (ultimo abono o gestion, con fallback al
        // primer cargo si nunca hubo ninguno) para los 3 -- mismo criterio que
        // CarteleraController usa para cartera_fria (60+ dias sin NINGUN
        // movimiento). Usar una sola base evita que un cliente quede sin bucket
        // (lo que rompería la suma == 100% de clientes con saldo)
        $saludCartera = ['al_dia' => 0, 'atrasados' => 0, 'fria' => 0];

        foreach (Cliente::all() as $cliente) {
            $movs = $porCliente->get($cliente->id, collect());
            $saldo = $saldoDe($movs);

            if ($saldo <= 0) {
                continue;
            }

            $ultimaActividad = $movs->whereIn('tipo', ['abono', 'gestion'])->whereNotNull('fecha')->sortByDesc('fecha')->first();
            $primerCargo = $movs->where('tipo', 'cargo')->whereNotNull('fecha')->sortBy('fecha')->first();
            $fechaReferencia = $ultimaActividad?->fecha ?? $primerCargo?->fecha;

            if ($fechaReferencia === null) {
                // sin ninguna fecha real conocida -- se trata como el caso mas
                // urgente, nunca como "al dia" por falta de dato
                $saludCartera['fria']++;

                continue;
            }

            $dias = $hoy->copy()->startOfDay()->diffInDays($fechaReferencia, true);

            if ($dias <= 15) {
                $saludCartera['al_dia']++;
            } elseif ($dias < 60) {
                $saludCartera['atrasados']++;
            } else {
                $saludCartera['fria']++;
            }
        }

        // 8. crecimiento de clientes: nuevos este mes vs total acumulado hasta el
        // mes anterior (no vs "nuevos del mes anterior" -- la base de comparacion
        // es el tamaño de la cartera de clientes, no el ritmo de altas)
        $clientesNuevosEsteMes = Cliente::whereBetween('created_at', [$inicioMes, $finMes])->count();
        $totalClientesMesAnterior = Cliente::where('created_at', '<', $inicioMes)->count();
        $crecimientoClientesPct = $totalClientesMesAnterior > 0
            ? round(($clientesNuevosEsteMes / $totalClientesMesAnterior) * 100, 1)
            : null;

        return Inertia::render('Kpi/Index', [
            'dineroEnCalle' => $dineroEnCalle,
            'periodos' => $periodos,
            'semanasDelMes' => $semanasDelMes,
            'ultimos6Meses' => $ultimos6Meses,
            'actividadCobradores' => $actividadCobradores,
            'antiguedadCartera' => $rangos,
            'saludCartera' => $saludCartera,
            'totalClientesActivos' => Cliente::count(),
            'clientesNuevosEsteMes' => $clientesNuevosEsteMes,
            'crecimientoClientesPct' => $crecimientoClientesPct,
            'kpiTaller' => $this->kpiTaller($request),
        ]);
    }

    /**
     * Comisiones del taller por tecnico y rango (ComisionesTaller). Rango: mismos presets
     * que Ventas (VentasService::resolverRango, zona America/Caracas); por defecto el mes.
     * Una query string invalida (rango desconocido, fechas libres incompletas) cae al
     * mes en vez de romper el KPI y devuelve el mensaje en 'error'.
     */
    private function kpiTaller(Request $request): array
    {
        $ventas = app(VentasService::class);
        $rango = $request->query('rango_taller', 'mes');
        $error = null;

        $validador = Validator::make($request->query(), [
            'rango_taller' => ['nullable', Rule::in(VentasService::RANGOS)],
            'desde_taller' => $rango === 'libre' ? ['required', 'date_format:Y-m-d'] : ['nullable'],
            'hasta_taller' => $rango === 'libre' ? ['required', 'date_format:Y-m-d', 'after_or_equal:desde_taller'] : ['nullable'],
        ], [
            'rango_taller.in' => 'rango no valido',
            'desde_taller.required' => 'indica la fecha desde',
            'hasta_taller.required' => 'indica la fecha hasta',
            'hasta_taller.after_or_equal' => 'la fecha hasta no puede ser anterior a la fecha desde',
            '*.date_format' => 'fecha no valida',
        ]);

        if ($validador->fails()) {
            $error = $validador->errors()->first();
            $rango = 'mes';
        }

        [$desde, $hasta] = $ventas->resolverRango($rango, $request->query('desde_taller'), $request->query('hasta_taller'));

        return [
            ...ComisionesTaller::calcular($desde, $hasta, AjustesTaller::comisionServicioPct(), AjustesTaller::comisionRepuestosPct()),
            'rango' => $rango,
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'error' => $error,
        ];
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
