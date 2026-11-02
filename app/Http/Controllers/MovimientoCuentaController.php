<?php

namespace App\Http\Controllers;

use App\Models\Cuota;
use App\Models\MovimientoCuenta;
use App\Models\PlanFinanciamiento;
use App\Services\SerialesVenta;
use App\Services\SincronizaPlanConCargos;
use Illuminate\Validation\ValidationException;
use App\Models\Producto;
use App\Services\ImagenUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MovimientoCuentaController extends Controller
{
    private const DIAS_POR_FRECUENCIA = ['semanal' => 7, 'quincenal' => 15, 'mensual' => 30];

    // ventana de tiempo para detectar un posible abono duplicado -- ajustable
    private const VENTANA_HORAS_DUPLICADO_ABONO = 12;

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

    // pre-chequeo ANTES del submit final -- el frontend llama esto, y solo si
    // hay match muestra el modal de confirmacion. No bloquea nada por si solo:
    // si no hay coincidencia, el formulario sigue su flujo normal sin friccion
    public function verificarAbonoDuplicado(Request $request)
    {
        $validated = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'monto' => ['required', 'numeric'],
            'moneda' => ['nullable', 'in:usd,ves'],
        ]);

        $existente = MovimientoCuenta::where('cliente_id', $validated['cliente_id'])
            ->where('tipo', 'abono')
            ->where('monto', $validated['monto'])
            ->where('moneda', $validated['moneda'] ?? 'usd')
            ->where('created_at', '>=', now()->subHours(self::VENTANA_HORAS_DUPLICADO_ABONO))
            ->with('registradoPor:id,name')
            ->latest('created_at')
            ->first();

        if (! $existente) {
            return response()->json(['duplicado' => null]);
        }

        return response()->json(['duplicado' => [
            'monto' => (float) $existente->monto,
            'hora' => $existente->created_at->format('H:i'),
            'fecha_hoy' => $existente->created_at->isToday(),
            'usuario' => $existente->registradoPor?->name ?? 'desconocido',
        ]]);
    }

    public function store(Request $request)
    {
        $tipo = $request->input('tipo');
        $esCargo = $tipo === 'cargo';
        $esGestion = $tipo === 'gestion';
        $requiereMonto = in_array($tipo, ['abono', 'ajuste_devolucion'], true);

        if ($esCargo) {
            $esFinanciada = $request->boolean('es_financiada');
            // De contado = inicial igual al total, o el interruptor de la UI. El servidor aplica la regla
            // aunque la UI no use el interruptor (antes inicial == total daba 422 "la inicial debe ser menor")
            $totalEnviado = round(collect($request->input('productos', []))
                ->sum(fn ($p) => (float) ($p['cantidad'] ?? 0) * (float) ($p['precio_unitario'] ?? 0)), 2);
            $esContado = $request->boolean('es_contado')
                || ($esFinanciada && $request->filled('monto_inicial') && $totalEnviado > 0
                    && round((float) $request->input('monto_inicial'), 2) === $totalEnviado);

            $validated = $request->validate([
                'cliente_id' => ['required', 'exists:clientes,id'],
                'fecha' => ['required', 'date'],
                'productos' => ['required', 'array', 'min:1'],
                'productos.*.descripcion' => ['required', 'string', 'max:255'],
                'productos.*.cantidad' => ['required', 'numeric', 'min:0.01'],
                'productos.*.precio_unitario' => ['required', 'numeric', 'min:0.01'],
                'productos.*.pendiente_entrega' => ['nullable', 'boolean'],
                'productos.*.lleva_serial' => ['nullable', 'boolean'],
                'productos.*.seriales' => ['nullable', 'array'],
                'productos.*.seriales.*' => ['nullable', 'string', 'max:100'],
                'es_contado' => ['nullable', 'boolean'],
                'modalidad_precio' => ['required', 'in:divisa,bcv'],
                // plazo y frecuencia son del plan: una venta de contado no los lleva
                'plazo_meses' => [Rule::requiredIf(! $esContado), 'nullable', 'integer', 'min:1'],
                'frecuencia_pago' => [Rule::requiredIf(! $esContado), 'nullable', 'in:semanal,quincenal,mensual'],
                'tasa_cambio' => ['nullable', 'numeric', 'min:0.0001'],
                'foto_producto' => ['nullable', 'image', 'max:5120'],
                'usa_plan_cuotas' => ['boolean'],
                'cuotas' => [Rule::requiredIf($request->boolean('usa_plan_cuotas')), 'array'],
                'cuotas.*.numero_cuota' => ['required_with:cuotas', 'integer', 'min:1'],
                'cuotas.*.monto_sugerido' => ['required_with:cuotas', 'numeric', 'min:0.01'],
                'cuotas.*.fecha_esperada' => ['required_with:cuotas', 'date'],
                'es_financiada' => ['boolean'],
                'monto_inicial' => [Rule::requiredIf($esFinanciada), 'nullable', 'numeric', 'min:0'],
                'metodo_pago_inicial' => [Rule::requiredIf(fn () => $esContado || ($esFinanciada && (float) $request->input('monto_inicial', 0) > 0)), 'nullable', 'in:efectivo,zelle,binance,transferencia,pago_movil,bancamiga_divisa,punto_venta,intercambio,devolucion'],
                'numero_cuotas_financiamiento' => [Rule::requiredIf($esFinanciada && ! $esContado), 'nullable', 'integer', 'min:1', 'max:36'],
                'porcentaje_mora' => [Rule::requiredIf($esFinanciada && ! $esContado), 'nullable', 'numeric', 'min:0', 'max:100'],
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

            // el carrito completo se financia: el total es la suma de todas
            // las lineas del mismo envio, no la del primer producto
            $montoTotal = round(collect($validated['productos'])
                ->sum(fn (array $p) => (float) $p['cantidad'] * (float) $p['precio_unitario']), 2);

            // inicial == total es venta de contado (arriba); solo una inicial MAYOR al total es un error
            if ($esFinanciada && ! $esContado && (float) $validated['monto_inicial'] > $montoTotal) {
                throw ValidationException::withMessages([
                    'monto_inicial' => 'La inicial ('.number_format((float) $validated['monto_inicial'], 2).') no puede ser mayor al total de la venta ('.number_format($montoTotal, 2).').',
                ]);
            }

            $primero = DB::transaction(function () use ($request, $validated, $esFinanciada, $esContado, $montoTotal) {
                // seriales: cantidad exacta, distintos y sin repetir un vigente; dentro de la transaccion
                // para que la comprobacion y el alta vayan juntas (el indice unico es el respaldo)
                SerialesVenta::validar($validated['productos']);

                // fecha/modalidad/plazo/frecuencia se comparten entre todos los productos
                // del mismo envio; cada uno crea su propio registro tipo=cargo
                // independiente -- el plan de cuotas y la foto (si se adjunto) quedan
                // en el primero. compra_id une las lineas del mismo envio (el
                // "carrito"): es lo que permite financiar el total de la operacion
                // sin perder el detalle por producto
                $compraId = (string) Str::uuid();

                $movimientos = collect($validated['productos'])->map(function (array $producto) use ($validated, $compraId, $esContado) {
                    $catalogo = Producto::firstOrCreate(
                        ['nombre' => Producto::normalizarNombre($producto['descripcion'])],
                        ['veces_usado' => 0]
                    );
                    $catalogo->increment('veces_usado');
                    // "lleva serial" se aprende: la proxima vez que se venda este articulo sale marcado
                    if (array_key_exists('lleva_serial', $producto)) {
                        $catalogo->update(['lleva_serial' => (bool) $producto['lleva_serial']]);
                    }

                    return MovimientoCuenta::create([
                        'cliente_id' => $validated['cliente_id'],
                        'compra_id' => $compraId,
                        'fecha' => $validated['fecha'],
                        'tipo' => 'cargo',
                        'descripcion' => $producto['descripcion'],
                        'cantidad' => $producto['cantidad'],
                        'precio_unitario' => $producto['precio_unitario'],
                        'modalidad_precio' => $validated['modalidad_precio'],
                        'plazo_meses' => $esContado ? null : $validated['plazo_meses'],
                        'frecuencia_pago' => $esContado ? null : $validated['frecuencia_pago'],
                        'monto' => $producto['cantidad'] * $producto['precio_unitario'],
                        'pendiente_entrega' => (bool) ($producto['pendiente_entrega'] ?? false),
                        'es_contado' => $esContado,
                        'moneda' => 'usd',
                        'tasa_cambio' => $validated['tasa_cambio'] ?? null,
                        'registrado_por' => auth()->id(),
                    ]);
                });

                $primero = $movimientos->first();

                // un renglon de serial por unidad, ligado a SU linea de cargo
                foreach ($validated['productos'] as $i => $producto) {
                    if (! empty($producto['lleva_serial'])) {
                        SerialesVenta::registrar($movimientos[$i], $producto['seriales'], auth()->id());
                    }
                }

                if ($request->hasFile('foto_producto')) {
                    $this->adjuntarComprimida($primero, $request->file('foto_producto'), 'producto');
                }

                if (! $esContado && $request->boolean('usa_plan_cuotas')) {
                    foreach ($validated['cuotas'] as $cuota) {
                        $primero->planCuotas()->create([
                            'numero_cuota' => $cuota['numero_cuota'],
                            'monto_sugerido' => $cuota['monto_sugerido'],
                            'fecha_esperada' => $cuota['fecha_esperada'],
                        ]);
                    }
                }

                if ($esContado) {
                    // sin plan ni cuotas: un abono por el total, por el mismo camino que la inicial del plan
                    $this->registrarPagoInicial($primero, $montoTotal, $validated, [
                        'descripcion' => 'Pago de contado - '.$primero->descripcion.($movimientos->count() > 1 ? ' (+'.($movimientos->count() - 1).' más)' : ''),
                        'comentario' => 'Venta de contado (pago completo)',
                        'es_contado' => true,
                        // mismo compra_id que sus lineas: permite reconciliar el grupo si luego se edita o se borra algo
                        'compra_id' => $compraId,
                    ]);

                    activity()->causedBy(auth()->user())->performedOn($primero)
                        ->withProperties(['compra_id' => $compraId, 'total' => $montoTotal, 'metodo_pago' => $validated['metodo_pago_inicial'], 'lineas' => $movimientos->count()])
                        ->log('venta_contado');
                } elseif ($esFinanciada) {
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
            // sin 'nullable' en la rama ajuste_devolucion/gestion, 'exists'
            // igual corria contra el valor null (la key sigue presente en el
            // request aunque el valor sea null) -- Laravel solo salta reglas
            // no-implicitas cuando el campo es null Y 'nullable' esta en la
            // lista. exists:planes_financiamiento,id contra null siempre
            // fallaba ("selected plan financiamiento id is invalid"),
            // bloqueando CUALQUIER ajuste/devolucion. Bug real, confirmado
            // 2026-10-02. Para tipo=abono 'exists' sigue aplicando normal
            'plan_financiamiento_id' => $tipo === 'abono'
                ? ['nullable', 'exists:planes_financiamiento,id']
                : ['nullable', 'prohibited'],
            'duplicado_confirmado' => ['nullable', 'boolean'],
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
            'referencia' => ($validated['metodo_pago'] ?? null) !== 'efectivo' ? ($validated['referencia'] ?? null) : null,
            'comentario' => $validated['comentario'] ?? null,
            'registrado_por' => auth()->id(),
            // solo tiene sentido para tipo=abono -- el checkbox de confirmacion
            // de duplicado no existe para ajuste_devolucion/gestion
            'duplicado_confirmado' => $tipo === 'abono' && $request->boolean('duplicado_confirmado'),
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

    // Una venta de contado solo queda fuera de KPI/mora/cartera mientras su cargo y su pago netean cero.
    // Si una edicion o un borrado lo rompe (monto distinto, pago eliminado, linea eliminada), TODA la compra
    // vuelve a credito: asi la deuda que quede es visible y nunca se oculta tras la marca es_contado
    private function reconciliarContado(MovimientoCuenta $tocado): void
    {
        if (! $tocado->es_contado || ! $tocado->compra_id) {
            return;
        }

        $filas = MovimientoCuenta::where('compra_id', $tocado->compra_id)->where('es_contado', true)->get();
        $cargos = round((float) $filas->where('tipo', 'cargo')->sum('monto'), 2);
        $abonos = round((float) $filas->where('tipo', 'abono')->sum('monto'), 2);

        if ($filas->isEmpty() || $cargos !== $abonos || $cargos <= 0) {
            MovimientoCuenta::where('compra_id', $tocado->compra_id)->where('es_contado', true)->update(['es_contado' => false]);

            activity()->causedBy(auth()->user())->performedOn($tocado)
                ->withProperties(['compra_id' => $tocado->compra_id, 'cargos' => $cargos, 'abonos' => $abonos])
                ->log('contado_revertido_a_credito');
        }
    }

    // Pago al registrar la venta: la inicial de un plan (con plan_financiamiento_id) o el pago total de una
    // venta de contado (sin plan). Es un abono normal: tasa de cambio y autor por transaccion
    private function registrarPagoInicial(MovimientoCuenta $cargo, float $monto, array $validated, array $extra): MovimientoCuenta
    {
        return MovimientoCuenta::create([
            'cliente_id' => $cargo->cliente_id,
            'fecha' => $cargo->fecha,
            'tipo' => 'abono',
            'monto' => $monto,
            'moneda' => 'usd',
            'metodo_pago' => $validated['metodo_pago_inicial'],
            'tasa_cambio' => $validated['tasa_cambio'] ?? null,
            'registrado_por' => auth()->id(),
            ...$extra,
        ]);
    }

    // Nueva compra financiada en un solo paso: el Cargo ya existe (creado por el
    // caller), aqui se crea el plan, la inicial (abono normal SIN cuota_id -- ya
    // redujo el monto a financiar antes de generar las cuotas) y las N cuotas
    // restantes sobre el saldo remanente
    private function crearPlanFinanciamiento(MovimientoCuenta $cargo, array $validated): void
    {
        // total del carrito (todas las lineas con el mismo compra_id), no solo
        // el cargo que sostiene el plan -- misma fuente que usan la mora y la
        // ficha del cliente, para que nunca puedan discrepar
        $montoTotal = PlanFinanciamiento::montoTotalDeCompra($cargo);
        $montoInicial = (float) $validated['monto_inicial'];
        $numeroCuotas = (int) $validated['numero_cuotas_financiamiento'];
        $montoAFinanciar = round($montoTotal - $montoInicial, 2);

        $plan = PlanFinanciamiento::crearParaCargo($cargo, [
            'monto_inicial' => $montoInicial,
            'porcentaje_mora' => $validated['porcentaje_mora'],
            'frecuencia_pago' => $validated['frecuencia_pago'],
            'numero_cuotas' => $numeroCuotas,
        ]);

        if ($montoInicial > 0) {
            $this->registrarPagoInicial($cargo, $montoInicial, $validated, [
                'descripcion' => "Inicial - {$cargo->descripcion}",
                'plan_financiamiento_id' => $plan->id,
                'es_inicial' => true,
                'comentario' => 'Inicial pactada al financiar la venta',
            ]);
        }

        $dias = self::DIAS_POR_FRECUENCIA[$validated['frecuencia_pago']] ?? 30;
        foreach (PlanFinanciamiento::repartirCuotas($montoAFinanciar, $numeroCuotas) as $i => $montoPactado) {
            $numero = $i + 1;

            $plan->cuotas()->create([
                'numero' => $numero,
                'monto_pactado' => $montoPactado,
                'fecha_vencimiento' => $cargo->fecha->copy()->addDays($dias * $numero),
            ]);
        }
    }

    // Un pago es UNA fila con el monto completo, ligada al plan (no a una
    // cuota). "Llenar y desbordar" lo hace recalcularCascada(): la cuota vigente
    // se completa primero y el excedente pasa a la siguiente; lo que sobre de
    // cubrir TODAS las cuotas se queda en la misma fila (nunca se pierde dinero)
    private function aplicarAbonoAPlan(array $datosBase, float $monto, PlanFinanciamiento $plan, bool $llevaComprobante): MovimientoCuenta
    {
        $fila = MovimientoCuenta::create([
            ...$datosBase,
            'monto' => $monto,
            'plan_financiamiento_id' => $plan->id,
            'estado_validacion' => $llevaComprobante ? 'pendiente' : null,
        ]);

        $plan->recalcularCascada();

        return $fila;
    }

    public function update(Request $request, MovimientoCuenta $movimiento, SincronizaPlanConCargos $sincronizaPlan)
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
            'pendiente_entrega' => ['nullable', 'boolean'],
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
            'metodo_pago', 'referencia', 'comentario', 'pendiente_entrega',
        ]);

        // el plan de financiamiento solo se toca si cambia el monto de la linea; un 422 del
        // recalculo (p. ej. nuevo total menor a lo abonado) deshace tambien la edicion
        $cambiaMonto = $esCargo && round((float) $monto, 2) !== round((float) $movimiento->monto, 2);

        DB::transaction(function () use ($request, $movimiento, $validated, $esCargo, $esGestion, $monto, $cambiaMonto, $sincronizaPlan) {
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
                // solo los cargos; volver a marcar "pendiente" borra la entrega anterior
                ...($esCargo && $request->has('pendiente_entrega')
                    ? ['pendiente_entrega' => $request->boolean('pendiente_entrega')]
                        + ($request->boolean('pendiente_entrega') ? ['entregado_en' => null, 'entregado_por' => null] : [])
                    : []),
            ]);

            if ($cambiaMonto) {
                $sincronizaPlan->alCambiarMonto($movimiento);
            }
        });

        // editar el monto de un abono de plan reparte de nuevo la cascada
        $movimiento->planDeAbono()?->recalcularCascada();

        $this->reconciliarContado($movimiento->fresh());

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

    // Corregir un serial mal digitado: misma normalizacion y unicidad que al venderlo, con huella en ActivityLog
    public function corregirSerial(Request $request, \App\Models\SerialProducto $serial)
    {
        $validated = $request->validate(['serial' => ['required', 'string', 'max:100']], ['serial.required' => 'El serial no puede quedar vacío.']);

        SerialesVenta::corregir($serial, $validated['serial'], $request->user());

        return redirect()->back();
    }

    // "Marcar entregado": cierra la venta adelantada. No toca monto, saldo ni plan
    public function entregar(Request $request, MovimientoCuenta $movimiento)
    {
        abort_if($movimiento->tipo !== 'cargo' || ! $movimiento->pendiente_entrega, 422, 'Este cargo no esta pendiente de entrega');

        $movimiento->update([
            'pendiente_entrega' => false,
            'entregado_en' => now(),
            'entregado_por' => $request->user()->id,
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($movimiento)
            ->withProperties(['entregado_en' => $movimiento->entregado_en->toDateTimeString()])
            ->log('entrega_movimiento');

        return redirect()->back();
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

    public function destroy(Request $request, MovimientoCuenta $movimiento, SincronizaPlanConCargos $sincronizaPlan)
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
        // anular una linea del carrito recalcula o anula su plan; si el plan tiene abonos y era
        // la ultima linea (o el nuevo total no alcanza), el 422 deshace tambien el borrado
        DB::transaction(function () use ($request, $movimiento, $validated, $sincronizaPlan) {
            $movimiento->update([
                'motivo_eliminacion' => $validated['motivo'],
                'eliminado_por' => auth()->id(),
            ]);
            $movimiento->delete();

            if ($movimiento->tipo === 'cargo') {
                $sincronizaPlan->alAnularLinea($movimiento);
                // los seriales de la linea quedan libres (soft-delete) y pueden volver a registrarse
                SerialesVenta::liberarDeLinea($movimiento, $request->user(), 'linea de cargo eliminada: '.$validated['motivo']);
            }
        });

        // anular un abono de plan libera lo que habia cubierto en las cuotas
        $movimiento->planDeAbono()?->recalcularCascada();

        $this->reconciliarContado($movimiento);

        return redirect()->route('clientes.show', $clienteId);
    }
}
