<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\ReglaPlazo;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClienteController extends Controller
{
    public function index()
    {
        return Inertia::render('Clientes/Index', [
            'clientes' => Cliente::latest()->get(),
        ]);
    }

    public function show(Cliente $cliente)
    {
        $movimientos = MovimientoCuenta::where('cliente_id', $cliente->id)
            ->with('registradoPor:id,name')
            ->orderBy('fecha')
            ->orderBy('id')
            ->get()
            ->map(fn (MovimientoCuenta $m) => [
                'id' => $m->id,
                'fecha' => $m->fecha->toDateString(),
                'tipo' => $m->tipo,
                'descripcion' => $m->descripcion,
                'cantidad' => $m->cantidad,
                'precio_unitario' => $m->precio_unitario,
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
            ]);

        return Inertia::render('Clientes/Show', [
            'cliente' => $cliente,
            'movimientos' => $movimientos,
            'saldoPendiente' => MovimientoCuenta::saldoPendiente($cliente->id),
            'totalCobrado' => MovimientoCuenta::totalCobrado($cliente->id),
            'reglas' => ReglaPlazo::orderBy('monto_min')->get(),
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
