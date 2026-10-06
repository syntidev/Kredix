<?php

namespace App\Services\Ia;

use Illuminate\Support\Str;

// Log propio de llamadas IA, una línea JSON por llamada (storage/logs/ia.jsonl).
// Solo metadatos: nunca la key, el prompt ni datos del cliente.
class BitacoraIa
{
    public static function ruta(): string
    {
        return storage_path('logs/ia.jsonl');
    }

    public static function ms(int $inicioHrtime): int
    {
        return intdiv(hrtime(true) - $inicioHrtime, 1_000_000);
    }

    /** $resultado: ok|error|requiere_revision|descartado */
    public static function registrar(string $job, int $ticketId, string $promptVersion, ?string $modelo, int $ms, string $resultado, array $extra = []): void
    {
        if (isset($extra['error'])) {
            $extra['error'] = Str::limit($extra['error'], 200);
        }

        file_put_contents(self::ruta(), json_encode([
            'fecha' => now()->toIso8601String(),
            'job' => $job,
            'ticket' => $ticketId,
            'prompt_version' => $promptVersion,
            'modelo' => $modelo,
            'ms' => $ms,
            'resultado' => $resultado,
            ...$extra,
        ], JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** @return list<array> últimas $n líneas válidas */
    public static function ultimas(int $n): array
    {
        if (! is_file(self::ruta())) {
            return [];
        }

        // ponytail: lee el archivo completo; rotar o usar tail si pasa de unos MB
        return collect(file(self::ruta(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ->take(-$n)->map(fn ($l) => json_decode($l, true))->filter()->values()->all();
    }
}
