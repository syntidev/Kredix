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
            ->with(['cliente:id,nombre', 'registradoPor:id,name'])
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
                'registradoPor' => $m->registradoPor?->name,
            ]);

        $abonosHoy = MovimientoCuenta::where('tipo', 'abono')
            ->whereDate('fecha', now()->toDateString())
            ->with('cliente:id,nombre')
            ->orderByDesc('created_at')
            ->get();

        // estado_validacion null no significa "no aplica" -- movimientos
        // historicos (importados antes de este campo) quedaron en null en la
        // BD pero siguen sin validar, se tratan como 'pendiente' (mismo
        // criterio que estadoValidacionEfectivo() en resources/js/lib/estadoValidacion.js).
        // El gate ya no es "tiene comprobante adjunto" (un abono electronico
        // historico sin foto tambien necesita conciliarse) sino metodo_pago:
        // efectivo nunca requiere validacion, el resto siempre la requiere.
        $estadoValidacionEfectivo = fn (MovimientoCuenta $m) => $m->metodo_pago !== 'efectivo'
            ? ($m->estado_validacion ?? 'pendiente')
            : null;

        // agregado de TODA la cartera (suma del dia por metodo) -- mismo criterio
        // que enCalle/cobradoHoy: solo admin. La lista individual de abonos
        // (para validar cada pago) se mantiene visible para todos los roles.
        $esAdmin = (bool) auth()->user()?->es_admin;

        $cierreDelDia = $abonosHoy
            ->groupBy('metodo_pago')
            ->map(fn ($grupo, $metodoPago) => [
                'metodoPago' => $metodoPago,
                'total' => $esAdmin ? (float) $grupo->sum('monto') : null,
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

        // agregado de TODA la cartera -- mismo criterio que totalCarteraActiva en
        // ClienteController::cartera(): solo admin, null para el resto (el saldo
        // de UN cliente individual sigue visible para todos en su ficha)
        $enCalle = $esAdmin
            ? (float) DB::query()->fromSub($saldosActivos, 'saldos')->sum('saldo')
            : null;

        $cobradoHoy = $esAdmin
            ? (float) MovimientoCuenta::where('tipo', 'abono')->whereDate('fecha', now()->toDateString())->sum('monto')
            : null;

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
