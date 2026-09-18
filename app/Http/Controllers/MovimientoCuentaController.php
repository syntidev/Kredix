<?php

namespace App\Http\Controllers;

use App\Models\MovimientoCuenta;
use App\Models\Producto;
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

        if ($esCargo) {
            $validated = $request->validate([
                'cliente_id' => ['required', 'exists:clientes,id'],
                'fecha' => ['required', 'date'],
                'productos' => ['required', 'array', 'min:1'],
                'productos.*.descripcion' => ['required', 'string', 'max:255'],
                'productos.*.cantidad' => ['required', 'numeric', 'min:0.01'],
                'productos.*.precio_unitario' => ['required', 'numeric', 'min:0.01'],
                'modalidad_precio' => ['required', 'in:divisa,bcv'],
                'plazo_meses' => ['required', 'integer', 'min:1'],
                'frecuencia_pago' => ['required', 'in:semanal,quincenal,mensual'],
                'tasa_cambio' => ['nullable', 'numeric', 'min:0.0001'],
                'foto_producto' => ['nullable', 'image', 'max:5120'],
                'usa_plan_cuotas' => ['boolean'],
                'cuotas' => [Rule::requiredIf($request->boolean('usa_plan_cuotas')), 'array'],
                'cuotas.*.numero_cuota' => ['required_with:cuotas', 'integer', 'min:1'],
                'cuotas.*.monto_sugerido' => ['required_with:cuotas', 'numeric', 'min:0.01'],
                'cuotas.*.fecha_esperada' => ['required_with:cuotas', 'date'],
            ], [
                'productos.*.descripcion.required' => 'descripcion requerida',
                'productos.*.cantidad.required' => 'cantidad requerida',
                'productos.*.precio_unitario.required' => 'precio unitario requerido',
                'plazo_meses.required' => 'plazo requerido',
                'frecuencia_pago.required' => 'frecuencia de pago requerida',
                'cuotas.required' => 'plan de cuotas requerido',
            ]);

            // fecha/modalidad/plazo/frecuencia se comparten entre todos los productos
            // del mismo envio; cada uno crea su propio registro tipo=cargo
            // independiente -- el plan de cuotas y la foto (si se adjunto) quedan
            // en el primero, ya que el frontend solo permite cuotas con 1 producto
            $movimientos = collect($validated['productos'])->map(function (array $producto) use ($validated) {
                $catalogo = Producto::firstOrCreate(
                    ['nombre' => Producto::normalizarNombre($producto['descripcion'])],
                    ['veces_usado' => 0]
                );
                $catalogo->increment('veces_usado');

                return MovimientoCuenta::create([
                    'cliente_id' => $validated['cliente_id'],
                    'fecha' => $validated['fecha'],
                    'tipo' => 'cargo',
                    'descripcion' => $producto['descripcion'],
                    'cantidad' => $producto['cantidad'],
                    'precio_unitario' => $producto['precio_unitario'],
                    'modalidad_precio' => $validated['modalidad_precio'],
                    'plazo_meses' => $validated['plazo_meses'],
                    'frecuencia_pago' => $validated['frecuencia_pago'],
                    'monto' => $producto['cantidad'] * $producto['precio_unitario'],
                    'moneda' => 'usd',
                    'tasa_cambio' => $validated['tasa_cambio'] ?? null,
                    'registrado_por' => auth()->id(),
                ]);
            });

            $primero = $movimientos->first();

            if ($request->hasFile('foto_producto')) {
                $this->adjuntarComprimida($primero, $request->file('foto_producto'), 'producto');
            }

            if ($request->boolean('usa_plan_cuotas')) {
                foreach ($validated['cuotas'] as $cuota) {
                    $primero->planCuotas()->create([
                        'numero_cuota' => $cuota['numero_cuota'],
                        'monto_sugerido' => $cuota['monto_sugerido'],
                        'fecha_esperada' => $cuota['fecha_esperada'],
                    ]);
                }
            }

            return redirect()->route('clientes.show', $validated['cliente_id']);
        }

        $validated = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'tipo' => ['required', 'in:abono,ajuste_devolucion,gestion'],
            'tipo_contacto' => [Rule::requiredIf($esGestion), 'nullable', 'in:llamada,whatsapp,visita,otro'],
            'fecha_prometida' => ['nullable', 'date'],
            'fecha' => ['required', 'date'],
            'descripcion' => ['required', 'string', 'max:255'],
            'tasa_cambio' => ['nullable', 'numeric', 'min:0.0001'],
            'monto' => [Rule::requiredIf($requiereMonto), 'nullable', 'numeric', 'min:0.01'],
            'metodo_pago' => [Rule::requiredIf($requiereMonto), 'nullable', 'in:efectivo,zelle,binance,transferencia,pago_movil,bancamiga_divisa,punto_venta,intercambio,devolucion'],
            'referencia' => [Rule::requiredIf($requiereMonto && $request->input('metodo_pago') !== 'efectivo'), 'nullable', 'string', 'max:255'],
            'comentario' => ['required', 'string', 'max:1000'],
            'comprobante' => ['nullable', 'image', 'max:5120'],
        ], [
            'comentario.required' => 'comentario requerido',
            'monto.required' => 'monto requerido',
            'metodo_pago.required' => 'metodo de pago requerido',
            'referencia.required' => 'referencia requerida para este metodo de pago',
            'tipo_contacto.required' => 'tipo de contacto requerido',
        ]);

        $monto = $validated['monto'] ?? 0;

        $movimiento = MovimientoCuenta::create([
            'cliente_id' => $validated['cliente_id'],
            'fecha' => $validated['fecha'],
            'tipo' => $validated['tipo'],
            'tipo_contacto' => $esGestion ? $validated['tipo_contacto'] : null,
            'fecha_prometida' => $esGestion ? ($validated['fecha_prometida'] ?? null) : null,
            'descripcion' => $validated['descripcion'],
            'monto' => $monto,
            'moneda' => 'usd',
            'tasa_cambio' => $validated['tasa_cambio'] ?? null,
            'metodo_pago' => $validated['metodo_pago'] ?? null,
            'referencia' => $validated['metodo_pago'] !== 'efectivo' ? ($validated['referencia'] ?? null) : null,
            'comentario' => $validated['comentario'] ?? null,
            'registrado_por' => auth()->id(),
            'estado_validacion' => ($tipo === 'abono' && $request->hasFile('comprobante')) ? 'pendiente' : null,
        ]);

        if ($request->hasFile('comprobante')) {
            $this->adjuntarComprimida($movimiento, $request->file('comprobante'), 'comprobantes');
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
            'metodo_pago' => [Rule::requiredIf($requiereMonto), 'nullable', 'in:efectivo,zelle,binance,transferencia,pago_movil,bancamiga_divisa,punto_venta,intercambio,devolucion'],
            'referencia' => [Rule::requiredIf($requiereMonto && $request->input('metodo_pago') !== 'efectivo'), 'nullable', 'string', 'max:255'],
            'comentario' => [Rule::requiredIf(! $esCargo), 'nullable', 'string', 'max:1000'],
            'comprobante' => ['nullable', 'image', 'max:5120'],
            'foto_producto' => ['nullable', 'image', 'max:5120'],
            'motivo_edicion' => ['required', 'string', 'max:500'],
        ], [
            'comentario.required' => 'comentario requerido',
            'monto.required' => 'monto requerido',
            'metodo_pago.required' => 'metodo de pago requerido',
            'referencia.required' => 'referencia requerida para este metodo de pago',
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
            'metodo_pago', 'referencia', 'comentario',
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
            'referencia' => ($validated['metodo_pago'] ?? null) !== 'efectivo' ? ($validated['referencia'] ?? null) : null,
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
        // requiere validacion cualquier abono electronico (metodo_pago != efectivo),
        // tenga o no comprobante adjunto -- abonos historicos importados antes del
        // flujo de comprobantes tambien necesitan conciliarse; efectivo nunca la
        // requiere (mismo criterio que estadoValidacionEfectivo en el frontend)
        abort_if($movimiento->tipo !== 'abono' || $movimiento->metodo_pago === 'efectivo', 422, 'Este movimiento no requiere validacion');

        $nuevoEstado = ($movimiento->estado_validacion ?? 'pendiente') === 'pendiente' ? 'validado' : 'pendiente';

        $movimiento->update([
            'estado_validacion' => $nuevoEstado,
            'validado_por' => $nuevoEstado === 'validado' ? auth()->id() : null,
            'validado_en' => $nuevoEstado === 'validado' ? now() : null,
        ]);

        return redirect()->back();
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
