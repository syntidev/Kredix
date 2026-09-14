<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use Illuminate\Support\Facades\DB;
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

        $abonosHoy = MovimientoCuenta::where('tipo', 'abono')
            ->whereDate('fecha', now()->toDateString())
            ->with('cliente:id,nombre')
            ->orderByDesc('created_at')
            ->get();

        // mismo criterio que estadoValidacionEfectivo() en Clientes/Show.vue: solo
        // un abono con comprobante real adjunto requiere validacion
        $estadoValidacionEfectivo = fn (MovimientoCuenta $m) => $m->getFirstMedia('comprobantes')
            ? ($m->estado_validacion ?? 'pendiente')
            : null;

        $cierreDelDia = $abonosHoy
            ->groupBy('metodo_pago')
            ->map(fn ($grupo, $metodoPago) => [
                'metodoPago' => $metodoPago,
                'total' => (float) $grupo->sum('monto'),
                'cantidad' => $grupo->count(),
                'pendientes' => $grupo->filter(fn (MovimientoCuenta $m) => $estadoValidacionEfectivo($m) === 'pendiente')->count(),
                'abonos' => $grupo->map(fn (MovimientoCuenta $m) => [
                    'id' => $m->id,
                    'clienteId' => $m->cliente_id,
                    'clienteNombre' => $m->cliente?->nombre,
                    'monto' => (float) $m->monto,
                    'hora' => $m->created_at->format('H:i'),
                    'estadoValidacion' => $estadoValidacionEfectivo($m),
                ])->values(),
            ])
            ->values();

        $saldosActivos = DB::table('movimientos_cuenta')
            ->select('cliente_id', DB::raw("SUM(CASE WHEN tipo = 'cargo' THEN monto WHEN tipo IN ('abono', 'ajuste_devolucion') THEN -monto ELSE 0 END) as saldo"))
            ->whereNull('deleted_at')
            ->groupBy('cliente_id')
            ->havingRaw('saldo > 0');

        $enCalle = (float) DB::query()->fromSub($saldosActivos, 'saldos')->sum('saldo');

        $cobradoHoy = (float) MovimientoCuenta::where('tipo', 'abono')
            ->whereDate('fecha', now()->toDateString())
            ->sum('monto');

        $ultimosAbonos = MovimientoCuenta::where('tipo', 'abono')
            ->select('cliente_id', DB::raw('MAX(fecha) as ultima_fecha'))
            ->groupBy('cliente_id')
            ->pluck('ultima_fecha', 'cliente_id');

        // mismo criterio de severidad que ClienteController::cartera() (nunca
        // abonaron primero, luego mas dias sin abonar) pero acotado a quien
        // realmente debe (saldo > 0) -- "cartera con mora" real, no cartera completa
        $carteraConMora = Cliente::query()
            ->joinSub($saldosActivos, 'saldos', 'saldos.cliente_id', '=', 'clientes.id')
            ->select('clientes.id', 'clientes.nombre', 'saldos.saldo')
            ->get()
            ->map(function ($c) use ($ultimosAbonos) {
                $ultima = $ultimosAbonos->get($c->id);

                return [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'saldoPendiente' => (float) $c->saldo,
                    'diasSinAbonar' => $ultima ? now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($ultima), true) : null,
                ];
            })
            ->sortByDesc(fn ($c) => $c['diasSinAbonar'] ?? INF)
            ->take(3)
            ->values();

        return Inertia::render('Home/Index', [
            'totalClientes' => Cliente::count(),
            'clientesConSaldo' => $clientesConSaldo,
            'eventosUrgentes' => (new CarteleraController())->calcularEventos()->take(3)->values(),
            'actividadReciente' => $actividadReciente,
            'cierreDelDia' => $cierreDelDia,
            'enCalle' => $enCalle,
            'cobradoHoy' => $cobradoHoy,
            'carteraConMora' => $carteraConMora,
        ]);
    }
}
