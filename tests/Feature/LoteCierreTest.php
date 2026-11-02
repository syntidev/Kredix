<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\TicketTaller;
use App\Models\User;
use App\Services\Taller\AjustesTaller;
use App\Services\Taller\ComisionesTaller;
use App\Services\VentasService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

// Lote de cierre: presets de fecha, cotejo de comisiones con el caso real del VPS,
// detalle del KPI, porcentajes en Configuracion y orden de "Siguiente"
class LoteCierreTest extends TestCase
{
    private User $tecnico;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $migraciones = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => str_contains($p, 'replace_procesado_con_estado_en_prospectos_200k'))
            ->values()->all();
        Artisan::call('migrate', ['--path' => $migraciones, '--realpath' => true]);

        $this->tecnico = User::factory()->create(['name' => 'Carlos Rojas', 'rol_taller' => true]);
        $this->cliente = Cliente::create(['nombre' => 'Ana Perez', 'telefono' => '+584141234567']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ticket(?float $servicio, string $cuando, ?float $repuestos = null, array $extra = [], string $estado = 'atendido', bool $comisionable = true): TicketTaller
    {
        $t = TicketTaller::create([
            'tipo' => 'servicio_cliente', 'cliente_id' => $this->cliente->id, 'tipo_servicio' => 'basico',
            'monto_servicio' => $servicio, 'motivo_ingreso' => 'frena mal', 'bici_marca_modelo' => 'Trek',
            'categoria_bici' => 'mtb', 'talla_rin' => '29', 'es_electrica' => false, 'diagnostico' => [],
            'mecanico_id' => $this->tecnico->id, 'registrado_por' => $this->tecnico->id,
            'estado' => $estado, 'comisionable' => $comisionable,
        ]);
        $t->forceFill(['atendido_en' => $cuando, ...$extra])->save();
        if ($repuestos !== null) {
            $t->repuestos()->create(['producto' => 'Repuesto', 'cantidad' => 1, 'precio' => $repuestos]);
        }

        return $t;
    }

    // el caso real del VPS: semana 26/10-01/11, 8 tickets, sin cortesias ni tienda
    private function semanaReal(): void
    {
        foreach ([20, 15, 15, 15, 15, 10, 15, 15] as $i => $servicio) {
            $this->ticket($servicio, '2026-10-'.(26 + min($i, 5)).' 10:00:00', match ($i) { 1 => 30.0, 4 => 48.0, default => null });
        }
    }

    private function rango(string $preset, string $hoy): array
    {
        Carbon::setTestNow(Carbon::parse($hoy, 'America/Caracas'));
        [$desde, $hasta] = app(VentasService::class)->resolverRango($preset);

        return [$desde->toDateString(), $hasta->toDateString()];
    }

    public function test_semana_pasada_es_de_lunes_a_domingo_anterior(): void
    {
        $this->assertSame(['2026-10-26', '2026-11-01'], $this->rango('semana_pasada', '2026-11-02 09:00:00')); // lunes
        $this->assertSame(['2026-10-19', '2026-10-25'], $this->rango('semana_pasada', '2026-11-01 09:00:00')); // domingo
    }

    public function test_mes_anterior_es_el_mes_calendario_completo(): void
    {
        $this->assertSame(['2026-10-01', '2026-10-31'], $this->rango('mes_anterior', '2026-11-02 09:00:00'));
        $this->assertSame(['2026-12-01', '2026-12-31'], $this->rango('mes_anterior', '2027-01-05 09:00:00'));
        $this->assertSame(['2026-02-01', '2026-02-28'], $this->rango('mes_anterior', '2026-03-31 09:00:00')); // sin desbordar a marzo
    }

    public function test_los_presets_existentes_no_cambian(): void
    {
        $this->assertSame(['2026-10-27', '2026-11-02'], $this->rango('7_dias', '2026-11-02 09:00:00'));
        $this->assertSame(['2026-11-01', '2026-11-02'], $this->rango('mes', '2026-11-02 09:00:00'));
    }

    public function test_la_semana_real_da_60_mas_3_90_igual_63_90(): void
    {
        $this->semanaReal();

        $c = ComisionesTaller::calcular(Carbon::parse('2026-10-26'), Carbon::parse('2026-11-01'), 50, 5);
        $fila = $c['porMecanico'][0];

        $this->assertSame(8, $fila['tickets_atendidos']);
        $this->assertSame(120.0, $fila['servicio_base']);
        $this->assertSame(60.0, $fila['comision_servicio']);
        $this->assertSame(78.0, $fila['repuestos_base']);
        $this->assertSame(3.9, $fila['comision_repuestos']);
        $this->assertSame(63.9, $fila['total']);
    }

    public function test_el_kpi_en_pantalla_da_lo_mismo_con_el_preset_libre_y_el_detalle_suma_los_totales(): void
    {
        $this->semanaReal();
        $this->ticket(15, '2026-10-30 12:00:00', 7.0, ['atendido_en_aprox' => true]);          // aproximado
        $this->ticket(null, '2026-10-30 13:00:00', 5.0, [], 'atendido', false);              // tienda
        $this->ticket(0, '2026-10-30 14:00:00', 10.0);                                       // cortesia
        $admin = User::factory()->create(['es_admin' => true]);

        $props = null;
        $this->actingAs($admin)->get('/kpi?rango_taller=libre&desde_taller=2026-10-26&hasta_taller=2026-11-01')
            ->assertInertia(function ($p) use (&$props) {
                $props = $p->toArray()['props']['kpiTaller'];
            });

        $detalle = collect($props['detalle']);
        $this->assertCount(11, $detalle);
        $this->assertEqualsWithDelta($props['totales']['servicio_base'], $detalle->sum('servicio'), 0.001);
        $this->assertEqualsWithDelta($props['totales']['repuestos_base'], $detalle->sum('repuestos'), 0.001);
        $this->assertEquals(['TIENDA' => 1, 'CORTESIA' => 1, 'normal' => 9], $detalle->countBy('clase')->all());
        $this->assertSame(1, $detalle->where('aproximada', true)->count());
        $this->assertNull($detalle->firstWhere('clase', 'TIENDA')['servicio']);
        $this->assertSame(1, $props['tickets_fecha_aprox']);
    }

    public function test_cambiar_servicio_de_50_a_40_da_48_de_comision(): void
    {
        $this->semanaReal();
        $admin = User::factory()->create(['es_admin' => true]);

        $this->actingAs($admin)->put('/configuracion/taller', ['tecnico_defecto_id' => $this->tecnico->id, 'comision_servicio_pct' => 40, 'comision_repuestos_pct' => 5])
            ->assertSessionHasNoErrors();

        $fila = ComisionesTaller::calcular(Carbon::parse('2026-10-26'), Carbon::parse('2026-11-01'), AjustesTaller::comisionServicioPct(), AjustesTaller::comisionRepuestosPct())['porMecanico'][0];
        $this->assertSame(48.0, $fila['comision_servicio']);
        $this->assertSame('40', Configuracion::valorDe(AjustesTaller::CLAVE_COMISION_SERVICIO));
    }

    public function test_porcentajes_fuera_de_0_a_100_dan_422(): void
    {
        $admin = User::factory()->create(['es_admin' => true]);

        foreach ([['comision_servicio_pct' => 100.01, 'comision_repuestos_pct' => 5], ['comision_servicio_pct' => 50, 'comision_repuestos_pct' => -0.01]] as $datos) {
            $this->actingAs($admin)->putJson('/configuracion/taller', $datos)->assertStatus(422);
        }
    }

    public function test_siguiente_sigue_el_orden_del_listado_por_fecha_de_compromiso(): void
    {
        $a = $this->ticket(15, '2026-10-30 10:00:00', null, ['fecha_compromiso' => '2026-11-10'], 'en_proceso');
        $b = $this->ticket(15, '2026-10-31 10:00:00', null, ['fecha_compromiso' => '2026-11-05'], 'en_proceso');
        $c = $this->ticket(15, '2026-10-29 10:00:00', null, [], 'atendido');
        $siguiente = function (TicketTaller $t) {
            $id = 'x';
            $this->actingAs($this->tecnico)->get("/taller/{$t->id}")->assertInertia(function ($p) use (&$id) {
                $id = $p->toArray()['props']['siguienteTicketId'];
            });

            return $id;
        };

        // lista: B (compromiso 05/11), A (10/11), C (atendido)
        $this->assertSame($a->id, $siguiente($b));
        $this->assertSame($c->id, $siguiente($a));
        $this->assertNull($siguiente($c));
    }
}
