<?php

namespace App\Services\Taller;

use App\Models\TicketTaller;

// Informe deterministico de la Revision tecnica -- sin IA, mismo input da
// siempre el mismo texto. Fuente unica de la lectura del JSON para el texto,
// el resumen de Show.vue y la tabla del PDF.
class InformeRevision
{
    private const PAQUETE_LABEL = ['basico' => 'Basico', 'full' => 'Full', 'vip' => 'VIP', 'otro' => 'Otro'];

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
                $resumen['tareas'][] = $etiqueta;
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
                    'componente' => $etiqueta,
                    'acciones' => array_map(fn ($a) => $acciones[$a]['texto'] ?? $a, $hechas),
                ];
            }
            if (in_array('recomendar', $c['acciones'] ?? [], true)) {
                $resumen['recomendados'][] = [
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

    public function generarTexto(TicketTaller $t): string
    {
        $r = self::resumen($t->revision_tecnica);
        $lineas = [];

        if ($t->tipo_servicio) {
            $lineas[] = 'Servicio '.(self::PAQUETE_LABEL[$t->tipo_servicio] ?? $t->tipo_servicio).'.';
        }
        if ($r['tareas']) {
            $lineas[] = 'Tareas realizadas: '.implode('; ', $r['tareas']).'.';
        }
        if ($r['intervenidos']) {
            $lineas[] = 'Intervenido: '.implode('; ', array_map(
                fn ($i) => "{$i['componente']} (".self::unirConY($i['acciones']).')',
                $r['intervenidos'],
            )).'.';
        }
        if ($r['ok']) {
            $lineas[] = "Revisado sin novedad: {$r['ok']} ".($r['ok'] === 1 ? 'componente' : 'componentes').'.';
        }
        if ($r['recomendados']) {
            $lineas[] = 'Recomendaciones: '.implode('; ', array_map(
                fn ($rec) => $rec['componente']
                    .($rec['motivos'] ? ' — '.implode(', ', $rec['motivos']) : '')
                    .': se recomienda cambio en el próximo servicio'
                    .($rec['nota'] !== '' ? " ({$rec['nota']})" : ''),
                $r['recomendados'],
            )).'.';
        }

        return implode("\n", $lineas);
    }

    private static function unirConY(array $items): string
    {
        $ultimo = array_pop($items);

        return $items ? implode(', ', $items).' y '.$ultimo : $ultimo;
    }
}
