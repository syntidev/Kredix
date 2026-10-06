<?php

namespace App\Jobs;

use App\Models\TicketTaller;
use App\Services\Ia\BitacoraIa;
use App\Services\Ia\ClienteIa;
use App\Services\Taller\InformeRevision;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use JsonException;
use Throwable;
use UnexpectedValueException;

// Revision confirmada -> 3 o 4 frases para el cliente en informe_ia. Nunca toca trabajo_realizado.
// Unico por ticket hasta que arranca: una rafaga de guardados = una llamada. Si la revision cambia
// durante la llamada, el resultado se descarta y se reencola.
class RedactarInforme implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    // v2: guia de voz OnBike (.doc/GUIA_VOZ_ONBIKE.md) con ejemplos few-shot
    public const PROMPT_VERSION = 'v2';

    // jerga prohibida por la guia de voz; si aparece, el texto queda en requiere_revision
    public const JERGA = ['pana', 'chamo', 'vaina', 'burda', 'chevere', 'fino', 'epale', 'quedo full', 'quedaron full'];

    public const RETRASO_MINUTOS = 2;

    public $timeout = 300;

    public $tries = 2;

    public $deleteWhenMissingModels = true;

    public function __construct(public TicketTaller $ticket)
    {
        $this->onQueue('ia');
    }

    public static function encolar(TicketTaller $t): void
    {
        self::dispatch($t)->delay(now()->addMinutes(self::RETRASO_MINUTOS));
    }

    public function uniqueId(): string
    {
        return (string) $this->ticket->id;
    }

    public function handle(ClienteIa $ia): void
    {
        $t = $this->ticket;
        if ($t->informe_ia_estado === 'aprobado' || ! InformeRevision::tieneContenido($t->revision_tecnica)) {
            return;
        }

        $hash = self::hash($t->revision_tecnica);
        $inicio = hrtime(true);
        $modelo = config('ia.modelo_texto');
        $log = fn (string $resultado, array $extra = []) => BitacoraIa::registrar(
            'redactar', $t->id, self::PROMPT_VERSION, $modelo, BitacoraIa::ms($inicio), $resultado, $extra
        );

        try {
            $r = $ia->chat([
                ['role' => 'system', 'content' => self::prompt()],
                ['role' => 'user', 'content' => json_encode(self::entrada($t), JSON_UNESCAPED_UNICODE)],
            ], ['temperature' => 0.3, 'max_tokens' => 600, 'timeout' => $this->timeout - 20]);
            $modelo = $r['modelo'];
        } catch (Throwable $e) {
            $log('error', ['error' => $e->getMessage()]);
            throw $e;
        }

        $actual = $t->fresh();
        if (! $actual || self::hash($actual->revision_tecnica) !== $hash) {
            $log('descartado');
            if ($actual) {
                self::encolar($actual);
            }

            return;
        }

        try {
            $texto = self::validarTexto($r['texto']);
        } catch (JsonException|UnexpectedValueException $e) {
            $actual->updateQuietly(['informe_ia_estado' => 'error']);
            $log('error', ['error' => $e->getMessage()]);

            return;
        }

        $hallazgos = array_filter([
            'menciones' => self::menciones($texto, $actual->revision_tecnica),
            'jerga' => self::jerga($texto),
        ]);
        $actual->updateQuietly([
            'informe_ia' => $texto,
            'informe_ia_estado' => $hallazgos ? 'requiere_revision' : 'listo',
            'informe_ia_generado_en' => now(),
            'informe_ia_hash' => $hash,
            'informe_ia_desactualizado' => false,
        ]);
        $log($hallazgos ? 'requiere_revision' : 'ok', $hallazgos);
    }

    public function failed(Throwable $e): void
    {
        $this->ticket->updateQuietly(['informe_ia_estado' => 'error']);
    }

    // sha1 del JSON con claves ordenadas: MySQL reordena las claves de una columna JSON
    public static function hash(?array $revision): string
    {
        $ordenar = function ($v) use (&$ordenar) {
            if (! is_array($v)) {
                return $v;
            }
            array_is_list($v) ? sort($v) : ksort($v);

            return array_map($ordenar, $v);
        };

        return sha1(json_encode($ordenar($revision ?? [])));
    }

    // Lo unico que ve el modelo: revision confirmada + paquete + bici. Nunca sugerencias_ia.
    public static function entrada(TicketTaller $t): array
    {
        $etiquetas = InformeRevision::componentes();
        $sinNovedad = collect($t->revision_tecnica['componentes'] ?? [])
            ->filter(fn ($c) => ($c['acciones'] ?? []) === ['ok'])
            ->keys()->map(fn ($k) => $etiquetas[$k] ?? $k)->values()->all();

        return [
            'paquete' => $t->tipo_servicio,
            'bici' => $t->bici_marca_modelo,
            'revision' => [...InformeRevision::resumen($t->revision_tecnica), 'sin_novedad' => $sinNovedad],
        ];
    }

    /** @throws JsonException|UnexpectedValueException */
    public static function validarTexto(string $respuesta): string
    {
        $texto = trim((string) (ClienteIa::extraerJson($respuesta)['texto'] ?? ''));
        $largo = mb_strlen($texto);
        if ($largo < 80 || $largo > 700) {
            throw new UnexpectedValueException("texto con {$largo} caracteres (se esperan 80-700)");
        }

        return $texto;
    }

    // Etiquetas de componentes que NO estan en la revision pero aparecen en el texto.
    // ponytail: busqueda por palabras de la etiqueta; si da falsos positivos, mapa de sinonimos por clave.
    public static function menciones(string $texto, ?array $revision): array
    {
        $norm = fn (string $s) => mb_strtolower(Str::ascii($s));
        $terminos = fn (string $etiqueta) => collect(preg_split('/[\/(),]|\s+(?:y|o)\s+/', $norm($etiqueta)))
            ->map(fn ($s) => preg_replace('/^(juego|caja) de /', '', trim($s)))
            ->filter(fn ($s) => strlen($s) >= 4);

        $etiquetas = InformeRevision::componentes();
        $presentes = collect($revision['componentes'] ?? [])->filter(fn ($c) => ! empty($c['acciones']))->keys();
        // terminos compartidos con lo que si esta (ej. "guayas", "centrado de ruedas") no cuentan como ajenos
        $permitido = $presentes->map(fn ($k) => $etiquetas[$k] ?? '')
            ->merge(collect($revision['tareas'] ?? [])->filter()->keys()->map(fn ($k) => config("taller.tareas.{$k}", '')))
            ->map($norm)->implode(' | ');
        $t = $norm($texto);

        return collect($etiquetas)->except($presentes->all())
            ->filter(fn ($etiqueta) => $terminos($etiqueta)->contains(
                fn ($term) => ! str_contains($permitido, $term) && preg_match('/\b'.preg_quote($term, '/').'\b/', $t)
            ))->values()->all();
    }

    public static function jerga(string $texto): array
    {
        $t = mb_strtolower(Str::ascii($texto));

        return array_values(array_filter(self::JERGA, fn ($j) => preg_match('/\b'.preg_quote($j, '/').'\b/', $t)));
    }

    private static function prompt(): string
    {
        $trato = config('ia.trato') === 'usted'
            ? 'Trata al cliente de USTED ("le recomendamos", "su bici"). Los ejemplos están en tú: adapta el trato, no el tono.'
            : 'Trata al cliente de TÚ ("te recomendamos", "tu bici").';

        return <<<PROMPT
        Eres un mecánico de confianza del taller OnBike Margarita (Venezuela). Con el JSON de la revisión técnica
        redactas el mensaje para el cliente. Sabes lo que haces y lo explicas claro: profesional sin ser frío,
        cercano sin ser informal de más.

        Voz:
        - Primera persona del plural: "revisamos", "ajustamos", "te recomendamos".
        - {$trato}
        - 3 o 4 frases completas, máximo 700 caracteres. El detalle técnico ya está en la tabla del PDF.
        - Nombra la bici por su marca o modelo si viene en "bici" ("tu Trek"); si no, "tu bici".
        - Cuando recomiendes algo, di por qué en pocas palabras. Las recomendaciones van al final, con su motivo.
        - Si hay un hallazgo de seguridad (fisura, fuga, freno), menciónalo primero y con claridad, sin alarmar.

        Vocabulario:
        - Sí, términos del oficio usados en Venezuela: cauchos, rolineras, tripa, guayas, piñones, cassette, platos,
          bielas, mazas, pedalier, juego de dirección, horquilla.
        - Prohibida la jerga: pana, chamo, vaina, burda, chévere, fino, épale, "full" como adjetivo ("quedó full").
          Sin groserías, sin diminutivos excesivos, sin exclamaciones múltiples, sin emojis.
        - Prohibido el tono de formulario: "Se realizó…", "Se procedió a…", "Componente intervenido:", "Revisado sin novedad".

        Reglas estrictas:
        - Usa SOLO lo que está en el JSON. Si no está en la revisión, no existe: prohibido mencionar otros componentes,
          trabajos o problemas.
        - Prohibido hablar de precios, montos o costos, prometer fechas o plazos, ofrecer garantías o nombrar a otros clientes.

        Ejemplos de tono (referencia, no copies datos que no estén en el JSON):
        1. Tu Trek quedó lista para rodar. Le hicimos el servicio Full: lubricamos y ajustamos la cadena, y revisamos el resto de los componentes, que están en buen estado. Te recomendamos cambiar el cassette en el próximo servicio, porque ya muestra desgaste y con el tiempo afecta los cambios.
        2. Recibimos tu bici para el servicio Básico y durante la revisión encontramos una fisura en el cuadro, cerca del pedalier. Por seguridad no hicimos ningún trabajo adicional sobre esa zona. Te recomendamos que la evalúe un especialista antes de volver a rodar; cualquier duda, con gusto te explicamos lo que vimos.
        3. Le hicimos el servicio Básico a tu Specialized: lubricamos la cadena, ajustamos frenos y cambios, y calibramos la presión de los cauchos. Revisamos todo lo demás y está en buen estado, así que puedes rodar tranquilo.

        Responde SOLO con JSON, sin markdown: {"texto":"..."}
        PROMPT;
    }
}
