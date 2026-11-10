<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Configuracion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// Etiquetas de envio e impresion libre (Xprinter 58mm). Los VALORES impresos (nombre, cedula, telefono,
// direccion, texto libre) viven solo en el estado de la pagina: aqui se guardan unicamente los TITULOS de las
// plantillas de "Campos" (Configuracion) y un evento sin contenido en ActivityLog.
class EtiquetasController extends Controller
{
    public const CLAVE_PLANTILLAS = 'etiquetas_plantillas';

    public const MAX_PLANTILLAS = 20;

    public const MAX_CAMPOS = 12;

    // siempre existe, definida en codigo; no se guarda ni se borra
    public const PLANTILLA_ENVIO = ['nombre' => 'Envío', 'campos' => ['Nombre', 'Cédula', 'Teléfono', 'Dirección']];

    public const MODOS = ['libre', 'titulo_texto', 'campos', 'envio', 'ficha_cliente'];

    /** @return list<array{nombre: string, campos: list<string>}> las plantillas guardadas (sin "Envío") */
    private static function guardadas(): array
    {
        $json = json_decode((string) Configuracion::valorDe(self::CLAVE_PLANTILLAS), true);

        return collect(is_array($json) ? $json : [])
            ->filter(fn ($p) => is_array($p) && filled($p['nombre'] ?? null) && is_array($p['campos'] ?? null))
            ->map(fn ($p) => ['nombre' => (string) $p['nombre'], 'campos' => array_values(array_map('strval', $p['campos']))])
            ->values()->all();
    }

    public function index()
    {
        return Inertia::render('Etiquetas/Index', [
            'plantillaEnvio' => self::PLANTILLA_ENVIO,
            'plantillas' => self::guardadas(),
            'limites' => ['plantillas' => self::MAX_PLANTILLAS, 'campos' => self::MAX_CAMPOS],
        ]);
    }

    // datos del cliente para rellenar el modo Envio (se leen, nunca se guardan en otro lado)
    public function cliente(Cliente $cliente)
    {
        return response()->json($cliente->only(['nombre', 'cedula', 'telefono', 'direccion']));
    }

    // reemplaza la lista completa: guardar, renombrar y borrar son la misma operacion
    public function guardarPlantillas(Request $request)
    {
        $validated = $request->validate([
            'plantillas' => ['nullable', 'array', 'max:'.self::MAX_PLANTILLAS],
            'plantillas.*.nombre' => ['required', 'string', 'min:1', 'max:60', Rule::notIn([self::PLANTILLA_ENVIO['nombre']])],
            'plantillas.*.campos' => ['required', 'array', 'min:1', 'max:'.self::MAX_CAMPOS],
            'plantillas.*.campos.*' => ['required', 'string', 'min:1', 'max:40'],
        ], [
            'plantillas.max' => 'Máximo '.self::MAX_PLANTILLAS.' plantillas.',
            'plantillas.*.nombre.required' => 'La plantilla necesita un nombre.',
            'plantillas.*.nombre.max' => 'El nombre de la plantilla admite hasta 60 caracteres.',
            'plantillas.*.nombre.not_in' => 'El nombre «Envío» está reservado.',
            'plantillas.*.campos.max' => 'Una plantilla admite hasta '.self::MAX_CAMPOS.' campos.',
            'plantillas.*.campos.required' => 'La plantilla necesita al menos un campo.',
            'plantillas.*.campos.*.required' => 'Cada campo necesita un título.',
            'plantillas.*.campos.*.max' => 'El título del campo admite hasta 40 caracteres.',
        ]);

        $lista = collect($validated['plantillas'] ?? [])
            ->map(fn ($p) => ['nombre' => trim($p['nombre']), 'campos' => array_values(array_map('trim', $p['campos']))])
            ->values()->all();
        Configuracion::updateOrCreate(['clave' => self::CLAVE_PLANTILLAS], ['valor' => json_encode($lista, JSON_UNESCAPED_UNICODE)]);

        return back();
    }

    // "Imprimir": solo modo/plantilla y usuario. El contenido de la etiqueta no llega aqui nunca
    public function registrarImpresion(Request $request)
    {
        $validated = $request->validate([
            'modo' => ['required', Rule::in(self::MODOS)],
            'plantilla' => ['nullable', 'string', 'max:60'],
        ]);

        activity('etiquetas')->causedBy($request->user())
            ->withProperties(['modo' => $validated['modo'], 'plantilla' => $validated['plantilla'] ?? null])
            ->log('etiqueta_impresa');

        return response()->noContent();
    }
}
