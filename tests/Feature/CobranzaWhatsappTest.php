<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\MovimientoCuenta;
use App\Models\PlanFinanciamiento;
use App\Models\User;
use App\Services\CobranzaWhatsapp;
use App\Services\Taller\PlantillasWhatsapp;
use App\Services\Taller\PresupuestoWhatsapp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// Lote 5: WhatsApp de cobranza en la ficha del cliente (motor PlantillasWhatsapp, espacio "cobranza")
class CobranzaWhatsappTest extends TestCase
{
    private User $usuario;

    private User $admin;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->usuario = User::factory()->create(['name' => 'Operador']);
        $this->admin = User::factory()->create(['name' => 'Admin', 'es_admin' => true]);
        $this->cliente = Cliente::create(['nombre' => 'Miguel Termini', 'telefono' => '+584141234567']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cargo(float $monto, string $articulo = 'Pedales Garmin', array $extra = [], ?Cliente $cliente = null): MovimientoCuenta
    {
        return MovimientoCuenta::create([
            'cliente_id' => ($cliente ?? $this->cliente)->id, 'fecha' => '2026-11-01', 'tipo' => 'cargo', 'descripcion' => $articulo,
            'cantidad' => 1, 'precio_unitario' => $monto, 'monto' => $monto, 'moneda' => 'usd', 'modalidad_precio' => 'divisa',
            'plazo_meses' => 1, 'frecuencia_pago' => 'mensual', 'registrado_por' => $this->usuario->id, ...$extra,
        ]);
    }

    private function abono(float $monto, string $fecha = '2026-11-01'): MovimientoCuenta
    {
        return MovimientoCuenta::create([
            'cliente_id' => $this->cliente->id, 'fecha' => $fecha, 'tipo' => 'abono', 'descripcion' => 'Zelle', 'monto' => $monto,
            'moneda' => 'usd', 'metodo_pago' => 'zelle', 'referencia' => 'Z1', 'registrado_por' => $this->usuario->id,
        ]);
    }

    private function plan(MovimientoCuenta $cargo, string $vence, float $pactado = 400): void
    {
        $plan = PlanFinanciamiento::create(['movimiento_cuenta_id' => $cargo->id, 'monto_inicial' => 0, 'porcentaje_mora' => 0, 'frecuencia_pago' => 'mensual', 'numero_cuotas' => 1]);
        $plan->cuotas()->create(['numero' => 1, 'monto_pactado' => $pactado, 'fecha_vencimiento' => $vence, 'monto_abonado' => 0]);
    }

    private function datos(?Cliente $cliente = null): array
    {
        return CobranzaWhatsapp::paraCliente($cliente ?? $this->cliente);
    }

    private function tipo(string $tipo, ?Cliente $cliente = null): array
    {
        return collect($this->datos($cliente)['tipos'])->firstWhere('tipo', $tipo);
    }

    private function texto(string $tipo, ?Cliente $cliente = null): string
    {
        return $this->tipo($tipo, $cliente)['texto'];
    }

    // ---- caso Miguel: compra de 1199 + abono general de 250 ----
    public function test_los_seis_tipos_generan_texto_con_las_cifras_de_la_cuenta_y_sin_llaves(): void
    {
        $this->cargo(1199, 'Pedales Garmin RS210', ['pendiente_entrega' => true]);
        $this->abono(250);

        $tipos = collect($this->datos()['tipos']);

        $this->assertCount(7, $tipos);
        foreach ($tipos as $t) {
            $this->assertNotSame('', trim($t['texto']), $t['tipo']);
            $this->assertStringNotContainsString('{', $t['texto'], $t['tipo']);
            $this->assertStringNotContainsString('}', $t['texto'], $t['tipo']);
        }
        foreach (['estado_cuenta', 'saldo', 'recordatorio'] as $tipo) {
            $this->assertStringContainsString('$949.00', $this->texto($tipo), $tipo);
        }
        $this->assertStringContainsString('$250.00', $this->texto('estado_cuenta'));
        $this->assertStringContainsString('$250.00', $this->texto('abono_recibido'));
        $this->assertStringContainsString('• PEDALES GARMIN RS210 — $1,199.00 (01/11)', $this->texto('estado_cuenta'));
        $this->assertStringContainsString('Miguel Termini', $this->texto('libre'));
    }

    public function test_abono_y_saldo_son_los_de_la_ficha_tambien_con_un_abono_anulado(): void
    {
        $this->cargo(1199);
        $this->abono(250);
        $segundo = $this->abono(100, '2026-11-05');

        $comparar = function () {
            $props = null;
            $this->actingAs($this->usuario)->get("/clientes/{$this->cliente->id}")->assertInertia(function ($p) use (&$props) {
                $props = $p->toArray()['props'];
            });
            $this->assertStringContainsString('Abonado: $'.number_format($props['totalCobrado'], 2).' · Saldo: $'.number_format($props['saldoPendiente'], 2), $this->texto('estado_cuenta'));
        };

        $comparar();
        $segundo->delete(); // anulado: ni suma ni es "el ultimo abono"
        $comparar();
        $this->assertStringContainsString('$250.00', $this->texto('abono_recibido'));
        $this->assertStringContainsString('01/11/2026', $this->texto('abono_recibido'));
    }

    public function test_estado_cuenta_con_40_compras_cabe_en_1800_y_cierra_con_y_n_mas(): void
    {
        foreach (range(1, 40) as $i) {
            $this->cargo(10 + $i, "Articulo de prueba con nombre largo numero $i");
        }

        $texto = $this->texto('estado_cuenta');

        $this->assertLessThanOrEqual(PresupuestoWhatsapp::URL_MAX, PresupuestoWhatsapp::largoDelEnlace('584141234567', $texto));
        $this->assertMatchesRegularExpression('/• y \d+ más\n/', $texto);
        $this->assertStringContainsString('Abonado:', $texto, 'el cierre con la cuenta sigue ahi');
    }

    public function test_recordatorio_cuota_vencida_futura_y_sin_cuotas(): void
    {
        $this->plan($this->cargo(400), '2026-11-07'); // hace 3 dias
        $this->assertStringContainsString('3 días', $this->texto('recordatorio'));
        $this->assertSame('recordatorio', $this->datos()['preseleccion']);

        $futuro = Cliente::create(['nombre' => 'Futuro', 'telefono' => '+584140000001']);
        $this->plan($this->cargo(400, 'Casco', [], $futuro), '2026-11-20');
        $this->assertStringContainsString('20/11/2026', $this->texto('recordatorio', $futuro));
        $this->assertStringNotContainsString('atraso', $this->texto('recordatorio', $futuro));
        $this->assertSame('estado_cuenta', $this->datos($futuro)['preseleccion']);

        $sin = Cliente::create(['nombre' => 'Sin Cuotas', 'telefono' => '+584140000002']);
        $this->cargo(80, 'Luces', [], $sin);
        $this->assertStringContainsString('$80.00', $this->texto('recordatorio', $sin));
        $this->assertStringNotContainsString('{', $this->texto('recordatorio', $sin));
    }

    public function test_por_entregar_deshabilitado_sin_articulos_y_con_ellos_lista_los_nombres(): void
    {
        $this->cargo(50, 'Casco');
        $t = $this->tipo('por_entregar');
        $this->assertFalse($t['habilitado']);
        $this->assertNotNull($t['pista']);

        $this->cargo(1150, 'Garmin Fenix 9 Pro', ['pendiente_entrega' => true]);
        $t = $this->tipo('por_entregar');
        $this->assertTrue($t['habilitado']);
        $this->assertStringContainsString('• GARMIN FENIX 9 PRO', $t['texto']);
        $this->assertStringNotContainsString('CASCO', $t['texto']);
    }

    public function test_abono_recibido_sin_abonos_esta_deshabilitado(): void
    {
        $this->cargo(50);
        $this->assertFalse($this->tipo('abono_recibido')['habilitado']);
    }

    public function test_cliente_sin_telefono_lo_informa(): void
    {
        $sin = Cliente::create(['nombre' => 'Sin Tel', 'telefono' => null]);

        $this->assertFalse($this->datos($sin)['tiene_telefono']);
        $this->assertTrue($this->datos()['tiene_telefono']);
    }

    // el Taller no cambia: cada plantilla sigue resolviendo al texto original de config/taller.php
    public function test_las_plantillas_del_taller_siguen_igual_que_el_texto_original(): void
    {
        foreach (PlantillasWhatsapp::CAMPOS as $campo => $d) {
            $this->assertSame((string) config($d['defecto']), PlantillasWhatsapp::texto($campo), $campo);
            $this->assertSame((string) config($d['defecto']), PlantillasWhatsapp::defecto($campo), $campo);
        }
        $this->assertStringContainsString('{monto} solo se permite', PlantillasWhatsapp::errorDeVariables('libre', 'x {monto}'));
    }

    // ---- configuracion ----
    private function guardar(array $textos, ?User $quien = null)
    {
        return $this->actingAs($quien ?? $this->admin)->put('/configuracion/whatsapp-cobranza', $textos);
    }

    private function todosPorDefecto(): array
    {
        return collect(PlantillasWhatsapp::COBRANZA)->map(fn ($c, $t) => PlantillasWhatsapp::defecto($t, 'cobranza'))->all();
    }

    public function test_editar_una_plantilla_persiste_la_usa_y_restaurar_vuelve_al_codigo(): void
    {
        $this->cargo(100);
        $this->guardar([...$this->todosPorDefecto(), 'saldo' => 'Buen día {nombre}, debes {saldo}'])->assertRedirect();
        $this->assertSame('Buen día Miguel Termini, debes $100.00', $this->texto('saldo'));

        $this->guardar($this->todosPorDefecto())->assertRedirect();
        $this->assertNull(Configuracion::valorDe('whatsapp_cobranza_saldo'), 'igual al original: sigue al codigo');
        $this->assertStringContainsString('Quedamos atentos', $this->texto('saldo'));
    }

    public function test_configuracion_valida_acceso_largo_y_variables(): void
    {
        // lote 10: la edicion ya no es solo admin; solo el rol Taller queda fuera
        $this->guardar($this->todosPorDefecto(), User::factory()->create(['rol_taller' => true]))->assertForbidden();
        $this->guardar([...$this->todosPorDefecto(), 'saldo' => str_repeat('a', 1001)])->assertSessionHasErrors('saldo');
        $this->guardar([...$this->todosPorDefecto(), 'saldo' => 'Hola {nombre} {cuota_fecha}'])->assertSessionHasErrors('saldo');
        $this->guardar([...$this->todosPorDefecto(), 'libre' => ''])->assertSessionHasErrors('libre');
    }

    public function test_la_configuracion_solo_viaja_a_admin(): void
    {
        $this->actingAs($this->admin)->get('/configuracion')->assertInertia(fn ($p) => $p->has('plantillasCobranza', 7));
        $this->actingAs($this->usuario)->get('/configuracion')->assertInertia(fn ($p) => $p->has('plantillasCobranza', 7));
    }

    // ---- trazabilidad ----
    public function test_abrir_whatsapp_registra_el_evento_sin_el_texto_y_la_gestion_es_opcional(): void
    {
        $this->actingAs($this->usuario)->postJson("/clientes/{$this->cliente->id}/whatsapp-cobranza", ['tipo' => 'saldo'])->assertNoContent();

        $log = Activity::where('description', 'whatsapp_cobranza')->firstOrFail();
        $this->assertSame($this->cliente->id, (int) $log->subject_id);
        $this->assertSame($this->usuario->id, (int) $log->causer_id);
        $this->assertSame(['tipo' => 'saldo'], $log->properties->toArray());
        $this->assertSame(0, MovimientoCuenta::where('tipo', 'gestion')->count());

        $this->actingAs($this->usuario)->postJson("/clientes/{$this->cliente->id}/whatsapp-cobranza", ['tipo' => 'recordatorio', 'anotar_gestion' => true])->assertNoContent();
        $g = MovimientoCuenta::where('tipo', 'gestion')->firstOrFail();
        $this->assertSame('WhatsApp: recordatorio', $g->comentario);
        $this->assertSame('whatsapp', $g->tipo_contacto);

        $this->actingAs($this->usuario)->postJson("/clientes/{$this->cliente->id}/whatsapp-cobranza", ['tipo' => 'otro'])->assertStatus(422);
    }
}
