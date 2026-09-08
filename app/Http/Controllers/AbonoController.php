<?php

namespace App\Http\Controllers;

use App\Models\Abono;
use App\Models\User;
use App\Models\VentaCredito;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AbonoController extends Controller
{
    public function show(VentaCredito $venta)
    {
        $venta->load(['cliente:id,nombre', 'items']);

        return Inertia::render('Abonos/Show', [
            'venta' => $venta,
            'abonos' => $venta->abonos()->with('registradoPor:id,name')->latest()->get()->map(function (Abono $abono) {
                return [
                    'id' => $abono->id,
                    'monto' => $abono->monto,
                    'moneda' => $abono->moneda,
                    'tasa_cambio' => $abono->tasa_cambio,
                    'metodo_pago' => $abono->metodo_pago,
                    'tipo' => $abono->tipo,
                    'comentario' => $abono->comentario,
                    'registrado_por' => $abono->registradoPor?->name,
                    'created_at' => $abono->created_at,
                    'comprobante_url' => $abono->getFirstMediaUrl('comprobantes') ?: null,
                ];
            }),
            'totalCobrado' => $venta->totalCobrado(),
            'totalAjustes' => $venta->totalAjustes(),
            'saldoPendiente' => $venta->saldoPendiente(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'venta_credito_id' => ['required', 'exists:ventas_credito,id'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'moneda' => ['required', 'in:usd,ves'],
            'tasa_cambio' => ['required', 'numeric', 'min:0.0001'],
            'metodo_pago' => ['required', 'in:efectivo,zelle,binance,transferencia'],
            'tipo' => ['required', 'in:abono,ajuste_devolucion'],
            'comentario' => ['required', 'string', 'max:1000'],
            'comprobante' => ['nullable', 'image', 'max:5120'],
        ], [
            'comentario.required' => 'comentario requerido',
            'monto.required' => 'monto requerido',
            'moneda.required' => 'moneda requerida',
            'tasa_cambio.required' => 'tasa de cambio requerida',
            'metodo_pago.required' => 'metodo de pago requerido',
        ]);

        $registradoPor = auth()->id() ?? User::firstOrCreate(
            ['email' => 'sistema@kredix.local'],
            ['name' => 'Sistema', 'password' => bcrypt(str()->random(32))]
        )->id;

        $abono = Abono::create([
            'venta_credito_id' => $validated['venta_credito_id'],
            'monto' => $validated['monto'],
            'moneda' => $validated['moneda'],
            'tasa_cambio' => $validated['tasa_cambio'],
            'metodo_pago' => $validated['metodo_pago'],
            'tipo' => $validated['tipo'],
            'comentario' => $validated['comentario'],
            'registrado_por' => $registradoPor,
        ]);

        if ($request->hasFile('comprobante')) {
            $abono->addMediaFromRequest('comprobante')->toMediaCollection('comprobantes');
        }

        return redirect()->route('ventas-credito.abonos', $validated['venta_credito_id']);
    }
}
