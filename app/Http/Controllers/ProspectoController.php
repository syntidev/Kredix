<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuscaTokenizado;
use App\Models\Cliente;
use App\Models\Prospecto200k;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProspectoController extends Controller
{
    use BuscaTokenizado;

    private const MIN_CARACTERES_BUSQUEDA = 2;

    private const MAX_RESULTADOS_BUSQUEDA = 30;

    // buscador puro: sin query no hay resultados, nunca un listado de los 700+
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $resultados = [];

        if (mb_strlen($q) >= self::MIN_CARACTERES_BUSQUEDA) {
            $tokens = $this->tokensDeBusqueda($q);

            $resultados = Prospecto200k::query()
                ->where(function ($query) use ($q, $tokens) {
                    $this->whereNombreTokenizado($query, 'nombre', $tokens);
                    $query->orWhere('ci', 'like', "%{$q}%")
                        ->orWhere('telefono', 'like', "%{$q}%")
                        ->orWhere('correo', 'like', "%{$q}%");
                })
                ->orderBy('nombre')
                ->limit(self::MAX_RESULTADOS_BUSQUEDA)
                ->get();
        }

        return Inertia::render('Prospectos/Index', [
            'q' => $q,
            'resultados' => $resultados,
        ]);
    }

    public function promover(Prospecto200k $prospecto)
    {
        abort_if($prospecto->procesado, 409, 'Este prospecto ya fue promovido a cliente.');

        $cliente = Cliente::create([
            'nombre' => mb_strtoupper($prospecto->nombre, 'UTF-8'),
            'telefono' => $prospecto->telefono,
            'email' => $prospecto->correo,
            'cedula' => $prospecto->ci,
        ]);

        $prospecto->update(['procesado' => true, 'cliente_id' => $cliente->id]);

        return redirect()->route('clientes.show', $cliente->id);
    }
}
