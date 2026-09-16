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

        foreach ($filas as $fila) {
            $nombre = trim(preg_replace('/\s+/', ' ', (string) ($fila[0] ?? '')));
            if ($nombre === '') {
                continue; // fila en blanco al final del sheet
            }

            $ci = Prospecto200k::normalizarCi((string) ($fila[1] ?? ''));
            $telefono = Prospecto200k::normalizarTelefono((string) ($fila[2] ?? ''));
            $correo = trim((string) ($fila[3] ?? '')) ?: null;

            if (! $ci && ! $telefono) {
                $incompletos++;
            }

            $nombreNorm = mb_strtolower($nombre, 'UTF-8');

            // BUG CRITICO corregido (2026-09-16): CI/telefono solos NO identifican
            // una persona en este archivo -- es un formulario de evento donde una
            // persona registra a varios familiares con SU MISMO telefono/CI y
            // nombres distintos (real: CI 12225261 tiene "Zonia Marcano", "Bonaldi
            // (Zonia Marcano)", "Yuma (Zonia Marcano)", "Efren (Zonia Marcano)" --
            // 4 personas). El import anterior las fusiono en 1 sola fila, perdiendo
            // 3 registros reales. Clave de upsert ahora: nombre normalizado Y (CI
            // exacto O telefono exacto) -- un duplicado real (misma fila repetida
            // literal, ej. "Luis Marcano" x5 identico) SI colapsa a 1 registro
            if ($ci || $telefono) {
                $existente = Prospecto200k::whereRaw('LOWER(nombre) = ?', [$nombreNorm])
                    ->where(function ($query) use ($ci, $telefono) {
                        if ($ci) {
                            $query->orWhere('ci', $ci);
                        }
                        if ($telefono) {
                            $query->orWhere('telefono', $telefono);
                        }
                    })
                    ->first();
            } else {
                // sin CI ni telefono no hay señal confiable de identidad -- solo
                // se trata como "la misma fila" si nombre Y correo tambien
                // coinciden exacto (duplicado literal completo), nunca solo por
                // nombre (asi se evita el fallback que fusionaba filas sin datos)
                $existente = Prospecto200k::whereNull('ci')->whereNull('telefono')
                    ->whereRaw('LOWER(nombre) = ?', [$nombreNorm])
                    ->where(function ($query) use ($correo) {
                        $correo
                            ? $query->whereRaw('LOWER(TRIM(correo)) = ?', [mb_strtolower(trim($correo), 'UTF-8')])
                            : $query->whereNull('correo');
                    })
                    ->first();
            }

            if ($existente) {
                $actualizados++;
                if ($ejecutar) {
                    $existente->update([
                        'lote' => $lote,
                        'ci' => $existente->ci ?: $ci,
                        'telefono' => $existente->telefono ?: $telefono,
                        'correo' => $existente->correo ?: $correo,
                    ]);
                }
            } else {
                $nuevos++;
                if ($ejecutar) {
                    Prospecto200k::create([
                        'nombre' => $nombre,
                        'ci' => $ci,
                        'telefono' => $telefono,
                        'correo' => $correo,
                        'lote' => $lote,
                        'estado' => 'pendiente',
                    ]);
                }
            }
        }

        $this->info(($ejecutar ? 'EJECUTADO' : 'DRY-RUN (sin --ejecutar, no se escribio nada)').": lote \"{$lote}\"");
        $this->info('Filas totales: '.count($filas)." | Nuevos: {$nuevos} | Actualizados: {$actualizados} | Sin CI ni telefono: {$incompletos}");

        return self::SUCCESS;
    }
}
