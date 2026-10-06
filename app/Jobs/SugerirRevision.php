<?php

namespace App\Jobs;

use App\Models\TicketTaller;
use App\Services\Ia\BitacoraIa;
use App\Services\Ia\ClienteIa;
use App\Services\Taller\InformeRevision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

// Al crear el ticket: motivo de ingreso -> componentes probables con accion.
// Se guarda aparte en sugerencias_ia; nunca escribe en revision_tecnica.
class SugerirRevision implements ShouldQueue
{
    use Queueable;

    public const PROMPT_VERSION = 'v1';

    public $timeout = 300;

    public $tries = 2;

    public $deleteWhenMissingModels = true;

    public function __construct(public TicketTaller $ticket)
    {
        $this->onQueue('ia');
    }

    public function handle(ClienteIa $ia): void
    {
        $t = $this->ticket;
        $inicio = hrtime(true);
        $modelo = config('ia.modelo_texto');

        try {
            $r = $ia->chat([
                ['role' => 'system', 'content' => self::prompt()],
                ['role' => 'user', 'content' => json_encode([
                    'motivo' => $t->motivo_ingreso,
                    'paquete' => $t->tipo_servicio,
                    'categoria' => $t->categoria_bici,
                    'electrica' => (bool) $t->es_electrica,
                ], JSON_UNESCAPED_UNICODE)],
            ], ['temperature' => 0, 'max_tokens' => 600, 'timeout' => $this->timeout - 20]);
            $modelo = $r['modelo'];
            $componentes = self::filtrar(ClienteIa::extraerJson($r['texto']), (bool) $t->es_electrica);
        } catch (Throwable $e) {
            BitacoraIa::registrar('sugerir', $t->id, self::PROMPT_VERSION, $modelo, BitacoraIa::ms($inicio), 'error', ['error' => $e->getMessage()]);
            throw $e;
        }

        $t->updateQuietly(['sugerencias_ia' => [
            'componentes' => $componentes,
            'modelo' => $modelo,
            'prompt_version' => self::PROMPT_VERSION,
            'generado_en' => now()->toIso8601String(),
        ]]);
        BitacoraIa::registrar('sugerir', $t->id, self::PROMPT_VERSION, $modelo, BitacoraIa::ms($inicio), 'ok');
    }

    public function failed(Throwable $e): void
    {
        $this->ticket->updateQuietly(['sugerencias_ia' => [
            'estado' => 'error',
            'prompt_version' => self::PROMPT_VERSION,
            'generado_en' => now()->toIso8601String(),
        ]]);
    }

    // Descarta en silencio lo que no esta en config('taller'): son sugerencias, no datos confirmados.
    public static function filtrar(array $datos, bool $esElectrica): array
    {
        $validos = InformeRevision::componentes();
        if (! $esElectrica) {
            $validos = array_diff_key($validos, config('taller.grupos.ebike.componentes'));
        }
        $acciones = array_keys(config('taller.acciones'));
        $motivos = array_keys(config('taller.motivos'));

        $limpio = [];
        foreach ((array) ($datos['componentes'] ?? []) as $clave => $c) {
            $a = array_values(array_intersect((array) ($c['acciones'] ?? []), $acciones));
            if (! isset($validos[$clave]) || ! $a) {
                continue;
            }
            $m = in_array('recomendar', $a, true) ? array_values(array_intersect((array) ($c['motivos'] ?? []), $motivos)) : [];
            $limpio[$clave] = ['acciones' => $a, 'motivos' => $m];
        }

        return $limpio;
    }

    private static function prompt(): string
    {
        $componentes = implode(', ', array_keys(InformeRevision::componentes()));
        $acciones = implode(', ', array_keys(config('taller.acciones')));
        $motivos = implode(', ', array_keys(config('taller.motivos')));

        return <<<PROMPT
        Eres el jefe de un taller de bicicletas en Venezuela. Recibes el motivo de ingreso que dijo el cliente
        (español venezolano: cauchos = neumáticos, tripa = cámara, rolineras = rodamientos, guayas = cables)
        y sugieres qué componentes debe revisar primero el técnico y la acción más probable.

        Responde SOLO con JSON, sin texto ni markdown:
        {"componentes":{"<clave>":{"acciones":["<accion>"],"motivos":["<motivo>"]}}}

        Claves permitidas (ninguna otra): {$componentes}
        Acciones permitidas: {$acciones}
        Motivos permitidos, solo junto a "recomendar": {$motivos}

        Reglas:
        - Solo componentes relacionados con el motivo, entre 1 y 5. Si el motivo no apunta a nada concreto: {"componentes":{}}
        - No sugieras bateria, motor ni cableado si electrica es false.
        PROMPT;
    }
}
