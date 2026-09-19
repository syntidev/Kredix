<?php

namespace App\Console\Commands;

use App\Models\TicketTaller;
use Illuminate\Console\Command;

class LimpiarDemoTaller extends Command
{
    protected $signature = 'app:limpiar-demo-taller';

    protected $description = 'Borra los tickets de ejemplo creados por app:sembrar-demo-taller (identificados por el prefijo [DEMO] en la bici)';

    public function handle(): int
    {
        $tickets = TicketTaller::where('bici_marca_modelo', 'like', '[DEMO] %')->get();

        if ($tickets->isEmpty()) {
            $this->info('No hay tickets demo para borrar.');

            return self::SUCCESS;
        }

        foreach ($tickets as $ticket) {
            $ticket->clearMediaCollection('entrada');
            $ticket->clearMediaCollection('salida');
            $ticket->repuestos()->delete();
            $ticket->forceDelete();
        }

        $this->info($tickets->count().' tickets demo borrados.');

        return self::SUCCESS;
    }
}
