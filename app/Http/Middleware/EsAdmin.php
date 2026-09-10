<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->es_admin) {
            return redirect()->route('home')->with('error', 'Solo un administrador puede acceder a esta seccion.');
        }

        return $next($request);
    }
}
