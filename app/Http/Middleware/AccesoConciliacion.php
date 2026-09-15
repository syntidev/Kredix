<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AccesoConciliacion
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        abort_unless($usuario?->es_admin || $usuario?->acceso_conciliacion, 403);

        return $next($request);
    }
}
