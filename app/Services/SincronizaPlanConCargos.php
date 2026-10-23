<?php

namespace App\Services;

use App\Models\Cuota;
use App\Models\MovimientoCuenta;
use App\Models\PlanFinanciamiento;

/**
 * Mantiene el plan de financiamiento alineado con las lineas VIVAS de su compra.
 * Se llama dentro de la transaccion que edita o anula la linea: un abort(422) deshace todo.
 * Nunca toca abonos ni cuotas con pagos; los cargos de mora no tienen plan y no entran aqui.
 */
class SincronizaPlanConCargos
{
    // la linea cambio de monto: recalcula las cuotas pendientes sobre el nuevo total
    public function alCambiarMonto(MovimientoCuenta $cargo): void
    {
        $plan = PlanFinanciamiento::deCargo($cargo);

        if ($plan) {
            $this->recalcular($plan, 'plan_recalculado', ['linea' => $cargo->descripcion]);
        }
    }

    // la linea ya fue anulada (soft-delete): anula el plan si era la ultima viva, o lo re-ancla y recalcula
    public function alAnularLinea(MovimientoCuenta $cargo): void
    {
        $plan = PlanFinanciamiento::deCargo($cargo);

        if (! $plan) {
            return;
        }

        $vivas = blank($cargo->compra_id)
            ? collect()
            : MovimientoCuenta::where('compra_id', $cargo->compra_id)->where('tipo', 'cargo')->orderBy('id')->get();

        if ($vivas->isEmpty()) {
            $this->anular($plan, $cargo);

            return;
        }

        if ($plan->movimiento_cuenta_id === $cargo->id) {
            $anterior = $plan->movimiento_cuenta_id;
            $plan->update(['movimiento_cuenta_id' => $vivas->first()->id]);
            $this->registrar($plan, 'plan_reanclado', ['movimiento_cuenta_id' => $anterior], ['movimiento_cuenta_id' => $plan->movimiento_cuenta_id]);
        }

        $this->recalcular($plan, 'plan_recalculado', ['linea_anulada' => $cargo->descripcion]);
    }

    private function recalcular(PlanFinanciamiento $plan, string $evento, array $contexto): void
    {
        $plan->recalcularCascada();

        $cuotas = $plan->cuotas()->get();
        $total = PlanFinanciamiento::montoTotalDeCompra($plan->cargo);
        $aFinanciar = round($total - (float) $plan->monto_inicial, 2);
        $abonado = $plan->totalAbonado();

        abort_if($aFinanciar < $abonado, 422, 'El nuevo total ('.$this->usd($total).') deja el financiamiento por debajo de lo ya abonado al plan ('.$this->usd($abonado + (float) $plan->monto_inicial).'). Revierte abonos antes de bajar el monto.');

        // cuotas con pagos (completos o parciales) no se tocan; las demas absorben la diferencia
        $conPagos = $cuotas->filter(fn (Cuota $c) => (float) $c->monto_abonado > 0);
        $pendientes = $cuotas->filter(fn (Cuota $c) => (float) $c->monto_abonado <= 0)->values();
        $restante = round($aFinanciar - (float) $conPagos->sum('monto_pactado'), 2);

        if ($pendientes->isEmpty()) {
            abort_if($restante !== 0.0, 422, 'No quedan cuotas pendientes para absorber el cambio de monto. Revierte abonos antes de cambiarlo.');

            return;
        }

        abort_if($restante <= 0, 422, 'El nuevo total no deja saldo para las cuotas pendientes. Revierte abonos antes de bajar el monto.');

        $antes = $cuotas->mapWithKeys(fn (Cuota $c) => [$c->numero => (float) $c->monto_pactado])->all();

        foreach (PlanFinanciamiento::repartirCuotas($restante, $pendientes->count()) as $i => $monto) {
            $pendientes[$i]->update(['monto_pactado' => $monto]);
        }

        $plan->recalcularCascada();

        $despues = $plan->cuotas()->get()->mapWithKeys(fn (Cuota $c) => [$c->numero => (float) $c->monto_pactado])->all();

        if ($antes !== $despues) {
            $this->registrar($plan, $evento, ['total' => $this->totalAnterior($antes, $plan), 'cuotas' => $antes], ['total' => $total, 'cuotas' => $despues], $contexto);
        }
    }

    private function anular(PlanFinanciamiento $plan, MovimientoCuenta $cargo): void
    {
        abort_if($plan->tieneAbonos(), 422, 'No se puede borrar: el plan de financiamiento tiene abonos aplicados. Revierte primero los abonos.');

        $cuotas = $plan->cuotas()->get();
        $antes = ['cuotas' => $cuotas->mapWithKeys(fn (Cuota $c) => [$c->numero => (float) $c->monto_pactado])->all(), 'anulado' => false];

        $plan->cuotas()->delete();
        $plan->delete();

        $this->registrar($plan, 'plan_anulado', $antes, ['cuotas' => [], 'anulado' => true], ['linea_anulada' => $cargo->descripcion]);
    }

    // el total de antes = lo pactado en cuotas + la inicial (el plan no guarda el total por separado)
    private function totalAnterior(array $cuotasAntes, PlanFinanciamiento $plan): float
    {
        return round(array_sum($cuotasAntes) + (float) $plan->monto_inicial, 2);
    }

    private function registrar(PlanFinanciamiento $plan, string $evento, array $antes, array $despues, array $contexto = []): void
    {
        activity()
            ->causedBy(auth()->user())
            ->performedOn($plan)
            ->withProperties(['antes' => $antes, 'despues' => $despues, ...$contexto])
            ->log($evento);
    }

    private function usd(float $monto): string
    {
        return '$'.number_format($monto, 2);
    }
}
