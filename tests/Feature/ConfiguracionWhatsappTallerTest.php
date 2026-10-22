<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Models\User;
use App\Services\Taller\PlantillasWhatsapp;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

// Bloque "Taller" de Configuracion > WhatsApp: cinco textos editables, todos disponibles a la
// vez (sin radio), con su propio endpoint; las plantillas 1-3 y su radio no cambian
class ConfiguracionWhatsappTallerTest extends TestCase
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

        // cualquier usuario autenticado que hoy abre Configuracion puede editar: sin roles nuevos
        $this->usuario = User::factory()->create(['es_admin' => false, 'rol_taller' => false]);
    }

    /** Los cinco textos originales, o con cambios encima. */
    private function datos(array $cambios = []): array
    {
        $defectos = [];
        foreach (array_keys(PlantillasWhatsapp::CAMPOS) as $campo) {
            $defectos[$campo] = PlantillasWhatsapp::defecto($campo);
        }

        return [...$defectos, ...$cambios];
    }

    private function guardar(array $cambios = [])
    {
        return $this->actingAs($this->usuario)->put('/configuracion/whatsapp-taller', $this->datos($cambios));
    }

    private function guardado(string $campo): ?string
    {
        return Configuracion::valorDe(PlantillasWhatsapp::CAMPOS[$campo]['clave']);
    }

    public function test_guarda_los_cinco_textos_y_la_pantalla_los_devuelve(): void
    {
        $this->guardar([
            'listo_por_cobrar' => 'Hola {nombre}, tu {bici}: {monto}',
            'listo_pagado' => 'Hola {nombre}, tu {bici} pagada {monto}',
            'repuesto' => 'Repuesto {bici} {nombre}',
            'danos' => 'Detalle {bici} {nombre}',
            'libre' => 'Hola {nombre} que tal',
        ])->assertRedirect('/configuracion');

        $this->assertSame('Hola {nombre}, tu {bici}: {monto}', $this->guardado('listo_por_cobrar'));
        $this->assertSame('Hola {nombre} que tal', $this->guardado('libre'));

        $props = null;
        $this->actingAs($this->usuario)->get('/configuracion')->assertInertia(function ($pagina) use (&$props) {
            $props = $pagina->toArray()['props']['plantillasTaller'];
        });
        $this->assertCount(5, $props);
        $this->assertSame('listo_por_cobrar', $props[0]['campo']);
        $this->assertSame('Hola {nombre}, tu {bici}: {monto}', $props[0]['texto']);
        $this->assertSame(PlantillasWhatsapp::defecto('listo_por_cobrar'), $props[0]['defecto']);
        $this->assertSame(['nombre', 'bici', 'monto', 'fecha_entrega'], $props[0]['variables']);
        $this->assertSame(['nombre', 'bici', 'fecha_entrega'], $props[2]['variables']);
    }

    public function test_sin_nada_guardado_la_pantalla_muestra_el_original_con_onbike_margarita(): void
    {
        $props = null;
        $this->actingAs($this->usuario)->get('/configuracion')->assertInertia(function ($pagina) use (&$props) {
            $props = $pagina->toArray()['props']['plantillasTaller'];
        });

        $this->assertStringContainsString('OnBike Margarita', $props[0]['texto']);
        $this->assertSame($props[0]['defecto'], $props[0]['texto']);
    }

    // ---- {monto} solo en las dos de "Unidad lista" ----
    public function test_monto_se_permite_en_las_dos_de_unidad_lista(): void
    {
        $this->guardar(['listo_por_cobrar' => 'Total {monto}', 'listo_pagado' => 'Pagado, era {monto}'])->assertSessionHasNoErrors();

        $this->assertSame('Pagado, era {monto}', $this->guardado('listo_pagado'));
    }

    public function test_monto_se_rechaza_en_repuesto_danos_y_libre_y_no_se_guarda_nada(): void
    {
        foreach (['repuesto' => 'Falta un repuesto', 'danos' => 'Detalle en la revisión', 'libre' => 'Mensaje libre'] as $campo => $etiqueta) {
            $this->guardar(['listo_por_cobrar' => 'Se habria guardado {nombre}', $campo => 'Cuesta {monto}'])
                ->assertSessionHasErrors([$campo => "{monto} solo se permite en «Unidad lista» (por cobrar y ya pagado); quítalo de «{$etiqueta}»."]);

            $this->assertNull($this->guardado('listo_por_cobrar'), "con error en $campo no se guarda ninguna caja");
        }
    }

    public function test_una_variable_desconocida_se_rechaza_y_dice_cuales_son_las_validas(): void
    {
        $this->guardar(['repuesto' => 'Hola {apodo}'])
            ->assertSessionHasErrors(['repuesto' => 'La variable {apodo} no es válida en «Falta un repuesto». Las válidas son: {nombre}, {bici}, {fecha_entrega}.']);

        $this->guardar(['listo_por_cobrar' => 'Hola {cliente}'])
            ->assertSessionHasErrors(['listo_por_cobrar' => 'La variable {cliente} no es válida en «Unidad lista — por cobrar». Las válidas son: {nombre}, {bici}, {monto}, {fecha_entrega}.']);
    }

    public function test_el_texto_vacio_no_se_acepta(): void
    {
        $mensaje = 'El texto no puede quedar vacío; usa «Restablecer al texto original».';

        $this->guardar(['danos' => ''])->assertSessionHasErrors(['danos' => $mensaje]);
        $this->guardar(['libre' => '   '])->assertSessionHasErrors(['libre' => $mensaje]);
    }

    public function test_el_texto_no_puede_pasar_de_1000_caracteres(): void
    {
        $this->guardar(['libre' => str_repeat('a', 1001)])->assertSessionHasErrors('libre');
    }

    // ---- restablecer ----
    public function test_restablecer_vuelve_al_original_y_deja_de_guardar_el_valor(): void
    {
        $this->guardar(['repuesto' => 'Texto propio {nombre}'])->assertSessionHasNoErrors();
        $this->assertSame('Texto propio {nombre}', $this->guardado('repuesto'));

        // "Restablecer al texto original" pone en la caja el original y se guarda
        $this->guardar(['repuesto' => PlantillasWhatsapp::defecto('repuesto')])->assertSessionHasNoErrors();

        $this->assertNull($this->guardado('repuesto'));
        $this->assertSame(PlantillasWhatsapp::defecto('repuesto'), PlantillasWhatsapp::texto('repuesto'));
    }

    public function test_el_original_de_libre_que_termina_en_espacio_tambien_se_reconoce(): void
    {
        $this->guardar(['libre' => 'Otro {nombre}']);
        // el navegador/servidor recortan el espacio final del original
        $this->guardar(['libre' => rtrim(PlantillasWhatsapp::defecto('libre'))]);

        $this->assertNull($this->guardado('libre'));
    }

    // ---- plantillas 1-3 y su radio: sin cambios ----
    public function test_las_plantillas_1_a_3_y_el_radio_siguen_igual(): void
    {
        $this->actingAs($this->usuario)->put('/configuracion/whatsapp', [
            'whatsapp_intro_1' => 'Cobranza uno {nombre}',
            'whatsapp_intro_2' => 'Cobranza dos {nombre}',
            'whatsapp_intro_3' => 'General {nombre}',
            'whatsapp_plantilla_activa' => '2',
        ])->assertRedirect('/configuracion');

        $this->assertSame('Cobranza dos {nombre}', Configuracion::valorDe('whatsapp_intro_2'));
        $this->assertSame('2', Configuracion::valorDe('whatsapp_plantilla_activa'));

        // guardar el bloque Taller (aunque falle) no toca las plantillas 1-3 ni el radio
        $this->guardar(['libre' => 'Con {monto}'])->assertSessionHasErrors('libre');
        $this->guardar(['libre' => 'Correcto {nombre}'])->assertSessionHasNoErrors();

        $this->assertSame('Cobranza uno {nombre}', Configuracion::valorDe('whatsapp_intro_1'));
        $this->assertSame('General {nombre}', Configuracion::valorDe('whatsapp_intro_3'));
        $this->assertSame('2', Configuracion::valorDe('whatsapp_plantilla_activa'));

        // y el radio sigue exigiendo una plantilla activa valida
        $this->actingAs($this->usuario)->put('/configuracion/whatsapp', ['whatsapp_plantilla_activa' => '9'])
            ->assertSessionHasErrors('whatsapp_plantilla_activa');
    }

    public function test_sin_iniciar_sesion_no_se_puede_guardar(): void
    {
        $this->put('/configuracion/whatsapp-taller', $this->datos())->assertRedirect('/login');
    }
}
