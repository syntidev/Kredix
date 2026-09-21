<?php

namespace App\Http\Controllers;

use App\Models\Cuota;
use App\Models\MovimientoCuenta;
use App\Models\PlanFinanciamiento;
use App\Models\Producto;
use App\Services\ImagenUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MovimientoCuentaController extends Controller
{
    private const DIAS_POR_FRECUENCIA = ['semanal' => 7, 'quincenal' => 15, 'mensual' => 30];

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
            $esFinanciada = $request->boolean('es_financiada');

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
                'es_financiada' => ['boolean'],
                'monto_inicial' => [Rule::requiredIf($esFinanciada), 'nullable', 'numeric', 'min:0'],
                'metodo_pago_inicial' => [Rule::requiredIf(fn () => $esFinanciada && (float) $request->input('monto_inicial', 0) > 0), 'nullable', 'in:efectivo,zelle,binance,transferencia,pago_movil,bancamiga_divisa,punto_venta,intercambio,devolucion'],
                'numero_cuotas_financiamiento' => [Rule::requiredIf($esFinanciada), 'nullable', 'integer', 'min:1', 'max:36'],
                'porcentaje_mora' => [Rule::requiredIf($esFinanciada), 'nullable', 'numeric', 'min:0', 'max:100'],
            ], [
                'productos.*.descripcion.required' => 'descripcion requerida',
                'productos.*.cantidad.required' => 'cantidad requerida',
                'productos.*.precio_unitario.required' => 'precio unitario requerido',
                'plazo_meses.required' => 'plazo requerido',
                'frecuencia_pago.required' => 'frecuencia de pago requerida',
                'cuotas.required' => 'plan de cuotas requerido',
                'monto_inicial.required' => 'monto inicial requerido',
                'metodo_pago_inicial.required' => 'metodo de pago de la inicial requerido',
                'numero_cuotas_financiamiento.required' => 'numero de cuotas requerido',
                'porcentaje_mora.required' => 'porcentaje de mora requerido',
            ]);

            if ($esFinanciada) {
                abort_if(count($validated['productos']) !== 1, 422, 'Una venta financiada solo admite un producto');

                $montoTotal = $validated['productos'][0]['cantidad'] * $validated['productos'][0]['precio_unitario'];
                abort_if((float) $validated['monto_inicial'] >= $montoTotal, 422, 'La inicial debe ser menor al monto total de la venta');
            }

            $primero = DB::transaction(function () use ($request, $validated, $esFinanciada) {
                // fecha/modalidad/plazo/frecuencia se comparten entre todos los productos
                // del mismo envio; cada uno crea su propio registro tipo=cargo
                // independiente -- el plan de cuotas y la foto (si se adjunto) quedan
                // en el primero, ya que el frontend solo permite cuotas/financiamiento
                // con 1 producto
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

                if ($esFinanciada) {
                    $this->crearPlanFinanciamiento($primero, $validated);
                }

                return $primero;
            });

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
            'plan_financiamiento_id' => [$tipo === 'abono' ? 'nullable' : 'prohibited', 'exists:planes_financiamiento,id'],
        ], [
            'comentario.required' => 'comentario requerido',
            'monto.required' => 'monto requerido',
            'metodo_pago.required' => 'metodo de pago requerido',
            'referencia.required' => 'referencia requerida para este metodo de pago',
            'tipo_contacto.required' => 'tipo de contacto requerido',
        ]);

        $monto = $validated['monto'] ?? 0;

        $datosBase = [
            'cliente_id' => $validated['cliente_id'],
            'fecha' => $validated['fecha'],
            'tipo' => $validated['tipo'],
            'tipo_contacto' => $esGestion ? $validated['tipo_contacto'] : null,
            'fecha_prometida' => $esGestion ? ($validated['fecha_prometida'] ?? null) : null,
            'descripcion' => $validated['descripcion'],
            'moneda' => 'usd',
            'tasa_cambio' => $validated['tasa_cambio'] ?? null,
            'metodo_pago' => $validated['metodo_pago'] ?? null,
            'referencia' => $validated['metodo_pago'] !== 'efectivo' ? ($validated['referencia'] ?? null) : null,
            'comentario' => $validated['comentario'] ?? null,
            'registrado_por' => auth()->id(),
        ];

        $planId = $validated['plan_financiamiento_id'] ?? null;

        // el toggle "no aplica al plan" ya no existe en el frontend, pero se
        // bloquea tambien aqui -- mientras el cliente tenga un plan con saldo
        // pendiente, ningun abono puede "salir por el costado" del plan o el
        // saldo general y las cuotas se desincronizan (bug real, caso Garmin)
        if ($tipo === 'abono' && ! $planId) {
            $tienePlanPendiente = PlanFinanciamiento::whereHas('cargo', fn ($q) => $q->where('cliente_id', $validated['cliente_id']))
                ->whereHas('cuotas', fn ($q) => $q->whereColumn('monto_abonado', '<', 'monto_pactado'))
                ->exists();

            abort_if($tienePlanPendiente, 422, 'Este cliente tiene un plan de financiamiento con saldo pendiente — el abono debe aplicarse a ese plan');
        }

        if ($tipo === 'abono' && $planId) {
            $plan = PlanFinanciamiento::with('cuotas')->findOrFail($planId);
            abort_if($plan->cargo->cliente_id !== (int) $validated['cliente_id'], 422, 'El plan no pertenece a este cliente');

            $movimiento = DB::transaction(function () use ($datosBase, $monto, $plan, $request) {
                return $this->aplicarAbonoAPlan($datosBase, $monto, $plan, $request->hasFile('comprobante'));
            });
        } else {
            $movimiento = MovimientoCuenta::create([
                ...$datosBase,
                'monto' => $monto,
                'estado_validacion' => ($tipo === 'abono' && $request->hasFile('comprobante')) ? 'pendiente' : null,
            ]);
        }

        if ($request->hasFile('comprobante')) {
            $this->adjuntarComprimida($movimiento, $request->file('comprobante'), 'comprobantes');
        }

        return redirect()->route('clientes.show', $validated['cliente_id']);
    }

    // Nueva compra financiada en un solo paso: el Cargo ya existe (creado por el
    // caller), aqui se crea el plan, la inicial (abono normal SIN cuota_id -- ya
    // redujo el monto a financiar antes de generar las cuotas) y las N cuotas
    // restantes sobre el saldo remanente
    private function crearPlanFinanciamiento(MovimientoCuenta $cargo, array $validated): void
    {
        $montoTotal = (float) $cargo->monto;
        $montoInicial = (float) $validated['monto_inicial'];
        $numeroCuotas = (int) $validated['numero_cuotas_financiamiento'];
        $montoAFinanciar = round($montoTotal - $montoInicial, 2);

        $plan = PlanFinanciamiento::create([
            'movimiento_cuenta_id' => $cargo->id,
            'monto_inicial' => $montoInicial,
            'porcentaje_mora' => $validated['porcentaje_mora'],
            'frecuencia_pago' => $validated['frecuencia_pago'],
            'numero_cuotas' => $numeroCuotas,
        ]);

        if ($montoInicial > 0) {
            MovimientoCuenta::create([
                'cliente_id' => $cargo->cliente_id,
                'fecha' => $cargo->fecha,
                'tipo' => 'abono',
                'descripcion' => "Inicial - {$cargo->descripcion}",
                'monto' => $montoInicial,
                'moneda' => 'usd',
                'metodo_pago' => $validated['metodo_pago_inicial'],
                'comentario' => 'Inicial pactada al financiar la venta',
                'registrado_por' => auth()->id(),
            ]);
        }

        $dias = self::DIAS_POR_FRECUENCIA[$validated['frecuencia_pago']] ?? 30;
        $base = floor(($montoAFinanciar / $numeroCuotas) * 100) / 100;
        $acumulado = 0;

        for ($numero = 1; $numero <= $numeroCuotas; $numero++) {
            $esUltima = $numero === $numeroCuotas;
            $montoPactado = $esUltima ? round($montoAFinanciar - $acumulado, 2) : $base;
            $acumulado += $montoPactado;

            $plan->cuotas()->create([
                'numero' => $numero,
                'monto_pactado' => $montoPactado,
                'fecha_vencimiento' => $cargo->fecha->copy()->addDays($dias * $numero),
            ]);
        }
    }

    // "Llenar y desbordar": la cuota vigente (primera con saldo pendiente) se
    // completa primero, el excedente pasa a la siguiente, y asi hasta agotar el
    // abono o las cuotas -- si sobra monto despues de cubrir TODAS las cuotas,
    // el resto se registra como abono normal sin cuota_id (nunca se pierde dinero)
    private function aplicarAbonoAPlan(array $datosBase, float $monto, PlanFinanciamiento $plan, bool $llevaComprobante): MovimientoCuenta
    {
        $restante = $monto;
        $primero = null;

        $cuotasPendientes = $plan->cuotas()->whereColumn('monto_abonado', '<', 'monto_pactado')->orderBy('numero')->get();

        foreach ($cuotasPendientes as $cuota) {
            if ($restante <= 0) {
                break;
            }

            $faltante = round((float) $cuota->monto_pactado - (float) $cuota->monto_abonado, 2);
            $aplicado = min($restante, $faltante);

            $fila = MovimientoCuenta::create([
                ...$datosBase,
                'monto' => $aplicado,
                'cuota_id' => $cuota->id,
            ]);

            $cuota->recalcular();
            $primero ??= $fila;
            $restante = round($restante - $aplicado, 2);
        }

        if ($restante > 0) {
            $sobrante = MovimientoCuenta::create([
                ...$datosBase,
                'monto' => $restante,
            ]);
            $primero ??= $sobrante;
        }

        // el comprobante (uno solo, adjuntado despues por el caller) siempre va
        // sobre la primera fila creada, asi que solo ella puede nacer "pendiente"
        if ($llevaComprobante) {
            $primero->update(['estado_validacion' => 'pendiente']);
        }

        return $primero;
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
