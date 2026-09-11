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
        $actividadReciente = MovimientoCuenta::where('tipo', 'abono')
            ->with('cliente:id,nombre')
            ->orderByDesc('created_at')
            ->take(6)
            ->get()
            ->map(fn (MovimientoCuenta $m) => [
                'id' => $m->id,
                'clienteId' => $m->cliente_id,
                'clienteNombre' => $m->cliente?->nombre,
                'monto' => (float) $m->monto,
                'creadoEn' => $m->created_at->toIso8601String(),
            ]);

        return Inertia::render('Home/Index', [
            'totalClientes' => Cliente::count(),
            'clientesConSaldo' => $clientesConSaldo,
            'eventosUrgentes' => (new CarteleraController())->calcularEventos()->take(3)->values(),
            'actividadReciente' => $actividadReciente,
        ]);
    }
}
