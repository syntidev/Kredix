<?php

namespace App\Console\Commands;

use App\Models\MovimientoCuenta;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CorregirGrupoACargoAbono extends Command
{
    /**
     * Corrige los movimientos importados con tipo='cargo' que en realidad son abono
     * -- bug del parser original (parse_lote_completo.py) que asignaba 'cargo' por
     * defecto a cualquier metodo_pago no reconocido como palabra clave de cargo, sin
     * revisar si era una palabra clave de pago conocida. El propio parser dejo el
     * autodiagnostico en el comentario: "METODO='X' no es palabra clave de cargo
     * conocida...". Ver diagnostico CLI-A del 2026-09-11.
     *
     * Identifica los candidatos por PATRON DE DATOS, no por ID -- los IDs son
     * distintos en cada base (local vs VPS) segun el orden real de insercion.
     * Solo corrige si el METODO extraido coincide con un metodo de pago real
     * conocido (ver PALABRAS_CLAVE); textos ambiguos como 'PENDIENTE' o
     * 'CAFECOBIKE' se listan aparte para revision manual, nunca se autocorrigen.
     *
     * Filtra por where('tipo','cargo') -- si ya se corrigio, no hace nada.
     * Corre en modo dry-run por defecto: sin --ejecutar solo reporta, no escribe.
     */
    protected $signature = 'app:corregir-grupo-a-cargo-abono
        {--ejecutar : Sin este flag no se escribe nada en la base de datos}';

    protected $description = 'Corrige los movimientos cargo mal clasificados (eran abono) detectados por el autodiagnostico del parser';

    private const PALABRAS_CLAVE = [
        'PMOVIL' => 'pago_movil',
        'PAGOMOVIL' => 'pago_movil',
        'CASH' => 'efectivo',
        'EFECTIVO' => 'efectivo',
        'BINANCE' => 'binance',
        'ZELLE' => 'zelle',
        'TRANSFERENCIA' => 'transferencia',
        'TRANSFER' => 'transferencia',
        'PUNTODEVENTA' => 'punto_venta',
        'PUNTOVENTA' => 'punto_venta',
        'POS' => 'punto_venta',
        'BANCAMIGA' => 'bancamiga_divisa',
    ];

    public function handle(): int
    {
        $ejecutar = $this->option('ejecutar');

        $sistema = User::where('email', 'sistema@kredix.local')->first();
        if (! $sistema) {
            $this->error("No existe el usuario 'sistema@kredix.local' -- se necesita para causedBy.");

            return self::FAILURE;
        }

        $candidatos = MovimientoCuenta::where('tipo', 'cargo')
            ->where('comentario', 'like', "%no es palabra clave de cargo%")
            ->get();

        $corregibles = [];
        $ambiguos = [];

        foreach ($candidatos as $m) {
            if (! preg_match("/METODO='([^']*)'/", $m->comentario, $match)) {
                continue;
            }

            $metodoRaw = $match[1];
            $normalizado = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $metodoRaw));

            if (isset(self::PALABRAS_CLAVE[$normalizado])) {
                $corregibles[] = ['movimiento' => $m, 'metodo_raw' => $metodoRaw, 'metodo_pago' => self::PALABRAS_CLAVE[$normalizado]];
            } else {
                $ambiguos[] = ['movimiento' => $m, 'metodo_raw' => $metodoRaw];
            }
        }

        if (! empty($ambiguos)) {
            $this->comment(count($ambiguos) . ' movimiento(s) con metodo ambiguo (NO se tocan, requieren revision manual):');
            foreach ($ambiguos as $a) {
                $this->line("  id={$a['movimiento']->id} cliente_id={$a['movimiento']->cliente_id} monto={$a['movimiento']->monto} metodo='{$a['metodo_raw']}'");
            }
        }

        if (empty($corregibles)) {
            $this->info('Nada que corregir -- ningun movimiento cargo con metodo de pago real reconocible pendiente.');

            return self::SUCCESS;
        }

        $this->info(($ejecutar ? 'EJECUTANDO' : 'DRY-RUN (sin --ejecutar, no se escribe nada)')
            . ': ' . count($corregibles) . ' movimiento(s) a corregir.');

        if (! $ejecutar) {
            foreach ($corregibles as $c) {
                $this->line("id={$c['movimiento']->id} cliente_id={$c['movimiento']->cliente_id} monto={$c['movimiento']->monto} metodo='{$c['metodo_raw']}' -> metodo_pago={$c['metodo_pago']}");
            }
            $this->comment('Corre con --ejecutar para aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($corregibles, $sistema) {
            foreach ($corregibles as $c) {
                $m = $c['movimiento'];
                $antes = $m->only(['tipo', 'metodo_pago', 'cantidad', 'precio_unitario', 'modalidad_precio', 'plazo_meses', 'frecuencia_pago']);

                $m->update([
                    'tipo' => 'abono',
                    'metodo_pago' => $c['metodo_pago'],
                    'cantidad' => null,
                    'precio_unitario' => null,
                    'modalidad_precio' => null,
                    'plazo_meses' => null,
                    'frecuencia_pago' => null,
                ]);

                activity()
                    ->causedBy($sistema)
                    ->performedOn($m)
                    ->withProperties([
                        'antes' => $antes,
                        'despues' => $m->only(array_keys($antes)),
                    ])
                    ->log('Corregido por import: cargo->abono, metodo real detectado');
            }
        });

        $this->info('Corregidos: ' . count($corregibles));

        return self::SUCCESS;
    }
}
