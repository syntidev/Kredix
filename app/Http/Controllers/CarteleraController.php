<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use Inertia\Inertia;

class CarteleraController extends Controller
{
    // Paleta reutilizada del semaforo de antiguedad ya usado en Cartera/Index.vue
    // (no se definio una paleta nueva en el prompt de este sprint).
    private const ROJO = 'rojo';

    private const NARANJA = 'naranja';

    private const VERDE = 'verde';

    public function index()
    {
        return Inertia::render('Cartelera/Index', [
            'eventos' => $this->calcularEventos(),
        ]);
    }

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

        $clientesConSaldo = Cliente::all()
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

            $abonos = $movs->where('tipo', 'abono')->sortBy('fecha')->values();
            $gestiones = $movs->where('tipo', 'gestion')->sortBy('fecha')->values();
            $cargos = $movs->where('tipo', 'cargo')->sortBy('fecha')->values();

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
                    round($diasSinAbonar)
                ));
            }

            // 2. Sin gestion reciente
            $tieneGestionReciente = $gestiones->contains(fn (MovimientoCuenta $g) => $g->fecha->gte($hoy->copy()->subDays(14)));
            if ($saldo >= $umbralTop25 && $umbralTop25 > 0 && ! $tieneGestionReciente) {
                $eventos->push($this->evento(
                    'sin_gestion', self::NARANJA, $cliente,
                    'Saldo de '.number_format($saldo, 2).' (top 25% de la cartera), sin gestion en 14 dias',
                    $saldo
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
                            round($diasAtraso)
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
                        round($diasVencida)
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
                    $eventos->push($this->evento('buen_comportamiento', self::VERDE, $cliente, $detalle, 0));
                }
            }

            // 6. Cartera fria: 60+ dias sin abono NI gestion
            $ultimaActividad = $movs->whereIn('tipo', ['abono', 'gestion'])->sortByDesc('fecha')->first();
            $referencia = $ultimaActividad?->fecha ?? $cargos->first()?->fecha;

            if ($referencia) {
                $diasFrio = $hoy->diffInDays($referencia, true);

                if ($diasFrio >= 60) {
                    $eventos->push($this->evento(
                        'cartera_fria', self::ROJO, $cliente,
                        round($diasFrio).' dias sin ningun movimiento (ni abono ni gestion)',
                        round($diasFrio)
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
            ->values();
    }

    private function evento(string $tipo, string $color, Cliente $cliente, string $mensaje, float $severidad): array
    {
        return [
            'tipo' => $tipo,
            'color' => $color,
            'cliente_id' => $cliente->id,
            'cliente_nombre' => $cliente->nombre,
            'telefono' => $cliente->telefono,
            'mensaje' => $mensaje,
            'severidad' => $severidad,
        ];
    }
}
