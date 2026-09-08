<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ReglaPlazo;
use App\Models\VentaCredito;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class VentaCreditoController extends Controller
{
    public function index()
    {
        return Inertia::render('VentasCredito/Index', [
            'ventas' => VentaCredito::with(['cliente:id,nombre', 'items'])->latest()->get(),
            'clientes' => Cliente::orderBy('nombre')->get(['id', 'nombre']),
            'reglas' => ReglaPlazo::orderBy('monto_min')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.descripcion_libre' => ['required', 'string', 'max:255'],
            'items.*.precio_unitario' => ['required', 'numeric', 'min:0.01'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
            'moneda' => ['required', 'in:usd,ves'],
            'tasa_cambio' => ['required', 'numeric', 'min:0.0001'],
            'abono_inicial' => ['nullable', 'numeric', 'min:0'],
            'plazo_meses' => ['required', 'integer', 'min:1'],
            'frecuencia_pago' => ['required', 'in:semanal,quincenal,mensual'],
            'fecha_venta' => ['nullable', 'date'],
        ], [
            'cliente_id.required' => 'cliente requerido',
            'items.required' => 'debe agregar al menos un item',
            'items.min' => 'debe agregar al menos un item',
            'moneda.required' => 'moneda requerida',
            'tasa_cambio.required' => 'tasa de cambio requerida',
            'plazo_meses.required' => 'plazo requerido',
            'frecuencia_pago.required' => 'frecuencia de pago requerida',
        ]);

        $montoTotal = collect($validated['items'])
            ->sum(fn ($item) => $item['precio_unitario'] * $item['cantidad']);

        $venta = DB::transaction(function () use ($validated, $montoTotal) {
            $venta = VentaCredito::create([
                'cliente_id' => $validated['cliente_id'],
                'monto_total' => $montoTotal,
                'moneda' => $validated['moneda'],
                'tasa_cambio' => $validated['tasa_cambio'],
                'abono_inicial' => $validated['abono_inicial'] ?? 0,
                'plazo_meses' => $validated['plazo_meses'],
                'frecuencia_pago' => $validated['frecuencia_pago'],
                'fecha_venta' => $validated['fecha_venta'] ?? now()->toDateString(),
                'estado' => 'activo',
            ]);

            $venta->items()->createMany($validated['items']);

            return $venta;
        });

        return redirect()->route('ventas-credito.index');
    }
}
