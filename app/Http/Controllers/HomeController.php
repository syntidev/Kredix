<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        $clientesConSaldo = MovimientoCuenta::selectRaw(
            "cliente_id, SUM(CASE WHEN tipo = 'cargo' THEN monto WHEN tipo IN ('abono', 'ajuste_devolucion') THEN -monto ELSE 0 END) as saldo"
        )
            ->groupBy('cliente_id')
            ->havingRaw('saldo > 0')
            ->count();

        // fecha (DATE, sin hora) no alcanza para "hace X tiempo" con precision real --
        // created_at (timestamp real del registro) es la unica fuente de hora exacta
        $actividadReciente = MovimientoCuenta::whereIn('tipo', ['abono', 'cargo'])
            ->with('cliente:id,nombre')
            ->orderByDesc('created_at')
            ->take(6)
            ->get()
            ->map(fn (MovimientoCuenta $m) => [
                'id' => $m->id,
                'tipo' => $m->tipo,
                'clienteId' => $m->cliente_id,
                'clienteNombre' => $m->cliente?->nombre,
                'monto' => (float) $m->monto,
                'estadoValidacion' => $m->estado_validacion,
                'creadoEn' => $m->created_at->toIso8601String(),
            ]);

        $cierreDelDia = MovimientoCuenta::where('tipo', 'abono')
            ->whereDate('fecha', now()->toDateString())
            ->selectRaw("metodo_pago, SUM(monto) as total, COUNT(*) as cantidad, SUM(CASE WHEN estado_validacion = 'pendiente' THEN 1 ELSE 0 END) as pendientes")
            ->groupBy('metodo_pago')
            ->get()
            ->map(fn ($fila) => [
                'metodoPago' => $fila->metodo_pago,
                'total' => (float) $fila->total,
                'cantidad' => (int) $fila->cantidad,
                'pendientes' => (int) $fila->pendientes,
            ]);

        return Inertia::render('Home/Index', [
            'totalClientes' => Cliente::count(),
            'clientesConSaldo' => $clientesConSaldo,
            'eventosUrgentes' => (new CarteleraController())->calcularEventos()->take(3)->values(),
            'actividadReciente' => $actividadReciente,
            'cierreDelDia' => $cierreDelDia,
        ]);
    }
}
