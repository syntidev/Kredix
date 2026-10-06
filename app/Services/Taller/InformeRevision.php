<?php

namespace App\Services\Taller;

use App\Models\TicketTaller;

// Informe deterministico de la Revision tecnica -- sin IA, mismo input da
// siempre el mismo texto. Fuente unica de la lectura del JSON para el texto,
// el resumen de Show.vue y la tabla del PDF.
class InformeRevision
{
    private const PAQUETE_LABEL = ['basico' => 'Básico', 'full' => 'Full', 'vip' => 'VIP', 'otro' => 'Otro'];

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
        $resumen = ['tareas' => [], 'intervenidos' => [], 'recomendados' => [], 'ok' => 0];

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
            // 'ok' solo cuenta si es la unica accion -- con otras acciones manda lo hecho
            $hechas = array_values(array_diff($c['acciones'] ?? [], ['ok', 'recomendar']));

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
                    'nota' => trim($c['nota'] ?? ''),
                ];
            }
            if (($c['acciones'] ?? []) === ['ok']) {
                $resumen['ok']++;
            }
        }

        return $resumen;
    }

    // tope por debajo del max:2000 del guardado manual de trabajo_realizado
    public const TOPE_TEXTO = 1500;

    // sin notas (viven en la revision y en el PDF). Si se pasa del tope, quita
    // componentes completos desde el final y lo dice en la ultima linea
    public function generarTexto(TicketTaller $t): string
    {
        $r = self::resumen($t->revision_tecnica);
        $omitidos = 0;

        while (mb_strlen($texto = $this->armarTexto($t, $r, $omitidos)) > self::TOPE_TEXTO
            && ($r['recomendados'] || $r['intervenidos'])) {
            $r['recomendados'] ? array_pop($r['recomendados']) : array_pop($r['intervenidos']);
            $omitidos++;
        }

        return $texto;
    }

    // tono de .doc/GUIA_VOZ_ONBIKE.md: primera persona plural, tuteo, sin
    // "Intervenido" ni "Revisado sin novedad"
    private function armarTexto(TicketTaller $t, array $r, int $omitidos): string
    {
        $nombres = config('taller.componentes_cliente');
        $acciones = config('taller.acciones');
        $bici = trim((string) $t->bici_marca_modelo) ?: 'bici';
        $frases = [];

        // [verbos, objeto] -- tareas ("lubricamos" + "la cadena") y componentes
        // ("lubricamos y ajustamos" + "la cadena")
        $pares = [];
        foreach (array_keys($r['tareas']) as $clave) {
            $pares[] = explode(' ', config("taller.tareas_cliente.$clave"), 2);
        }
        foreach ($r['intervenidos'] as $i) {
            $verbos = array_map(fn ($a) => $acciones[$a]['verbo'] ?? $a, $i['hechas']);
            $objeto = $nombres[$i['clave']] ?? $i['componente'];
            // la tarea que ya dice lo mismo que el componente sobra
            $pares = array_values(array_filter($pares, fn ($p) => ! ($p[1] === $objeto && in_array($p[0], $verbos, true))));
            $pares[] = [self::unirConY($verbos), $objeto];
        }
        // mismo verbo junto: "ajustamos los frenos y los cambios"
        $porVerbo = [];
        foreach ($pares as [$verbos, $objeto]) {
            $porVerbo[$verbos][] = $objeto;
        }
        $hecho = array_map(fn ($verbos, $objetos) => $verbos.' '.self::unirConY($objetos), array_keys($porVerbo), $porVerbo);
        $inicio = $t->tipo_servicio
            ? 'Le hicimos el servicio '.(self::PAQUETE_LABEL[$t->tipo_servicio] ?? $t->tipo_servicio)." a tu $bici"
            : "Trabajamos en tu $bici";
        if ($hecho || $t->tipo_servicio) {
            $frases[] = $inicio.($hecho ? ': '.self::unirConY($hecho) : '').'.';
        }

        if ($r['ok']) {
            $mas = $hecho ? ' más' : '';
            $frases[] = $r['ok'] === 1
                ? "Revisamos 1 componente$mas y está en buen estado."
                : "Revisamos {$r['ok']} componentes$mas y están en buen estado.";
        }

        foreach (array_values($r['recomendados']) as $n => $rec) {
            $motivo = $rec['motivos'] ? ' por '.self::unirConY(array_map(fn ($m) => str_replace('/', ' o ', $m), $rec['motivos'])) : '';
            $frases[] = ($n === 0 ? 'Te recomendamos' : 'También te recomendamos')
                .' cambiar '.($nombres[$rec['clave']] ?? $rec['componente'])
                .($n === 0 ? ' en el próximo servicio' : '').$motivo.'.';
        }

        if ($omitidos) {
            $frases[] = "…y {$omitidos} componentes más; ver detalle en la revisión técnica.";
        }

        return implode(' ', $frases);
    }

    private static function unirConY(array $items): string
    {
        $ultimo = array_pop($items);

        return $items ? implode(', ', $items).' y '.$ultimo : $ultimo;
    }
}
