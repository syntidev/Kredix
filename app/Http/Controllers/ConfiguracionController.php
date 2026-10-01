<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Services\ImagenUploadService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConfiguracionController extends Controller
{
    private const CLAVES_EMPRESA = ['empresa_razon_social', 'empresa_rif', 'empresa_direccion', 'empresa_telefono', 'empresa_email'];

    public function __construct(private ImagenUploadService $imagenUploadService)
    {
    }

    public function index()
    {
        return Inertia::render('Configuracion/Index', [
            'whatsappIntro1' => Configuracion::valorDe('whatsapp_intro_1'),
            'whatsappIntro2' => Configuracion::valorDe('whatsapp_intro_2'),
            'whatsappIntro3' => Configuracion::valorDe('whatsapp_intro_3'),
            'whatsappPlantillaActiva' => Configuracion::valorDe('whatsapp_plantilla_activa', '1'),
            'tasaBcvActual' => Configuracion::valorDe('tasa_bcv_actual'),
            'pdfMensajeGlobal' => Configuracion::valorDe('pdf_mensaje_global'),
            'empresaRazonSocial' => Configuracion::valorDe('empresa_razon_social'),
            'empresaRif' => Configuracion::valorDe('empresa_rif'),
            'empresaDireccion' => Configuracion::valorDe('empresa_direccion'),
            'empresaTelefono' => Configuracion::valorDe('empresa_telefono'),
            'empresaEmail' => Configuracion::valorDe('empresa_email'),
            'empresaLogoUrl' => Configuracion::logoHost()->getFirstMediaUrl('logo_empresa') ?: null,
        ]);
    }

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

    public function updateEstadoCuenta(Request $request)
    {
        $validated = $request->validate([
            'pdf_mensaje_global' => ['nullable', 'string', 'max:2000'],
        ]);

        Configuracion::updateOrCreate(['clave' => 'pdf_mensaje_global'], ['valor' => $validated['pdf_mensaje_global'] ?? null]);

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
