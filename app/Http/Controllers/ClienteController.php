<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClienteController extends Controller
{
    public function index()
    {
        return Inertia::render('Clientes/Index', [
            'clientes' => Cliente::latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'cedula' => ['nullable', 'string', 'max:20'],
        ], [
            'nombre.required' => 'nombre requerido',
            'telefono.required' => 'telefono requerido',
            'email.email' => 'email invalido',
            'nombre.max' => 'nombre demasiado largo',
            'telefono.max' => 'telefono demasiado largo',
            'email.max' => 'email demasiado largo',
            'cedula.max' => 'cedula demasiado larga',
        ]);

        Cliente::create($validated);

        return redirect()->route('clientes.index');
    }
}
