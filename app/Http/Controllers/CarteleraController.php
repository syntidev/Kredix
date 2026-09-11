<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class CarteleraController extends Controller
{
    // Paleta reutilizada del semaforo de antiguedad ya usado en Cartera/Index.vue
    // (no se definio una paleta nueva en el prompt de este sprint).
    private const ROJO = 'rojo';

    private const NARANJA = 'naranja';

    private const VERDE = 'verde';

    private const POR_PAGINA = 20;

    private const DIAS_DEFAULT = 90;

    public function index(Request $request)
    {
        $tarjetas = $this->calcularEventos();

        $tipo = $request->query('tipo', 'todos');
        $severidad = $request->query('severidad'); // null | critico | atencion | informativo
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');
        $historico = $request->boolean('historico');
        $usaDefault = ! $historico && $severidad === null && ! $desde && ! $hasta;

        $colorPorSeveridad = ['critico' => self::ROJO, 'atencion' => self::NARANJA, 'informativo' => self::VERDE];

        // rango de fecha efectivo: explicito (desde/hasta) manda sobre el default de
        // 90 dias; "historico" quita cualquier limite de fecha
        $fechaDesde = $desde ? Carbon::parse($desde)->startOfDay() : ($historico ? null : now()->subDays(self::DIAS_DEFAULT)->startOfDay());
        $fechaHasta = $hasta ? Carbon::parse($hasta)->endOfDay() : null;

        $conDateFiltro = $tarjetas->filter(function ($t) use ($fechaDesde, $fechaHasta) {
            if ($fechaDesde === null && $fechaHasta === null) {
                return true;
            }
            if ($t['fecha_evento'] === null) {
                return false;
            }
            $fecha = Carbon::parse($t['fecha_evento']);

            return (! $fechaDesde || $fecha->gte($fechaDesde)) && (! $fechaHasta || $fecha->lte($fechaHasta));
        })->values();

        // severidad: si el usuario no eligio nada y estamos en el default (sin
        // historico ni fechas explicitas), solo critico+atencion -- si el usuario
        // ya toco cualquier filtro, se respeta exactamente lo que pidio
        $conSeveridadDefault = $usaDefault
            ? $conDateFiltro->filter(fn ($t) => in_array($t['color'], [self::ROJO, self::NARANJA], true))->values()
            : $conDateFiltro;

        // conteos de tipo: sobre el set ya filtrado por fecha+severidad, para que
        // los chips de tipo reflejen "un click de distancia" del estado actual
        $conteosTipo = ['todos' => $conSeveridadDefault->count()];
        foreach (['fuera_patron', 'sin_gestion', 'cuota_vencida', 'promesa_vencida', 'buen_comportamiento', 'cartera_fria'] as $t) {
            $conteosTipo[$t] = $conSeveridadDefault->where('tipo', $t)->count();
        }

        $conTipoFiltro = $tipo === 'todos' ? $conSeveridadDefault : $conSeveridadDefault->where('tipo', $tipo)->values();

        // conteos de severidad: sobre fecha+tipo (sin la severidad seleccionada),
        // usando el set con default de severidad YA quitado para no auto-excluirse
        $baseParaConteoSeveridad = $tipo === 'todos' ? $conDateFiltro : $conDateFiltro->where('tipo', $tipo)->values();
        $conteosSeveridad = [
            'todos' => $baseParaConteoSeveridad->count(),
            'critico' => $baseParaConteoSeveridad->where('color', self::ROJO)->count(),
            'atencion' => $baseParaConteoSeveridad->where('color', self::NARANJA)->count(),
            'informativo' => $baseParaConteoSeveridad->where('color', self::VERDE)->count(),
        ];

        $resultado = $severidad && isset($colorPorSeveridad[$severidad])
            ? $conTipoFiltro->where('color', $colorPorSeveridad[$severidad])->values()
            : $conTipoFiltro;

        $page = (int) $request->query('page', 1);
        $paginador = new LengthAwarePaginator(
            $resultado->forPage($page, self::POR_PAGINA)->values(),
            $resultado->count(),
            self::POR_PAGINA,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return Inertia::render('Cartelera/Index', [
            'tarjetas' => $paginador->toArray(),
            'conteosTipo' => $conteosTipo,
            'conteosSeveridad' => $conteosSeveridad,
            'filtros' => [
                'tipo' => $tipo,
                'severidad' => $severidad,
                'desde' => $desde,
                'hasta' => $hasta,
                'historico' => $historico,
                'usaDefault' => $usaDefault,
            ],
        ]);
    }

    /**
     * Devuelve UNA tarjeta por cliente (nunca varias): si un cliente dispara
     * multiples eventos, la tarjeta principal es la mas severa (mismo orden
     * color+severidad ya usado) y el resto queda en 'secundarios' como badges.
     * 'fecha_evento' es la fecha de la ultima actividad real del cliente (abono,
     * gestion o -- si nunca tuvo ninguna -- su primer cargo), usada para el
     * filtro de rango de fechas; null si el cliente no tiene ningun movimiento
     * con fecha real.
     */
    public function calcularEventos()
    {
        $hoy = now()->startOfDay();

        $todos = MovimientoCuenta::with('planCuotas')->orderBy('fecha')->get();
        $porCliente = $todos->groupBy('cliente_id');

        $saldoDe = fn ($movs) => (float) $movs->sum(fn (MovimientoCuenta $m) => match ($m->tipo) {
            'cargo' => (float) $m->monto,
            'abono', 'ajuste_devolucion' => -(float) $m->monto,
            default => 0,
        });

        $clientesConSaldo = Cliente::with('usuarioResponsable:id,name')->get()
            ->map(fn (Cliente $c) => ['cliente' => $c, 'movs' => $porCliente->get($c->id, collect())])
            ->map(fn ($x) => [...$x, 'saldo' => $saldoDe($x['movs'])])
            ->filter(fn ($x) => $x['saldo'] > 0)
            ->values();

        // umbral del 25% mas alto de saldo, sobre la cartera activa
        $saldosOrdenados = $clientesConSaldo->pluck('saldo')->sort()->values();
        $umbralTop25 = $saldosOrdenados->isEmpty()
            ? 0
            : $saldosOrdenados->get(min((int) floor($saldosOrdenados->count() * 0.75), $saldosOrdenados->count() - 1));

        $eventos = collect();

        foreach ($clientesConSaldo as $item) {
            $cliente = $item['cliente'];
            $movs = $item['movs'];
            $saldo = $item['saldo'];

            // movimientos sin fecha (importados del papel sin dato) se excluyen de
            // todo calculo de antiguedad -- no cuentan como "hoy" ni rompen el
            // calculo, simplemente no participan en el FIFO/promedios de fechas
            $abonos = $movs->where('tipo', 'abono')->whereNotNull('fecha')->sortBy('fecha')->values();
            $gestiones = $movs->where('tipo', 'gestion')->whereNotNull('fecha')->sortBy('fecha')->values();
            $cargos = $movs->where('tipo', 'cargo')->whereNotNull('fecha')->sortBy('fecha')->values();

            // fecha de referencia del cliente (ultima actividad real conocida),
            // usada por el filtro de rango de fechas del feed -- se calcula una
            // sola vez por cliente, no por evento
            $ultimaActividad = $movs->whereIn('tipo', ['abono', 'gestion'])->whereNotNull('fecha')->sortByDesc('fecha')->first();
            $fechaEvento = ($ultimaActividad?->fecha ?? $cargos->first()?->fecha)?->toDateString();

            $ultimoAbono = $abonos->last();
            $diasSinAbonar = $ultimoAbono ? $hoy->diffInDays($ultimoAbono->fecha, true) : null;

            $intervalos = [];
            for ($i = 1; $i < $abonos->count(); $i++) {
                $intervalos[] = $abonos[$i]->fecha->diffInDays($abonos[$i - 1]->fecha, true);
            }
            $promedioIntervalo = count($intervalos) > 0 ? array_sum($intervalos) / count($intervalos) : null;

            // 1. Salio de su patron
            if ($promedioIntervalo !== null && $promedioIntervalo > 0 && $diasSinAbonar !== null && $diasSinAbonar > 2 * $promedioIntervalo) {
                $eventos->push($this->evento(
                    'fuera_patron', self::NARANJA, $cliente,
                    round($diasSinAbonar).' dias sin abonar, su promedio es '.round($promedioIntervalo),
                    round($diasSinAbonar), $fechaEvento
                ));
            }

            // 2. Sin gestion reciente
            $tieneGestionReciente = $gestiones->contains(fn (MovimientoCuenta $g) => $g->fecha->gte($hoy->copy()->subDays(14)));
            if ($saldo >= $umbralTop25 && $umbralTop25 > 0 && ! $tieneGestionReciente) {
                $eventos->push($this->evento(
                    'sin_gestion', self::NARANJA, $cliente,
                    'Saldo de '.number_format($saldo, 2).' (top 25% de la cartera), sin gestion en 14 dias',
                    $saldo, $fechaEvento
                ));
            }

            // 3. Cuota vencida (FIFO visual, mismo criterio que ClienteController::compromisosCuotas)
            foreach ($cargos as $cargo) {
                if ($cargo->planCuotas->isEmpty()) {
                    continue;
                }

                $totalAbonadoDesde = $abonos->filter(fn (MovimientoCuenta $a) => $a->fecha->gte($cargo->fecha))
                    ->sum(fn (MovimientoCuenta $a) => (float) $a->monto);
                $restante = $totalAbonadoDesde;

                foreach ($cargo->planCuotas->sortBy('numero_cuota') as $cuota) {
                    $monto = (float) $cuota->monto_sugerido;

                    if ($restante >= $monto) {
                        $restante -= $monto;

                        continue;
                    }

                    $restante = 0;

                    if ($cuota->fecha_esperada->lt($hoy)) {
                        $diasAtraso = $hoy->diffInDays($cuota->fecha_esperada, true);
                        $eventos->push($this->evento(
                            'cuota_vencida', self::ROJO, $cliente,
                            'Cuota '.$cuota->numero_cuota.' de "'.$cargo->descripcion.'" vencida hace '.round($diasAtraso).' dias',
                            round($diasAtraso), $fechaEvento
                        ));
                    }
                }
            }

            // 4. Promesa vencida
            $ultimaGestionConPromesa = $gestiones->filter(fn (MovimientoCuenta $g) => $g->fecha_prometida !== null)
                ->sortByDesc('fecha_prometida')
                ->first();

            if ($ultimaGestionConPromesa && $ultimaGestionConPromesa->fecha_prometida->lt($hoy)) {
                $pagoDespuesDePromesa = $abonos->contains(fn (MovimientoCuenta $a) => $a->fecha->gt($ultimaGestionConPromesa->fecha_prometida));

                if (! $pagoDespuesDePromesa) {
                    $diasVencida = $hoy->diffInDays($ultimaGestionConPromesa->fecha_prometida, true);
                    $eventos->push($this->evento(
                        'promesa_vencida', self::ROJO, $cliente,
                        'Prometio pagar el '.$ultimaGestionConPromesa->fecha_prometida->format('d/m/Y').', hace '.round($diasVencida).' dias, sin abono desde entonces',
                        round($diasVencida), $fechaEvento
                    ));
                }
            }

            // 5. Buen comportamiento (ultimo abono mas rapido o mas completo que su promedio previo)
            if ($abonos->count() >= 3) {
                $previos = $abonos->slice(0, -1)->values();
                $ultimo = $abonos->last();

                $promedioMontoPrevio = (float) $previos->avg(fn (MovimientoCuenta $a) => (float) $a->monto);

                $intervalosPrevios = [];
                for ($i = 1; $i < $previos->count(); $i++) {
                    $intervalosPrevios[] = $previos[$i]->fecha->diffInDays($previos[$i - 1]->fecha, true);
                }
                $promedioIntervaloPrevio = count($intervalosPrevios) > 0 ? array_sum($intervalosPrevios) / count($intervalosPrevios) : null;
                $intervaloUltimo = $ultimo->fecha->diffInDays($previos->last()->fecha, true);

                $masCompleto = (float) $ultimo->monto > $promedioMontoPrevio;
                $masRapido = $promedioIntervaloPrevio !== null && $intervaloUltimo < $promedioIntervaloPrevio;

                if ($masCompleto || $masRapido) {
                    $detalle = $masCompleto
                        ? 'Ultimo abono de '.number_format((float) $ultimo->monto, 2).', su promedio es '.number_format($promedioMontoPrevio, 2)
                        : 'Abono a los '.round($intervaloUltimo).' dias, su promedio es '.round($promedioIntervaloPrevio);
                    $eventos->push($this->evento('buen_comportamiento', self::VERDE, $cliente, $detalle, 0, $fechaEvento));
                }
            }

            // 6. Cartera fria: 60+ dias sin abono NI gestion
            if ($fechaEvento) {
                $diasFrio = $hoy->diffInDays(Carbon::parse($fechaEvento), true);

                if ($diasFrio >= 60) {
                    $eventos->push($this->evento(
                        'cartera_fria', self::ROJO, $cliente,
                        round($diasFrio).' dias sin ningun movimiento (ni abono ni gestion)',
                        round($diasFrio), $fechaEvento
                    ));
                }
            }
        }

        $ordenColor = [self::ROJO => 0, self::NARANJA => 1, self::VERDE => 2];

        return $eventos
            ->sortBy([
                fn ($a, $b) => $ordenColor[$a['color']] <=> $ordenColor[$b['color']],
                fn ($a, $b) => $b['severidad'] <=> $a['severidad'],
            ])
            ->values()
            ->groupBy('cliente_id')
            ->map(function ($eventosCliente) {
                // ya vienen ordenados por severidad -- el primero es el principal
                $principal = $eventosCliente->first();
                $secundarios = $eventosCliente->slice(1)->map(fn ($e) => [
                    'tipo' => $e['tipo'],
                    'color' => $e['color'],
                ])->values();

                return [...$principal, 'secundarios' => $secundarios];
            })
            ->values();
    }

    private function evento(string $tipo, string $color, Cliente $cliente, string $mensaje, float $severidad, ?string $fechaEvento): array
    {
        return [
            'tipo' => $tipo,
            'color' => $color,
            'cliente_id' => $cliente->id,
            'cliente_nombre' => $cliente->nombre,
            'telefono' => $cliente->telefono,
            'mensaje' => $mensaje,
            'severidad' => $severidad,
            'fecha_evento' => $fechaEvento,
            'responsable' => $cliente->usuarioResponsable?->name ?? 'Sin asignar',
        ];
    }
}
