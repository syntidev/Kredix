<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\MovimientoCuenta;
use App\Models\User;
use App\Services\CobranzaWhatsapp;
use App\Services\Taller\PlantillasWhatsapp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// Lote 10: tipo "promocion" del WhatsApp de cobranza + acceso de usuarios no-Taller a las plantillas
class CobranzaPromocionTest extends TestCase
{
    private User $admin;

    private User $normal;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->admin = User::factory()->create(['es_admin' => true]);
        $this->normal = User::factory()->create(['es_admin' => false]);
        $this->cliente = Cliente::create(['nombre' => 'Ana Pérez', 'telefono' => '+584141234567']);
        $this->cargo(30);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cargo(float $monto): MovimientoCuenta
    {
        return MovimientoCuenta::create(['cliente_id' => $this->cliente->id, 'fecha' => '2026-11-01', 'tipo' => 'cargo', 'descripcion' => 'Casco', 'cantidad' => 1, 'precio_unitario' => $monto, 'monto' => $monto, 'moneda' => 'usd', 'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'mensual', 'registrado_por' => $this->admin->id]);
    }

    private function promo(): array
    {
        return collect(CobranzaWhatsapp::paraCliente($this->cliente->fresh())['tipos'])->firstWhere('tipo', 'promocion');
    }

    private function enFecha(string $fecha): array
    {
        Carbon::setTestNow(Carbon::parse($fecha.' 10:00', 'America/Caracas'));

        return $this->promo();
    }

    public function test_texto_por_defecto_con_saldo_y_fecha_limite_sin_llaves(): void
    {
        $t = $this->enFecha('2026-11-10')['texto'];

        $this->assertStringContainsString('$30.00', $t);
        $this->assertStringContainsString('hasta el 30 de noviembre de 2026', $t);
        $this->assertStringContainsString('Ana Pérez', $t);
        $this->assertStringNotContainsString('{', $t);
        $this->assertStringNotContainsString('}', $t);
        $this->assertStringNotContainsString('$$', $t);
    }

    public function test_fin_de_mes_bisiesto_y_nombre_del_mes(): void
    {
        $this->assertStringContainsString('hasta el 28 de febrero de 2027', $this->enFecha('2027-02-15')['texto']);
        $this->assertStringContainsString('hasta el 29 de febrero de 2028', $this->enFecha('2028-02-15')['texto']);
        $this->assertStringContainsString('hasta el 31 de diciembre de 2026', $this->enFecha('2026-12-03')['texto']);

        Configuracion::updateOrCreate(['clave' => 'whatsapp_cobranza_promocion'], ['valor' => 'Mes: {mes}']);
        $this->assertSame('Mes: noviembre', $this->enFecha('2026-11-10')['texto']);
        $this->assertSame('Mes: diciembre', $this->enFecha('2026-12-03')['texto']);
    }

    public function test_el_dia_se_toma_en_zona_caracas_y_no_en_utc(): void
    {
        // 30 nov 22:00 Caracas = 1 dic 02:00 UTC: sigue siendo noviembre
        Carbon::setTestNow(Carbon::parse('2026-12-01 02:00:00', 'UTC'));

        $this->assertStringContainsString('hasta el 30 de noviembre de 2026', $this->promo()['texto']);
    }

    public function test_el_saldo_es_el_de_la_ficha_con_y_sin_abono_anulado(): void
    {
        $abono = MovimientoCuenta::create(['cliente_id' => $this->cliente->id, 'fecha' => '2026-11-02', 'tipo' => 'abono', 'descripcion' => 'Abono', 'monto' => 10, 'moneda' => 'usd', 'modalidad_precio' => 'divisa', 'registrado_por' => $this->admin->id]);
        $this->assertStringContainsString('$'.number_format(MovimientoCuenta::saldoPendiente($this->cliente->id), 2), $this->promo()['texto']);
        $this->assertStringContainsString('$20.00', $this->promo()['texto']);

        $abono->delete();
        $this->assertStringContainsString('$30.00', $this->promo()['texto']);
        $this->assertStringContainsString('$'.number_format(MovimientoCuenta::saldoPendiente($this->cliente->id), 2), $this->promo()['texto']);
    }

    public function test_sin_saldo_esta_deshabilitada_y_la_preseleccion_no_cambia(): void
    {
        $this->assertTrue($this->promo()['habilitado']);

        MovimientoCuenta::where('cliente_id', $this->cliente->id)->delete();
        $d = CobranzaWhatsapp::paraCliente($this->cliente->fresh());

        $p = collect($d['tipos'])->firstWhere('tipo', 'promocion');
        $this->assertFalse($p['habilitado']);
        $this->assertSame('Sin saldo pendiente', $p['pista']);
        $this->assertSame('estado_cuenta', $d['preseleccion']);
    }

    public function test_orden_del_selector_y_el_libre_sigue_igual(): void
    {
        $tipos = array_column(CobranzaWhatsapp::paraCliente($this->cliente)['tipos'], 'tipo');
        $this->assertSame(['recordatorio', 'estado_cuenta', 'abono_recibido', 'saldo', 'por_entregar', 'promocion', 'libre'], $tipos);

        $this->assertSame(['nombre'], PlantillasWhatsapp::COBRANZA['libre']['variables']);
        $this->assertNotNull(PlantillasWhatsapp::errorDeVariables('libre', 'Hola {saldo}', 'cobranza'));
    }

    public function test_el_texto_por_defecto_cabe_en_el_maximo_contando_caracteres_unicode(): void
    {
        $texto = PlantillasWhatsapp::defecto('promocion', 'cobranza');

        $this->assertLessThanOrEqual(1000, mb_strlen($texto));
        $this->assertLessThan(strlen($texto), mb_strlen($texto), 'los emojis pesan mas bytes que caracteres');
    }

    private function textos(array $cambios = []): array
    {
        return $cambios + collect(PlantillasWhatsapp::COBRANZA)->map(fn ($c, $t) => PlantillasWhatsapp::texto($t, 'cobranza'))->all();
    }

    public function test_guardar_valida_variables_longitud_y_restaurar(): void
    {
        $ok = '{nombre} {saldo} {mes} {fecha_limite} promo';
        $this->actingAs($this->normal)->put('/configuracion/whatsapp-cobranza', $this->textos(['promocion' => $ok]))->assertRedirect();
        $this->assertSame($ok, PlantillasWhatsapp::texto('promocion', 'cobranza'));

        $this->actingAs($this->normal)->putJson('/configuracion/whatsapp-cobranza', $this->textos(['promocion' => 'Cuota {cuota_monto}']))
            ->assertStatus(422)->assertJsonPath('errors.promocion.0', 'La variable {cuota_monto} no es válida en «Promoción del mes». Las válidas son: {nombre}, {saldo}, {mes}, {fecha_limite}.');
        $this->actingAs($this->normal)->putJson('/configuracion/whatsapp-cobranza', $this->textos(['promocion' => str_repeat('a', 1001)]))
            ->assertStatus(422)->assertJsonValidationErrors('promocion');

        // restaurar = guardar el texto de codigo: queda null y sigue al original
        $this->actingAs($this->normal)->put('/configuracion/whatsapp-cobranza', $this->textos(['promocion' => PlantillasWhatsapp::defecto('promocion', 'cobranza')]))->assertRedirect();
        $this->assertSame(PlantillasWhatsapp::defecto('promocion', 'cobranza'), PlantillasWhatsapp::texto('promocion', 'cobranza'));
        $this->assertNull(Configuracion::valorDe('whatsapp_cobranza_promocion'));
    }

    public function test_enlace_wa_me_con_emojis_codificados_y_dentro_del_tope(): void
    {
        $texto = $this->promo()['texto'];
        // misma codificacion que WhatsappCobranza.vue (encodeURIComponent)
        $url = 'https://wa.me/584141234567?text='.rawurlencode($texto);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame($texto, $q['text']);
        $this->assertStringContainsString('%F0%9F%9A%B4%E2%80%8D%E2%99%82%EF%B8%8F', $url, 'secuencia de emoji codificada');
        $this->assertLessThanOrEqual(1800, strlen($url));
    }

    public function test_registrar_gestion_guarda_tipo_promocion_y_usuario_sin_texto(): void
    {
        $this->actingAs($this->normal)->postJson("/clientes/{$this->cliente->id}/whatsapp-cobranza", ['tipo' => 'promocion'])->assertSuccessful();

        $e = Activity::where('description', 'whatsapp_cobranza')->firstOrFail();
        $this->assertSame('promocion', $e->properties['tipo']);
        $this->assertSame($this->normal->id, (int) $e->causer_id);
        $this->assertArrayNotHasKey('texto', $e->properties->toArray());
    }

    // ---- Bloque B: acceso ----
    public function test_acceso_por_rol_a_ficha_y_plantillas(): void
    {
        $taller = User::factory()->create(['rol_taller' => true]);

        foreach ([$this->admin, $this->normal] as $u) {
            $this->actingAs($u)->get("/clientes/{$this->cliente->id}")->assertOk()->assertInertia(fn ($p) => $p->has('whatsappCobranza.tipos', 7));
            $this->actingAs($u)->get('/configuracion')->assertOk()->assertInertia(fn ($p) => $p->has('plantillasCobranza', 7));
            $this->actingAs($u)->put('/configuracion/whatsapp-cobranza', $this->textos())->assertRedirect();
        }

        $this->actingAs($taller)->get("/clientes/{$this->cliente->id}")->assertForbidden();
        $this->actingAs($taller)->get('/configuracion')->assertForbidden();
        $this->actingAs($taller)->put('/configuracion/whatsapp-cobranza', $this->textos())->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->get('/configuracion')->assertRedirect('/login');
        $this->put('/configuracion/whatsapp-cobranza', $this->textos())->assertRedirect('/login');
    }

    public function test_lo_solo_admin_de_configuracion_sigue_restringido_para_el_usuario_normal(): void
    {
        $this->actingAs($this->normal)->get('/configuracion')->assertInertia(fn ($p) => $p->where('ajustesTaller', null));
        $this->actingAs($this->normal)->put('/configuracion/taller', ['comision_servicio_pct' => 50, 'comision_repuestos_pct' => 5])->assertForbidden();
    }
}
