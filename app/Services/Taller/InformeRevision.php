<?php

namespace App\Services\Taller;

use App\Models\TicketTaller;

// Informe deterministico de la Revision tecnica -- sin IA, mismo input da
// siempre el mismo texto. Fuente unica de la lectura del JSON para el texto,
// el resumen de Show.vue, la tabla del PDF y las frases fijas de la IA.
class InformeRevision
{
    // tope por debajo del max:2000 del guardado manual de trabajo_realizado
    public const TOPE_TEXTO = 1500;

    // clave => etiqueta de todos los grupos, plano
    public static function componentes(): array
    {
        return collect(config('taller.grupos'))->flatMap(fn ($g) => $g['componentes'])->all();
    }

    public static function tieneContenido(?array $revision): bool
    {
        return collect($revision['tareas'] ?? [])->contains(true)
            || collect($revision['componentes'] ?? [])->contains(fn ($c) => ! empty($c['acciones']));
    }

    public static function resumen(?array $revision): array
    {
        $etiquetas = self::componentes();
        $acciones = config('taller.acciones');
        $motivos = config('taller.motivos');
        $resumen = ['tareas' => [], 'intervenidos' => [], 'recomendados' => [], 'ok' => 0, 'ok_claves' => []];

        foreach (config('taller.tareas') as $clave => $etiqueta) {
            if ($revision['tareas'][$clave] ?? false) {
                $resumen['tareas'][$clave] = $etiqueta;
            }
        }

        // orden del catalogo, no del JSON -- MySQL reordena las claves de una columna JSON
        foreach ($etiquetas as $clave => $etiqueta) {
            if (! isset($revision['componentes'][$clave])) {
                continue;
            }
            $c = $revision['componentes'][$clave];
            // 'ok' solo cuenta si es la unica accion -- con otras acciones manda lo
            // hecho. Las acciones excluidas del componente (revisiones viejas) no cuentan
            $hechas = array_values(array_diff(
                $c['acciones'] ?? [],
                ['ok', 'recomendar'],
                config("taller.acciones_excluidas.$clave", []),
            ));

            if ($hechas) {
                $resumen['intervenidos'][] = [
                    'clave' => $clave,
                    'hechas' => $hechas,
                    'componente' => $etiqueta,
                    'acciones' => array_map(fn ($a) => $acciones[$a]['texto'] ?? $a, $hechas),
                ];
            }
            if (in_array('recomendar', $c['acciones'] ?? [], true)) {
                $resumen['recomendados'][] = [
                    'clave' => $clave,
                    'componente' => $etiqueta,
                    'motivos' => array_map(fn ($m) => mb_strtolower($motivos[$m] ?? $m), $c['motivos'] ?? []),
                    'motivos_claves' => $c['motivos'] ?? [],
                    // nota interna del tecnico: se ve en pantalla, nunca en el PDF
                    'nota' => trim($c['nota'] ?? ''),
                ];
            }
            if (($c['acciones'] ?? []) === ['ok']) {
                $resumen['ok']++;
                $resumen['ok_claves'][] = $clave;
            }
        }

        return $resumen;
    }

    // Frases fijas compartidas con RedactarInforme (la IA no las redacta):
    // seguridad = especialista evalua, nunca cambiar; resto = cambiar en el proximo servicio
    public static function frasesFijas(TicketTaller $t): array
    {
        [$seguridad, $normales] = self::separarRecomendaciones(self::resumen($t->revision_tecnica)['recomendados']);

        return [
            'seguridad' => self::fraseSeguridad($seguridad),
            'recomendaciones' => array_map(self::fraseRecomendacion(...), $normales, array_keys($normales)),
        ];
    }

    // sin notas, precios ni fechas. Solo lo que el tecnico marco. Si se pasa del
    // tope, quita componentes completos desde el final y lo dice al cierre
    public function generarTexto(TicketTaller $t): string
    {
        $r = self::resumen($t->revision_tecnica);
        [$seguridad, $normales] = self::separarRecomendaciones($r['recomendados']);
        $esCambio = fn ($i) => in_array('cambiado', $i['hechas'], true);
        $partes = [
            'tareas' => $r['tareas'],
            'seguridad' => $seguridad,
            'cambiados' => array_values(array_filter($r['intervenidos'], $esCambio)),
            'otros' => array_values(array_filter($r['intervenidos'], fn ($i) => ! $esCambio($i))),
            'ok' => $r['ok_claves'],
            'recomendados' => $normales,
        ];
        $omitidos = 0;

        while (mb_strlen($texto = $this->armarTexto($t, $partes, $omitidos)) > self::TOPE_TEXTO) {
            $lista = collect(['recomendados', 'ok', 'otros', 'cambiados', 'seguridad'])->first(fn ($l) => (bool) $partes[$l]);
            if (! $lista) {
                break;
            }
            array_pop($partes[$lista]);
            $omitidos++;
        }

        return $texto;
    }

    // tono de .doc/GUIA_VOZ_ONBIKE.md: tuteo, primera persona plural, comas y
    // una sola "y" por enumeracion. Orden: servicio, seguridad, cambiado, otros
    // trabajos, revisado sin novedad, recomendaciones normales
    private function armarTexto(TicketTaller $t, array $p, int $omitidos): string
    {
        $nombre = fn ($clave) => self::nombre($clave);
        $bici = trim((string) $t->bici_marca_modelo) ?: 'bici';
        // "Otro" no es un nombre de servicio: se dice "trabajamos en tu bici"
        $paquete = $t->tipo_servicio !== 'otro' ? config("taller.paquetes_etiqueta.{$t->tipo_servicio}") : null;
        $frases = [];

        // una frase por conjunto de verbos: exacto (nunca "lubricamos" algo que
        // solo se ajusto) y sin encadenar "y"
        $porVerbos = [];
        foreach ($p['otros'] as $i) {
            $verbos = collect(config('taller.acciones'))
                ->filter(fn ($a, $clave) => isset($a['verbo']) && in_array($clave, $i['hechas'], true))
                ->pluck('verbo')->all();
            $porVerbos[self::unirConY($verbos)][] = $nombre($i['clave']);
        }
        $nombradosOtros = array_merge(...array_values($porVerbos ?: [[]]));

        // la tarea que repite a un componente ya nombrado sobra. Sin texto de
        // cliente en el config, la etiqueta normal como respaldo
        $tareas = array_filter(
            array_map(fn ($clave) => config("taller.tareas_cliente.$clave") ?? mb_strtolower($p['tareas'][$clave]), array_keys($p['tareas'])),
            fn ($frase) => ! in_array(explode(' ', $frase, 2)[1] ?? '', $nombradosOtros, true),
        );
        // mismo verbo junto: "ajustamos los frenos y los cambios"; con mas de un
        // verbo, solo comas dentro del grupo para no encadenar "y"
        $porVerbo = [];
        foreach ($tareas as $frase) {
            [$verbo, $objeto] = array_pad(explode(' ', $frase, 2), 2, null);
            $porVerbo[$verbo][] = $objeto;
        }
        $unirGrupo = count($porVerbo) === 1 ? self::unirConY(...) : fn ($objetos) => implode(', ', $objetos);
        $tareas = array_map(fn ($verbo, $objetos) => trim("$verbo ".$unirGrupo(array_filter($objetos))), array_keys($porVerbo), $porVerbo);
        $frases[] = ($paquete ? "Le hicimos el servicio $paquete a tu $bici" : "Trabajamos en tu $bici")
            .($tareas ? ': '.self::unirConY($tareas) : '').'.';

        if ($frase = self::fraseSeguridad($p['seguridad'])) {
            $frases[] = $frase;
        }
        if ($p['cambiados']) {
            $frases[] = 'Cambiamos '.self::unirConY(array_map(fn ($i) => $nombre($i['clave']), $p['cambiados'])).'.';
        }
        foreach ($porVerbos as $verbos => $objetos) {
            $frases[] = ucfirst("$verbos ".self::unirConY($objetos)).'.';
        }
        // solo lo marcado OK, con su concordancia; nunca "el resto". Dos puntos
        // en vez de "y están": no encadena dos "y" en la misma frase
        if ($p['ok']) {
            $nombres = array_map($nombre, $p['ok']);
            $frases[] = 'Revisamos '.self::unirConY($nombres)
                .(count($nombres) > 1 || self::esPlural($nombres[0]) ? ': están' : ': está').' en buen estado.';
        }
        foreach ($p['recomendados'] as $n => $rec) {
            $frases[] = self::fraseRecomendacion($rec, $n);
        }

        if ($omitidos) {
            $frases[] = "…y {$omitidos} componentes más; ver detalle en la revisión técnica.";
        }

        return implode(' ', $frases);
    }

    private static function separarRecomendaciones(array $recomendados): array
    {
        $esSeguridad = fn ($rec) => (bool) array_intersect($rec['motivos_claves'], config('taller.motivos_seguridad'));

        return [
            array_values(array_filter($recomendados, $esSeguridad)),
            array_values(array_filter($recomendados, fn ($rec) => ! $esSeguridad($rec))),
        ];
    }

    // todos los hallazgos de seguridad en una sola frase; nunca "cambiar"
    private static function fraseSeguridad(array $items): ?string
    {
        if (! $items) {
            return null;
        }
        $tipos = collect($items)->flatMap(fn ($rec) => $rec['motivos_claves'])
            ->intersect(config('taller.motivos_seguridad'))->unique()->values()->all();
        // varias piezas con un solo tipo: "encontramos fisuras" (no "una fisura" para dos piezas)
        $hallazgos = count($tipos) === 1 && count($items) > 1
            ? config("taller.motivos_cliente.{$tipos[0]}.1")
            : self::unirConY(array_map(fn ($m) => config("taller.motivos_cliente.$m.0"), $tipos));

        return 'Por seguridad, te recomendamos no rodar hasta que un especialista evalúe '
            .self::unirConY(array_map(fn ($rec) => self::nombre($rec['clave']), $items)).": encontramos $hallazgos.";
    }

    // "la cadena muestra desgaste" / "los piñones muestran desgaste"
    private static function fraseRecomendacion(array $rec, int $n): string
    {
        $nombre = self::nombre($rec['clave']);
        $plural = (int) self::esPlural($nombre);
        $porque = array_values(array_filter(array_map(fn ($m) => config("taller.motivos_cliente.$m.$plural"), $rec['motivos_claves'])));

        return ($n === 0 ? "Te recomendamos cambiar $nombre en el próximo servicio" : "También te recomendamos cambiar $nombre")
            .($porque ? ' porque '.self::unirConY($porque) : '').'.';
    }

    private static function nombre(string $clave): string
    {
        return config("taller.componentes_cliente.$clave", self::componentes()[$clave] ?? $clave);
    }

    private static function esPlural(string $nombre): bool
    {
        return (bool) preg_match('/^(los|las) /', $nombre);
    }

    private static function unirConY(array $items): string
    {
        $items = array_values($items);
        $ultimo = (string) array_pop($items);

        return $items ? implode(', ', $items).' y '.$ultimo : $ultimo;
    }
}
