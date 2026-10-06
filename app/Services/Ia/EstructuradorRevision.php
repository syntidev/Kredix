<?php

namespace App\Services\Ia;

use App\Services\Taller\InformeRevision;
use JsonException;

// Dictado del técnico -> JSON de revisión contra el catálogo de config/taller.php.
class EstructuradorRevision
{
    public function __construct(private ClienteIa $ia) {}

    /** @return array{revision: ?array, errores: list<string>, crudo: string, modelo: string, latencia_ms: int} */
    public function estructurar(string $dictado, ?string $modelo = null): array
    {
        $r = $this->ia->chat([
            ['role' => 'system', 'content' => $this->prompt()],
            ['role' => 'user', 'content' => $dictado],
        ], array_filter(['model' => $modelo, 'temperature' => 0, 'max_tokens' => 800]));

        try {
            $revision = ClienteIa::extraerJson($r['texto']);
            $errores = self::validar($revision);
        } catch (JsonException $e) {
            [$revision, $errores] = [null, ['JSON inválido: '.$e->getMessage()]];
        }

        return ['revision' => $revision, 'errores' => $errores, 'crudo' => $r['texto'],
            'modelo' => $r['modelo'], 'latencia_ms' => $r['latencia_ms']];
    }

    /** @return list<string> claves o estructura fuera del catálogo; vacío = válido */
    public static function validar(mixed $revision): array
    {
        if (! is_array($revision) || ! is_array($revision['componentes'] ?? null) || count($revision) !== 1) {
            return ['Estructura inválida: se espera {"componentes":{...}}'];
        }

        $errores = [];
        foreach ($revision['componentes'] as $clave => $c) {
            if (! array_key_exists($clave, InformeRevision::componentes())) {
                $errores[] = "Componente fuera del catálogo: {$clave}";
            }
            $acciones = $c['acciones'] ?? null;
            $motivos = $c['motivos'] ?? [];
            if (! is_array($acciones) || $acciones === [] || ! is_array($motivos)) {
                $errores[] = "{$clave}: acciones/motivos deben ser listas y acciones no vacía";

                continue;
            }
            foreach (array_diff($acciones, array_keys(config('taller.acciones'))) as $a) {
                $errores[] = "{$clave}: acción fuera del catálogo: {$a}";
            }
            foreach (array_diff($motivos, array_keys(config('taller.motivos'))) as $m) {
                $errores[] = "{$clave}: motivo fuera del catálogo: {$m}";
            }
            if ($motivos !== [] && ! in_array('recomendar', $acciones, true)) {
                $errores[] = "{$clave}: motivos solo se permiten con 'recomendar'";
            }
        }

        return $errores;
    }

    private function prompt(): string
    {
        $componentes = implode(', ', array_keys(InformeRevision::componentes()));
        $acciones = implode(', ', array_keys(config('taller.acciones')));
        $motivos = implode(', ', array_keys(config('taller.motivos')));

        return <<<PROMPT
        Eres el asistente de un taller de bicicletas en Venezuela. Conviertes el dictado de un técnico en JSON.
        Vocabulario local: cauchos = neumáticos, tripa = cámara, rolineras = rodamientos, guayas = cables, cassette/piñón = pinones, juego = holgura.

        Responde SOLO con JSON, sin texto ni markdown, con esta forma exacta:
        {"componentes":{"<clave>":{"acciones":["..."],"motivos":["..."],"nota":""}}}

        Claves de componente permitidas (ninguna otra): {$componentes}
        Acciones permitidas (ninguna otra): {$acciones}
        Motivos permitidos, SOLO junto a la acción "recomendar": {$motivos}

        Reglas:
        - Incluye solo lo que el dictado menciona. Lo que no se menciona, no se incluye. Nunca rellenes componentes por "todo bien".
        - Prohibido inventar componentes, acciones o motivos.
        - Un componente con desgaste, juego, ruido, fisura o fuga que el técnico NO reparó lleva "recomendar" y su motivo.
        - Si un trabajo no tiene acción exacta en la lista (por ejemplo "purgué"), usa la acción más cercana y explica en "nota".
        - "nota" es texto corto en español o "" si no hace falta.
        PROMPT;
    }
}
