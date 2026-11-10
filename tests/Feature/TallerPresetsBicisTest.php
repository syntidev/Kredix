<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\MovimientoCuenta;
use App\Models\TicketTaller;
use App\Models\User;
use App\Services\Taller\ComisionesTaller;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Lote 4: servicios rapidos (presets), sugerencias de bici y C1 (cerrar un ticket sin pago)
class TallerPresetsBicisTest extends TestCase
{
    private User $admin;

    private User $tecnico;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $migraciones = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => str_contains($p, 'replace_procesado_con_estado_en_prospectos_200k'))
            ->values()->all();
        Artisan::call('migrate', ['--path' => $migraciones, '--realpath' => true]);

        Storage::fake('public');
        config(['ia.activa' => false, 'taller.revision_tecnica_activa' => true]);
        $this->admin = User::factory()->create(['name' => 'Admin', 'es_admin' => true]);
        $this->tecnico = User::factory()->create(['name' => 'Carlos Rojas', 'rol_taller' => true]);
        $this->cliente = Cliente::create(['nombre' => 'Ana Perez', 'telefono' => '+584141234567']);
    }

    private function ticket(array $extra = []): TicketTaller
    {
        $creado = $extra['created_at'] ?? null;
        unset($extra['created_at']);
        $t = TicketTaller::create([
            'tipo' => 'servicio_cliente', 'cliente_id' => $this->cliente->id, 'tipo_servicio' => 'otro', 'monto_servicio' => 15,
            'motivo_ingreso' => 'pincha', 'bici_marca_modelo' => 'Trek Marlin', 'categoria_bici' => 'mtb', 'talla_rin' => '29',
            'es_electrica' => false, 'diagnostico' => [], 'mecanico_id' => $this->tecnico->id, 'registrado_por' => $this->tecnico->id,
            ...$extra,
        ]);
        if ($creado) {
            $t->forceFill(['created_at' => $creado])->save();
        }

        return $t;
    }

    private function guardarPresets(?array $presets, ?User $quien = null)
    {
        return $this->actingAs($quien ?? $this->admin)->put('/configuracion/taller', [
            'comision_servicio_pct' => 50, 'comision_repuestos_pct' => 5, 'presets_servicio' => $presets,
        ]);
    }

    // ---- Bloque A: presets ----
    public function test_guardar_dos_presets_los_persiste_y_viajan_a_nuevo_y_show(): void
    {
        $this->guardarPresets([['nombre' => 'Antipinchazo (2 ruedas)', 'monto' => 15], ['nombre' => 'Antipinchazo (1 rueda)', 'monto' => '7.50']])->assertRedirect();

        $esperado = [['nombre' => 'Antipinchazo (2 ruedas)', 'monto' => 15.0], ['nombre' => 'Antipinchazo (1 rueda)', 'monto' => 7.5]];
        $this->assertEquals($esperado, json_decode(Configuracion::valorDe('taller_presets_servicio'), true));

        $this->actingAs($this->tecnico)->get('/taller/nuevo')->assertInertia(fn ($p) => $this->assertEquals($esperado, $p->toArray()['props']['presetsServicio']));
        $t = $this->ticket();
        $this->actingAs($this->tecnico)->get("/taller/{$t->id}")->assertInertia(fn ($p) => $this->assertEquals($esperado, $p->toArray()['props']['presetsServicio']));
    }

    public function test_presets_invalidos_dan_422(): void
    {
        $this->guardarPresets([['nombre' => '', 'monto' => 10]])->assertSessionHasErrors('presets_servicio.0.nombre');
        $this->guardarPresets([['nombre' => 'X', 'monto' => -1]])->assertSessionHasErrors('presets_servicio.0.monto');
        $this->guardarPresets([['nombre' => 'X', 'monto' => 10000]])->assertSessionHasErrors('presets_servicio.0.monto');
        $this->guardarPresets([['nombre' => str_repeat('a', 61), 'monto' => 1]])->assertSessionHasErrors('presets_servicio.0.nombre');
        $veintiuno = array_map(fn ($i) => ['nombre' => "P$i", 'monto' => 1], range(1, 21));
        $this->guardarPresets($veintiuno)->assertSessionHasErrors('presets_servicio');
        $this->assertNull(Configuracion::valorDe('taller_presets_servicio'), 'nada se guardo');

        $veinte = array_map(fn ($i) => ['nombre' => "P$i", 'monto' => 1], range(1, 20));
        $this->guardarPresets($veinte)->assertSessionHasNoErrors();
        $this->guardarPresets([['nombre' => 'Tope', 'monto' => 9999.99], ['nombre' => 'Cero', 'monto' => 0]])->assertSessionHasNoErrors();
    }

    public function test_un_no_admin_no_puede_editar_presets(): void
    {
        $this->guardarPresets([['nombre' => 'X', 'monto' => 5]], $this->tecnico)->assertForbidden();
        $this->assertNull(Configuracion::valorDe('taller_presets_servicio'));
    }

    public function test_guardar_la_configuracion_sin_presets_no_los_borra(): void
    {
        $this->guardarPresets([['nombre' => 'Antipinchazo', 'monto' => 15]]);
        $this->actingAs($this->admin)->put('/configuracion/taller', ['comision_servicio_pct' => 50, 'comision_repuestos_pct' => 5])->assertRedirect();

        $this->assertCount(1, json_decode(Configuracion::valorDe('taller_presets_servicio'), true));
    }

    public function test_quitar_el_ultimo_preset_vacia_la_lista(): void
    {
        $this->guardarPresets([['nombre' => 'Antipinchazo', 'monto' => 15]]);
        $this->actingAs($this->admin)->put('/configuracion/taller', ['comision_servicio_pct' => 50, 'comision_repuestos_pct' => 5, 'presets_servicio' => ''])->assertRedirect();

        $this->assertSame([], json_decode(Configuracion::valorDe('taller_presets_servicio'), true));
    }

    public function test_dos_toques_de_7_50_guardan_15_y_el_kpi_da_servicio_base_15_y_comision_7_50(): void
    {
        // el chip suma 7.50 dos veces en pantalla; lo que viaja al guardar es Servicio = 15.00
        $this->actingAs($this->tecnico)->post('/taller', [
            'cliente_id' => $this->cliente->id, 'motivo_ingreso' => 'pincha', 'bici_marca_modelo' => 'GW', 'categoria_bici' => 'mtb',
            'tipo_servicio' => 'otro', 'monto_servicio' => 15.00, 'mecanico_id' => $this->tecnico->id,
            'fotos_entrada' => [UploadedFile::fake()->image('e.jpg', 600, 900)],
        ])->assertRedirect();
        $t = TicketTaller::firstOrFail();
        $this->assertEquals(15.0, (float) $t->monto_servicio);

        $t->forceFill(['estado' => 'atendido', 'atendido_en' => '2026-11-03 12:00:00'])->save();
        $fila = ComisionesTaller::calcular(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), 50, 5)['porMecanico'][0];

        $this->assertSame(15.0, $fila['servicio_base']);
        $this->assertSame(7.5, $fila['comision_servicio']);
    }

    // ---- Bloque B: sugerencias de bici ----
    private function sugerencias(string $q, ?int $cliente = null): array
    {
        return $this->actingAs($this->tecnico)->getJson('/taller/bicis?q='.urlencode($q).($cliente ? "&cliente_id=$cliente" : ''))->assertOk()->json();
    }

    public function test_specialized_devuelve_una_por_bici_sin_importar_mayusculas_ni_espacios_la_mas_reciente_primero(): void
    {
        $this->ticket(['bici_marca_modelo' => 'SPECIALIZED NEGRA', 'categoria_bici' => 'ruta', 'talla_rin' => '28', 'created_at' => now()->subDays(5)]);
        $this->ticket(['bici_marca_modelo' => 'Trek Marlin', 'created_at' => now()->subDays(3)]);
        $this->ticket(['bici_marca_modelo' => ' Specialized   negra ', 'categoria_bici' => 'mtb', 'talla_rin' => '29', 'es_electrica' => true, 'created_at' => now()->subDays(1)]);
        $this->ticket(['bici_marca_modelo' => 'Specialized Rockhopper', 'created_at' => now()->subDays(2)]);

        $s = $this->sugerencias('SPECIALIZED')['sugerencias'];

        $this->assertSame(['Specialized   negra', 'Specialized Rockhopper'], array_column($s, 'bici_marca_modelo'), 'sin duplicados, reciente primero, grafia del mas reciente');
        $this->assertSame('mtb', $s[0]['categoria_bici'], 'datos del ticket mas reciente con ese texto');
        $this->assertSame('29', $s[0]['talla_rin']);
        $this->assertTrue($s[0]['es_electrica']);
    }

    public function test_ignora_acentos_exige_dos_caracteres_y_topa_en_ocho(): void
    {
        $this->ticket(['bici_marca_modelo' => 'Cañón Éxito']);
        $this->assertCount(1, $this->sugerencias('canon exi')['sugerencias']);
        $this->assertSame([], $this->sugerencias('c')['sugerencias']);

        foreach (range(1, 10) as $i) {
            $this->ticket(['bici_marca_modelo' => "Giant $i"]);
        }
        $this->assertCount(8, $this->sugerencias('giant')['sugerencias']);
    }

    public function test_elegida_una_sugerencia_trae_los_datos_del_ticket_mas_reciente(): void
    {
        $this->ticket(['bici_marca_modelo' => 'Orbea', 'categoria_bici' => 'ruta', 'talla_rin' => '26', 'es_electrica' => false, 'created_at' => now()->subDay()]);
        $this->ticket(['bici_marca_modelo' => 'orbea', 'categoria_bici' => 'otro', 'talla_rin' => '20', 'es_electrica' => true]);

        $s = $this->sugerencias('orbea')['sugerencias'];

        $this->assertCount(1, $s);
        $this->assertSame(['bici_marca_modelo' => 'orbea', 'categoria_bici' => 'otro', 'talla_rin' => '20', 'es_electrica' => true], $s[0]);
    }

    public function test_bicis_del_cliente_dos_distintas_cero_si_no_tiene_y_sin_eliminados(): void
    {
        $this->ticket(['bici_marca_modelo' => 'Trek', 'created_at' => now()->subDays(4)]);
        $this->ticket(['bici_marca_modelo' => 'TREK ', 'created_at' => now()->subDays(3)]);
        $this->ticket(['bici_marca_modelo' => 'Giant', 'created_at' => now()->subDays(2)]);
        $this->ticket(['bici_marca_modelo' => 'Cannondale'])->delete();
        $otro = Cliente::create(['nombre' => 'Sin Tickets', 'telefono' => '+584140000009']);

        $this->assertSame(['Giant', 'TREK'], array_column($this->sugerencias('', $this->cliente->id)['cliente'], 'bici_marca_modelo'));
        $this->assertSame([], $this->sugerencias('', $otro->id)['cliente']);
        $this->assertSame([], $this->sugerencias('cannon')['sugerencias'], 'ticket eliminado no sugiere');
    }

    public function test_bicis_exige_sesion(): void
    {
        $this->getJson('/taller/bicis?q=trek')->assertUnauthorized();
    }

    // ---- C1: cerrar un ticket sin ningun pago ni abono ----
    public function test_c1_un_ticket_en_proceso_sin_pago_se_puede_marcar_atendido_a_cuenta(): void
    {
        $t = $this->ticket(['trabajo_realizado' => 'Cambio de cámara']);
        $t->addMedia(UploadedFile::fake()->image('entrada.jpg', 600, 900))->toMediaCollection('entrada');
        $this->assertSame(0, MovimientoCuenta::count(), 'sin pagos ni abonos');

        $this->actingAs($this->tecnico)->patch("/taller/{$t->id}/marcar-atendido", ['pagado_en_taller' => false, 'texto_cliente' => 'sin_texto'])->assertRedirect();

        $t->refresh();
        $this->assertSame('atendido', $t->estado);
        $this->assertNotNull($t->atendido_en);
        $this->assertFalse((bool) $t->pagado_en_taller);
        $this->assertSame(1, MovimientoCuenta::where('tipo', 'cargo')->count(), 'queda A CUENTA: cargo al cliente, ningun pago');
    }
}
