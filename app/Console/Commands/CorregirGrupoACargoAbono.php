<?php

namespace App\Console\Commands;

use App\Models\MovimientoCuenta;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CorregirGrupoACargoAbono extends Command
{
    /**
     * Corrige los 12 movimientos importados con tipo='cargo' que en realidad son
     * abono -- bug del parser original (parse_lote_completo.py) que asignaba
     * 'cargo' por defecto a cualquier metodo_pago no reconocido como palabra clave
     * de cargo, sin revisar si era una palabra clave de pago conocida
     * (P.MOVIL/ZELLE/BINANCE/CASH). Ver diagnostico CLI-A del 2026-09-11.
     *
     * Filtra por where('tipo','cargo') -- si ya se corrigio, no hace nada.
     * Corre en modo dry-run por defecto: sin --ejecutar solo reporta, no escribe.
     */
    protected $signature = 'app:corregir-grupo-a-cargo-abono
        {--ejecutar : Sin este flag no se escribe nada en la base de datos}';

    protected $description = 'Corrige los 12 movimientos del Grupo A (cargo mal clasificado, era abono)';

    private const METODO_MAPEADO = [
        1819 => 'pago_movil', // RAUL RODISLA, P.MOVIL
        1820 => 'pago_movil', // RAUL RODISLA, P.MOVIL
        1417 => 'pago_movil', // LUIS REAL, P.MOVIL
        1419 => 'pago_movil', // LUIS REAL, P.MOVIL
        1420 => 'pago_movil', // LUIS REAL, P.MOVIL
        1421 => 'efectivo',   // LUIS REAL, CASH
        865 => 'zelle',       // GERARDO CASTILLO, ZELLE
        616 => 'binance',     // ERICK PEÑA GOCHO, BINANCE
        619 => 'binance',     // ERICK PEÑA GOCHO, BINANCE
        620 => 'binance',     // ERICK PEÑA GOCHO, BINANCE
        621 => 'binance',     // ERICK PEÑA GOCHO, BINANCE
        1893 => 'efectivo',   // RAMON MARIN JUANGRIEGO, CASH
    ];

    public function handle(): int
    {
        $ejecutar = $this->option('ejecutar');

        $sistema = User::where('email', 'sistema@kredix.local')->first();
        if (! $sistema) {
            $this->error("No existe el usuario 'sistema@kredix.local' -- se necesita para causedBy.");

            return self::FAILURE;
        }

        $ids = array_keys(self::METODO_MAPEADO);
        $movimientos = MovimientoCuenta::whereIn('id', $ids)->where('tipo', 'cargo')->get();

        if ($movimientos->isEmpty()) {
            $this->info('Nada que corregir -- los 12 movimientos ya estan en tipo=abono.');

            return self::SUCCESS;
        }

        $this->info(($ejecutar ? 'EJECUTANDO' : 'DRY-RUN (sin --ejecutar, no se escribe nada)')
            . ': ' . $movimientos->count() . ' movimientos a corregir de ' . count($ids) . ' esperados.');

        if (! $ejecutar) {
            foreach ($movimientos as $m) {
                $this->line("id={$m->id} cliente_id={$m->cliente_id} monto={$m->monto} metodo_pago_nuevo=" . self::METODO_MAPEADO[$m->id]);
            }
            $this->comment('Corre con --ejecutar para aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($movimientos, $sistema) {
            foreach ($movimientos as $m) {
                $antes = $m->only(['tipo', 'metodo_pago', 'cantidad', 'precio_unitario', 'modalidad_precio', 'plazo_meses', 'frecuencia_pago']);

                $m->update([
                    'tipo' => 'abono',
                    'metodo_pago' => self::METODO_MAPEADO[$m->id],
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

        $this->info('Corregidos: ' . $movimientos->count());

        return self::SUCCESS;
    }
}
