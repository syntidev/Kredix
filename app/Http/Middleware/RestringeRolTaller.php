<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestringeRolTaller
{
    // rutas fuera de /taller que un usuario rol_taller igual necesita: '/',
    // '/dashboard' y '/home' son el aterrizaje post-login (AuthenticatedSessionController
    // redirige a /dashboard, que a su vez redirige a /home -- ambos pasos pasan
    // por este middleware antes de llegar al closure que hace la redireccion,
    // asi que los 3 deben estar aqui o el login mismo queda bloqueado con 403)
    // se redirigen a /taller en vez de bloquear, para no dejar al mecanico sin
    // pantalla de entrada; 'logout' para poder salir, y los 2 buscadores JSON
    // que el modulo Taller consume (repuestos via catalogo de productos,
    // cliente al crear un ticket)
    private const REDIRIGE_A_TALLER = ['/', '/dashboard', '/home'];

    // '/login': si el usuario ya esta autenticado, es la propia guardia 'guest'
    // de Laravel la que debe decidir que hacer (redirige a /dashboard) -- este
    // middleware no debe interponerse antes de que esa logica corra
    private const PERMITIDAS_SIN_REDIRIGIR = ['/logout', '/login', '/productos', '/clientes/buscar', '/clientes/rapido'];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario?->rol_taller) {
            return $next($request);
        }

        $path = '/'.ltrim($request->path(), '/');

        if ($path === '/taller' || str_starts_with($path, '/taller/')) {
            return $next($request);
        }

        if (in_array($path, self::REDIRIGE_A_TALLER, true)) {
            return redirect('/taller');
        }

        if (in_array($path, self::PERMITIDAS_SIN_REDIRIGIR, true)) {
            return $next($request);
        }

        abort(403);
    }
}
