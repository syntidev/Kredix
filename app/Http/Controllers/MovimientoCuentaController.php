<?php

namespace App\Http\Controllers;

use App\Models\MovimientoCuenta;
use App\Services\ImagenUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class MovimientoCuentaController extends Controller
{
    public function __construct(private ImagenUploadService $imagenUploadService)
    {
    }

    private function adjuntarComprimida(MovimientoCuenta $movimiento, UploadedFile $file, string $coleccion): void
    {
        $rutaComprimida = $this->imagenUploadService->comprimir($file);

        $movimiento->addMedia($rutaComprimida)
            ->usingFileName($file->getClientOriginalName())
            ->toMediaCollection($coleccion);
    }

    public function store(Request $request)
    {
        $tipo = $request->input('tipo');
        $esCargo = $tipo === 'cargo';
        $esGestion = $tipo === 'gestion';
        $requiereMonto = in_array($tipo, ['abono', 'ajuste_devolucion'], true);

        $validated = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'tipo' => ['required', 'in:cargo,abono,ajuste_devolucion,gestion'],
            'tipo_contacto' => [Rule::requiredIf($esGestion), 'nullable', 'in:llamada,whatsapp,visita,otro'],
            'fecha_prometida' => ['nullable', 'date'],
            'fecha' => ['required', 'date'],
            'descripcion' => ['required', 'string', 'max:255'],
            'tasa_cambio' => ['nullable', 'numeric', 'min:0.0001'],
            'cantidad' => [Rule::requiredIf($esCargo), 'nullable', 'numeric', 'min:0.01'],
            'precio_unitario' => [Rule::requiredIf($esCargo), 'nullable', 'numeric', 'min:0.01'],
            'modalidad_precio' => [Rule::requiredIf($esCargo), 'nullable', 'in:divisa,bcv'],
            'plazo_meses' => [Rule::requiredIf($esCargo), 'nullable', 'integer', 'min:1'],
            'frecuencia_pago' => [Rule::requiredIf($esCargo), 'nullable', 'in:semanal,quincenal,mensual'],
            'monto' => [Rule::requiredIf($requiereMonto), 'nullable', 'numeric', 'min:0.01'],
            'metodo_pago' => [Rule::requiredIf($requiereMonto), 'nullable', 'in:efectivo,zelle,binance,transferencia,pago_movil,bancamiga_divisa,punto_venta'],
            'comentario' => [Rule::requiredIf(! $esCargo), 'nullable', 'string', 'max:1000'],
            'comprobante' => ['nullable', 'image', 'max:5120'],
            'foto_producto' => ['nullable', 'image', 'max:5120'],
            'usa_plan_cuotas' => ['boolean'],
            'cuotas' => [Rule::requiredIf($esCargo && $request->boolean('usa_plan_cuotas')), 'array'],
            'cuotas.*.numero_cuota' => ['required_with:cuotas', 'integer', 'min:1'],
            'cuotas.*.monto_sugerido' => ['required_with:cuotas', 'numeric', 'min:0.01'],
            'cuotas.*.fecha_esperada' => ['required_with:cuotas', 'date'],
        ], [
            'comentario.required' => 'comentario requerido',
            'monto.required' => 'monto requerido',
            'metodo_pago.required' => 'metodo de pago requerido',
            'cantidad.required' => 'cantidad requerida',
            'precio_unitario.required' => 'precio unitario requerido',
            'plazo_meses.required' => 'plazo requerido',
            'frecuencia_pago.required' => 'frecuencia de pago requerida',
            'tipo_contacto.required' => 'tipo de contacto requerido',
            'cuotas.required' => 'plan de cuotas requerido',
        ]);

        $monto = $esCargo
            ? $validated['cantidad'] * $validated['precio_unitario']
            : ($validated['monto'] ?? 0);

        $movimiento = MovimientoCuenta::create([
            'cliente_id' => $validated['cliente_id'],
            'fecha' => $validated['fecha'],
            'tipo' => $validated['tipo'],
            'tipo_contacto' => $esGestion ? $validated['tipo_contacto'] : null,
            'fecha_prometida' => $esGestion ? ($validated['fecha_prometida'] ?? null) : null,
            'descripcion' => $validated['descripcion'],
            'cantidad' => $esCargo ? $validated['cantidad'] : null,
            'precio_unitario' => $esCargo ? $validated['precio_unitario'] : null,
            'modalidad_precio' => $esCargo ? $validated['modalidad_precio'] : null,
            'plazo_meses' => $esCargo ? $validated['plazo_meses'] : null,
            'frecuencia_pago' => $esCargo ? $validated['frecuencia_pago'] : null,
            'monto' => $monto,
            'moneda' => 'usd',
            'tasa_cambio' => $validated['tasa_cambio'] ?? null,
            'metodo_pago' => $validated['metodo_pago'] ?? null,
            'comentario' => $validated['comentario'] ?? null,
            'registrado_por' => auth()->id(),
            'estado_validacion' => ($tipo === 'abono' && $request->hasFile('comprobante')) ? 'pendiente' : null,
        ]);

        if ($request->hasFile('comprobante')) {
            $this->adjuntarComprimida($movimiento, $request->file('comprobante'), 'comprobantes');
        }

        if ($esCargo && $request->hasFile('foto_producto')) {
            $this->adjuntarComprimida($movimiento, $request->file('foto_producto'), 'producto');
        }

        if ($esCargo && $request->boolean('usa_plan_cuotas')) {
            foreach ($validated['cuotas'] as $cuota) {
                $movimiento->planCuotas()->create([
                    'numero_cuota' => $cuota['numero_cuota'],
                    'monto_sugerido' => $cuota['monto_sugerido'],
                    'fecha_esperada' => $cuota['fecha_esperada'],
                ]);
            }
        }

        return redirect()->route('clientes.show', $validated['cliente_id']);
    }

    public function update(Request $request, MovimientoCuenta $movimiento)
    {
        $esCargo = $movimiento->tipo === 'cargo';
        $esGestion = $movimiento->tipo === 'gestion';
        $requiereMonto = ! $esCargo && ! $esGestion;

        $validated = $request->validate([
            'fecha' => ['required', 'date'],
            'descripcion' => ['required', 'string', 'max:255'],
            'tipo_contacto' => [Rule::requiredIf($esGestion), 'nullable', 'in:llamada,whatsapp,visita,otro'],
            'fecha_prometida' => ['nullable', 'date'],
            'moneda' => ['required', 'in:usd,ves'],
            'tasa_cambio' => ['nullable', 'numeric', 'min:0.0001'],
            'cantidad' => [Rule::requiredIf($esCargo), 'nullable', 'numeric', 'min:0.01'],
            'precio_unitario' => [Rule::requiredIf($esCargo), 'nullable', 'numeric', 'min:0.01'],
            'modalidad_precio' => [Rule::requiredIf($esCargo), 'nullable', 'in:divisa,bcv'],
            'plazo_meses' => [Rule::requiredIf($esCargo), 'nullable', 'integer', 'min:1'],
            'frecuencia_pago' => [Rule::requiredIf($esCargo), 'nullable', 'in:semanal,quincenal,mensual'],
            'monto' => [Rule::requiredIf($requiereMonto), 'nullable', 'numeric', 'min:0.01'],
            'metodo_pago' => [Rule::requiredIf($requiereMonto), 'nullable', 'in:efectivo,zelle,binance,transferencia,pago_movil,bancamiga_divisa,punto_venta'],
            'comentario' => [Rule::requiredIf(! $esCargo), 'nullable', 'string', 'max:1000'],
            'comprobante' => ['nullable', 'image', 'max:5120'],
            'foto_producto' => ['nullable', 'image', 'max:5120'],
            'motivo_edicion' => ['required', 'string', 'max:500'],
        ], [
            'comentario.required' => 'comentario requerido',
            'monto.required' => 'monto requerido',
            'metodo_pago.required' => 'metodo de pago requerido',
            'cantidad.required' => 'cantidad requerida',
            'precio_unitario.required' => 'precio unitario requerido',
            'plazo_meses.required' => 'plazo requerido',
            'frecuencia_pago.required' => 'frecuencia de pago requerida',
            'motivo_edicion.required' => 'motivo de edicion requerido',
        ]);

        $monto = $esCargo
            ? $validated['cantidad'] * $validated['precio_unitario']
            : ($validated['monto'] ?? 0);

        $antes = $movimiento->only([
            'fecha', 'descripcion', 'tipo_contacto', 'fecha_prometida', 'cantidad', 'precio_unitario',
            'modalidad_precio', 'plazo_meses', 'frecuencia_pago', 'monto', 'moneda', 'tasa_cambio',
            'metodo_pago', 'comentario',
        ]);

        $movimiento->update([
            'fecha' => $validated['fecha'],
            'descripcion' => $validated['descripcion'],
            'tipo_contacto' => $esGestion ? $validated['tipo_contacto'] : $movimiento->tipo_contacto,
            'fecha_prometida' => $esGestion ? ($validated['fecha_prometida'] ?? null) : $movimiento->fecha_prometida,
            'cantidad' => $esCargo ? $validated['cantidad'] : null,
            'precio_unitario' => $esCargo ? $validated['precio_unitario'] : null,
            'modalidad_precio' => $esCargo ? $validated['modalidad_precio'] : null,
            'plazo_meses' => $esCargo ? $validated['plazo_meses'] : null,
            'frecuencia_pago' => $esCargo ? $validated['frecuencia_pago'] : null,
            'monto' => $monto,
            'moneda' => $validated['moneda'],
            'tasa_cambio' => $validated['tasa_cambio'] ?? null,
            'metodo_pago' => $validated['metodo_pago'] ?? null,
            'comentario' => $validated['comentario'] ?? null,
        ]);

        if ($request->hasFile('comprobante')) {
            $this->adjuntarComprimida($movimiento, $request->file('comprobante'), 'comprobantes');

            // abonos creados antes de que este campo existiera (o a los que se les
            // adjunta comprobante por primera vez aqui) nunca tuvieron oportunidad
            // de nacer "pendiente" -- se corrige al momento en que de verdad
            // adquieren un comprobante, sin pisar un estado ya decidido
            if ($movimiento->tipo === 'abono' && $movimiento->estado_validacion === null) {
                $movimiento->update(['estado_validacion' => 'pendiente']);
            }
        }

        if ($esCargo && $request->hasFile('foto_producto')) {
            $this->adjuntarComprimida($movimiento, $request->file('foto_producto'), 'producto');
        }

        activity()
            ->causedBy($request->user())
            ->performedOn($movimiento)
            ->withProperties([
                'motivo' => $validated['motivo_edicion'],
                'antes' => $antes,
                'despues' => $movimiento->only(array_keys($antes)),
            ])
            ->log('actualizacion_movimiento');

        return redirect()->route('clientes.show', $movimiento->cliente_id);
    }

    public function validar(Request $request, MovimientoCuenta $movimiento)
    {
        // la fuente de verdad es si tiene un comprobante real adjunto, no si la
        // columna estado_validacion ya fue poblada -- abonos creados antes de que
        // este campo existiera tienen comprobante pero estado_validacion en null
        abort_if($movimiento->tipo !== 'abono' || ! $movimiento->getFirstMedia('comprobantes'), 422, 'Este movimiento no requiere validacion');

        $nuevoEstado = ($movimiento->estado_validacion ?? 'pendiente') === 'pendiente' ? 'validado' : 'pendiente';

        $movimiento->update([
            'estado_validacion' => $nuevoEstado,
            'validado_por' => $nuevoEstado === 'validado' ? auth()->id() : null,
            'validado_en' => $nuevoEstado === 'validado' ? now() : null,
        ]);

        return redirect()->route('clientes.show', $movimiento->cliente_id);
    }

    public function destroy(Request $request, MovimientoCuenta $movimiento)
    {
        $validated = $request->validate([
            'motivo' => ['required', 'string', 'max:1000'],
        ], [
            'motivo.required' => 'motivo requerido',
        ]);

        $clienteId = $movimiento->cliente_id;

        // soft-delete real (SoftDeletes ya excluye estos registros de cualquier
        // query normal: saldoPendiente/totalCobrado, tabla de Movimientos, PDF,
        // Resumen del dia, Cartelera y KPI todos usan Eloquent, nunca SQL crudo
        // sobre movimientos_cuenta) -- el registro sigue en la BD para auditoria,
        // nunca forceDelete
        $movimiento->update([
            'motivo_eliminacion' => $validated['motivo'],
            'eliminado_por' => auth()->id(),
        ]);
        $movimiento->delete();

        return redirect()->route('clientes.show', $clienteId);
    }
}
