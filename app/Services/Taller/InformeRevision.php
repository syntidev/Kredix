<?php

namespace App\Services\Taller;

use App\Models\TicketTaller;

// Informe deterministico de la Revision tecnica -- sin IA, mismo input da
// siempre el mismo texto. Fuente unica de la lectura del JSON para el texto,
// el resumen de Show.vue y la tabla del PDF.
class InformeRevision
{
    private const PAQUETE_LABEL = ['basico' => 'Básico', 'full' => 'Full', 'vip' => 'VIP', 'otro' => 'Otro'];

    // tope por debajo del max:2000 del guardado manual de trabajo_realizado
    public const TOPE_TEXTO = 1500;

    // hallazgos que no se recomiendan cambiar: los evalua un especialista
    private const MOTIVOS_SEGURIDAD = ['fisura', 'fuga'];

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
                    'motivos_claves' => $c['motivos'] ?? [],
                    'nota' => trim($c['nota'] ?? ''),
                ];
            }
            if (($c['acciones'] ?? []) === ['ok']) {
                $resumen['ok']++;
            }
        }

        return $resumen;
    }

    // sin notas, precios ni fechas. Si se pasa del tope, quita componentes
    // completos desde el final -- primero recomendaciones normales, luego
    // piezas cambiadas, al final hallazgos de seguridad -- y lo dice al cierre
    public function generarTexto(TicketTaller $t): string
    {
        $r = self::resumen($t->revision_tecnica);
        $esCambio = fn ($i) => in_array('cambiado', $i['hechas'], true);
        $esSeguridad = fn ($rec) => (bool) array_intersect($rec['motivos_claves'], self::MOTIVOS_SEGURIDAD);
        $partes = [
            'tareas' => $r['tareas'],
            'seguridad' => array_values(array_filter($r['recomendados'], $esSeguridad)),
            'cambiados' => array_values(array_filter($r['intervenidos'], $esCambio)),
            'otros' => array_values(array_filter($r['intervenidos'], fn ($i) => ! $esCambio($i))),
            'ok' => $r['ok'],
            'recomendados' => array_values(array_filter($r['recomendados'], fn ($rec) => ! $esSeguridad($rec))),
        ];
        $omitidos = 0;

        while (mb_strlen($texto = $this->armarTexto($t, $partes, $omitidos)) > self::TOPE_TEXTO) {
            $lista = match (true) {
                (bool) $partes['recomendados'] => 'recomendados',
                (bool) $partes['cambiados'] => 'cambiados',
                (bool) $partes['seguridad'] => 'seguridad',
                default => null,
            };
            if (! $lista) {
                break;
            }
            array_pop($partes[$lista]);
            $omitidos++;
        }

        return $texto;
    }

    // tono de .doc/GUIA_VOZ_ONBIKE.md: tuteo, primera persona plural, comas y
    // una sola "y" por enumeracion. Orden: servicio, seguridad, cambiado, el
    // resto agrupado, recomendaciones normales
    private function armarTexto(TicketTaller $t, array $p, int $omitidos): string
    {
        $nombre = fn ($item) => config("taller.componentes_cliente.{$item['clave']}", $item['componente']);
        $bici = trim((string) $t->bici_marca_modelo) ?: 'bici';
        $paquete = self::PAQUETE_LABEL[$t->tipo_servicio] ?? $t->tipo_servicio;
        $tareasPaquete = config("taller.paquetes.{$t->tipo_servicio}", []);
        $frases = [];

        // verbos de ajuste/lubricacion/limpieza en el orden del catalogo
        $verbosOtros = collect(config('taller.acciones'))
            ->filter(fn ($a, $clave) => isset($a['verbo']) && $clave !== 'cambiado'
                && collect($p['otros'])->contains(fn ($i) => in_array($clave, $i['hechas'], true)))
            ->pluck('verbo')->all();
        $nombresOtros = array_map($nombre, $p['otros']);

        if ($tareasPaquete && ! array_diff($tareasPaquete, array_keys($p['tareas']))) {
            $frases[] = "Le hicimos el servicio $paquete completo a tu $bici.";
        } else {
            // la tarea que repite a un componente ya nombrado sobra
            // sin texto de cliente en el config: la etiqueta normal como respaldo
            $tareas = array_filter(
                array_map(fn ($clave) => config("taller.tareas_cliente.$clave") ?? mb_strtolower($p['tareas'][$clave]), array_keys($p['tareas'])),
                fn ($frase) => ! (count($nombresOtros) <= 3 && in_array(explode(' ', $frase, 2)[1] ?? '', $nombresOtros, true)),
            );
            // mismo verbo junto: "ajustamos los frenos y los cambios"; con mas
            // de un verbo, solo comas dentro del grupo para no encadenar "y"
            $porVerbo = [];
            foreach ($tareas as $frase) {
                [$verbo, $objeto] = array_pad(explode(' ', $frase, 2), 2, null);
                $porVerbo[$verbo][] = $objeto;
            }
            $unirGrupo = count($porVerbo) === 1 ? self::unirConY(...) : fn ($objetos) => implode(', ', $objetos);
            $tareas = array_map(fn ($verbo, $objetos) => trim("$verbo ".$unirGrupo(array_filter($objetos))), array_keys($porVerbo), $porVerbo);
            $frases[] = ($paquete ? "Le hicimos el servicio $paquete a tu $bici" : "Trabajamos en tu $bici")
                .($tareas ? ': '.self::unirConY($tareas) : '').'.';
        }

        // todos los hallazgos de seguridad en una sola frase; nunca "cambiar"
        if ($p['seguridad']) {
            $hallazgos = collect($p['seguridad'])->flatMap(fn ($rec) => $rec['motivos_claves'])
                ->intersect(self::MOTIVOS_SEGURIDAD)->unique()
                ->map(fn ($m) => config("taller.motivos_cliente.$m.0"))->values()->all();
            $frases[] = 'Por seguridad, te recomendamos no rodar hasta que un especialista evalúe '
                .self::unirConY(array_map($nombre, $p['seguridad'])).': encontramos '.self::unirConY($hallazgos).'.';
        }

        if ($p['cambiados']) {
            $frases[] = 'Cambiamos '.self::unirConY(array_map($nombre, $p['cambiados'])).'.';
        }
        if ($p['otros'] && count($p['otros']) > 3) {
            $frases[] = ucfirst(self::unirConY($verbosOtros).' '.count($p['otros']).' componentes más.');
        } elseif ($p['otros']) {
            // una frase por conjunto de verbos: exacto (nunca "lubricamos" algo
            // que solo se ajusto) y sin encadenar "y"
            $porVerbos = [];
            foreach ($p['otros'] as $i) {
                $verbos = collect(config('taller.acciones'))
                    ->filter(fn ($a, $clave) => isset($a['verbo']) && in_array($clave, $i['hechas'], true))
                    ->pluck('verbo')->all();
                $porVerbos[self::unirConY($verbos)][] = $nombre($i);
            }
            foreach ($porVerbos as $verbos => $objetos) {
                $frases[] = ucfirst("$verbos ".self::unirConY($objetos)).'.';
            }
        }
        if ($p['ok']) {
            $frases[] = 'Revisamos el resto y está en buen estado.';
        }

        foreach ($p['recomendados'] as $n => $rec) {
            // concordancia: "los piñones muestran", "la cadena muestra"
            $plural = (int) preg_match('/^(los|las) /', $nombre($rec));
            $porque = array_values(array_filter(array_map(fn ($m) => config("taller.motivos_cliente.$m.$plural"), $rec['motivos_claves'])));
            $frases[] = ($n === 0 ? 'Te recomendamos cambiar '.$nombre($rec).' en el próximo servicio' : 'También te recomendamos cambiar '.$nombre($rec))
                .($porque ? ' porque '.self::unirConY($porque) : '').'.';
        }

        if ($omitidos) {
            $frases[] = "…y {$omitidos} componentes más; ver detalle en la revisión técnica.";
        }

        return implode(' ', $frases);
    }

    private static function unirConY(array $items): string
    {
        $items = array_values($items);
        $ultimo = (string) array_pop($items);

        return $items ? implode(', ', $items).' y '.$ultimo : $ultimo;
    }
}
