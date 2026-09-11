<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FusionarRosyDeDominicis extends Command
{
    /**
     * Rosy de Dominicis quedo importada como 2 clientes (id 378 y 379) por 2 hojas
     * de papel separadas del mismo cliente real -- misma cedula 19.468.241, mismo
     * telefono. Reasigna los movimientos de 379 hacia 378 via update() (preserva
     * IDs y su ActivityLog), agrega nota de fusion a 378, y soft-deletea 379.
     * Ver diagnostico/ejecucion CLI-A del 2026-09-11.
     *
     * Idempotente: si 379 ya esta soft-deleted, no hace nada.
     */
    protected $signature = 'app:fusionar-rosy-de-dominicis
        {--ejecutar : Sin este flag no se escribe nada en la base de datos}';

    protected $description = 'Fusiona los clientes duplicados 378 y 379 (Rosy de Dominicis, 2 hojas de papel)';

    private const ID_CONSERVAR = 378;
    private const ID_FUSIONAR = 379;

    public function handle(): int
    {
        $ejecutar = $this->option('ejecutar');

        $sistema = User::where('email', 'sistema@kredix.local')->first();
        if (! $sistema) {
            $this->error("No existe el usuario 'sistema@kredix.local' -- se necesita para causedBy.");

            return self::FAILURE;
        }

        $clienteFusionar = Cliente::withTrashed()->find(self::ID_FUSIONAR);
        if (! $clienteFusionar || $clienteFusionar->trashed()) {
            $this->info('Nada que fusionar -- el cliente ' . self::ID_FUSIONAR . ' ya esta soft-deleted.');

            return self::SUCCESS;
        }

        $clienteConservar = Cliente::findOrFail(self::ID_CONSERVAR);
        $movimientos = MovimientoCuenta::where('cliente_id', self::ID_FUSIONAR)->get();

        $this->info(($ejecutar ? 'EJECUTANDO' : 'DRY-RUN (sin --ejecutar, no se escribe nada)')
            . ': ' . $movimientos->count() . ' movimientos a reasignar de cliente ' . self::ID_FUSIONAR . ' a ' . self::ID_CONSERVAR . '.');

        if (! $ejecutar) {
            foreach ($movimientos as $m) {
                $this->line("id={$m->id} tipo={$m->tipo} monto={$m->monto} fecha=" . ($m->fecha?->toDateString() ?? 'null'));
            }
            $this->comment('Corre con --ejecutar para aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($movimientos, $clienteConservar, $clienteFusionar, $sistema) {
            foreach ($movimientos as $m) {
                $clienteIdAntes = $m->cliente_id;
                $m->update(['cliente_id' => self::ID_CONSERVAR]);

                activity()
                    ->causedBy($sistema)
                    ->performedOn($m)
                    ->withProperties([
                        'antes' => ['cliente_id' => $clienteIdAntes],
                        'despues' => ['cliente_id' => self::ID_CONSERVAR],
                    ])
                    ->log('Reasignado por fusion de clientes duplicados: cliente ' . self::ID_FUSIONAR . ' -> ' . self::ID_CONSERVAR);
            }

            $notaFusion = 'Cliente tenia 2 hojas de credito separadas en papel (26/08/25 y 04/09/25), fusionadas el '
                . now()->format('d/m/Y') . ' tras confirmacion de Carlos — misma cedula y telefono';
            $notasActuales = $clienteConservar->notas ? $clienteConservar->notas . "\n" : '';
            $clienteConservar->update(['notas' => $notasActuales . $notaFusion]);

            $clienteFusionar->delete();
        });

        $this->info('Movimientos reasignados: ' . $movimientos->count() . '. Cliente ' . self::ID_FUSIONAR . ' soft-deleted.');

        return self::SUCCESS;
    }
}
