<?php

namespace App\Services\Taller;

use App\Models\TicketTaller;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

// Sugerencias de bici salidas del historial de tickets (sin tabla propia). Los tickets eliminados
// (soft-delete) no cuentan. Comparar sin mayusculas, acentos ni espacios sobrantes; una sola bici por
// texto normalizado, con la grafia y los datos del ticket MAS RECIENTE.
class BicisTaller
{
    public const MAX_SUGERENCIAS = 8;

    public const MAX_DEL_CLIENTE = 5;

    public const MIN_CARACTERES = 2;

    public static function normalizar(?string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', Str::lower(Str::ascii((string) $texto))));
    }

    /** @return list<array{bici_marca_modelo: string, categoria_bici: ?string, talla_rin: ?string, es_electrica: bool}> */
    public static function sugerencias(string $busqueda): array
    {
        $q = self::normalizar($busqueda);
        if (mb_strlen($q) < self::MIN_CARACTERES) {
            return [];
        }

        return self::distintas(self::recientes()->filter(fn ($t) => str_contains(self::normalizar($t->bici_marca_modelo), $q)), self::MAX_SUGERENCIAS);
    }

    /** @return list<array{bici_marca_modelo: string, categoria_bici: ?string, talla_rin: ?string, es_electrica: bool}> */
    public static function delCliente(int $clienteId): array
    {
        return self::distintas(self::recientes()->where('cliente_id', $clienteId), self::MAX_DEL_CLIENTE);
    }

    // del mas reciente al mas antiguo (el id desempata tickets del mismo instante)
    private static function recientes(): Collection
    {
        return TicketTaller::query()
            ->whereNotNull('bici_marca_modelo')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'cliente_id', 'bici_marca_modelo', 'categoria_bici', 'talla_rin', 'es_electrica']);
    }

    private static function distintas(Collection $tickets, int $maximo): array
    {
        return $tickets
            ->filter(fn ($t) => self::normalizar($t->bici_marca_modelo) !== '')
            ->unique(fn ($t) => self::normalizar($t->bici_marca_modelo))
            ->take($maximo)
            ->map(fn ($t) => [
                'bici_marca_modelo' => trim($t->bici_marca_modelo),
                'categoria_bici' => $t->categoria_bici,
                'talla_rin' => $t->talla_rin,
                'es_electrica' => (bool) $t->es_electrica,
            ])->values()->all();
    }
}
