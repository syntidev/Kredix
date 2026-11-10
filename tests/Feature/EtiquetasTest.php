<?php

namespace Tests\Feature;

use App\Http\Controllers\EtiquetasController;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// Lote 8: etiquetas de envio e impresion libre. Los valores impresos nunca se guardan.
class EtiquetasTest extends TestCase
{
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->usuario = User::factory()->create(['name' => 'Operador']); // no admin: cualquier usuario autenticado puede
    }

    private function guardar(array $plantillas, $enviarVacio = false)
    {
        return $this->actingAs($this->usuario)->put('/etiquetas/plantillas', ['plantillas' => $enviarVacio ? '' : $plantillas]);
    }

    // ---- acceso y pagina ----
    public function test_cualquier_usuario_autenticado_ve_la_pagina_y_el_visitante_no(): void
    {
        $this->get('/etiquetas')->assertRedirect('/login');
        $this->actingAs($this->usuario)->get('/etiquetas')->assertOk()->assertInertia(fn ($p) => $p->component('Etiquetas/Index')
            ->where('plantillaEnvio.nombre', 'Envío')->where('plantillaEnvio.campos', ['Nombre', 'Cédula', 'Teléfono', 'Dirección'])
            ->where('plantillas', []));
    }

    // ---- plantillas ----
    public function test_guardar_renombrar_y_borrar_plantillas_persiste(): void
    {
        $this->guardar([['nombre' => 'Pedido', 'campos' => ['Producto', 'Talla']]])->assertRedirect();
        $this->assertSame([['nombre' => 'Pedido', 'campos' => ['Producto', 'Talla']]], json_decode(Configuracion::valorDe('etiquetas_plantillas'), true));

        $this->guardar([['nombre' => 'Pedido nuevo', 'campos' => ['Producto', 'Talla']]])->assertRedirect(); // renombrar
        $this->actingAs($this->usuario)->get('/etiquetas')->assertInertia(fn ($p) => $p->where('plantillas.0.nombre', 'Pedido nuevo'));

        $this->guardar([], true)->assertRedirect(); // borrar la ultima
        $this->assertSame([], json_decode(Configuracion::valorDe('etiquetas_plantillas'), true));
    }

    public function test_la_plantilla_envio_existe_aunque_la_clave_este_vacia_y_no_se_puede_guardar_con_ese_nombre(): void
    {
        $this->assertNull(Configuracion::valorDe('etiquetas_plantillas'));
        $this->actingAs($this->usuario)->get('/etiquetas')->assertInertia(fn ($p) => $p->has('plantillaEnvio.campos', 4));

        $this->guardar([['nombre' => 'Envío', 'campos' => ['A']]])->assertSessionHasErrors('plantillas.0.nombre');
    }

    public function test_plantillas_invalidas_dan_422(): void
    {
        $this->guardar([['nombre' => '', 'campos' => ['A']]])->assertSessionHasErrors('plantillas.0.nombre');
        $this->guardar([['nombre' => str_repeat('a', 61), 'campos' => ['A']]])->assertSessionHasErrors('plantillas.0.nombre');
        $this->guardar([['nombre' => 'X', 'campos' => array_map(fn ($i) => "C$i", range(1, 13))]])->assertSessionHasErrors('plantillas.0.campos');
        $this->guardar([['nombre' => 'X', 'campos' => [str_repeat('t', 41)]]])->assertSessionHasErrors('plantillas.0.campos.0');
        $this->guardar([['nombre' => 'X', 'campos' => ['']]])->assertSessionHasErrors('plantillas.0.campos.0');
        $veintiuna = array_map(fn ($i) => ['nombre' => "P$i", 'campos' => ['A']], range(1, 21));
        $this->guardar($veintiuna)->assertSessionHasErrors('plantillas');
        $this->assertNull(Configuracion::valorDe('etiquetas_plantillas'), 'nada se guardo');

        $veinte = array_map(fn ($i) => ['nombre' => "P$i", 'campos' => array_map(fn ($c) => "C$c", range(1, 12))], range(1, 20));
        $this->guardar($veinte)->assertSessionHasNoErrors();
    }

    // ---- modo Envio con cliente ----
    public function test_el_cliente_rellena_los_cuatro_campos_y_no_se_crea_ningun_cliente(): void
    {
        $c = Cliente::create(['nombre' => 'Miguel Termini', 'telefono' => '+584141234567', 'cedula' => 'V-12345678', 'direccion' => 'Av. Principal, casa 4, Porlamar']);
        $antes = Cliente::count();

        $this->actingAs($this->usuario)->getJson("/etiquetas/cliente/{$c->id}")->assertOk()->assertExactJson([
            'nombre' => 'Miguel Termini', 'cedula' => 'V-12345678', 'telefono' => '+584141234567', 'direccion' => 'Av. Principal, casa 4, Porlamar',
        ]);
        // escribir todo a mano no pasa por ningun endpoint que cree clientes: solo se registra la impresion
        $this->actingAs($this->usuario)->postJson('/etiquetas/impresa', ['modo' => 'envio', 'plantilla' => 'Envío'])->assertNoContent();

        $this->assertSame($antes, Cliente::count());
    }

    // ---- privacidad ----
    public function test_el_evento_guarda_modo_y_usuario_sin_contenido_y_los_valores_no_quedan_en_ningun_lado(): void
    {
        $c = Cliente::create(['nombre' => 'Persona Privada', 'telefono' => '+584149998877', 'cedula' => 'V-99887766', 'direccion' => 'Calle Secreta 123']);
        $this->actingAs($this->usuario)->getJson("/etiquetas/cliente/{$c->id}")->assertOk();
        // aunque alguien mande contenido extra, el endpoint solo toma modo/plantilla
        $this->actingAs($this->usuario)->postJson('/etiquetas/impresa', ['modo' => 'envio', 'plantilla' => 'Envío', 'texto' => 'Calle Secreta 123', 'cedula' => 'V-99887766'])->assertNoContent();
        $this->guardar([['nombre' => 'Pedido', 'campos' => ['Producto']]]);

        $e = Activity::where('description', 'etiqueta_impresa')->firstOrFail();
        $this->assertSame($this->usuario->id, (int) $e->causer_id);
        $this->assertSame(['modo' => 'envio', 'plantilla' => 'Envío'], $e->properties->toArray());

        $volcado = json_encode(Activity::all()->toArray()).json_encode(Configuracion::all()->toArray());
        foreach (['Calle Secreta 123', 'V-99887766', '+584149998877', 'Persona Privada'] as $valor) {
            $this->assertStringNotContainsString($valor, $volcado);
        }
    }

    public function test_modo_desconocido_da_422(): void
    {
        $this->actingAs($this->usuario)->postJson('/etiquetas/impresa', ['modo' => 'otro'])->assertStatus(422);
        $this->assertSame(0, DB::table('activity_log')->where('description', 'etiqueta_impresa')->count());
    }

    public function test_la_constante_de_modos_incluye_el_boton_de_la_ficha(): void
    {
        $this->assertContains('ficha_cliente', EtiquetasController::MODOS);
        $this->actingAs($this->usuario)->postJson('/etiquetas/impresa', ['modo' => 'ficha_cliente'])->assertNoContent();
    }
}
