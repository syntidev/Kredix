<?php

namespace App\Console\Commands;

use App\Models\Prospecto200k;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportarProspectos200k extends Command
{
    /**
     * Lee el xlsx de eventos (Nombre y Apellido / CI-DNI-Pasaporte / Telefono /
     * Correo, columnas fijas 0-3) desde DataProspectos/{archivo} y hace upsert en
     * prospectos_200k. Dry-run por defecto: sin --ejecutar solo reporta, no escribe.
     */
    protected $signature = 'app:importar-prospectos-200k
        {archivo : Nombre del archivo dentro de DataProspectos/}
        {--ejecutar : Sin este flag no se escribe nada en la base de datos}';

    protected $description = 'Importa/actualiza prospectos de la base de eventos 200K (upsert por CI o telefono)';

    public function handle(): int
    {
        $path = 'DataProspectos/'.$this->argument('archivo');
        $ejecutar = $this->option('ejecutar');

        if (! file_exists($path)) {
            $this->error("No se encontro el archivo: {$path}");

            return self::FAILURE;
        }

        // lote = nombre del archivo sin extension: mismo archivo re-importado
        // siempre cae en el mismo lote (upsert), archivo nuevo = lote nuevo
        $lote = preg_replace('/(\.(xlsx|xls|csv))+$/i', '', $this->argument('archivo'));

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $filas = $reader->load($path)->getActiveSheet()->toArray(null, true, true, false);
        array_shift($filas); // header

        $nuevos = 0;
        $actualizados = 0;
        $incompletos = 0;

        // BUG CRITICO corregido (2026-09-16): CI/telefono solos NO identifican una
        // persona en este archivo -- es un formulario de evento donde una persona
        // registra a varios familiares con SU MISMO telefono/CI y nombres distintos
        // (real: CI 12225261 tiene "Zonia Marcano", "Bonaldi (Zonia Marcano)", "Yuma
        // (Zonia Marcano)", "Efren (Zonia Marcano)" -- 4 personas). Clave de upsert:
        // nombre normalizado Y (CI exacto O telefono exacto) -- un duplicado real
        // (misma fila repetida literal, ej. "Luis Marcano" x5 identico) SI colapsa.
        //
        // BUG CRITICO #2 corregido (2026-09-16): el dry-run comparaba cada fila
        // SOLO contra la base de datos real -- con la tabla vacia, dos filas
        // duplicadas del MISMO archivo (ej. "Luis Marcano" x5) nunca se detectaban
        // entre si, porque ninguna de las dos anteriores habia sido escrita todavia.
        // Ahora se mantiene un indice en memoria ($vistos) sembrado con lo que ya
        // hay en la base, y cada fila (se escriba o no) se agrega a ese indice --
        // dry-run y --ejecutar corren la misma logica de deteccion, solo difiere si
        // el resultado se persiste.
        // ponytail: matching O(n^2) en memoria sobre $vistos, correcto mientras el
        // archivo se mida en miles (caso actual: ~700/evento); si un solo archivo
        // llega a decenas de miles de filas, indexar $vistos por ci/telefono en vez
        // de recorrerlo entero por fila.
        $vistos = Prospecto200k::all()->map(fn (Prospecto200k $p) => [
            'nombreNorm' => mb_strtolower($p->nombre, 'UTF-8'),
            'ci' => $p->ci,
            'telefono' => $p->telefono,
            'correo' => $p->correo,
            'correoNorm' => $p->correo ? mb_strtolower(trim($p->correo), 'UTF-8') : null,
            'model' => $p,
        ])->all();

        foreach ($filas as $fila) {
            $nombre = trim(preg_replace('/\s+/', ' ', (string) ($fila[0] ?? '')));
            if ($nombre === '') {
                continue; // fila en blanco al final del sheet
            }

            $ci = Prospecto200k::normalizarCi((string) ($fila[1] ?? ''));
            $telefono = Prospecto200k::normalizarTelefono((string) ($fila[2] ?? ''));
            $correo = trim((string) ($fila[3] ?? '')) ?: null;
            $correoNorm = $correo ? mb_strtolower(trim($correo), 'UTF-8') : null;

            if (! $ci && ! $telefono) {
                $incompletos++;
            }

            $nombreNorm = mb_strtolower($nombre, 'UTF-8');

            $idxExistente = null;
            foreach ($vistos as $i => $visto) {
                if ($visto['nombreNorm'] !== $nombreNorm) {
                    continue;
                }
                if ($ci || $telefono) {
                    if (($ci && $visto['ci'] === $ci) || ($telefono && $visto['telefono'] === $telefono)) {
                        $idxExistente = $i;
                        break;
                    }
                } elseif (! $visto['ci'] && ! $visto['telefono'] && $visto['correoNorm'] === $correoNorm) {
                    // sin CI ni telefono no hay señal confiable de identidad -- solo
                    // se trata como "la misma fila" si nombre Y correo tambien
                    // coinciden exacto (duplicado literal completo), nunca solo nombre
                    $idxExistente = $i;
                    break;
                }
            }

            if ($idxExistente !== null) {
                $actualizados++;
                $vistos[$idxExistente]['ci'] = $vistos[$idxExistente]['ci'] ?: $ci;
                $vistos[$idxExistente]['telefono'] = $vistos[$idxExistente]['telefono'] ?: $telefono;
                $vistos[$idxExistente]['correo'] = $vistos[$idxExistente]['correo'] ?: $correo;
                $vistos[$idxExistente]['correoNorm'] = $vistos[$idxExistente]['correo'] ? mb_strtolower(trim($vistos[$idxExistente]['correo']), 'UTF-8') : null;

                if ($ejecutar && $vistos[$idxExistente]['model']) {
                    $vistos[$idxExistente]['model']->update([
                        'lote' => $lote,
                        'ci' => $vistos[$idxExistente]['ci'],
                        'telefono' => $vistos[$idxExistente]['telefono'],
                        'correo' => $vistos[$idxExistente]['correo'],
                    ]);
                }
            } else {
                $nuevos++;
                $model = null;
                if ($ejecutar) {
                    $model = Prospecto200k::create([
                        'nombre' => $nombre,
                        'ci' => $ci,
                        'telefono' => $telefono,
                        'correo' => $correo,
                        'lote' => $lote,
                        'estado' => 'pendiente',
                    ]);
                }
                $vistos[] = [
                    'nombreNorm' => $nombreNorm,
                    'ci' => $ci,
                    'telefono' => $telefono,
                    'correo' => $correo,
                    'correoNorm' => $correoNorm,
                    'model' => $model,
                ];
            }
        }

        $this->info(($ejecutar ? 'EJECUTADO' : 'DRY-RUN (sin --ejecutar, no se escribio nada)').": lote \"{$lote}\"");
        $this->info('Filas totales: '.count($filas)." | Nuevos: {$nuevos} | Actualizados: {$actualizados} | Sin CI ni telefono: {$incompletos}");

        return self::SUCCESS;
    }
}
