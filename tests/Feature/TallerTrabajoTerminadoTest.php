<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\TicketTaller;
use App\Models\User;
use App\Services\Taller\ComisionesTaller;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// Lote 7: "Trabajo terminado" sin cerrar el ticket; cuenta en la comision del tecnico en COALESCE(trabajado_en, atendido_en)
class TallerTrabajoTerminadoTest extends TestCase
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

        Storage::fake('public');
        config(['ia.activa' => false, 'taller.revision_tecnica_activa' => true]);
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->tecnico = User::factory()->create(['name' => 'Carlos Rojas', 'rol_taller' => true]);
        $this->cliente = Cliente::create(['nombre' => 'DATALINK', 'telefono' => '+584141234567']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ticket(float $servicio, ?float $repuestos, ?string $cierre, array $extra = []): TicketTaller
    {
        $t = TicketTaller::create([
            'tipo' => 'servicio_cliente', 'cliente_id' => $this->cliente->id, 'tipo_servicio' => 'basico', 'monto_servicio' => $servicio,
            'motivo_ingreso' => 'x', 'bici_marca_modelo' => 'Trek', 'categoria_bici' => 'mtb', 'talla_rin' => '29', 'es_electrica' => false,
            'diagnostico' => [], 'mecanico_id' => $this->tecnico->id, 'registrado_por' => $this->tecnico->id,
            'estado' => $cierre ? 'atendido' : 'en_proceso', 'comisionable' => true, ...$extra,
        ]);
        $t->forceFill(['created_at' => '2026-11-01 09:00:00', 'atendido_en' => $cierre])->save();
        if ($repuestos) {
            $t->repuestos()->create(['producto' => 'Pieza', 'cantidad' => 1, 'precio' => $repuestos]);
        }

        return $t;
    }

    // los 12 tickets atendidos de Carlos Rojas del 02/11 al 07/11 (servicio incl. armado / repuestos)
    private function docePorTecnico(): void
    {
        foreach ([
            [15, 0, '2026-11-02 17:13'], [15, 0, '2026-11-04 19:30'], [15, 15, '2026-11-05 13:57'], [15, 0, '2026-11-05 13:57'],
            [15, 37, '2026-11-04 18:38'], [0, 160, '2026-11-06 19:48'], [5, 0, '2026-11-05 18:34'], [15, 0, '2026-11-06 19:50'],
            [15, 90, '2026-11-07 18:27'], [15, 45, '2026-11-07 18:30'], [15, 15, '2026-11-04 19:31'],
        ] as [$servicio, $repuestos, $cierre]) {
            $this->ticket($servicio, $repuestos, $cierre);
        }
        $this->ticket(15, 0, '2026-11-05 15:57', ['incluye_armado' => true, 'monto_armado' => 10]); // #57: 15 + armado 10
    }

    private function kpi(string $desde, string $hasta): array
    {
        return ComisionesTaller::calcular(Carbon::parse($desde), Carbon::parse($hasta), 50, 5);
    }

    private function marcar(TicketTaller $t, ?string $fecha)
    {
        return $this->actingAs($this->tecnico)->patch("/taller/{$t->id}/trabajo-terminado", $fecha === null ? [] : ['fecha_trabajo' => $fecha]);
    }

    // ---- criterios 2 a 5: la regla de conteo ----
    public function test_el_caso_del_59_100_60_sin_marcar_y_108_10_marcado_con_contador_1(): void
    {
        $this->docePorTecnico();
        $t59 = $this->ticket(15, 0, null); // En proceso

        $sin = $this->kpi('2026-11-02', '2026-11-07');
        $this->assertSame(100.6, $sin['totales']['total']);
        $this->assertSame(165.0, $sin['totales']['servicio_base']);
        $this->assertSame(362.0, $sin['totales']['repuestos_base']);
        $this->assertSame(0, $sin['trabajados_sin_cerrar'], 'un En proceso sin marcar no cuenta');

        $this->marcar($t59, '2026-11-05')->assertRedirect();

        $con = $this->kpi('2026-11-02', '2026-11-07');
        $this->assertSame(108.1, $con['totales']['total']);
        $this->assertSame(180.0, $con['totales']['servicio_base']);
        $this->assertSame(90.0, $con['totales']['comision_servicio']);
        $this->assertSame(18.1, $con['totales']['comision_repuestos']);
        $this->assertSame(1, $con['trabajados_sin_cerrar']);
        $this->assertSame('en_proceso', $t59->fresh()->estado);
    }

    public function test_cerrar_el_59_el_09_11_no_lo_cuenta_de_nuevo_ni_mueve_su_fecha(): void
    {
        $this->docePorTecnico();
        $t59 = $this->ticket(15, 0, null, ['trabajo_realizado' => 'ok']);
        $t59->addMedia(UploadedFile::fake()->image('e.jpg', 600, 900))->toMediaCollection('entrada');
        $this->marcar($t59, '2026-11-05')->assertRedirect();

        Carbon::setTestNow('2026-11-09 11:00:00');
        $this->actingAs($this->tecnico)->patch("/taller/{$t59->id}/marcar-atendido", ['pagado_en_taller' => false, 'texto_cliente' => 'sin_texto', 'fecha_trabajo' => '2026-11-08'])->assertRedirect();

        $t59->refresh();
        $this->assertSame('atendido', $t59->estado);
        $this->assertSame('2026-11-05 18:00:00', $t59->trabajado_en->toDateTimeString(), 'trabajado_en intacto');
        $this->assertSame('2026-11-09 11:00:00', $t59->atendido_en->toDateTimeString(), 'el cierre ignora la fecha del lote 6');
        $this->assertSame(0, Activity::where('description', 'atendido_en_ajustado')->count());

        $this->assertSame(108.1, $this->kpi('2026-11-02', '2026-11-07')['totales']['total'], 'sigue en su semana');
        $semanaDelCierre = $this->kpi('2026-11-09', '2026-11-14');
        $this->assertCount(0, $semanaDelCierre['porMecanico'], 'no cuenta en la semana del cierre');
        $this->assertSame(0, $semanaDelCierre['trabajados_sin_cerrar']);
    }

    public function test_desmarcar_vuelve_a_100_60_y_el_eliminado_no_cuenta_aunque_este_marcado(): void
    {
        $this->docePorTecnico();
        $t59 = $this->ticket(15, 0, null);
        $this->marcar($t59, '2026-11-05')->assertRedirect();
        $this->marcar($t59, null)->assertRedirect();

        $this->assertNull($t59->fresh()->trabajado_en);
        $this->assertSame(100.6, $this->kpi('2026-11-02', '2026-11-07')['totales']['total']);

        $this->marcar($t59, '2026-11-05');
        $t59->delete();
        $this->assertSame(100.6, $this->kpi('2026-11-02', '2026-11-07')['totales']['total']);
    }

    public function test_fecha_futura_anterior_a_la_creacion_y_ticket_atendido_dan_422(): void
    {
        $t = $this->ticket(15, 0, null);

        $this->marcar($t, '2026-11-11')->assertSessionHasErrors('fecha_trabajo');
        $this->marcar($t, '2026-10-31')->assertSessionHasErrors('fecha_trabajo');
        $this->assertNull($t->fresh()->trabajado_en);

        $cerrado = $this->ticket(15, 0, '2026-11-03 10:00');
        $this->marcar($cerrado, '2026-11-03')->assertStatus(422);
    }

    // ---- criterio 6: no cambia nada mas, y deja huella ----
    public function test_marcar_y_desmarcar_dejan_evento_y_no_tocan_estado_montos_ni_cuenta(): void
    {
        $t = $this->ticket(15, 20, null);
        $campos = ['estado', 'monto_servicio', 'monto_armado', 'movimiento_cuenta_id', 'pagado_en_taller'];
        $antes = $t->fresh()->only($campos);

        $this->marcar($t, '2026-11-05')->assertRedirect();
        $this->marcar($t, '2026-11-06')->assertRedirect(); // cambio de fecha
        $this->marcar($t, null)->assertRedirect();

        $this->assertEquals($antes, $t->fresh()->only($campos));
        $this->assertSame(0, MovimientoCuenta::count(), 'sin cargo ni movimientos');

        $marcados = Activity::where('description', 'trabajo_terminado_marcado')->orderBy('id')->get();
        $this->assertCount(2, $marcados);
        $this->assertNull($marcados[0]->properties['anterior']);
        $this->assertSame('2026-11-05 18:00:00', $marcados[0]->properties['nuevo']);
        $this->assertSame('2026-11-05 18:00:00', $marcados[1]->properties['anterior']);
        $this->assertSame($this->tecnico->id, (int) $marcados[0]->causer_id);
        $des = Activity::where('description', 'trabajo_terminado_desmarcado')->firstOrFail();
        $this->assertSame('2026-11-06 18:00:00', $des->properties['anterior']);
        $this->assertNull($des->properties['nuevo']);
    }

    public function test_marcar_hoy_usa_la_hora_actual(): void
    {
        $t = $this->ticket(15, 0, null);
        $this->marcar($t, '2026-11-10')->assertRedirect();
        $this->assertSame('2026-11-10 10:00:00', $t->fresh()->trabajado_en->toDateTimeString());
    }

    // ---- criterio 7: el detalle suma lo mismo que los totales ----
    public function test_el_detalle_suma_igual_que_los_totales_e_incluye_la_marca_sin_cerrar(): void
    {
        $this->docePorTecnico();
        $t59 = $this->ticket(15, 0, null);
        $this->marcar($t59, '2026-11-05');

        $kpi = $this->kpi('2026-11-02', '2026-11-07');
        $detalle = collect($kpi['detalle']);

        $this->assertEqualsWithDelta($kpi['totales']['servicio_base'], $detalle->sum('servicio'), 0.001);
        $this->assertEqualsWithDelta($kpi['totales']['repuestos_base'], $detalle->sum('repuestos'), 0.001);
        $this->assertSame(13, $detalle->count());
        $fila = $detalle->firstWhere('id', $t59->id);
        $this->assertTrue($fila['sin_cerrar']);
        $this->assertSame('2026-11-05', $fila['fecha']);
        $this->assertSame(1, $detalle->where('sin_cerrar', true)->count());
    }

    // ---- fichas y lista ----
    public function test_la_ficha_y_la_lista_traen_trabajado_en_solo_para_en_proceso(): void
    {
        $t = $this->ticket(15, 0, null);
        $this->marcar($t, '2026-11-05');

        $this->actingAs($this->tecnico)->get("/taller/{$t->id}")->assertInertia(fn ($p) => $p->where('ticket.trabajado_en', '2026-11-05'));
        $lista = null;
        $this->actingAs($this->tecnico)->get('/taller')->assertInertia(function ($p) use (&$lista, $t) {
            $lista = collect($p->toArray()['props']['tickets']['data'])->firstWhere('id', $t->id);
        });
        $this->assertSame('2026-11-05', $lista['trabajado_en']);
    }
}
