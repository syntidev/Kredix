<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\TicketTaller;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Mensajes rapidos de WhatsApp: el controlador arma cuatro plantillas (unidad lista,
// repuesto, danos, libre) en cualquier estado del ticket, solo con un cliente que
// tenga telefono. La cifra va unicamente en "unidad lista"
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

    /** Ticket con cliente y un repuesto de 2 x 5; por defecto atendido. */
    private function ticket(array $datos = [], string $telefono = '+584141234567', string $nombre = 'ANA PEREZ'): TicketTaller
    {
        $cliente = Cliente::create(['nombre' => $nombre, 'telefono' => $telefono]);

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

    /** @return array<string, string>|null prop mensajesWhatsapp de Show */
    private function mensajes(TicketTaller $t): ?array
    {
        $mensajes = null;
        $this->actingAs($this->usuario)->get("/taller/{$t->id}")
            ->assertInertia(function ($pagina) use (&$mensajes) {
                $mensajes = $pagina->toArray()['props']['mensajesWhatsapp'];
                $pagina->has('mensajesWhatsapp');
            });

        return $mensajes;
    }

    private function mensaje(TicketTaller $t, string $clave = 'unidad_lista'): string
    {
        return $this->mensajes($t)[$clave];
    }

    // ---- disponibilidad ----
    public function test_en_proceso_con_cliente_y_telefono_trae_las_cuatro_plantillas(): void
    {
        $mensajes = $this->mensajes($this->ticket(['estado' => 'en_proceso']));

        $this->assertSame(['unidad_lista', 'repuesto', 'danos', 'libre'], array_keys($mensajes));
    }

    public function test_tambien_hay_plantillas_con_el_ticket_atendido(): void
    {
        $this->assertCount(4, $this->mensajes($this->ticket(['estado' => 'atendido'])));
    }

    public function test_un_trabajo_de_la_tienda_no_tiene_plantillas(): void
    {
        $t = $this->ticket(['tipo' => 'armado_interno', 'tipo_servicio' => null, 'monto_servicio' => null]);
        $t->update(['cliente_id' => null]);

        $this->assertNull($this->mensajes($t));
    }

    public function test_sin_telefono_no_hay_plantillas_pero_el_ticket_sigue_teniendo_cliente(): void
    {
        $t = $this->ticket(['estado' => 'en_proceso'], '');
        $props = null;
        $this->actingAs($this->usuario)->get("/taller/{$t->id}")->assertInertia(function ($pagina) use (&$props) {
            $props = $pagina->toArray()['props'];
        });

        $this->assertNull($props['mensajesWhatsapp']);
        // la pantalla muestra el boton deshabilitado con el aviso de telefono
        $this->assertNotNull($props['ticket']['cliente']);
    }

    // ---- unidad lista: total y "ya pagado" ----
    public function test_atendido_a_credito_lleva_nombre_bici_y_total(): void
    {
        $mensaje = $this->mensaje($this->ticket(['pagado_en_taller' => false]));

        $this->assertStringContainsString('Hola Ana Perez', $mensaje);
        $this->assertStringContainsString('Tu Trek Marlin 7 ya está lista', $mensaje);
        // 15 de servicio + 2 x 5 de repuestos
        $this->assertStringContainsString('El total es $25.00', $mensaje);
        $this->assertStringNotContainsString('{', $mensaje);
    }

    public function test_en_proceso_muestra_el_total_acumulado_y_nunca_dice_pagado(): void
    {
        $mensaje = $this->mensaje($this->ticket(['estado' => 'en_proceso']));

        $this->assertStringContainsString('El total es $25.00', $mensaje);
        $this->assertStringNotContainsString('pagado', $mensaje);
    }

    public function test_ya_pagado_solo_con_pagado_en_taller(): void
    {
        $pagado = $this->mensaje($this->ticket(['estado' => 'atendido', 'pagado_en_taller' => true]));
        $this->assertStringContainsString('El servicio ya está pagado', $pagado);
        $this->assertStringNotContainsString('$25.00', $pagado);

        foreach ([['estado' => 'en_proceso'], ['estado' => 'atendido', 'pagado_en_taller' => false]] as $datos) {
            $this->assertStringNotContainsString('pagado', $this->mensaje($this->ticket($datos)));
        }
    }

    public function test_en_proceso_con_repuestos_sin_precio_no_menciona_el_total(): void
    {
        $t = $this->ticket(['estado' => 'en_proceso']);
        $t->repuestos()->create(['producto' => 'Cauchos', 'cantidad' => 1, 'precio' => 0]);

        $mensaje = $this->mensaje($t);

        $this->assertStringContainsString('ya está lista para retirar', $mensaje);
        $this->assertStringNotContainsString('total', $mensaje);
        $this->assertStringNotContainsString('$', $mensaje);
    }

    public function test_atendido_con_un_repuesto_en_cero_conserva_el_total(): void
    {
        $t = $this->ticket(['estado' => 'atendido']);
        $t->repuestos()->create(['producto' => 'Cauchos', 'cantidad' => 1, 'precio' => 0]);

        $this->assertStringContainsString('El total es $25.00', $this->mensaje($t));
    }

    // ---- las demas plantillas no llevan cifras ----
    public function test_repuesto_danos_y_libre_no_llevan_monto_ni_cifras(): void
    {
        // bici sin digitos: el modelo "Trek Marlin 7" aportaria un 7 al texto
        $t = $this->ticket(['estado' => 'en_proceso', 'bici_marca_modelo' => 'Trek Marlin']);

        foreach (['repuesto', 'danos', 'libre'] as $clave) {
            $mensaje = $this->mensaje($t, $clave);
            $this->assertStringStartsWith('Hola Ana Perez', $mensaje, $clave);
            $this->assertDoesNotMatchRegularExpression('/[\d$]|\{/', $mensaje, "la plantilla $clave no debe llevar cifras");
        }
        $this->assertStringContainsString('repuesto adicional', $this->mensaje($t, 'repuesto'));
        $this->assertStringContainsString('detalle adicional', $this->mensaje($t, 'danos'));
    }

    public function test_las_plantillas_de_config_no_tienen_el_marcador_de_monto(): void
    {
        foreach (['whatsapp_repuesto', 'whatsapp_danos', 'whatsapp_libre'] as $clave) {
            $this->assertDoesNotMatchRegularExpression('/[\d$]|\{monto\}/', config("taller.$clave"), $clave);
        }
    }

    // ---- nombres ----
    public function test_los_apellidos_con_enie_y_tilde_salen_bien_en_todas_las_plantillas(): void
    {
        $t = $this->ticket([], '+584141234567', 'MUÑOZ PÉREZ');

        foreach ($this->mensajes($t) as $clave => $mensaje) {
            $this->assertStringContainsString('Hola Muñoz Pérez', $mensaje, $clave);
        }
    }

    public function test_un_armado_con_cliente_telefono_y_monto_avisa_el_total_servicio_mas_repuestos(): void
    {
        // 30 de servicio de armado + 2 x 5 de repuestos
        $t = $this->ticket(['tipo' => 'armado_interno', 'tipo_servicio' => null, 'monto_servicio' => 30]);

        $this->assertStringContainsString('El total es $40.00', $this->mensaje($t));
    }
}
