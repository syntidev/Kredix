<?php

namespace App\Console\Commands;

use App\Models\MovimientoCuenta;
use App\Models\Producto;
use Illuminate\Console\Command;

class PoblarCatalogoProductos extends Command
{
    /**
     * Poblacion inicial del catalogo de productos, a partir de las descripciones
     * ya existentes en movimientos_cuenta (tipo=cargo) -- la fuente de datos real
     * confirmada por Carlos, no se empieza vacio.
     *
     * Idempotente: solo crea los que no existen ya (por nombre normalizado).
     * Corre en modo dry-run por defecto: sin --ejecutar solo reporta, no escribe.
     */
    protected $signature = 'app:poblar-catalogo-productos
        {--ejecutar : Sin este flag no se escribe nada en la base de datos}';

    protected $description = 'Puebla la tabla productos desde las descripciones de cargos ya existentes';

    public function handle(): int
    {
        $ejecutar = $this->option('ejecutar');

        $descripciones = MovimientoCuenta::where('tipo', 'cargo')
            ->whereNotNull('descripcion')
            ->pluck('descripcion');

        $agrupado = $descripciones
            ->map(fn (string $d) => Producto::normalizarNombre($d))
            ->filter(fn (string $d) => $d !== '')
            ->countBy();

        if ($agrupado->isEmpty()) {
            $this->info('No hay cargos con descripcion para poblar el catalogo.');

            return self::SUCCESS;
        }

        $this->info(($ejecutar ? 'EJECUTANDO' : 'DRY-RUN (sin --ejecutar, no se escribe nada)')
            .': '.$agrupado->count().' producto(s) unico(s) detectado(s) desde '.$descripciones->count().' cargo(s).');

        $creados = 0;
        foreach ($agrupado as $nombre => $conteo) {
            $existe = Producto::where('nombre', $nombre)->exists();
            $this->line(($existe ? '(ya existe) ' : '(nuevo) ')."'{$nombre}' -> veces_usado={$conteo}");

            if ($ejecutar && ! $existe) {
                Producto::create(['nombre' => $nombre, 'veces_usado' => $conteo]);
                $creados++;
            }
        }

        if (! $ejecutar) {
            $this->comment('Corre con --ejecutar para aplicar.');
        } else {
            $this->info("Creados: {$creados} de {$agrupado->count()} productos unicos.");
        }

        return self::SUCCESS;
    }
}
