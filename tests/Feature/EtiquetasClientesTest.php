<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// Lote 9: Etiquetas con clientes registrados (buscador) y guardado parcial en la ficha
class EtiquetasClientesTest extends TestCase
{
    private User $usuario;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->usuario = User::factory()->create(['name' => 'Operador']);
        $this->cliente = Cliente::create([
            'nombre' => 'MIGUEL TERMINI PÉREZ', 'telefono' => '+584141234567', 'cedula' => '12345678', 'direccion' => null,
            'email' => 'miguel@example.com', 'notas' => 'Cliente VIP',
        ]);
    }

    private function buscar(string $q): array
    {
        return $this->actingAs($this->usuario)->getJson('/etiquetas/clientes?q='.urlencode($q))->assertOk()->json();
    }

    private function guardar(array $datos, ?User $quien = null, ?Cliente $cliente = null)
    {
        return $this->actingAs($quien ?? $this->usuario)->putJson('/etiquetas/cliente/'.($cliente ?? $this->cliente)->id, $datos);
    }

    // ---- buscador ----
    public function test_encuentra_por_nombre_parcial_sin_mayusculas_ni_acentos_por_cedula_y_por_telefono(): void
    {
        foreach (['termini', 'TERMINI perez', 'miguel pérez', 'ter'] as $q) {
            $this->assertSame([$this->cliente->id], array_column($this->buscar($q), 'id'), $q);
        }
        $this->assertSame([$this->cliente->id], array_column($this->buscar('12345678'), 'id'), 'cédula');
        $this->assertSame([$this->cliente->id], array_column($this->buscar('0414 123 4567'), 'id'), 'teléfono local');
        $this->assertSame([$this->cliente->id], array_column($this->buscar('+58 414 1234567'), 'id'), 'teléfono internacional');
        $this->assertSame([], $this->buscar('zzzz'));
        $this->assertSame([], $this->buscar('t'), 'mínimo 2 caracteres');
    }

    public function test_el_resultado_trae_lo_necesario_y_topa_en_8(): void
    {
        foreach (range(1, 10) as $i) {
            Cliente::create(['nombre' => "GARCIA $i", 'telefono' => '+58414000000'.$i]);
        }

        $r = $this->buscar('garcia');

        $this->assertCount(8, $r);
        $this->assertSame(['id', 'nombre', 'cedula', 'telefono'], array_keys($r[0]));
    }

    // ---- guardar en la ficha ----
    public function test_rellenar_la_direccion_vacia_se_guarda_directo_y_deja_actividad_con_usuario(): void
    {
        $this->guardar(['direccion' => 'Av. Principal, Porlamar'])->assertOk()->assertJsonPath('direccion', 'Av. Principal, Porlamar');

        $this->assertSame('Av. Principal, Porlamar', $this->cliente->fresh()->direccion);
        $e = Activity::where('description', 'cliente_actualizado_desde_etiqueta')->firstOrFail();
        $this->assertSame($this->usuario->id, (int) $e->causer_id);
        $this->assertSame($this->cliente->id, (int) $e->subject_id);
        $this->assertSame(['direccion' => ['anterior' => null, 'nuevo' => 'Av. Principal, Porlamar']], $e->properties['campos']);
    }

    public function test_reemplazar_un_dato_existente_exige_confirmacion_y_con_ella_cambia(): void
    {
        $this->guardar(['cedula' => '87654321'])->assertStatus(422)->assertJsonValidationErrors('confirmar_reemplazo');
        $this->assertSame('12345678', $this->cliente->fresh()->cedula);
        $this->assertSame(0, Activity::where('description', 'cliente_actualizado_desde_etiqueta')->count());

        $this->guardar(['cedula' => '87654321', 'confirmar_reemplazo' => true])->assertOk();
        $this->assertSame('87654321', $this->cliente->fresh()->cedula);
    }

    public function test_valores_invalidos_dan_el_mismo_mensaje_que_la_edicion_normal(): void
    {
        $this->guardar(['cedula' => '12.345.678', 'confirmar_reemplazo' => true])->assertStatus(422)
            ->assertJsonPath('errors.cedula.0', 'La cedula no debe contener puntos ni espacios.');
        $this->guardar(['telefono' => '0414', 'confirmar_reemplazo' => true])->assertStatus(422)
            ->assertJsonPath('errors.telefono.0', 'telefono invalido, selecciona el pais y completa el numero');
        $this->assertSame('12345678', $this->cliente->fresh()->cedula);
        $this->assertSame('+584141234567', $this->cliente->fresh()->telefono);
    }

    public function test_solo_se_tocan_los_campos_que_difieren_y_nada_mas_del_cliente(): void
    {
        MovimientoCuenta::create(['cliente_id' => $this->cliente->id, 'fecha' => '2026-11-01', 'tipo' => 'cargo', 'descripcion' => 'Casco', 'cantidad' => 1, 'precio_unitario' => 80, 'monto' => 80, 'moneda' => 'usd', 'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'mensual', 'registrado_por' => $this->usuario->id]);
        $campos = ['nombre', 'cedula', 'telefono', 'email', 'notas', 'contacto_alterno_nombre', 'usuario_responsable_id'];
        $antes = $this->cliente->fresh()->only($campos);

        // nombre y teléfono iguales a la ficha (no cambian); la dirección sí; los demás campos ni se envían
        $this->guardar(['nombre' => 'miguel termini pérez', 'telefono' => '+584141234567', 'direccion' => 'Calle 1'])->assertOk();

        $c = $this->cliente->fresh();
        $this->assertSame('Calle 1', $c->direccion);
        $this->assertSame($antes, $c->only($campos));
        $this->assertSame(1, MovimientoCuenta::where('cliente_id', $c->id)->count());
        $this->assertSame(['direccion'], array_keys(Activity::where('description', 'cliente_actualizado_desde_etiqueta')->firstOrFail()->properties['campos']));
    }

    public function test_un_valor_vacio_nunca_borra_el_dato_de_la_ficha(): void
    {
        $this->guardar(['cedula' => '', 'telefono' => ''])->assertOk();

        $this->assertSame('12345678', $this->cliente->fresh()->cedula);
        $this->assertSame('+584141234567', $this->cliente->fresh()->telefono);
    }

    // ---- sin cliente seleccionado / permisos ----
    public function test_sin_cliente_no_se_crea_nadie_y_la_impresion_solo_registra_el_evento(): void
    {
        $antes = Cliente::count();
        $this->actingAs($this->usuario)->postJson('/etiquetas/impresa', ['modo' => 'envio', 'plantilla' => 'Envío'])->assertNoContent();

        $this->assertSame($antes, Cliente::count());
        $this->assertSame(0, Activity::where('description', 'cliente_actualizado_desde_etiqueta')->count());
    }

    public function test_un_usuario_de_solo_taller_recibe_403_y_el_visitante_no_pasa(): void
    {
        $taller = User::factory()->create(['rol_taller' => true]);

        $this->guardar(['direccion' => 'X'], $taller)->assertForbidden();
        $this->assertNull($this->cliente->fresh()->direccion);
        $this->actingAs($taller)->getJson('/etiquetas/clientes?q=miguel')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->putJson('/etiquetas/cliente/'.$this->cliente->id, ['direccion' => 'X'])->assertUnauthorized();
    }

    // ---- la direccion se edita en la ficha y llega a Etiquetas ----
    public function test_la_direccion_editada_en_la_ficha_aparece_al_elegir_el_cliente_en_etiquetas(): void
    {
        $this->actingAs($this->usuario)->put("/clientes/{$this->cliente->id}", [
            'nombre' => 'Miguel Termini Pérez', 'telefono' => '+584141234567', 'cedula' => '12345678', 'direccion' => 'Edif. Las Palmas, Piso 3',
        ])->assertRedirect();

        $this->actingAs($this->usuario)->getJson("/etiquetas/cliente/{$this->cliente->id}")->assertOk()->assertJsonPath('direccion', 'Edif. Las Palmas, Piso 3');
    }
}
