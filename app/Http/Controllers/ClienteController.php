<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\MovimientoCuenta;
use App\Models\ReglaPlazo;
use App\Services\TasaBcvService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');

        $clientes = Cliente::query()
            ->when($q, fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('nombre', 'like', "%{$q}%")
                    ->orWhere('cedula', 'like', "%{$q}%")
                    ->orWhere('telefono', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            }))
            ->latest()
            ->get();

        $productosMatch = $q
            ? MovimientoCuenta::where('tipo', 'cargo')
                ->where('descripcion', 'like', "%{$q}%")
                ->with('cliente:id,nombre')
                ->orderByDesc('fecha')
                ->get()
                ->map(fn (MovimientoCuenta $m) => [
                    'id' => $m->id,
                    'cliente_id' => $m->cliente_id,
                    'cliente_nombre' => $m->cliente?->nombre,
                    'descripcion' => $m->descripcion,
                    'fecha' => $m->fecha->toDateString(),
                    'monto' => $m->monto,
                ])
            : [];

        return Inertia::render('Clientes/Index', [
            'clientes' => $clientes,
            'productosMatch' => $productosMatch,
            'q' => $q,
        ]);
    }

    public function show(Cliente $cliente, TasaBcvService $tasaBcvService)
    {
        $movimientosRaw = MovimientoCuenta::where('cliente_id', $cliente->id)
            ->with('registradoPor:id,name')
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        $edicionesPorMovimiento = Activity::where('subject_type', MovimientoCuenta::class)
            ->whereIn('subject_id', $movimientosRaw->pluck('id'))
            ->latest()
            ->get()
            ->groupBy('subject_id');

        $movimientos = $movimientosRaw->map(function (MovimientoCuenta $m) use ($edicionesPorMovimiento) {
            $ultimaEdicion = $edicionesPorMovimiento->get($m->id)?->first();

            return [
                'id' => $m->id,
                'fecha' => $m->fecha->toDateString(),
                'tipo' => $m->tipo,
                'tipo_contacto' => $m->tipo_contacto,
                'descripcion' => $m->descripcion,
                'cantidad' => $m->cantidad,
                'precio_unitario' => $m->precio_unitario,
                'modalidad_precio' => $m->modalidad_precio,
                'plazo_meses' => $m->plazo_meses,
                'frecuencia_pago' => $m->frecuencia_pago,
                'monto' => $m->monto,
                'moneda' => $m->moneda,
                'tasa_cambio' => $m->tasa_cambio,
                'metodo_pago' => $m->metodo_pago,
                'comentario' => $m->comentario,
                'registrado_por' => $m->registradoPor?->name,
                'comprobante_url' => $m->getFirstMediaUrl('comprobantes') ?: null,
                'producto_url' => $m->getFirstMediaUrl('producto') ?: null,
                'editado' => $ultimaEdicion !== null,
                'motivo_edicion' => $ultimaEdicion?->getExtraProperty('motivo'),
            ];
        });

        $saldoPendiente = MovimientoCuenta::saldoPendiente($cliente->id);
        $ultimoAbono = $movimientosRaw->where('tipo', 'abono')->last();
        $diasSinAbonar = $ultimoAbono ? now()->startOfDay()->diffInDays($ultimoAbono->fecha, true) : null;

        $intro = str_replace(
            ['{nombre}', '{saldo}', '{dias_sin_abonar}'],
            [$cliente->nombre, number_format($saldoPendiente, 2), $diasSinAbonar ?? 'sin abonos registrados'],
            Configuracion::valorDe('whatsapp_intro', 'Hola {nombre},')
        );

        $mensajeWhatsapp = $intro
            ."\n\nSaldo pendiente: ".number_format($saldoPendiente, 2)
            ."\nDias sin abonar: ".($diasSinAbonar ?? 'sin abonos registrados')
            ."\n\nQuedamos atentos, gracias por su preferencia.";

        $ultimaTasaBcv = $tasaBcvService->getLastUpdate();

        return Inertia::render('Clientes/Show', [
            'cliente' => $cliente,
            'movimientos' => $movimientos,
            'saldoPendiente' => $saldoPendiente,
            'totalCobrado' => MovimientoCuenta::totalCobrado($cliente->id),
            'reglas' => ReglaPlazo::orderBy('monto_min')->get(),
            'mensajeWhatsapp' => $mensajeWhatsapp,
            'tasaBcv' => $ultimaTasaBcv ? [
                'rate' => $ultimaTasaBcv['rate'],
                'source' => $ultimaTasaBcv['source'],
                'fetchedAt' => $ultimaTasaBcv['fetched_at'],
                'stale' => $tasaBcvService->isStale(),
            ] : null,
        ]);
    }

    public function cartera()
    {
        $movimientos = MovimientoCuenta::orderBy('fecha')->get()->groupBy('cliente_id');

        $clientes = Cliente::all()->map(function (Cliente $c) use ($movimientos) {
            $movs = $movimientos->get($c->id, collect());
            $saldo = $movs->sum(fn (MovimientoCuenta $m) => $m->tipo === 'cargo' ? (float) $m->monto : -(float) $m->monto);
            $ultimoAbono = $movs->where('tipo', 'abono')->last();

            return [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'saldoPendiente' => $saldo,
                'ultimoAbonoFecha' => $ultimoAbono?->fecha->toDateString(),
                'diasDesdeUltimoAbono' => $ultimoAbono ? now()->startOfDay()->diffInDays($ultimoAbono->fecha, true) : null,
            ];
        })->sortByDesc('saldoPendiente')->values();

        return Inertia::render('Cartera/Index', [
            'clientes' => $clientes,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'cedula' => ['nullable', 'string', 'max:20'],
        ], [
            'nombre.required' => 'nombre requerido',
            'telefono.required' => 'telefono requerido',
            'email.email' => 'email invalido',
            'nombre.max' => 'nombre demasiado largo',
            'telefono.max' => 'telefono demasiado largo',
            'email.max' => 'email demasiado largo',
            'cedula.max' => 'cedula demasiado larga',
        ]);

        Cliente::create($validated);

        return redirect()->route('clientes.index');
    }

    public function update(Request $request, Cliente $cliente)
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'cedula' => ['nullable', 'string', 'max:20'],
        ], [
            'nombre.required' => 'nombre requerido',
            'telefono.required' => 'telefono requerido',
            'email.email' => 'email invalido',
            'nombre.max' => 'nombre demasiado largo',
            'telefono.max' => 'telefono demasiado largo',
            'email.max' => 'email demasiado largo',
            'cedula.max' => 'cedula demasiado larga',
        ]);

        $cliente->update($validated);

        return redirect()->route('clientes.index');
    }

    public function destroy(Cliente $cliente)
    {
        $cliente->delete();

        return redirect()->route('clientes.index');
    }
}
