<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\ConfiguracionPdf;
use App\Services\ImagenUploadService;
use App\Services\Taller\AjustesTaller;
use App\Services\Taller\PlantillasWhatsapp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ConfiguracionController extends Controller
{
    private const CLAVES_EMPRESA = ['empresa_razon_social', 'empresa_rif', 'empresa_direccion', 'empresa_telefono', 'empresa_email'];

    private const COLECCIONES_BANNER_PDF = ['banner_superior', 'banner_inferior'];

    public function __construct(private ImagenUploadService $imagenUploadService)
    {
    }

    public function index()
    {
        return Inertia::render('Configuracion/Index', [
            // bloque "Taller" de la pestana WhatsApp: texto vigente (guardado u original) y el original
            'plantillasTaller' => collect(PlantillasWhatsapp::CAMPOS)->map(fn (array $c, string $campo) => [
                'campo' => $campo,
                'etiqueta' => $c['etiqueta'],
                'variables' => $c['variables'],
                'texto' => PlantillasWhatsapp::texto($campo),
                'defecto' => PlantillasWhatsapp::defecto($campo),
            ])->values(),
            // pestana Taller: solo admin edita; el dato no viaja a quien no lo ve
            'ajustesTaller' => request()->user()->es_admin ? [
                'tecnicos' => AjustesTaller::tecnicos(),
                'tecnico_defecto_id' => AjustesTaller::tecnicoDefectoId(),
                'comision_servicio_pct' => AjustesTaller::comisionServicioPct(),
                'comision_repuestos_pct' => AjustesTaller::comisionRepuestosPct(),
                'presets_servicio' => AjustesTaller::presetsServicio(),
            ] : null,
            // plantillas de cobranza: las ve y edita todo usuario que no sea de solo Taller (el middleware ya lo excluye)
            'plantillasCobranza' => collect(PlantillasWhatsapp::COBRANZA)->map(fn (array $c, string $tipo) => [
                'campo' => $tipo,
                'etiqueta' => $c['etiqueta'],
                'variables' => $c['variables'],
                'texto' => PlantillasWhatsapp::texto($tipo, 'cobranza'),
                'defecto' => PlantillasWhatsapp::defecto($tipo, 'cobranza'),
            ])->values(),
            'tasaBcvActual' => Configuracion::valorDe('tasa_bcv_actual'),
            'pdfMensajeGlobal' => Configuracion::valorDe('pdf_mensaje_global'),
            'bannerSuperiorUrl' => ConfiguracionPdf::instancia()->getFirstMediaUrl('banner_superior', 'pdf') ?: null,
            'bannerInferiorUrl' => ConfiguracionPdf::instancia()->getFirstMediaUrl('banner_inferior', 'pdf') ?: null,
            'mostrarBannersEnTaller' => (bool) ConfiguracionPdf::instancia()->mostrar_banners_en_taller,
            'empresaRazonSocial' => Configuracion::valorDe('empresa_razon_social'),
            'empresaRif' => Configuracion::valorDe('empresa_rif'),
            'empresaDireccion' => Configuracion::valorDe('empresa_direccion'),
            'empresaTelefono' => Configuracion::valorDe('empresa_telefono'),
            'empresaEmail' => Configuracion::valorDe('empresa_email'),
            'empresaLogoUrl' => Configuracion::logoHost()->getFirstMediaUrl('logo_empresa') ?: null,
        ]);
    }

    // LEGADO: plantillas 1-3 de la ficha (ya no se usan en pantalla: las reemplaza el selector de cobranza). Se conserva el
    // endpoint porque tiene pruebas del Taller que no se tocan en este lote.
    // 3 endpoints independientes (uno por tab) en vez de un update() unico --
    // guardar un tab nunca valida ni escribe las claves de los otros dos.
    // Las 3 cajas + la plantilla activa se guardan juntas en un solo submit --
    // plantilla_activa es lo unico obligatorio (hay que elegir una), las cajas
    // de texto son opcionales para no forzar a llenar las 3 de una
    public function updateWhatsapp(Request $request)
    {
        $validated = $request->validate([
            'whatsapp_intro_1' => ['nullable', 'string', 'max:1000'],
            'whatsapp_intro_2' => ['nullable', 'string', 'max:1000'],
            'whatsapp_intro_3' => ['nullable', 'string', 'max:1000'],
            'whatsapp_plantilla_activa' => ['required', 'in:1,2,3'],
        ], [
            'whatsapp_plantilla_activa.required' => 'elige una plantilla activa',
            'whatsapp_plantilla_activa.in' => 'plantilla activa invalida',
        ]);

        foreach (['whatsapp_intro_1', 'whatsapp_intro_2', 'whatsapp_intro_3'] as $clave) {
            Configuracion::updateOrCreate(['clave' => $clave], ['valor' => $validated[$clave] ?? null]);
        }

        Configuracion::updateOrCreate(['clave' => 'whatsapp_plantilla_activa'], ['valor' => $validated['whatsapp_plantilla_activa']]);

        return redirect()->route('configuracion.index');
    }

    // Bloque "Taller" de la pestana WhatsApp: endpoint propio, asi guardarlo nunca
    // valida ni escribe las plantillas 1-3 ni el radio de la activa. Todas las del
    // taller estan disponibles a la vez (el operador elige al enviar): sin radio.
    // Vacio no se acepta (para volver al original esta "Restablecer"); un texto igual
    // al original se guarda como null y asi sigue al original si algun dia cambia
    public function updateWhatsappTaller(Request $request)
    {
        $reglas = [];
        $mensajes = [];
        foreach (array_keys(PlantillasWhatsapp::CAMPOS) as $campo) {
            $reglas[$campo] = ['required', 'string', 'max:1000', function (string $atributo, mixed $valor, Closure $fallar) use ($campo) {
                if ($error = PlantillasWhatsapp::errorDeVariables($campo, $valor)) {
                    $fallar($error);
                }
            }];
            $mensajes["$campo.required"] = 'El texto no puede quedar vacío; usa «Restablecer al texto original».';
        }

        $validated = $request->validate($reglas, $mensajes);

        foreach (PlantillasWhatsapp::CAMPOS as $campo => $definicion) {
            $texto = trim($validated[$campo]);

            Configuracion::updateOrCreate(
                ['clave' => $definicion['clave']],
                ['valor' => $texto === trim(PlantillasWhatsapp::defecto($campo)) ? null : $texto]
            );
        }

        return redirect()->route('configuracion.index');
    }

    // Bloque "Cobranza" de la pestana WhatsApp (todo usuario no-Taller, lote 10): mismo patron que el del Taller. Un texto igual al
    // original se guarda como null y sigue al original si algun dia cambia
    public function updateWhatsappCobranza(Request $request)
    {
        $reglas = [];
        foreach (array_keys(PlantillasWhatsapp::COBRANZA) as $tipo) {
            $reglas[$tipo] = ['required', 'string', 'max:1000', function (string $atributo, mixed $valor, Closure $fallar) use ($tipo) {
                if ($error = PlantillasWhatsapp::errorDeVariables($tipo, $valor, 'cobranza')) {
                    $fallar($error);
                }
            }];
        }
        $validated = $request->validate($reglas, ['*.required' => 'El texto no puede quedar vacío; usa «Restaurar texto por defecto».']);

        foreach (PlantillasWhatsapp::COBRANZA as $tipo => $definicion) {
            $texto = trim($validated[$tipo]);
            Configuracion::updateOrCreate(
                ['clave' => $definicion['clave']],
                ['valor' => $texto === trim(PlantillasWhatsapp::defecto($tipo, 'cobranza')) ? null : $texto]
            );
        }

        return redirect()->route('configuracion.index');
    }

    // pestana Taller (solo admin): tecnico por defecto y porcentajes de comision del KPI
    public function updateTaller(Request $request)
    {
        abort_unless($request->user()->es_admin, 403);

        $validated = $request->validate([
            'tecnico_defecto_id' => ['nullable', Rule::exists('users', 'id')->where('rol_taller', 1)->where('es_oculto', 0)],
            'comision_servicio_pct' => ['required', 'numeric', 'between:0,100'],
            'comision_repuestos_pct' => ['required', 'numeric', 'between:0,100'],
            // servicios rapidos: solo se tocan si el formulario los manda
            'presets_servicio' => ['sometimes', 'nullable', 'array', 'max:'.AjustesTaller::MAX_PRESETS_SERVICIO],
            'presets_servicio.*.nombre' => ['required', 'string', 'min:1', 'max:60'],
            'presets_servicio.*.monto' => ['required', 'numeric', 'between:0,9999.99'],
        ], [
            'presets_servicio.max' => 'Máximo '.AjustesTaller::MAX_PRESETS_SERVICIO.' servicios rápidos.',
            'presets_servicio.*.nombre.required' => 'Cada servicio rápido necesita un nombre.',
            'presets_servicio.*.nombre.max' => 'El nombre del servicio rápido admite hasta 60 caracteres.',
            'presets_servicio.*.monto.required' => 'Cada servicio rápido necesita un monto.',
            'presets_servicio.*.monto.numeric' => 'El monto del servicio rápido debe ser un número.',
            'presets_servicio.*.monto.between' => 'El monto del servicio rápido debe estar entre 0 y 9999.99.',
            'comision_servicio_pct.between' => 'El porcentaje debe estar entre 0 y 100.',
            'comision_repuestos_pct.between' => 'El porcentaje debe estar entre 0 y 100.',
            'tecnico_defecto_id.exists' => 'Elige un técnico válido.',
        ]);

        foreach ([
            AjustesTaller::CLAVE_TECNICO_DEFECTO => $validated['tecnico_defecto_id'] ?? null,
            AjustesTaller::CLAVE_COMISION_SERVICIO => $validated['comision_servicio_pct'],
            AjustesTaller::CLAVE_COMISION_REPUESTOS => $validated['comision_repuestos_pct'],
        ] as $clave => $valor) {
            Configuracion::updateOrCreate(['clave' => $clave], ['valor' => $valor]);
        }

        if (array_key_exists('presets_servicio', $validated)) {
            $presets = collect($validated['presets_servicio'] ?? [])
                ->map(fn ($p) => ['nombre' => trim($p['nombre']), 'monto' => round((float) $p['monto'], 2)])
                ->values()->all();
            Configuracion::updateOrCreate(['clave' => AjustesTaller::CLAVE_PRESETS_SERVICIO], ['valor' => json_encode($presets, JSON_UNESCAPED_UNICODE)]);
        }

        return redirect()->route('configuracion.index');
    }

    public function updateEstadoCuenta(Request $request)
    {
        $validated = $request->validate([
            'pdf_mensaje_global' => ['nullable', 'string', 'max:2000'],
        ]);

        Configuracion::updateOrCreate(['clave' => 'pdf_mensaje_global'], ['valor' => $validated['pdf_mensaje_global'] ?? null]);

        return redirect()->route('configuracion.index');
    }

    public function subirBannerPdf(Request $request, string $coleccion)
    {
        abort_unless(in_array($coleccion, self::COLECCIONES_BANNER_PDF, true), 404);

        $validated = $request->validate([
            'banner' => ['required', 'image', 'max:5120'],
        ], [
            'banner.required' => 'selecciona una imagen',
            'banner.image' => 'el archivo debe ser una imagen',
        ]);

        ConfiguracionPdf::instancia()
            ->addMedia($validated['banner']->getPathname())
            ->usingFileName($validated['banner']->getClientOriginalName())
            ->toMediaCollection($coleccion);

        return redirect()->route('configuracion.index');
    }

    public function quitarBannerPdf(string $coleccion)
    {
        abort_unless(in_array($coleccion, self::COLECCIONES_BANNER_PDF, true), 404);

        ConfiguracionPdf::instancia()->clearMediaCollection($coleccion);

        return redirect()->route('configuracion.index');
    }

    public function updateMostrarBannersTaller(Request $request)
    {
        $validated = $request->validate([
            'mostrar_banners_en_taller' => ['required', 'boolean'],
        ]);

        ConfiguracionPdf::instancia()->update(['mostrar_banners_en_taller' => $validated['mostrar_banners_en_taller']]);

        return redirect()->route('configuracion.index');
    }

    public function updateEmpresa(Request $request)
    {
        $validated = $request->validate([
            'empresa_razon_social' => ['nullable', 'string', 'max:255'],
            'empresa_rif' => ['nullable', 'string', 'max:50'],
            'empresa_direccion' => ['nullable', 'string', 'max:500'],
            'empresa_telefono' => ['nullable', 'string', 'max:50'],
            'empresa_email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ], [
            'empresa_email.email' => 'email invalido',
        ]);

        foreach (self::CLAVES_EMPRESA as $clave) {
            Configuracion::updateOrCreate(['clave' => $clave], ['valor' => $validated[$clave] ?? null]);
        }

        if ($request->hasFile('logo')) {
            $rutaComprimida = $this->imagenUploadService->comprimir($request->file('logo'));

            Configuracion::logoHost()->addMedia($rutaComprimida)
                ->usingFileName($request->file('logo')->getClientOriginalName())
                ->toMediaCollection('logo_empresa');
        }

        return redirect()->route('configuracion.index');
    }
}
