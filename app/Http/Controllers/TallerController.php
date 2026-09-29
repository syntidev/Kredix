<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\MovimientoCuenta;
use App\Models\TicketRepuesto;
use App\Models\TicketTaller;
use App\Models\User;
use App\Services\ImagenUploadService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class TallerController extends Controller
{
    public function __construct(private ImagenUploadService $imagenUploadService)
    {
    }

    private function fotosUrls(TicketTaller $ticket, string $coleccion): array
    {
        return $ticket->getMedia($coleccion)->map(fn ($media) => [
            'id' => $media->id,
            'url' => $media->getUrl(),
            'thumb_url' => $media->getUrl('thumb'),
        ])->all();
    }

    private function ticketResumen(TicketTaller $ticket): array
    {
        return [
            'id' => $ticket->id,
            'fecha' => $ticket->created_at->format('Y-m-d'),
            'tipo' => $ticket->tipo,
            'cliente' => $ticket->cliente?->nombre,
            'bici_marca_modelo' => $ticket->bici_marca_modelo,
            'categoria_bici' => $ticket->categoria_bici,
            'talla_rin' => $ticket->talla_rin,
            'es_electrica' => $ticket->es_electrica,
            'tipo_servicio' => $ticket->tipo_servicio,
            'mecanico' => $ticket->mecanico?->name,
            'registrado_por' => $ticket->registradoPor?->name,
            'estado' => $ticket->estado,
        ];
    }

    public function index(Request $request)
    {
        $estado = $request->query('estado');
        $rango = $request->query('rango');
        $tipoServicio = $request->query('tipo_servicio');

        $tickets = TicketTaller::query()
            ->with(['cliente:id,nombre', 'mecanico:id,name', 'registradoPor:id,name'])
            ->when(in_array($estado, ['en_proceso', 'atendido'], true), fn ($query) => $query->where('estado', $estado))
            ->when($rango === 'hoy', fn ($query) => $query->whereDate('created_at', today()))
            ->when($rango === 'esta_semana', fn ($query) => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]))
            ->when($rango === 'este_mes', fn ($query) => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]))
            ->when($tipoServicio === 'vip', fn ($query) => $query->where('tipo_servicio', 'vip'))
            ->when($tipoServicio === 'normal', fn ($query) => $query->where(fn ($q) => $q->whereNull('tipo_servicio')->orWhere('tipo_servicio', '!=', 'vip')))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $tickets->getCollection()->transform(fn ($ticket) => $this->ticketResumen($ticket));

        return Inertia::render('Taller/Index', [
            'tickets' => $tickets,
            'estado' => $estado,
            'rango' => $rango,
            'tipoServicio' => $tipoServicio,
        ]);
    }

    // vista Blade standalone (no Inertia) para imprimir en papel y dejar en
    // el taller -- espejo de respaldo para el mecanico que no usa el sistema
    // digital, siempre en_proceso (tickets abiertos), sin paginar
    public function bitacoraImprimir(Request $request)
    {
        $rango = $request->query('rango');

        $tickets = TicketTaller::query()
            ->with(['cliente:id,nombre', 'mecanico:id,name'])
            ->where('estado', 'en_proceso')
            ->when($rango === 'hoy', fn ($query) => $query->whereDate('created_at', today()))
            ->when($rango === 'esta_semana', fn ($query) => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]))
            ->when($rango === 'este_mes', fn ($query) => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]))
            ->orderByDesc('created_at')
            ->get();

        return view('taller.bitacora-imprimir', [
            'tickets' => $tickets,
            'fechaImpresion' => now()->format('d/m/Y H:i'),
        ]);
    }

    // PDF de atencion del ticket, mismo layout/patron que
    // ClienteController::estadoCuenta() (encabezado, folio en el footer via
    // canvas, headers no-cache -- misma razon: la ruta termina en .pdf y
    // Cloudflare/el navegador la tratarian como archivo estatico cacheable
    // si no fuera por estos headers, pero cada respuesta es especifica de
    // ESTE ticket)
    public function pdfAtencion(Request $request, TicketTaller $ticket)
    {
        $ticket->load(['cliente:id,nombre,telefono', 'mecanico:id,name', 'registradoPor:id,name', 'repuestos']);

        $fotoBase64 = fn (Media $media) => file_exists($media->getPath('thumb'))
            ? 'data:'.$media->mime_type.';base64,'.base64_encode(file_get_contents($media->getPath('thumb')))
            : null;

        $fotosEntrada = $ticket->getMedia('entrada')->map($fotoBase64)->filter()->values();
        $fotosSalida = $ticket->getMedia('salida')->map($fotoBase64)->filter()->values();

        $logoHost = Configuracion::logoHost();
        $logoMedia = $logoHost->getFirstMedia('logo_empresa');
        $logoBase64 = $logoMedia && file_exists($logoMedia->getPath())
            ? 'data:'.$logoMedia->mime_type.';base64,'.base64_encode(file_get_contents($logoMedia->getPath()))
            : null;

        $pdf = Pdf::loadView('pdf.taller-atencion', [
            'ticket' => $ticket,
            'fotosEntrada' => $fotosEntrada,
            'fotosSalida' => $fotosSalida,
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

        $nombreArchivo = 'ticket-'.$ticket->id.'-'.Str::slug($ticket->bici_marca_modelo).'.pdf';

        // folio puramente visual, nunca se persiste -- mismo criterio que
        // estado-cuenta (no es identificador de negocio)
        $folio = 'KRX-TALLER-'.$ticket->id.'-'.now()->format('YmdHis');
        $fechaGeneracion = now()->format('d/m/Y H:i');

        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $font = $pdf->getDomPDF()->getFontMetrics()->getFont('helvetica', 'normal');
        $colorGris = [0.45, 0.45, 0.45];
        $yFooter = $canvas->get_height() - 40;
        $canvas->page_text(36, $yFooter, "{$folio} · Generado el {$fechaGeneracion} por Kredix", $font, 8, $colorGris);
        $canvas->page_text($canvas->get_width() - 130, $yFooter, 'Pagina {PAGE_NUM} de {PAGE_COUNT}', $font, 8, $colorGris);

        $response = $request->boolean('descargar')
            ? $pdf->download($nombreArchivo)
            : $pdf->stream($nombreArchivo);

        return $response->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function create()
    {
        return Inertia::render('Taller/Nuevo', [
            'mecanicos' => User::where('es_oculto', false)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $esServicioCliente = $request->input('tipo') === 'servicio_cliente';

        $esVip = $esServicioCliente && $request->input('tipo_servicio') === 'vip';

        $validated = $request->validate([
            'tipo' => ['required', 'in:servicio_cliente,armado_interno'],
            'cliente_id' => [Rule::requiredIf($esServicioCliente), 'nullable', 'exists:clientes,id'],
            'motivo_ingreso' => [Rule::requiredIf($esServicioCliente), 'nullable', 'string', 'max:1000'],
            'bici_marca_modelo' => ['required', 'string', 'max:255'],
            'categoria_bici' => ['required', 'in:ruta,mtb,otro'],
            'talla_rin' => ['nullable', 'string', 'max:255'],
            'es_electrica' => ['nullable', 'boolean'],
            'tipo_servicio' => [Rule::requiredIf($esServicioCliente), 'nullable', 'in:basico,full,vip,otro'],
            'domicilio_direccion' => [Rule::requiredIf($esVip), 'nullable', 'string', 'max:1000'],
            'monto_servicio' => [Rule::requiredIf($esServicioCliente), 'nullable', 'numeric', 'min:0'],
            'mecanico_id' => ['required', 'exists:users,id'],
            'diagnostico' => ['nullable', 'array'],
            'diagnostico.*.item' => ['required_with:diagnostico', 'string', 'max:255'],
            'diagnostico.*.estado' => ['required_with:diagnostico', 'in:bien,atencion'],
            'diagnostico.*.nota' => ['nullable', 'string', 'max:1000'],
            // VIP se agenda por telefono antes de que el mecanico vea la bici
            // -- exigir foto en ese momento hace imposible registrar la cita.
            // Para el resto de tipos se mantiene obligatoria al crear.
            'fotos_entrada' => [Rule::requiredIf(! $esVip), 'array'],
            'fotos_entrada.*' => ['image', 'max:5120'],
        ], [
            'motivo_ingreso.required' => 'motivo de ingreso requerido',
            'categoria_bici.required' => 'categoria de bici requerida',
            'domicilio_direccion.required' => 'direccion del domicilio requerida para servicio VIP',
            'fotos_entrada.required' => 'al menos 1 foto de entrada requerida',
        ]);

        $ticket = TicketTaller::create([
            'tipo' => $validated['tipo'],
            'cliente_id' => $esServicioCliente ? $validated['cliente_id'] : null,
            'motivo_ingreso' => $esServicioCliente ? $validated['motivo_ingreso'] : null,
            'bici_marca_modelo' => $validated['bici_marca_modelo'],
            'categoria_bici' => $validated['categoria_bici'],
            'talla_rin' => $validated['talla_rin'] ?? '',
            'es_electrica' => $validated['es_electrica'] ?? false,
            'tipo_servicio' => $esServicioCliente ? $validated['tipo_servicio'] : null,
            'domicilio_direccion' => $esVip ? $validated['domicilio_direccion'] : null,
            'monto_servicio' => $esServicioCliente ? $validated['monto_servicio'] : null,
            'diagnostico' => $validated['diagnostico'] ?? [],
            'mecanico_id' => $validated['mecanico_id'],
            'registrado_por' => $request->user()->id,
        ]);

        foreach ($request->file('fotos_entrada', []) as $foto) {
            $rutaComprimida = $this->imagenUploadService->comprimir($foto);
            $ticket->addMedia($rutaComprimida)->usingFileName($foto->getClientOriginalName())->toMediaCollection('entrada');
        }

        return redirect()->route('taller.show', $ticket->id);
    }

    public function show(TicketTaller $ticket)
    {
        $ticket->load(['cliente:id,nombre,telefono', 'mecanico:id,name', 'registradoPor:id,name', 'repuestos']);

        return Inertia::render('Taller/Show', [
            'ticket' => [
                ...$ticket->only([
                    'id', 'tipo', 'motivo_ingreso', 'bici_marca_modelo', 'categoria_bici', 'talla_rin', 'es_electrica',
                    'tipo_servicio', 'domicilio_direccion', 'monto_servicio', 'diagnostico', 'estado', 'mecanico_id', 'trabajo_realizado',
                    'pagado_en_taller', 'movimiento_cuenta_id',
                ]),
                'cliente' => $ticket->cliente,
                'mecanico' => $ticket->mecanico,
                'registrado_por' => $ticket->registradoPor,
                'created_at' => $ticket->created_at->format('Y-m-d'),
                'repuestos' => $ticket->repuestos,
                'fotos_entrada' => $this->fotosUrls($ticket, 'entrada'),
                'fotos_salida' => $this->fotosUrls($ticket, 'salida'),
            ],
            'mecanicos' => User::where('es_oculto', false)->orderBy('name')->get(['id', 'name']),
            // mismo valor ya usado como razon_social en el PDF de estado de
            // cuenta -- una sola fuente de verdad para el nombre del negocio,
            // editable desde Configuracion sin tocar codigo
            'empresaNombre' => \App\Models\Configuracion::valorDe('empresa_razon_social', 'Kredix'),
            // SVG (no requiere Imagick, sirve para el ticket impreso 58mm) --
            // apunta a la ruta real taller.show, nunca una URL adivinada.
            // data-URI en vez de v-html: la declaracion XML del SVG crudo
            // puede romper al inyectarse como innerHTML, un img normal lo evita
            'ticketQrDataUri' => 'data:image/svg+xml;base64,'.base64_encode(
                \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(300)->generate(route('taller.show', $ticket->id))
            ),
        ]);
    }

    public function update(Request $request, TicketTaller $ticket)
    {
        $esServicioCliente = $ticket->tipo === 'servicio_cliente';

        $validated = $request->validate([
            'cliente_id' => [Rule::requiredIf($esServicioCliente), 'nullable', 'exists:clientes,id'],
            'motivo_ingreso' => [Rule::requiredIf($esServicioCliente), 'nullable', 'string', 'max:1000'],
            'bici_marca_modelo' => ['required', 'string', 'max:255'],
            'categoria_bici' => ['required', 'in:ruta,mtb,otro'],
            'talla_rin' => ['nullable', 'string', 'max:255'],
            'es_electrica' => ['nullable', 'boolean'],
            'tipo_servicio' => [Rule::requiredIf($esServicioCliente), 'nullable', 'in:basico,full,vip,otro'],
            'monto_servicio' => [Rule::requiredIf($esServicioCliente), 'nullable', 'numeric', 'min:0'],
            'mecanico_id' => ['required', 'exists:users,id'],
            'diagnostico' => ['nullable', 'array'],
            'diagnostico.*.item' => ['required_with:diagnostico', 'string', 'max:255'],
            'diagnostico.*.estado' => ['required_with:diagnostico', 'in:bien,atencion'],
            'diagnostico.*.nota' => ['nullable', 'string', 'max:1000'],
        ], [
            'cliente_id.required' => 'cliente requerido',
            'motivo_ingreso.required' => 'motivo de ingreso requerido',
            'categoria_bici.required' => 'categoria de bici requerida',
        ]);

        $ticket->update([
            // reasignar cliente_id conserva fotos/diagnostico/repuestos/historial --
            // son todas relaciones/columnas distintas de este update, nada mas se toca
            'cliente_id' => $esServicioCliente ? $validated['cliente_id'] : null,
            'motivo_ingreso' => $esServicioCliente ? $validated['motivo_ingreso'] : null,
            'bici_marca_modelo' => $validated['bici_marca_modelo'],
            'categoria_bici' => $validated['categoria_bici'],
            'talla_rin' => $validated['talla_rin'] ?? '',
            'es_electrica' => $validated['es_electrica'] ?? false,
            'tipo_servicio' => $esServicioCliente ? $validated['tipo_servicio'] : null,
            'monto_servicio' => $esServicioCliente ? $validated['monto_servicio'] : null,
            'mecanico_id' => $validated['mecanico_id'],
            'diagnostico' => $validated['diagnostico'] ?? [],
        ]);

        return redirect()->route('taller.show', $ticket->id);
    }

    public function destroy(Request $request, TicketTaller $ticket)
    {
        $validated = $request->validate([
            'motivo' => ['required', 'string', 'max:1000'],
        ], [
            'motivo.required' => 'motivo requerido',
        ]);

        // soft-delete real, mismo patron que MovimientoCuenta::destroy() -- el
        // registro sigue en la BD para auditoria (visible via withTrashed()),
        // jamas forceDelete
        $ticket->update([
            'motivo_eliminacion' => $validated['motivo'],
            'eliminado_por' => $request->user()->id,
        ]);
        $ticket->delete();

        return redirect()->route('taller.index');
    }

    public function guardarTrabajoRealizado(Request $request, TicketTaller $ticket)
    {
        $validated = $request->validate([
            'trabajo_realizado' => ['required', 'string', 'max:2000'],
        ], [
            'trabajo_realizado.required' => 'trabajo realizado requerido',
        ]);

        $ticket->update($validated);

        return redirect()->route('taller.show', $ticket->id);
    }

    public function subirFotos(Request $request, TicketTaller $ticket, string $coleccion)
    {
        abort_unless(in_array($coleccion, ['entrada', 'salida'], true), 404);

        $request->validate([
            'fotos.*' => ['required', 'image', 'max:5120'],
        ]);

        foreach ($request->file('fotos', []) as $foto) {
            $rutaComprimida = $this->imagenUploadService->comprimir($foto);
            $ticket->addMedia($rutaComprimida)->usingFileName($foto->getClientOriginalName())->toMediaCollection($coleccion);
        }

        return redirect()->route('taller.show', $ticket->id);
    }

    public function agregarRepuesto(Request $request, TicketTaller $ticket)
    {
        $validated = $request->validate([
            'producto' => ['required', 'string', 'max:255'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'precio' => ['required', 'numeric', 'min:0'],
        ]);

        $ticket->repuestos()->create($validated);

        return redirect()->route('taller.show', $ticket->id);
    }

    public function eliminarRepuesto(TicketTaller $ticket, TicketRepuesto $repuesto)
    {
        abort_unless($repuesto->ticket_id === $ticket->id, 404);

        $repuesto->delete();

        return redirect()->route('taller.show', $ticket->id);
    }

    public function marcarAtendido(Request $request, TicketTaller $ticket)
    {
        $esServicioCliente = $ticket->tipo === 'servicio_cliente';

        if (blank($ticket->trabajo_realizado)) {
            return back()->withErrors(['trabajo_realizado' => 'Debes registrar el trabajo realizado antes de marcar el ticket como atendido.']);
        }

        // VIP ya no exige foto de entrada al crear (se agenda antes de ver la
        // bici) -- por eso el cierre debe validarla aqui explicitamente, no
        // solo salida, o un VIP podria cerrar sin haber subido nunca entrada.
        if ($ticket->getMedia('entrada')->isEmpty()) {
            return back()->withErrors(['fotos_entrada' => 'Falta al menos 1 foto de entrada para poder marcar el ticket como atendido.']);
        }

        if ($ticket->getMedia('salida')->isEmpty()) {
            return back()->withErrors(['fotos_salida' => 'Falta al menos 1 foto de salida para poder marcar el ticket como atendido.']);
        }

        // armado_interno no tiene cliente que pague, no aplica -- decision
        // explicita solo se exige para servicio_cliente
        $validated = $request->validate([
            'pagado_en_taller' => [Rule::requiredIf($esServicioCliente), 'boolean'],
        ], [
            'pagado_en_taller.required' => 'indica si el ticket se pago en el momento',
        ]);

        DB::transaction(function () use ($ticket, $esServicioCliente, $validated) {
            $pagadoEnTaller = $esServicioCliente ? (bool) $validated['pagado_en_taller'] : false;

            // si ya tiene movimiento_cuenta_id, el cargo ya se genero antes --
            // no duplicar si el ticket se vuelve a guardar/reabrir
            if ($esServicioCliente && ! $pagadoEnTaller && ! $ticket->movimiento_cuenta_id) {
                $movimiento = MovimientoCuenta::create([
                    'cliente_id' => $ticket->cliente_id,
                    'fecha' => now(),
                    'tipo' => 'cargo',
                    'descripcion' => "Servicio de taller — {$ticket->bici_marca_modelo}",
                    'monto' => $ticket->totalTicket(),
                    'moneda' => 'usd',
                    'tasa_cambio' => null,
                    'metodo_pago' => null,
                    'comentario' => "Cargo generado automaticamente desde Taller — Ticket #{$ticket->id}",
                    'registrado_por' => auth()->id(),
                ]);

                $ticket->movimiento_cuenta_id = $movimiento->id;
            }

            $ticket->estado = 'atendido';
            $ticket->pagado_en_taller = $pagadoEnTaller;
            $ticket->save();
        });

        return redirect()->route('taller.show', $ticket->id);
    }
}
