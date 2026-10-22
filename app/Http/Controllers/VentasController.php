<?php

namespace App\Http\Controllers;

use App\Services\VentasService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class VentasController extends Controller
{
    public function __construct(private readonly VentasService $ventas) {}

    public function index(Request $request)
    {
        $rango = $request->query('rango', 'hoy');
        $esLibre = $rango === 'libre';

        // el rango libre trae sus dos fechas; los presets las calcula el servidor en la
        // zona horaria de la app (no la del dispositivo)
        $validated = $request->validate([
            'rango' => ['nullable', Rule::in(VentasService::RANGOS)],
            'desde' => $esLibre ? ['required', 'date_format:Y-m-d'] : ['nullable'],
            'hasta' => $esLibre ? ['required', 'date_format:Y-m-d', 'after_or_equal:desde'] : ['nullable'],
        ], [
            'rango.in' => 'rango no valido',
            'desde.required' => 'indica la fecha desde',
            'hasta.required' => 'indica la fecha hasta',
            'desde.date_format' => 'fecha desde no valida',
            'hasta.date_format' => 'fecha hasta no valida',
            'hasta.after_or_equal' => 'la fecha hasta no puede ser anterior a la fecha desde',
        ]);

        [$desde, $hasta] = $this->ventas->resolverRango($rango, $validated['desde'] ?? null, $validated['hasta'] ?? null);

        return Inertia::render('Ventas/Index', [
            'ventas' => $this->ventas->resumen($desde, $hasta),
            'rango' => $rango,
        ]);
    }
}
