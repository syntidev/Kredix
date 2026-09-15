<?php

namespace App\Http\Controllers\Concerns;

// "Hector palacios" debe encontrar "Hector Jose Palacios" -- un LIKE de
// frase completa se rompe si falta o sobra una palabra intermedia. Toda
// busqueda por NOMBRE (texto libre, no cedula/telefono que son match
// literal) parte el query en palabras y exige TODAS presentes (orden
// y posicion no importan), un solo punto de verdad para todos los
// buscadores de nombre del sistema (Home, Clientes, Cartera, Conciliacion)
trait BuscaTokenizado
{
    private function tokensDeBusqueda(string $q): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($q))));
    }

    // aplica el filtro multi-token AND sobre $campo dentro de un query builder
    // ya abierto (whereNested), reutilizable por cualquier controller
    private function whereNombreTokenizado($query, string $campo, array $tokens): void
    {
        $query->where(function ($sub) use ($campo, $tokens) {
            foreach ($tokens as $token) {
                $sub->where($campo, 'like', "%{$token}%");
            }
        });
    }
}
