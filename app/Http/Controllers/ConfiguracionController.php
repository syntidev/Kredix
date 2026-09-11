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
            'whatsappIntro' => Configuracion::valorDe('whatsapp_intro'),
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
    public function updateWhatsapp(Request $request)
    {
        $validated = $request->validate([
            'whatsapp_intro' => ['required', 'string', 'max:1000'],
        ], [
            'whatsapp_intro.required' => 'texto de intro requerido',
        ]);

        Configuracion::updateOrCreate(['clave' => 'whatsapp_intro'], ['valor' => $validated['whatsapp_intro']]);

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
