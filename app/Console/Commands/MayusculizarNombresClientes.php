<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use Illuminate\Console\Command;

class MayusculizarNombresClientes extends Command
{
    /**
     * Normaliza nombre de clientes ya importados a mayusculas -- mb_strtoupper,
     * nunca strtoupper() nativo (corrompe UTF-8 en tildes/enie). Ver tarea CLI-A
     * del 2026-09-11.
     *
     * Idempotente: solo actualiza los que no estan ya en mayusculas.
     * Corre en modo dry-run por defecto: sin --ejecutar solo reporta, no escribe.
     */
    protected $signature = 'app:mayusculizar-nombres-clientes
        {--ejecutar : Sin este flag no se escribe nada en la base de datos}';

    protected $description = 'Normaliza nombre de todos los clientes a mayusculas (UTF-8 seguro)';

    public function handle(): int
    {
        $ejecutar = $this->option('ejecutar');

        $clientes = Cliente::all()->filter(fn (Cliente $c) => $c->nombre !== mb_strtoupper($c->nombre, 'UTF-8'));

        if ($clientes->isEmpty()) {
            $this->info('Nada que corregir -- todos los nombres ya estan en mayusculas.');

            return self::SUCCESS;
        }

        $this->info(($ejecutar ? 'EJECUTANDO' : 'DRY-RUN (sin --ejecutar, no se escribe nada)')
            . ': ' . $clientes->count() . ' cliente(s) a normalizar.');

        foreach ($clientes as $c) {
            $nombreNuevo = mb_strtoupper($c->nombre, 'UTF-8');
            $this->line("id={$c->id} '{$c->nombre}' -> '{$nombreNuevo}'");

            if ($ejecutar) {
                $c->update(['nombre' => $nombreNuevo]);
            }
        }

        if (! $ejecutar) {
            $this->comment('Corre con --ejecutar para aplicar.');
        } else {
            $this->info('Normalizados: ' . $clientes->count());
        }

        return self::SUCCESS;
    }
}
