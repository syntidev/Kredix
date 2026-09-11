<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\MovimientoCuenta;
use App\Models\PlanCuota;
use App\Models\ReglaPlazo;
use App\Models\User;
use App\Services\TasaBcvService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');
        $filtro = $request->query('filtro', 'todos');
        $atendidoPor = $request->query('atendido_por'); // null (todos) | id de usuario | 'sin_asignar'

        // saldo por cliente calculado en SQL (subquery), no se cargan los 427
        // clientes con todos sus movimientos a PHP solo para filtrar/paginar
        $saldos = DB::table('movimientos_cuenta')
            ->select('cliente_id', DB::raw("SUM(CASE WHEN tipo = 'cargo' THEN monto WHEN tipo IN ('abono', 'ajuste_devolucion') THEN -monto ELSE 0 END) as saldo"))
            ->whereNull('deleted_at')
            ->groupBy('cliente_id');

        $clientes = Cliente::query()
            ->leftJoinSub($saldos, 'saldos', 'saldos.cliente_id', '=', 'clientes.id')
            ->select('clientes.*', DB::raw('COALESCE(saldos.saldo, 0) as saldo_pendiente'))
            ->when($q, fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('nombre', 'like', "%{$q}%")
                    ->orWhere('cedula', 'like', "%{$q}%")
                    ->orWhere('telefono', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            }))
            ->when($filtro === 'con_saldo', fn ($query) => $query->where(DB::raw('COALESCE(saldos.saldo, 0)'), '>', 0))
            ->when($filtro === 'sin_saldo', fn ($query) => $query->where(DB::raw('COALESCE(saldos.saldo, 0)'), '<=', 0))
            ->when($filtro === 'con_advertencia', fn ($query) => $query->where('clientes.notas', 'like', '%IMPORTADO CON ADVERTENCIA%'))
            ->when($atendidoPor === 'sin_asignar', fn ($query) => $query->whereNull('clientes.usuario_responsable_id'))
            ->when(is_numeric($atendidoPor), fn ($query) => $query->where('clientes.usuario_responsable_id', $atendidoPor))
            ->orderBy('clientes.nombre', 'asc')
            ->paginate(25)
            ->withQueryString();

        // cobertura: cuantos clientes con saldo activo no tienen responsable --
        // conteo independiente de los filtros actuales, siempre visible como chip
        $sinAsignarConSaldo = Cliente::query()
            ->leftJoinSub($saldos, 'saldos', 'saldos.cliente_id', '=', 'clientes.id')
            ->whereNull('clientes.usuario_responsable_id')
            ->where(DB::raw('COALESCE(saldos.saldo, 0)'), '>', 0)
            ->count();

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
                    'fecha' => $m->fecha?->toDateString(),
                    'monto' => $m->monto,
                ])
            : [];

        return Inertia::render('Clientes/Index', [
            'clientes' => $clientes,
            'productosMatch' => $productosMatch,
            'q' => $q,
            'filtro' => $filtro,
            'atendidoPor' => $atendidoPor,
            'usuarios' => User::where('es_oculto', false)->orderBy('name')->get(['id', 'name']),
            'sinAsignarConSaldo' => $sinAsignarConSaldo,
        ]);
    }

    public function show(Cliente $cliente, TasaBcvService $tasaBcvService)
    {
        $movimientosRaw = MovimientoCuenta::where('cliente_id', $cliente->id)
            ->with(['registradoPor:id,name', 'planCuotas'])
            ->orderByRaw('fecha IS NULL, fecha')
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
                'fecha' => $m->fecha?->toDateString(),
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
                'comprobante_thumb_url' => $m->getFirstMediaUrl('comprobantes', 'thumb') ?: null,
                'producto_url' => $m->getFirstMediaUrl('producto') ?: null,
                'producto_thumb_url' => $m->getFirstMediaUrl('producto', 'thumb') ?: null,
                'editado' => $ultimaEdicion !== null,
                'motivo_edicion' => $ultimaEdicion?->getExtraProperty('motivo'),
            ];
        });

        $saldoPendiente = MovimientoCuenta::saldoPendiente($cliente->id);
        $ultimoAbono = $movimientosRaw->where('tipo', 'abono')->whereNotNull('fecha')->last();
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
            'usuarios' => User::where('es_oculto', false)->orderBy('name')->get(['id', 'name']),
            'movimientos' => $movimientos,
            'saldoPendiente' => $saldoPendiente,
            'totalCobrado' => MovimientoCuenta::totalCobrado($cliente->id),
            'totalOtorgado' => (float) MovimientoCuenta::where('cliente_id', $cliente->id)->where('tipo', 'cargo')->sum('monto'),
            'reglas' => ReglaPlazo::orderBy('monto_min')->get(),
            'compromisosCuotas' => $this->compromisosCuotas($movimientosRaw),
            'mensajeWhatsapp' => $mensajeWhatsapp,
            'tasaBcvCargo' => $ultimaTasaBcv ? [
                'rate' => $ultimaTasaBcv['rate'],
                'source' => $ultimaTasaBcv['source'],
                'fetchedAt' => $ultimaTasaBcv['fetched_at'],
                'stale' => $tasaBcvService->isStale(),
            ] : null,
        ]);
    }

    public function estadoCuenta(Cliente $cliente)
    {
        $movimientosRaw = MovimientoCuenta::where('cliente_id', $cliente->id)
            ->with('planCuotas')
            ->orderByRaw('fecha IS NULL, fecha')
            ->orderBy('id')
            ->get();

        $saldo = 0;
        $movimientos = $movimientosRaw->map(function (MovimientoCuenta $m) use (&$saldo) {
            if ($m->tipo !== 'gestion') {
                $saldo += $m->tipo === 'cargo' ? (float) $m->monto : -(float) $m->monto;
            }

            return [
                'fecha' => $m->fecha?->toDateString(),
                'tipo' => $m->tipo,
                'descripcion' => $m->descripcion,
                'monto' => $m->tipo === 'gestion' ? null : (float) $m->monto,
                'saldo_acumulado' => $saldo,
            ];
        });

        $logoHost = Configuracion::logoHost();
        $logoMedia = $logoHost->getFirstMedia('logo_empresa');
        $logoBase64 = $logoMedia && file_exists($logoMedia->getPath())
            ? 'data:'.$logoMedia->mime_type.';base64,'.base64_encode(file_get_contents($logoMedia->getPath()))
            : null;

        $pdf = Pdf::loadView('pdf.estado-cuenta', [
            'cliente' => $cliente,
            'movimientos' => $movimientos,
            'saldoPendiente' => MovimientoCuenta::saldoPendiente($cliente->id),
            'compromisosCuotas' => $this->compromisosCuotas($movimientosRaw),
            'mensajeGlobal' => Configuracion::valorDe('pdf_mensaje_global'),
            'mensajeCliente' => $cliente->mensaje_pdf,
            'empresa' => [
                'razon_social' => Configuracion::valorDe('empresa_razon_social'),
                'rif' => Configuracion::valorDe('empresa_rif'),
                'direccion' => Configuracion::valorDe('empresa_direccion'),
                'telefono' => Configuracion::valorDe('empresa_telefono'),
                'email' => Configuracion::valorDe('empresa_email'),
                'logo_base64' => $logoBase64,
            ],
            'fechaEmision' => now()->format('d/m/Y H:i'),
        ]);

        return $pdf->download('estado-cuenta-'.Str::slug($cliente->nombre).'.pdf');
    }

    public function actualizarMensajePdf(Request $request, Cliente $cliente)
    {
        $validated = $request->validate([
            'mensaje_pdf' => ['nullable', 'string', 'max:2000'],
        ]);

        $cliente->update(['mensaje_pdf' => $validated['mensaje_pdf'] ?? null]);

        return redirect()->route('clientes.show', $cliente->id);
    }

    public function actualizarResponsable(Request $request, Cliente $cliente)
    {
        $validated = $request->validate([
            'usuario_responsable_id' => ['nullable', 'exists:users,id'],
        ]);

        $cliente->update(['usuario_responsable_id' => $validated['usuario_responsable_id'] ?? null]);

        return redirect()->route('clientes.show', $cliente->id);
    }

    // Compromisos de cuotas: RECONSTRUCCION VISUAL via FIFO, no un registro
    // contable. Ningun abono queda vinculado a una cuota especifica en la BD --
    // esto solo toma el total abonado desde la fecha del cargo y lo va aplicando
    // en orden a las cuotas sugeridas, para mostrar un avance aproximado.
    private function compromisosCuotas($movimientosRaw)
    {
        return $movimientosRaw
            ->where('tipo', 'cargo')
            // sin fecha propia el cargo no puede participar del FIFO por fecha --
            // se excluye del reparto visual de cuotas, no rompe ni se adivina
            ->filter(fn (MovimientoCuenta $m) => $m->fecha !== null && $m->planCuotas->isNotEmpty())
            ->map(function (MovimientoCuenta $cargo) use ($movimientosRaw) {
                $totalAbonadoDesde = $movimientosRaw
                    ->where('tipo', 'abono')
                    ->filter(fn (MovimientoCuenta $m) => $m->fecha !== null && $m->fecha->gte($cargo->fecha))
                    ->sum(fn (MovimientoCuenta $m) => (float) $m->monto);

                $restante = $totalAbonadoDesde;

                $cuotas = $cargo->planCuotas->map(function (PlanCuota $cuota) use (&$restante) {
                    $monto = (float) $cuota->monto_sugerido;

                    if ($restante >= $monto) {
                        $estado = 'cubierta';
                        $montoAplicado = $monto;
                        $restante -= $monto;
                    } elseif ($restante > 0) {
                        $estado = 'parcial';
                        $montoAplicado = $restante;
                        $restante = 0;
                    } else {
                        $estado = 'pendiente';
                        $montoAplicado = 0;
                    }

                    return [
                        'numero_cuota' => $cuota->numero_cuota,
                        'monto_sugerido' => $monto,
                        'fecha_esperada' => $cuota->fecha_esperada->toDateString(),
                        'estado' => $estado,
                        'monto_aplicado' => $montoAplicado,
                    ];
                })->values();

                return [
                    'cargo_id' => $cargo->id,
                    'descripcion' => $cargo->descripcion,
                    'fecha' => $cargo->fecha->toDateString(),
                    'monto_total' => (float) $cargo->monto,
                    'cuotas' => $cuotas,
                ];
            })
            ->values();
    }

    public function cartera(Request $request)
    {
        $q = $request->query('q');
        $filtroDias = $request->query('filtro_dias'); // reciente | sin_reciente | fria | nunca

        $movimientos = MovimientoCuenta::orderBy('fecha')->get()->groupBy('cliente_id');

        $clientes = Cliente::all()->map(function (Cliente $c) use ($movimientos) {
            $movs = $movimientos->get($c->id, collect());
            $saldo = $movs->sum(fn (MovimientoCuenta $m) => $m->tipo === 'cargo' ? (float) $m->monto : -(float) $m->monto);
            $ultimoAbono = $movs->where('tipo', 'abono')->whereNotNull('fecha')->last();

            return [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'cedula' => $c->cedula,
                'telefono' => $c->telefono,
                'saldoPendiente' => $saldo,
                'ultimoAbonoFecha' => $ultimoAbono?->fecha->toDateString(),
                'diasDesdeUltimoAbono' => $ultimoAbono ? now()->startOfDay()->diffInDays($ultimoAbono->fecha, true) : null,
            ];
        })
            // orden de severidad: sin abono nunca (null) primero, luego mas dias sin abonar
            ->sortByDesc(fn ($c) => $c['diasDesdeUltimoAbono'] ?? INF)
            ->values();

        // el monto agregado ("Cartera activa") es sensible, mismo dato que ya
        // restringimos en KPI -- solo admin lo ve; no-admin ve un conteo operativo
        // (clientes con al menos un evento activo en Cartelera) en su lugar
        $esAdmin = (bool) auth()->user()?->es_admin;

        // busqueda y filtros de dias operan sobre la tabla paginada -- los
        // agregados (Cartera activa, Clientes con saldo) siempre reflejan el
        // universo completo, mismo patron que sinAsignarConSaldo en Clientes/Index
        $clientesFiltrados = $clientes
            ->when($q, fn ($coll) => $coll->filter(function ($c) use ($q) {
                $needle = mb_strtolower($q);

                return str_contains(mb_strtolower($c['nombre'] ?? ''), $needle)
                    || str_contains(mb_strtolower($c['cedula'] ?? ''), $needle)
                    || str_contains(mb_strtolower($c['telefono'] ?? ''), $needle);
            }))
            ->when($filtroDias === 'reciente', fn ($coll) => $coll->filter(fn ($c) => $c['diasDesdeUltimoAbono'] !== null && $c['diasDesdeUltimoAbono'] <= 30))
            ->when($filtroDias === 'sin_reciente', fn ($coll) => $coll->filter(fn ($c) => $c['diasDesdeUltimoAbono'] !== null && $c['diasDesdeUltimoAbono'] > 30 && $c['diasDesdeUltimoAbono'] <= 90))
            ->when($filtroDias === 'fria', fn ($coll) => $coll->filter(fn ($c) => $c['diasDesdeUltimoAbono'] !== null && $c['diasDesdeUltimoAbono'] > 90))
            ->when($filtroDias === 'nunca', fn ($coll) => $coll->filter(fn ($c) => $c['diasDesdeUltimoAbono'] === null))
            ->values();

        $page = (int) $request->query('page', 1);
        $porPagina = 25;
        $paginador = new LengthAwarePaginator(
            $clientesFiltrados->forPage($page, $porPagina)->values(),
            $clientesFiltrados->count(),
            $porPagina,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return Inertia::render('Cartera/Index', [
            'clientes' => $paginador->toArray(),
            'esAdmin' => $esAdmin,
            'q' => $q,
            'filtroDias' => $filtroDias,
            'totalCarteraActiva' => $esAdmin ? (float) $clientes->sum('saldoPendiente') : null,
            'clientesConSaldo' => $clientes->filter(fn ($c) => $c['saldoPendiente'] > 0)->count(),
            'clientesRequierenSeguimiento' => $esAdmin
                ? null
                : (new CarteleraController())->calcularEventos()->pluck('cliente_id')->unique()->count(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'cedula' => ['nullable', 'string', 'max:20', $this->validarFormatoCedula()],
            'notas' => ['nullable', 'string'],
            'contacto_alterno_nombre' => ['nullable', 'string', 'max:255'],
            'contacto_alterno_telefono' => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
        ], [
            'nombre.required' => 'nombre requerido',
            'telefono.regex' => 'telefono invalido, selecciona el pais y completa el numero',
            'email.email' => 'email invalido',
            'nombre.max' => 'nombre demasiado largo',
            'telefono.max' => 'telefono demasiado largo',
            'email.max' => 'email demasiado largo',
            'cedula.max' => 'cedula demasiado larga',
            'contacto_alterno_nombre.max' => 'nombre de contacto alterno demasiado largo',
            'contacto_alterno_telefono.regex' => 'telefono de contacto alterno invalido, selecciona el pais y completa el numero',
            'contacto_alterno_telefono.max' => 'telefono de contacto alterno demasiado largo',
        ]);

        $validated['nombre'] = mb_strtoupper($validated['nombre'], 'UTF-8');

        Cliente::create($validated);

        return redirect()->route('clientes.index');
    }

    public function update(Request $request, Cliente $cliente)
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'cedula' => ['nullable', 'string', 'max:20', $this->validarFormatoCedula()],
            'notas' => ['nullable', 'string'],
            'contacto_alterno_nombre' => ['nullable', 'string', 'max:255'],
            'contacto_alterno_telefono' => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
        ], [
            'nombre.required' => 'nombre requerido',
            'telefono.regex' => 'telefono invalido, selecciona el pais y completa el numero',
            'email.email' => 'email invalido',
            'nombre.max' => 'nombre demasiado largo',
            'telefono.max' => 'telefono demasiado largo',
            'email.max' => 'email demasiado largo',
            'cedula.max' => 'cedula demasiado larga',
            'contacto_alterno_nombre.max' => 'nombre de contacto alterno demasiado largo',
            'contacto_alterno_telefono.regex' => 'telefono de contacto alterno invalido, selecciona el pais y completa el numero',
            'contacto_alterno_telefono.max' => 'telefono de contacto alterno demasiado largo',
        ]);

        $validated['nombre'] = mb_strtoupper($validated['nombre'], 'UTF-8');

        $cliente->update($validated);

        // vuelve a donde se disparo la edicion (listado o ficha individual),
        // nunca fuerza salir de la ficha al guardar
        return redirect()->back();
    }

    private function validarFormatoCedula(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (str_contains($value, '.') || str_contains($value, ' ')) {
                $fail('La cedula no debe contener puntos ni espacios.');

                return;
            }

            if (! ctype_digit($value)) {
                $fail('La cedula solo debe contener numeros.');

                return;
            }

            if ($value[0] === '0') {
                $fail('La cedula no debe empezar con cero.');
            }
        };
    }

    public function destroy(Cliente $cliente)
    {
        $cliente->delete();

        return redirect()->route('clientes.index');
    }
}
