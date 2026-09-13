<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\Http\Request;

class ProductoController extends Controller
{
    private const MIN_CARACTERES = 2;

    private const MAX_SUGERENCIAS = 8;

    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < self::MIN_CARACTERES) {
            return response()->json([]);
        }

        $normalizado = Producto::normalizarNombre($q);

        return response()->json(
            Producto::where('nombre', 'like', '%'.$normalizado.'%')
                ->orderByDesc('veces_usado')
                ->limit(self::MAX_SUGERENCIAS)
                ->pluck('nombre')
        );
    }
}
