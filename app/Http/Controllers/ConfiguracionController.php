<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConfiguracionController extends Controller
{
    public function index()
    {
        return Inertia::render('Configuracion/Index', [
            'whatsappIntro' => Configuracion::valorDe('whatsapp_intro'),
            'tasaBcvActual' => Configuracion::valorDe('tasa_bcv_actual'),
            'pdfMensajeGlobal' => Configuracion::valorDe('pdf_mensaje_global'),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'whatsapp_intro' => ['required', 'string', 'max:1000'],
            'tasa_bcv_actual' => ['nullable', 'numeric', 'min:0'],
            'pdf_mensaje_global' => ['nullable', 'string', 'max:2000'],
        ], [
            'whatsapp_intro.required' => 'texto de intro requerido',
        ]);

        Configuracion::updateOrCreate(['clave' => 'whatsapp_intro'], ['valor' => $validated['whatsapp_intro']]);
        Configuracion::updateOrCreate(['clave' => 'tasa_bcv_actual'], ['valor' => $validated['tasa_bcv_actual'] ?? null]);
        Configuracion::updateOrCreate(['clave' => 'pdf_mensaje_global'], ['valor' => $validated['pdf_mensaje_global'] ?? null]);

        return redirect()->route('configuracion.index');
    }
}
