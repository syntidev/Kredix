<?php

namespace App\Http\Middleware;

use App\Services\Taller\Funciones;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Rutas de la Revision tecnica (pantalla, guardado, sugerencias de la IA, texto desde la revision):
// con el interruptor apagado no existen (404)
class RevisionTecnicaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Funciones::revisionTecnica(), 404);

        return $next($request);
    }
}
