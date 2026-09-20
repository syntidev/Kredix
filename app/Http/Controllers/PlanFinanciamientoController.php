<?php

namespace App\Http\Controllers;

use App\Models\MovimientoCuenta;
use App\Models\PlanFinanciamiento;
use Illuminate\Support\Facades\DB;

class PlanFinanciamientoController extends Controller
{
    // Mora manual, nunca automatica: crea un Cargo (no un abono, sube lo que el
    // cliente debe) por el porcentaje_mora especifico de ESE plan sobre el
    // PRECIO TOTAL de la venta original (no el saldo pendiente) -- confirmado
    // con el caso real de Carlos ($79.90 = 10% de $799). Solo se puede aplicar
    // una vez por plan.
    public function aplicarMora(PlanFinanciamiento $plan)
    {
        abort_if($plan->aplicado_mora, 422, 'La mora ya fue aplicada sobre este plan');

        $plan->loadMissing('cargo');
        $montoMora = round((float) $plan->cargo->monto * ((float) $plan->porcentaje_mora / 100), 2);

        DB::transaction(function () use ($plan, $montoMora) {
            MovimientoCuenta::create([
                'cliente_id' => $plan->cargo->cliente_id,
                'fecha' => now()->toDateString(),
                'tipo' => 'cargo',
                'descripcion' => "Mora ({$plan->porcentaje_mora}%) - {$plan->cargo->descripcion}",
                'cantidad' => 1,
                'precio_unitario' => $montoMora,
                'modalidad_precio' => 'divisa',
                'monto' => $montoMora,
                'moneda' => 'usd',
                'registrado_por' => auth()->id(),
            ]);

            $plan->update(['aplicado_mora' => true]);
        });

        return redirect()->route('clientes.show', $plan->cargo->cliente_id);
    }
}
