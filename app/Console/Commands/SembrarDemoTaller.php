<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\TicketTaller;
use App\Models\User;
use Illuminate\Console\Command;

class SembrarDemoTaller extends Command
{
    /**
     * Tickets de ejemplo para que Carlos vea como luce /taller con contenido
     * real, sin depender de subir fotos. Usa clientes y mecanicos reales ya
     * existentes -- no crea datos falsos de otras tablas.
     *
     * Marcados con el prefijo "[DEMO] " en bici_marca_modelo: unico
     * identificador usado por app:limpiar-demo-taller para borrarlos despues.
     * Los tickets "atendido" aqui saltan a proposito el requisito real de
     * foto de salida (son datos de ejemplo, no un ticket cerrado de verdad).
     */
    protected const PREFIJO = '[DEMO] ';

    protected $signature = 'app:sembrar-demo-taller';

    protected $description = 'Crea tickets de ejemplo en /taller para revision visual (borrar con app:limpiar-demo-taller)';

    public function handle(): int
    {
        $clientes = Cliente::inRandomOrder()->limit(4)->get(['id']);
        $mecanicos = User::where('es_oculto', false)->orderBy('id')->limit(2)->get(['id']);

        if ($clientes->count() < 4 || $mecanicos->count() < 1) {
            $this->error('Se necesitan al menos 4 clientes y 1 mecanico existentes para sembrar la demo.');

            return self::FAILURE;
        }

        $mecanico1 = $mecanicos->get(0)->id;
        $mecanico2 = $mecanicos->get(1)->id ?? $mecanico1;

        $tickets = [
            [
                'tipo' => 'servicio_cliente',
                'cliente_id' => $clientes->get(0)->id,
                'bici_marca_modelo' => self::PREFIJO.'Yamaha paseo',
                'talla_rin' => 'Ruta',
                'tipo_servicio' => 'basico',
                'monto_servicio' => 15,
                'diagnostico' => [
                    ['item' => 'Cadena', 'estado' => 'bien', 'nota' => ''],
                    ['item' => 'Frenos', 'estado' => 'atencion', 'nota' => 'Pastillas gastadas, cambiar pronto'],
                ],
                'estado' => 'en_proceso',
                'mecanico_id' => $mecanico1,
            ],
            [
                'tipo' => 'servicio_cliente',
                'cliente_id' => $clientes->get(1)->id,
                'bici_marca_modelo' => self::PREFIJO.'Trek MTB',
                'talla_rin' => 'MTB',
                'tipo_servicio' => 'full',
                'monto_servicio' => 20,
                'diagnostico' => [
                    ['item' => 'Cadena', 'estado' => 'bien', 'nota' => ''],
                    ['item' => 'Cambios', 'estado' => 'bien', 'nota' => ''],
                ],
                'estado' => 'atendido',
                'mecanico_id' => $mecanico2,
                'repuesto' => ['producto' => 'CADENA FSA', 'cantidad' => 1, 'precio' => 12.50],
            ],
            [
                'tipo' => 'servicio_cliente',
                'cliente_id' => $clientes->get(2)->id,
                'bici_marca_modelo' => self::PREFIJO.'GW Alligator',
                'talla_rin' => 'Rin 20',
                'tipo_servicio' => 'vip',
                'monto_servicio' => 25,
                'diagnostico' => [
                    ['item' => 'Rayos', 'estado' => 'atencion', 'nota' => '2 rayos flojos, tensar'],
                    ['item' => 'Rolineras', 'estado' => 'bien', 'nota' => ''],
                ],
                'estado' => 'en_proceso',
                'mecanico_id' => $mecanico1,
            ],
            [
                'tipo' => 'servicio_cliente',
                'cliente_id' => $clientes->get(3)->id,
                'bici_marca_modelo' => self::PREFIJO.'Bicicleta BMX',
                'talla_rin' => 'Rin 16',
                'tipo_servicio' => 'otro',
                'monto_servicio' => 18,
                'diagnostico' => [],
                'estado' => 'atendido',
                'mecanico_id' => $mecanico2,
            ],
            [
                'tipo' => 'armado_interno',
                'cliente_id' => null,
                'bici_marca_modelo' => self::PREFIJO.'Specialized nueva (armado 1)',
                'talla_rin' => 'MTB',
                'tipo_servicio' => null,
                'monto_servicio' => null,
                'diagnostico' => [],
                'estado' => 'en_proceso',
                'mecanico_id' => $mecanico1,
            ],
            [
                'tipo' => 'armado_interno',
                'cliente_id' => null,
                'bici_marca_modelo' => self::PREFIJO.'GW nueva (armado 2)',
                'talla_rin' => 'Ruta',
                'tipo_servicio' => null,
                'monto_servicio' => null,
                'diagnostico' => [],
                'estado' => 'atendido',
                'mecanico_id' => $mecanico2,
            ],
        ];

        foreach ($tickets as $datos) {
            $repuesto = $datos['repuesto'] ?? null;
            unset($datos['repuesto']);

            $ticket = TicketTaller::create([...$datos, 'registrado_por' => $mecanico1]);

            if ($repuesto) {
                $ticket->repuestos()->create($repuesto);
            }
        }

        $this->info(count($tickets).' tickets demo creados en /taller.');

        return self::SUCCESS;
    }
}
