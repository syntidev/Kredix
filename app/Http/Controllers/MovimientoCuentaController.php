<?php

namespace App\Http\Controllers;

use App\Models\MovimientoCuenta;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MovimientoCuentaController extends Controller
{
    public function store(Request $request)
    {
        $esCargo = $request->input('tipo') === 'cargo';

        $validated = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'tipo' => ['required', 'in:cargo,abono,ajuste_devolucion'],
            'fecha' => ['required', 'date'],
            'descripcion' => ['required', 'string', 'max:255'],
            'moneda' => ['required', 'in:usd,ves'],
            'tasa_cambio' => ['required', 'numeric', 'min:0.0001'],
            'cantidad' => [Rule::requiredIf($esCargo), 'nullable', 'numeric', 'min:0.01'],
            'precio_unitario' => [Rule::requiredIf($esCargo), 'nullable', 'numeric', 'min:0.01'],
            'plazo_meses' => [Rule::requiredIf($esCargo), 'nullable', 'integer', 'min:1'],
            'frecuencia_pago' => [Rule::requiredIf($esCargo), 'nullable', 'in:semanal,quincenal,mensual'],
            'monto' => [Rule::requiredIf(! $esCargo), 'nullable', 'numeric', 'min:0.01'],
            'metodo_pago' => [Rule::requiredIf(! $esCargo), 'nullable', 'in:efectivo,zelle,binance,transferencia'],
            'comentario' => [Rule::requiredIf(! $esCargo), 'nullable', 'string', 'max:1000'],
            'comprobante' => ['nullable', 'image', 'max:5120'],
        ], [
            'comentario.required' => 'comentario requerido',
            'monto.required' => 'monto requerido',
            'metodo_pago.required' => 'metodo de pago requerido',
            'cantidad.required' => 'cantidad requerida',
            'precio_unitario.required' => 'precio unitario requerido',
            'plazo_meses.required' => 'plazo requerido',
            'frecuencia_pago.required' => 'frecuencia de pago requerida',
        ]);

        $monto = $esCargo
            ? $validated['cantidad'] * $validated['precio_unitario']
            : $validated['monto'];

        $movimiento = MovimientoCuenta::create([
            'cliente_id' => $validated['cliente_id'],
            'fecha' => $validated['fecha'],
            'tipo' => $validated['tipo'],
            'descripcion' => $validated['descripcion'],
            'cantidad' => $esCargo ? $validated['cantidad'] : null,
            'precio_unitario' => $esCargo ? $validated['precio_unitario'] : null,
            'plazo_meses' => $esCargo ? $validated['plazo_meses'] : null,
            'frecuencia_pago' => $esCargo ? $validated['frecuencia_pago'] : null,
            'monto' => $monto,
            'moneda' => $validated['moneda'],
            'tasa_cambio' => $validated['tasa_cambio'],
            'metodo_pago' => $validated['metodo_pago'] ?? null,
            'comentario' => $validated['comentario'] ?? null,
            'registrado_por' => auth()->id(),
        ]);

        if ($request->hasFile('comprobante')) {
            $movimiento->addMediaFromRequest('comprobante')->toMediaCollection('comprobantes');
        }

        return redirect()->route('clientes.show', $validated['cliente_id']);
    }
}
