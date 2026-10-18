<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\TicketTaller;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Aviso de WhatsApp "unidad lista": el controlador arma el texto y solo lo ofrece
// con el ticket atendido y un cliente con telefono
class TallerWhatsappTest extends TestCase
{
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        // mismo esquema real que EntregaATallerTest (salta la migracion vieja de sqlite)
        $migraciones = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => str_contains($p, 'replace_procesado_con_estado_en_prospectos_200k'))
            ->values()->all();
        Artisan::call('migrate', ['--path' => $migraciones, '--realpath' => true]);

        Storage::fake('public');
        config(['ia.activa' => false]);
        $this->usuario = User::factory()->create(['name' => 'Kleiver', 'rol_taller' => true]);
    }

    private function ticket(array $datos = [], string $telefono = '+584141234567'): TicketTaller
    {
        $cliente = Cliente::create(['nombre' => 'ANA PEREZ', 'telefono' => $telefono]);

        $t = TicketTaller::create([
            'tipo' => 'servicio_cliente',
            'cliente_id' => $cliente->id,
            'motivo_ingreso' => 'frena mal',
            'bici_marca_modelo' => 'Trek Marlin 7',
            'categoria_bici' => 'mtb',
            'talla_rin' => '29',
            'es_electrica' => false,
            'tipo_servicio' => 'basico',
            'monto_servicio' => 15,
            'estado' => 'atendido',
            'diagnostico' => [],
            'mecanico_id' => $this->usuario->id,
            'registrado_por' => $this->usuario->id,
            ...$datos,
        ]);
        $t->repuestos()->create(['producto' => 'Cadena', 'cantidad' => 2, 'precio' => 5]);

        return $t;
    }

    private function mensaje(TicketTaller $t): ?string
    {
        $mensaje = null;
        $this->actingAs($this->usuario)->get("/taller/{$t->id}")
            ->assertInertia(function ($pagina) use (&$mensaje) {
                $mensaje = $pagina->toArray()['props']['mensajeWhatsapp'];
                $pagina->has('mensajeWhatsapp');
            });

        return $mensaje;
    }

    public function test_el_texto_por_cobrar_lleva_nombre_bici_y_monto_total(): void
    {
        $mensaje = $this->mensaje($this->ticket(['pagado_en_taller' => false]));

        $this->assertStringContainsString('Hola Ana Perez', $mensaje);
        $this->assertStringContainsString('Tu Trek Marlin 7 ya está lista', $mensaje);
        // 15 de servicio + 2 x 5 de repuestos
        $this->assertStringContainsString('El total es $25.00', $mensaje);
        $this->assertStringNotContainsString('El monto del servicio', $mensaje);
        $this->assertStringNotContainsString('{', $mensaje);
    }

    public function test_si_se_pago_en_taller_el_texto_dice_que_ya_esta_pagado(): void
    {
        $mensaje = $this->mensaje($this->ticket(['pagado_en_taller' => true]));

        $this->assertStringContainsString('El servicio ya está pagado', $mensaje);
        $this->assertStringNotContainsString('$25.00', $mensaje);
    }

    public function test_sin_atender_no_hay_mensaje(): void
    {
        $this->assertNull($this->mensaje($this->ticket(['estado' => 'en_proceso'])));
    }

    public function test_sin_telefono_no_hay_mensaje(): void
    {
        $this->assertNull($this->mensaje($this->ticket([], '')));
    }

    public function test_los_apellidos_con_enie_y_tilde_salen_bien(): void
    {
        $t = $this->ticket();
        $t->cliente->update(['nombre' => 'MUÑOZ PÉREZ']);

        $this->assertStringContainsString('Hola Muñoz Pérez', $this->mensaje($t));
    }

    public function test_un_armado_con_cliente_telefono_y_monto_avisa_el_total_servicio_mas_repuestos(): void
    {
        // 30 de servicio de armado + 2 x 5 de repuestos
        $t = $this->ticket(['tipo' => 'armado_interno', 'tipo_servicio' => null, 'monto_servicio' => 30]);

        $this->assertStringContainsString('El total es $40.00', $this->mensaje($t));
    }

    public function test_un_armado_sin_cliente_no_tiene_mensaje(): void
    {
        $t = $this->ticket(['tipo' => 'armado_interno', 'tipo_servicio' => null, 'monto_servicio' => null]);
        $t->update(['cliente_id' => null]);

        $this->assertNull($this->mensaje($t));
    }
}
