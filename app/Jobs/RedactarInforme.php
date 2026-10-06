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
    // v3: reglas finales R1 -- orden fijo, fisura/fuga = especialista (nunca cambiar), nombres componentes_cliente
    public const PROMPT_VERSION = 'v3';

    private const MOTIVOS_SEGURIDAD = ['fisura', 'fuga'];

    private const MAX_TEXTO = 700;

    // mismo texto que InformeRevision::PAQUETE_LABEL (privado en ese archivo)
    private const PAQUETES = ['basico' => 'Básico', 'full' => 'Full', 'vip' => 'VIP'];

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
            $texto = self::validarTexto($r['texto'], self::frasesFijas($actual));
        } catch (JsonException|UnexpectedValueException $e) {
            $actual->updateQuietly(['informe_ia_estado' => 'error']);
            $log('error', ['error' => $e->getMessage()]);

            return;
        }

        $hallazgos = array_filter([
            'menciones' => self::menciones($texto, $actual->revision_tecnica),
            'jerga' => self::jerga($texto),
            'cambio_por_seguridad' => self::cambioPorSeguridad($texto, $actual->revision_tecnica),
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

    // Revision confirmada agrupada en el orden del texto, con nombres de config('taller.componentes_cliente').
    // Sin notas: viven en la revision y en el PDF.
    private static function clasificar(TicketTaller $t): array
    {
        $nombre = fn ($clave) => config("taller.componentes_cliente.{$clave}", $clave);
        $verbos = collect(config('taller.acciones'))->map(fn ($a) => $a['verbo'] ?? null)->filter();
        $r = ['seguridad' => [], 'cambiados' => [], 'otros_trabajos' => [], 'sin_novedad' => [], 'recomendaciones' => []];

        // orden del catalogo, no del JSON -- MySQL reordena las claves de una columna JSON
        foreach (array_keys(InformeRevision::componentes()) as $clave) {
            $acciones = $t->revision_tecnica['componentes'][$clave]['acciones'] ?? [];
            $motivos = $t->revision_tecnica['componentes'][$clave]['motivos'] ?? [];
            $seguridad = array_values(array_intersect($motivos, self::MOTIVOS_SEGURIDAD));

            if (in_array('cambiado', $acciones, true)) {
                $r['cambiados'][] = $nombre($clave);
            }
            if ($otros = array_values(array_intersect_key($verbos->all(), array_flip(array_diff($acciones, ['cambiado']))))) {
                $r['otros_trabajos'][] = ['componente' => $nombre($clave), 'hicimos' => $otros];
            }
            if ($acciones === ['ok']) {
                $r['sin_novedad'][] = $nombre($clave);
            }
            if (in_array('recomendar', $acciones, true)) {
                $seguridad
                    ? $r['seguridad'][] = ['componente' => $nombre($clave), 'hallazgos' => $seguridad]
                    : $r['recomendaciones'][] = ['componente' => $nombre($clave), 'motivos' => $motivos];
            }
        }

        return $r;
    }

    // Lo unico que ve el modelo: lo hecho en la revision confirmada + paquete + bici. Nunca sugerencias_ia ni
    // notas. Seguridad y recomendaciones no pasan por el modelo: son frases fijas (frasesFijas).
    public static function entrada(TicketTaller $t): array
    {
        $r = self::clasificar($t);
        $fijas = self::frasesFijas($t);

        // hechos ya redactados en el orden de la guia (cambiado -> resto); el modelo solo los une
        $porVerbo = [];
        foreach ($r['otros_trabajos'] as $o) {
            foreach ($o['hicimos'] as $verbo) {
                $porVerbo[$verbo][] = $o['componente'];
            }
        }
        $sin = $r['sin_novedad'];
        $hechos = array_values(array_unique(array_filter([
            $r['cambiados'] ? 'cambiamos '.self::unir($r['cambiados']) : null,
            ...array_map(fn ($verbo, $comps) => "{$verbo} ".self::unir($comps), array_keys($porVerbo), $porVerbo),
            ...collect($t->revision_tecnica['tareas'] ?? [])->filter()->keys()->map(fn ($k) => config("taller.tareas_cliente.{$k}"))->all(),
            $sin ? 'revisamos '.self::unir($sin).(count($sin) > 1 || preg_match('/^(los|las) /', $sin[0]) ? ' y están' : ' y está').' en buen estado' : null,
        ])));

        return [
            'bici' => trim((string) $t->bici_marca_modelo) ?: null,
            'paquete' => self::PAQUETES[$t->tipo_servicio] ?? null,
            'hechos' => $hechos,
            'max_caracteres' => self::MAX_TEXTO - mb_strlen(implode(' ', array_filter([$fijas['seguridad'], ...$fijas['recomendaciones']]))) - 1,
        ];
    }

    private static function unir(array $items): string
    {
        return count($items) > 1 ? implode(', ', array_slice($items, 0, -1)).' y '.end($items) : (string) reset($items);
    }

    // Plantillas obligatorias de la guia: fisura/fuga = especialista (nunca cambiar), resto = cambiar en el proximo servicio.
    public static function frasesFijas(TicketTaller $t): array
    {
        $r = self::clasificar($t);
        $unir = self::unir(...);
        $plural = fn (string $nombre) => (bool) preg_match('/^(los|las) /', $nombre);

        $seguridad = null;
        if ($r['seguridad']) {
            $tipos = array_values(array_unique(array_merge(...array_column($r['seguridad'], 'hallazgos'))));
            $hallazgos = count($tipos) === 1 && count($r['seguridad']) > 1
                ? ($tipos[0] === 'fisura' ? 'fisuras' : 'fugas')
                : $unir(array_map(fn ($m) => $m === 'fisura' ? 'una fisura' : 'una fuga', $tipos));
            $seguridad = 'Por seguridad, te recomendamos no rodar hasta que un especialista evalúe '
                .$unir(array_column($r['seguridad'], 'componente')).": encontramos {$hallazgos}.";
        }

        $recomendaciones = array_map(function ($rec) use ($unir, $plural) {
            $n = $plural($rec['componente']) ? 'n' : '';
            $porque = array_map(fn ($m) => match ($m) {
                'desgaste' => "ya muestra{$n} desgaste",
                'holgura' => "tiene{$n} holgura",
                'ruido' => "hace{$n} ruido",
                default => mb_strtolower(config("taller.motivos.{$m}", $m)),
            }, $rec['motivos']);

            return "Te recomendamos cambiar {$rec['componente']} en el próximo servicio".($porque ? ' porque '.$unir($porque) : '').'.';
        }, $r['recomendaciones']);

        return ['seguridad' => $seguridad, 'recomendaciones' => $recomendaciones];
    }

    // El modelo devuelve {"texto": cuerpo}; el texto final es seguridad + cuerpo + recomendaciones.
    /** @throws JsonException|UnexpectedValueException */
    public static function validarTexto(string $respuesta, array $fijas = ['seguridad' => null, 'recomendaciones' => []]): string
    {
        $cuerpo = trim((string) (ClienteIa::extraerJson($respuesta)['texto'] ?? ''));
        if ($cuerpo === '') {
            throw new UnexpectedValueException('respuesta sin "texto"');
        }
        $texto = implode(' ', array_filter([$fijas['seguridad'], $cuerpo, ...$fijas['recomendaciones']]));
        $largo = mb_strlen($texto);
        if ($largo < 80 || $largo > self::MAX_TEXTO) {
            throw new UnexpectedValueException("texto con {$largo} caracteres (se esperan 80-".self::MAX_TEXTO.')');
        }

        return $texto;
    }

    // Etiquetas de componentes que NO estan en la revision pero aparecen en el texto.
    // ponytail: busqueda por palabras de la etiqueta; si da falsos positivos, mapa de sinonimos por clave.
    public static function menciones(string $texto, ?array $revision): array
    {
        $norm = fn (string $s) => mb_strtolower(Str::ascii($s));
        $terminos = fn (string $etiqueta) => collect(preg_split('/[\/(),]|\s+(?:y|o)\s+/', $norm($etiqueta)))
            ->map(fn ($s) => preg_replace('/^((el|la|los|las) )?((juego|caja) de )?/', '', trim($s)))
            ->filter(fn ($s) => strlen($s) >= 4);

        $etiquetas = InformeRevision::componentes();
        $presentes = collect($revision['componentes'] ?? [])->filter(fn ($c) => ! empty($c['acciones']))->keys();
        // terminos compartidos con lo que si esta (ej. "guayas", "centrado de ruedas") no cuentan como ajenos
        // etiqueta de pantalla + nombre para el cliente ("Manubrio, potencia y tija" / "el manubrio")
        $etiquetas = collect($etiquetas)->map(fn ($e, $k) => $e.' / '.config("taller.componentes_cliente.{$k}", ''))->all();
        $permitido = $presentes->map(fn ($k) => $etiquetas[$k] ?? '')
            ->merge(collect($revision['tareas'] ?? [])->filter()->keys()->map(fn ($k) => config("taller.tareas.{$k}", '')))
            ->map($norm)->implode(' | ');
        $t = $norm($texto);

        return collect($etiquetas)->except($presentes->all())
            ->filter(fn ($etiqueta) => $terminos($etiqueta)->contains(
                fn ($term) => ! str_contains($permitido, $term) && preg_match('/\b'.preg_quote($term, '/').'\b/', $t)
            ))->map(fn ($e, $k) => InformeRevision::componentes()[$k])->values()->all();
    }

    // Componentes con fisura/fuga que el texto sugiere cambiar en la misma frase: el taller no diagnostico
    // un cambio, solo puede mandar a evaluar.
    public static function cambioPorSeguridad(string $texto, ?array $revision): array
    {
        $norm = fn (string $s) => mb_strtolower(Str::ascii($s));
        $frases = collect(preg_split('/(?<=[.;:!?])\s+/', $norm($texto)))->filter(fn ($f) => preg_match('/\bcambi\w*/', $f));

        return collect($revision['componentes'] ?? [])
            ->filter(fn ($c) => array_intersect($c['motivos'] ?? [], self::MOTIVOS_SEGURIDAD))
            ->keys()
            ->filter(function ($clave) use ($norm, $frases) {
                $nombre = preg_replace('/^(el|la|los|las) /', '', $norm(config("taller.componentes_cliente.{$clave}", $clave)));

                return $frases->contains(fn ($f) => preg_match('/\b'.preg_quote($nombre, '/').'\b/', $f));
            })->values()->all();
    }

    public static function jerga(string $texto): array
    {
        $t = mb_strtolower(Str::ascii($texto));

        return array_values(array_filter(self::JERGA, fn ($j) => preg_match('/\b'.preg_quote($j, '/').'\b/', $t)));
    }

    private static function prompt(): string
    {
        return <<<'PROMPT'
        Eres un mecánico de confianza del taller OnBike Margarita (Venezuela). Redactas la parte central del mensaje
        para el cliente con el JSON de la revisión técnica. Tono profesional y humano: sabes lo que haces y lo explicas claro.

        Cómo se arma el mensaje completo (orden fijo):
        1) hallazgos de seguridad -> 2) lo cambiado -> 3) el resto agrupado -> 4) recomendaciones.
        El sistema agrega por su cuenta las partes 1 y 4 con frases fijas. TÚ escribes solo las partes 2 y 3:
        - Abre nombrando la bici y el paquete si vienen ("Le hicimos el servicio Básico a tu Trek"); si "bici" es null,
          "tu bici"; si "paquete" es null, "Trabajamos en tu Trek".
        - Sigue con las frases de "hechos", TODAS y EN ESE ORDEN. Puedes unirlas con dos puntos, comas o "y" y poner
          mayúsculas, pero no cambies, quites ni agregues ningún trabajo ni componente.
        - Si "hechos" está vacío, escribe solo la apertura ("Recibimos tu Trek para el servicio Básico.").

        Reglas:
        - Trato de tú y primera persona del plural: "revisamos", "cambiamos", "tu bici".
        - Términos del oficio sí (cauchos, rolineras, tripa, piñones). Jerga no: pana, chamo, vaina, burda, chévere,
          épale, "full" como adjetivo. Tono de formulario no: "Se realizó", "Intervenido", "Revisado sin novedad".
        - PROHIBIDO escribir recomendaciones, hallazgos, problemas o la palabra "recomendamos": eso lo agrega el sistema.
        - Nombra los componentes exactamente como vienen en el JSON ("los piñones", "la caja de pedalier").
        - Solo lo que está en "hechos". Si no está ahí, no existe: no menciones otros componentes ni trabajos, y no
          digas que "el resto" o "los demás" están bien. Los ejemplos de abajo tienen datos inventados: copiar de ellos
          un trabajo que no esté en "hechos" invalida el mensaje.
        - Sin saludo, sin despedida, sin frases de cierre. Sin notas, precios, montos, fechas, plazos, garantías ni emojis.
        - 1 a 3 frases completas, como máximo "max_caracteres" caracteres.

        Ejemplos de tono de mensajes completos (referencia de voz, no copies sus datos; recuerda que tú NO escribes
        las recomendaciones ni los hallazgos):
        - Tu Trek quedó lista para rodar. Le hicimos el servicio Full: lubricamos y ajustamos la cadena, y revisamos el resto de los componentes, que están en buen estado. Te recomendamos cambiar el cassette en el próximo servicio, porque ya muestra desgaste y con el tiempo afecta los cambios.
        - Recibimos tu bici para el servicio Básico y durante la revisión encontramos una fisura en el cuadro, cerca del pedalier. Por seguridad no hicimos ningún trabajo adicional sobre esa zona. Te recomendamos que la evalúe un especialista antes de volver a rodar; cualquier duda, con gusto te explicamos lo que vimos.
        - Le hicimos el servicio Básico a tu Specialized: lubricamos la cadena, ajustamos frenos y cambios, y calibramos la presión de los cauchos. Revisamos todo lo demás y está en buen estado, así que puedes rodar tranquilo.

        Responde SOLO con JSON, sin markdown: {"texto":"<partes 2 y 3>"}
        PROMPT;
    }
}
