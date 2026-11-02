<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\TicketTaller;
use App\Models\User;
use App\Services\Taller\AjustesTaller;
use App\Services\Taller\ComisionesTaller;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Fase B: comisiones del tecnico en el KPI de Taller
class TallerComisionesTest extends TestCase
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

    // ticket atendido en $cuando; $servicio null = sin monto; $repuestos = precio de un repuesto
    private function atendido(?float $servicio, bool $comisionable = true, ?float $repuestos = null, string $cuando = '2026-10-10 12:00:00', array $extra = []): TicketTaller
    {
        $t = TicketTaller::create([
            'tipo' => 'servicio_cliente',
            'cliente_id' => $this->cliente->id,
            'tipo_servicio' => 'basico',
            'monto_servicio' => $servicio,
            'motivo_ingreso' => 'frena mal',
            'bici_marca_modelo' => 'Trek Marlin',
            'categoria_bici' => 'mtb',
            'talla_rin' => '29',
            'es_electrica' => false,
            'diagnostico' => [],
            'mecanico_id' => $this->tecnico->id,
            'registrado_por' => $this->tecnico->id,
            'estado' => 'atendido',
            'comisionable' => $comisionable,
        ]);
        $t->forceFill(['atendido_en' => $cuando, ...$extra])->save();
        if ($repuestos !== null) {
            $t->repuestos()->create(['producto' => 'Cadena', 'cantidad' => 1, 'precio' => $repuestos]);
        }

        return $t;
    }

    private function calcular(string $desde = '2026-10-01', string $hasta = '2026-10-31', float $pctServicio = 50, float $pctRepuestos = 5): array
    {
        return ComisionesTaller::calcular(Carbon::parse($desde), Carbon::parse($hasta), $pctServicio, $pctRepuestos);
    }

    public function test_diez_tickets_ocho_comisionables_a_15_y_dos_de_la_tienda(): void
    {
        foreach (range(1, 8) as $i) {
            $this->atendido(15);
        }
        $this->atendido(null, false);
        $this->atendido(null, false);

        $fila = $this->calcular()['porMecanico'][0];

        $this->assertSame(120.0, $fila['servicio_base']);
        $this->assertSame(60.0, $fila['comision_servicio']);
        $this->assertSame(10, $fila['tickets_atendidos']);
        $this->assertSame(8, $fila['con_cobro']);
        $this->assertSame(2, $fila['de_la_tienda']);
    }

    public function test_cortesia_comisiona_repuestos_y_no_servicio(): void
    {
        $this->atendido(0, true, 40);

        $fila = $this->calcular()['porMecanico'][0];

        $this->assertSame(0.0, $fila['comision_servicio']);
        $this->assertSame(2.0, $fila['comision_repuestos']);
        $this->assertSame(1, $fila['cortesias']);
        $this->assertSame(0, $fila['con_cobro']);
    }

    public function test_ticket_no_comisionable_no_suma_a_la_base_y_cuenta_como_de_la_tienda(): void
    {
        $this->atendido(15, false, 100);

        $fila = $this->calcular()['porMecanico'][0];

        $this->assertSame(0.0, $fila['servicio_base']);
        $this->assertSame(0.0, $fila['repuestos_base']);
        $this->assertSame(0.0, $fila['total']);
        $this->assertSame(1, $fila['de_la_tienda']);
    }

    public function test_cambiar_los_porcentajes_cambia_el_resultado(): void
    {
        $this->atendido(100, true, 100);

        $this->assertSame(55.0, $this->calcular()['porMecanico'][0]['total']);
        $this->assertSame(40.0, $this->calcular('2026-10-01', '2026-10-31', 30, 10)['porMecanico'][0]['total']);
    }

    public function test_los_porcentajes_salen_de_configuracion_con_defecto_50_y_5(): void
    {
        $this->assertSame(50.0, AjustesTaller::comisionServicioPct());
        $this->assertSame(5.0, AjustesTaller::comisionRepuestosPct());

        Configuracion::updateOrCreate(['clave' => AjustesTaller::CLAVE_COMISION_SERVICIO], ['valor' => '40']);

        $this->assertSame(40.0, AjustesTaller::comisionServicioPct());
    }

    public function test_borde_de_rango_por_atendido_en(): void
    {
        $this->atendido(10, true, null, '2026-10-10 00:00:00');   // dentro: primer segundo del dia
        $this->atendido(10, true, null, '2026-10-10 23:59:59');   // dentro: ultimo segundo
        $this->atendido(10, true, null, '2026-10-11 00:00:00');   // fuera
        $this->atendido(10, true, null, '2026-10-09 23:59:59');   // fuera

        $this->assertSame(2, $this->calcular('2026-10-10', '2026-10-10')['totales']['tickets_atendidos']);
    }

    public function test_avisa_cuando_hay_tickets_con_fecha_aproximada(): void
    {
        $this->atendido(10);
        $this->atendido(10, true, null, '2026-10-10 12:00:00', ['atendido_en_aprox' => true]);

        $this->assertSame(1, $this->calcular()['tickets_fecha_aprox']);
    }

    public function test_redondea_una_sola_vez_al_final_por_tecnico(): void
    {
        // 3 x $0.01 al 50%: por ticket 0.005 -> 0.01 (suma 0.03); agregado 0.015 -> 0.02
        foreach (range(1, 3) as $i) {
            $this->atendido(0.01);
        }

        $this->assertSame(0.02, $this->calcular()['porMecanico'][0]['comision_servicio']);
    }

    public function test_el_kpi_en_pantalla_coincide_con_el_calculo_y_es_solo_admin(): void
    {
        $this->atendido(100, true, 100, now()->toDateTimeString());
        $admin = User::factory()->create(['es_admin' => true]);

        $this->actingAs($this->tecnico)->get('/kpi')->assertForbidden();

        $this->actingAs($admin)->get('/kpi?rango_taller=mes')
            ->assertInertia(fn ($p) => $p
                ->where('kpiTaller.rango', 'mes')
                ->where('kpiTaller.totales.total', 55)
                ->where('kpiTaller.porMecanico.0.mecanico', 'Carlos Rojas'));
    }

    public function test_rango_libre_invalido_cae_al_mes_con_mensaje(): void
    {
        $admin = User::factory()->create(['es_admin' => true]);

        $this->actingAs($admin)->get('/kpi?rango_taller=libre&desde_taller=2026-10-10&hasta_taller=2026-10-01')
            ->assertInertia(fn ($p) => $p->where('kpiTaller.rango', 'mes')->whereNot('kpiTaller.error', null));
    }

    public function test_nuevo_ticket_guarda_el_marcador_comisionable(): void
    {
        Storage::fake('public');
        config(['ia.activa' => false]);

        $this->actingAs($this->tecnico)->post('/taller', [
            'motivo_ingreso' => 'armar', 'bici_marca_modelo' => 'GW', 'categoria_bici' => 'mtb',
            'mecanico_id' => $this->tecnico->id, 'tipo_servicio' => 'basico', 'monto_servicio' => 15,
            'cliente_id' => $this->cliente->id, 'comisionable' => false,
            'fotos_entrada' => [UploadedFile::fake()->image('e.jpg', 600, 900)],
        ])->assertSessionHasNoErrors();

        $this->assertFalse(TicketTaller::latest('id')->first()->comisionable);
    }
}
