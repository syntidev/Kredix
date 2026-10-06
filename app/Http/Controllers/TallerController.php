<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\ConfiguracionPdf;
use App\Models\MovimientoCuenta;
use App\Models\TicketRepuesto;
use App\Models\TicketTaller;
use App\Models\User;
use App\Services\ImagenUploadService;
use App\Services\Taller\InformeRevision;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class TallerController extends Controller
{
    public function __construct(private ImagenUploadService $imagenUploadService, private InformeRevision $informeRevision)
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

    // selector de mecanico: solo rol_taller, mas el mecanico actual del ticket
    // aunque no lo sea (tickets viejos) para que no se pierda al editar
    private function mecanicosOpciones(?int $mecanicoActualId = null)
    {
        return User::where(fn ($q) => $q->where('rol_taller', true)->where('es_oculto', false))
            ->when($mecanicoActualId, fn ($q) => $q->orWhere('id', $mecanicoActualId))
            ->orderBy('name')
            ->get(['id', 'name']);
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
            // desempate por id -- tickets reales comparten el mismo
            // created_at exacto (al segundo), sin este desempate el orden
            // entre ellos queda indefinido y "Siguiente" en show() no puede
            // navegarlos de forma consistente con lo que ve el usuario aqui
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $tickets->getCollection()->transform(fn ($ticket) => $this->ticketResumen($ticket));

        $misTickets = $request->user()->rol_taller
            ? TicketTaller::with('cliente:id,nombre')
                ->where('mecanico_id', $request->user()->id)
                ->where('estado', 'en_proceso')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($ticket) => $this->ticketResumen($ticket))
            : null;

        return Inertia::render('Taller/Index', [
            'misTickets' => $misTickets,
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
        $ticket->load(['cliente:id,nombre,telefono', 'mecanico:id,name', 'registradoPor:id,name', 'revisadoPor:id,name', 'repuestos']);

        // 'print' (1200px, sin recorte) en vez de 'thumb' (200x200 recortado a
        // cuadrado) -- 'thumb' se ve pixelado al estirarse en el reporte.
        // Fallback al original si 'print' aun no existe (fotos subidas antes
        // de agregar esta conversion, mientras no se regeneren). El layout
        // (ancho % por foto, orientacion) se calcula aqui en PHP -- la vista
        // solo pinta el % ya resuelto, no decide nada (sin flexbox/grid,
        // DomPDF no lo soporta)
        $construirFotos = function (string $coleccion) use ($ticket) {
            $medias = $ticket->getMedia($coleccion);
            $total = $medias->count();

            return $medias->map(function (Media $media) use ($total) {
                $ruta = $media->hasGeneratedConversion('print') && file_exists($media->getPath('print'))
                    ? $media->getPath('print')
                    : $media->getPath();

                if (! file_exists($ruta)) {
                    return null;
                }

                $dimensiones = getimagesize($ruta);
                $esVertical = $dimensiones && $dimensiones[1] > $dimensiones[0];

                // 1 foto: el ancho va en la IMG (centrada dentro de una celda
                // de 100%) -- vertical ocupa menos ancho relativo porque ya
                // gana alto; horizontal aprovecha mas ancho.
                // 2/3 fotos: el ancho va en la TD (columnas reales lado a
                // lado), la IMG llena el 100% de su celda. % exprimido al
                // maximo contra el gap real (ver $gapPadding mas abajo) --
                // antes 31.5%/48% dejaban margen de sobra sin ganancia
                // perceptible de tamano (bug real, confirmado por Carlos con
                // el ticket #7: 31.5% calculado es casi igual al 33% fijo de
                // antes del fix)
                [$tdAnchoPct, $imgAnchoPct] = match (true) {
                    $total <= 1 => [100, $esVertical ? 60 : 85],
                    $total === 2 => [49.5, 100],
                    default => [32.7, 100],
                };

                return [
                    'src' => 'data:'.$media->mime_type.';base64,'.base64_encode(file_get_contents($ruta)),
                    'tdAnchoPct' => $tdAnchoPct,
                    'imgAnchoPct' => $imgAnchoPct,
                ];
            })->filter()->values();
        };

        $fotosEntrada = $construirFotos('entrada');
        $fotosSalida = $construirFotos('salida');
        // columnas por seccion: 1 foto -> 1 col, 2 -> 2, 3+ -> 3 (maximo 3 por
        // fila, el resto envuelve a filas adicionales del mismo ancho)
        $columnasEntrada = min($fotosEntrada->count(), 3);
        $columnasSalida = min($fotosSalida->count(), 3);
        // alto maximo por seccion -- subido respecto al primer intento (el
        // techo anterior era conservador y no dejaba ganar alto real, solo
        // ancho). Probado contra el caso extremo visto en produccion
        // (retrato 1200x2598) + el ticket #7 real (3 entrada + 1 salida) sin
        // volver a romper la paginacion de 2 paginas
        $alturaMaxima = fn (int $total) => match (true) {
            $total <= 1 => 460,
            $total === 2 => 380,
            default => 340,
        };
        $alturaEntrada = $alturaMaxima($fotosEntrada->count());
        $alturaSalida = $alturaMaxima($fotosSalida->count());
        // gap entre columnas reducido al minimo visualmente aceptable (antes
        // 12px/10px) -- 4px real entre columnas en los dos casos (2px de
        // padding por lado), libera ancho real para las fotos en vez de
        // quedar como margen muerto
        $gapPadding = fn (int $total) => match (true) {
            $total === 2 => 2,
            $total >= 3 => 2,
            default => 0,
        };
        $padEntrada = $gapPadding($fotosEntrada->count());
        $padSalida = $gapPadding($fotosSalida->count());

        $logoHost = Configuracion::logoHost();
        $logoMedia = $logoHost->getFirstMedia('logo_empresa');
        $logoBase64 = $logoMedia && file_exists($logoMedia->getPath())
            ? 'data:'.$logoMedia->mime_type.';base64,'.base64_encode(file_get_contents($logoMedia->getPath()))
            : null;

        $mostrarBanners = (bool) ConfiguracionPdf::instancia()->mostrar_banners_en_taller;

        // banner_inferior se dibuja via canvas->page_script() despues del
        // render (ver mas abajo), una vez por cada pagina real generada --
        // ponerlo en el HTML normal solo lo mostraria una vez, en la pagina
        // donde termine cayendo el flujo del documento. Se necesita su ruta +
        // alto real (la conversion 'pdf' ya fija el ancho en 722px, el alto
        // queda proporcional) para reservarle espacio fijo en el margen
        // inferior de @page ANTES de que DomPDF calcule cuanto le queda a las
        // fotos. El canvas de DomPDF trabaja en PUNTOS (pt), no en los px de
        // CSS -- confirmado empiricamente (get_height() de una pagina A4 da
        // 841.89, el alto real en pt, no 1122.52px) -- por eso aqui todo se
        // convierte a pt (*0.75) antes de pasarlo a page_script()/image()
        $bannerInferiorMedia = $mostrarBanners ? ConfiguracionPdf::instancia()->getFirstMedia('banner_inferior') : null;
        $bannerInferiorRuta = $bannerInferiorMedia && file_exists($bannerInferiorMedia->getPath('pdf'))
            ? $bannerInferiorMedia->getPath('pdf')
            : null;
        $bannerInferiorAltoPx = $bannerInferiorRuta ? (getimagesize($bannerInferiorRuta)[1] ?? 0) : 0;
        // 40 = zona donde ya vive el folio/paginacion (yFooter = height-40,
        // mismas unidades que el canvas, pt), 10 de aire, 10 de aire inferior
        // -- @page margin SI es en px de CSS, por eso aqui se queda en px
        $margenPiePx = 80 + ($bannerInferiorRuta ? $bannerInferiorAltoPx + 20 : 0);

        $pdf = Pdf::loadView('pdf.taller-atencion', [
            'ticket' => $ticket,
            'revision' => InformeRevision::tieneContenido($ticket->revision_tecnica) ? InformeRevision::resumen($ticket->revision_tecnica) : null,
            'fotosEntrada' => $fotosEntrada,
            'fotosSalida' => $fotosSalida,
            'columnasEntrada' => $columnasEntrada,
            'columnasSalida' => $columnasSalida,
            'alturaEntrada' => $alturaEntrada,
            'alturaSalida' => $alturaSalida,
            'padEntrada' => $padEntrada,
            'padSalida' => $padSalida,
            'margenPiePx' => $margenPiePx,
            'mostrarBanners' => $mostrarBanners,
            'bannerSuperiorBase64' => $mostrarBanners ? ConfiguracionPdf::bannerBase64('banner_superior') : null,
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

        // banner_inferior en cada pagina -- todo en pt (*0.75 desde px, ver
        // comentario mas arriba): x=36px->27pt, ancho=722px->541.5pt (la
        // misma conversion que ya valida el comentario de ConfiguracionPdf,
        // 541.28pt de ancho de contenido medido con el motor de DomPDF)
        if ($bannerInferiorRuta) {
            $bannerAnchoPt = 722 * 0.75;
            $bannerAltoPt = $bannerInferiorAltoPx * 0.75;
            $bannerYPt = $yFooter - 10 - $bannerAltoPt;
            $canvas->page_script(function ($pageNumber, $pageCount, $canvas) use ($bannerInferiorRuta, $bannerAnchoPt, $bannerAltoPt, $bannerYPt) {
                $canvas->image($bannerInferiorRuta, 27, $bannerYPt, $bannerAnchoPt, $bannerAltoPt);
            });
        }

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
            'mecanicos' => $this->mecanicosOpciones(),
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
        $ticket->load(['cliente:id,nombre,telefono', 'mecanico:id,name', 'registradoPor:id,name', 'revisadoPor:id,name', 'repuestos']);

        // mismo orden que el listado (TallerController::index(),
        // orderByDesc('created_at') + orderByDesc('id')) -- comparacion por
        // tupla (created_at, id), no solo created_at: tickets reales
        // comparten el mismo created_at exacto (al segundo), con solo
        // created_at la query no encontraba nada y el boton quedaba
        // deshabilitado en todos. "Siguiente" = el que sigue mas abajo en
        // una lista descendente por (created_at, id)
        $siguienteTicket = TicketTaller::where(function ($query) use ($ticket) {
            $query->where('created_at', '<', $ticket->created_at)
                ->orWhere(function ($query) use ($ticket) {
                    $query->where('created_at', $ticket->created_at)
                        ->where('id', '<', $ticket->id);
                });
        })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id']);

        return Inertia::render('Taller/Show', [
            'siguienteTicketId' => $siguienteTicket?->id,
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
                'revision' => InformeRevision::tieneContenido($ticket->revision_tecnica) ? [
                    ...InformeRevision::resumen($ticket->revision_tecnica),
                    'revisado_por' => $ticket->revisadoPor?->name,
                    'revisado_en' => $ticket->revisado_en?->format('d/m/Y H:i'),
                    'texto' => $this->informeRevision->generarTexto($ticket),
                ] : null,
            ],
            'mecanicos' => $this->mecanicosOpciones($ticket->mecanico_id),
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
            // solo si la clave viene -- Show.vue ya no envia el checklist y
            // un ?? [] aqui borraria el diagnostico de los tickets viejos
            ...($request->has('diagnostico') ? ['diagnostico' => $validated['diagnostico'] ?? []] : []),
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

        // back(): se sube desde Show.vue y desde Revision.vue, cada una vuelve a si misma
        return back();
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

    public function revision(TicketTaller $ticket)
    {
        $ticket->load(['cliente:id,nombre', 'mecanico:id,name']);
        $catalogo = config('taller');

        return Inertia::render('Taller/Revision', [
            'ticket' => [
                ...$ticket->only(['id', 'tipo', 'bici_marca_modelo', 'categoria_bici', 'talla_rin', 'es_electrica', 'tipo_servicio', 'estado']),
                'cliente' => $ticket->cliente?->nombre,
                'mecanico' => $ticket->mecanico?->name,
                'revision_tecnica' => $ticket->revision_tecnica,
                'fotos_salida' => count($ticket->getMedia('salida')),
            ],
            'catalogo' => [
                'grupos' => collect($catalogo['grupos'])
                    ->reject(fn ($g) => ($g['solo_electrica'] ?? false) && ! $ticket->es_electrica)
                    ->all(),
                'acciones' => $catalogo['acciones'],
                'motivos' => $catalogo['motivos'],
                'tareas' => collect($catalogo['paquetes'][$ticket->tipo_servicio] ?? [])
                    ->mapWithKeys(fn ($clave) => [$clave => $catalogo['tareas'][$clave]])
                    ->all(),
            ],
        ]);
    }

    // autoguardado de Revision.vue (axios, JSON) -- reemplaza el JSON completo
    public function guardarRevision(Request $request, TicketTaller $ticket)
    {
        if ($ticket->estado === 'atendido') {
            return response()->json(['message' => 'El ticket ya esta atendido, la revision no se puede modificar.'], 422);
        }

        $validated = $request->validate([
            'tareas' => ['present', 'array:'.implode(',', array_keys(config('taller.tareas')))],
            'tareas.*' => ['boolean'],
            'componentes' => ['present', 'array:'.implode(',', array_keys(InformeRevision::componentes()))],
            'componentes.*.acciones' => ['present', 'array'],
            'componentes.*.acciones.*' => [Rule::in(array_keys(config('taller.acciones')))],
            'componentes.*.motivos' => ['nullable', 'array'],
            'componentes.*.motivos.*' => [Rule::in(array_keys(config('taller.motivos')))],
            'componentes.*.nota' => ['nullable', 'string', 'max:500'],
        ]);

        // texto generado de la revision ANTERIOR -- si trabajo_realizado sigue
        // siendo ese texto (nadie lo edito a mano), se regenera con cada
        // guardado; si recepcion ya escribio algo propio, no se pisa
        $textoAnterior = $this->informeRevision->generarTexto($ticket);

        $ticket->revision_tecnica = [
            'version' => 1,
            'tareas' => array_map('boolval', $validated['tareas']),
            // sin acciones = no revisado = no aparece en el JSON
            'componentes' => collect($validated['componentes'])
                ->filter(fn ($c) => ! empty($c['acciones']))
                ->map(fn ($c) => [
                    'acciones' => array_values(array_unique($c['acciones'])),
                    'motivos' => in_array('recomendar', $c['acciones'], true) ? array_values(array_unique($c['motivos'] ?? [])) : [],
                    'nota' => in_array('recomendar', $c['acciones'], true) ? trim($c['nota'] ?? '') : '',
                    'origen' => 'manual',
                ])
                ->all(),
        ];
        $ticket->revisado_por = $request->user()->id;
        $ticket->revisado_en = now();

        if (blank($ticket->trabajo_realizado) || $ticket->trabajo_realizado === $textoAnterior) {
            $ticket->trabajo_realizado = $this->informeRevision->generarTexto($ticket);
        }

        $ticket->save();

        return response()->json(['ok' => true]);
    }

    public function marcarAtendido(Request $request, TicketTaller $ticket)
    {
        $esServicioCliente = $ticket->tipo === 'servicio_cliente';

        $tieneRevision = InformeRevision::tieneContenido($ticket->revision_tecnica);

        if (blank($ticket->trabajo_realizado) && ! $tieneRevision) {
            return back()->withErrors(['trabajo_realizado' => 'Debes hacer la revision tecnica o escribir el trabajo realizado antes de marcar el ticket como atendido.']);
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
                $monto = $ticket->totalTicket();

                // cantidad/precio_unitario/modalidad_precio -- un cargo creado
                // manualmente desde el formulario de Clientes SIEMPRE los llena
                // (viene de un producto real); este cargo automatico los dejaba
                // en null. Clientes/Show.vue muestra el monto de un cargo via
                // m.precio_unitario (no m.monto) en la fila del listado -- con
                // null ahi, se veia $0.00 aunque el monto real fuera correcto
                // (bug real, confirmado con ticket #24, 2026-10-02)
                $movimiento = MovimientoCuenta::create([
                    'cliente_id' => $ticket->cliente_id,
                    'fecha' => now(),
                    'tipo' => 'cargo',
                    'descripcion' => "Servicio de taller — {$ticket->bici_marca_modelo}",
                    'cantidad' => 1,
                    'precio_unitario' => $monto,
                    'modalidad_precio' => 'divisa',
                    'monto' => $monto,
                    'moneda' => 'usd',
                    'tasa_cambio' => null,
                    'metodo_pago' => null,
                    'comentario' => "Cargo generado automaticamente desde Taller — Ticket #{$ticket->id}",
                    'registrado_por' => auth()->id(),
                ]);

                $ticket->movimiento_cuenta_id = $movimiento->id;
            }

            // guard permanente: un ticket a credito NUNCA debe quedar
            // "atendido" sin un cargo real en la cuenta del cliente -- si por
            // cualquier motivo la creacion de arriba no dejo un
            // movimiento_cuenta_id valido, toda la transaccion revierte
            // (el ticket NO queda atendido) en vez de quedar a medias.
            // Encontrado real: 8 tickets quedaron asi entre 2026-09-18 y
            // 2026-09-29 porque esta funcion de cargo automatico todavia no
            // existia (se agrego en acc2463, 2026-09-29 16:32) -- este guard
            // no corrige esos retroactivamente, solo blinda el flujo de aqui
            // en adelante
            abort_if($esServicioCliente && ! $pagadoEnTaller && ! $ticket->movimiento_cuenta_id, 500, 'No se pudo generar el cargo del servicio -- el ticket no se marco como atendido. Intenta de nuevo.');

            if (blank($ticket->trabajo_realizado)) {
                $ticket->trabajo_realizado = $this->informeRevision->generarTexto($ticket);
            }
            $ticket->estado = 'atendido';
            $ticket->pagado_en_taller = $pagadoEnTaller;
            $ticket->save();
        });

        return redirect()->route('taller.show', $ticket->id);
    }
}
