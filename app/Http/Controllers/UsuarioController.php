<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class UsuarioController extends Controller
{
    public function index()
    {
        return Inertia::render('Usuarios/Index', [
            'usuarios' => User::orderBy('name')->get(['id', 'name', 'email', 'es_admin', 'activo']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'es_admin' => ['boolean'],
        ], [
            'name.required' => 'nombre requerido',
            'email.required' => 'email requerido',
            'email.unique' => 'ya existe un usuario con este email',
            'password.required' => 'password requerido',
            'password.min' => 'password debe tener al menos 8 caracteres',
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'es_admin' => $validated['es_admin'] ?? false,
            'activo' => true,
        ]);

        return redirect()->route('usuarios.index');
    }

    public function toggleActivo(User $usuario)
    {
        $usuario->update(['activo' => ! $usuario->activo]);

        return redirect()->route('usuarios.index');
    }
}
