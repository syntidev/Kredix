<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuscaTokenizado;
use App\Models\MovimientoCuenta;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConciliacionController extends Controller
{
    use BuscaTokenizado;

    // efectivo no requiere conciliacion -- es el unico metodo que no exige
    // referencia (Fase A), y no hay nada que cruzar contra un comprobante
    // bancario/de billetera externo
    private const METODOS_ELECTRONICOS = ['zelle', 'binance', 'transferencia', 'pago_movil', 'bancamiga_divisa', 'punto_venta'];

    public function index(Request $request)
    {
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');
        $metodo = $request->query('metodo');
        $estado = $request->query('estado', 'todos');
        $q = trim((string) $request->query('q', ''));

        $query = MovimientoCuenta::query()
            ->whereIn('metodo_pago', self::METODOS_ELECTRONICOS)
            ->when($desde, fn ($query) => $query->whereDate('fecha', '>=', $desde))
            ->when($hasta, fn ($query) => $query->whereDate('fecha', '<=', $hasta))
            ->when($metodo && in_array($metodo, self::METODOS_ELECTRONICOS, true), fn ($query) => $query->where('metodo_pago', $metodo))
            // estado_validacion null en un abono es "todavia no fue validado" --
            // mismo significado que 'pendiente' explicito (movimientos historicos
            // importados antes de este campo quedaron en null). ajuste_devolucion
            // nunca requiere validacion, por eso el null ahi no cuenta como pendiente
            ->when($estado === 'pendiente', fn ($query) => $query->where(function ($sub) {
                $sub->where('estado_validacion', 'pendiente')
                    ->orWhere(function ($sub2) {
                        $sub2->where('tipo', 'abono')->whereNull('estado_validacion');
                    });
            }))
            ->when($estado === 'validado', fn ($query) => $query->where('estado_validacion', 'validado'))
            ->when($q !== '', function ($query) use ($q) {
                $tokens = $this->tokensDeBusqueda($q);
                $query->whereHas('cliente', function ($sub) use ($q, $tokens) {
                    $this->whereNombreTokenizado($sub, 'nombre', $tokens);
                    $sub->orWhere('cedula', 'like', "%{$q}%")
                        ->orWhere('telefono', 'like', "%{$q}%");
                });
            });

        // agregado exclusivo admin -- calculado sobre el mismo filtro ya
        // aplicado, antes de paginar, para que sea "total de lo filtrado" y
        // no "total de la pagina actual"; jamas viaja en el payload si el
        // usuario no es admin (invariante CLAUDE.md: agregados de cartera
        // gateados en backend, no solo ocultos en el frontend)
        $esAdmin = (bool) $request->user()?->es_admin;
        $totalFiltrado = $esAdmin ? (clone $query)->sum('monto') : null;

        $movimientos = $query
            ->with(['cliente:id,nombre', 'registradoPor:id,name'])
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (MovimientoCuenta $m) => [
                'id' => $m->id,
                'cliente_id' => $m->cliente_id,
                'cliente_nombre' => $m->cliente?->nombre,
                'tipo' => $m->tipo,
                'fecha' => $m->fecha?->toDateString(),
                'metodo_pago' => $m->metodo_pago,
                'referencia' => $m->referencia,
                'estado_validacion' => $m->tipo === 'abono' ? ($m->estado_validacion ?? 'pendiente') : $m->estado_validacion,
                'monto' => $m->monto,
                'comprobante_url' => $m->getFirstMediaUrl('comprobantes') ?: null,
                'comprobante_thumb_url' => $m->getFirstMediaUrl('comprobantes', 'thumb') ?: null,
                'registrado_por' => $m->registradoPor?->name,
            ]);

        return Inertia::render('Conciliacion/Index', [
            'movimientos' => $movimientos,
            'filtros' => compact('desde', 'hasta', 'metodo', 'estado', 'q'),
            'totalFiltrado' => $totalFiltrado,
        ]);
    }
}
