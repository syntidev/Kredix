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
            // mismo stripper que ya usa buscarMatchParaCliente para CI/telefono --
            // ci/telefono en prospectos_200k se guardan siempre limpios (import los
            // normaliza), asi que si el usuario escribe puntos/guiones el LIKE crudo
            // no encuentra nada aunque el dato exista; nombre/correo NO se normalizan
            $qDigitos = Prospecto200k::normalizarCi($q);

            $resultados = Prospecto200k::query()
                ->where(function ($query) use ($q, $tokens, $qDigitos) {
                    $this->whereNombreTokenizado($query, 'nombre', $tokens);
                    $query->orWhere('ci', 'like', "%{$q}%")
                        ->orWhere('telefono', 'like', "%{$q}%")
                        ->orWhere('correo', 'like', "%{$q}%");
                    if ($qDigitos) {
                        $query->orWhere('ci', 'like', "%{$qDigitos}%")
                            ->orWhere('telefono', 'like', "%{$qDigitos}%");
                    }
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
        abort_if($prospecto->estado === 'fusionado', 409, 'Este prospecto ya fue promovido a cliente.');

        $cliente = Cliente::create([
            'nombre' => mb_strtoupper($prospecto->nombre, 'UTF-8'),
            'telefono' => $prospecto->telefono,
            'email' => $prospecto->correo,
            'cedula' => $prospecto->ci,
        ]);

        $prospecto->update(['estado' => 'fusionado', 'cliente_id' => $cliente->id]);

        return redirect()->route('clientes.show', $cliente->id);
    }
}
