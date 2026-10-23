<?php

namespace Tests\Feature;

use App\Models\Cuota;
use App\Models\MovimientoCuenta;
use App\Models\PlanFinanciamiento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\TablasCartera;
use Tests\TestCase;

// El total del plan sigue las lineas VIVAS de su compra: editar el monto recalcula solo las
// cuotas pendientes, anular la ultima linea anula el plan, y nada toca abonos
class PlanVinculoTest extends TestCase
{
    use TablasCartera;

    private User $usuario;

    private int $clienteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearTablasCartera();

        $this->usuario = User::create(['name' => 'Sistema Test', 'email' => 'test@kredix.local', 'password' => bcrypt('secreto')]);
        $this->clienteId = DB::table('clientes')->insertGetId(['nombre' => 'Cliente de prueba', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Compra financiada sin inicial. $productos = [[descripcion, cantidad, precio], ...] */
    private function financiar(array $productos, int $cuotas = 7, float $inicial = 0): PlanFinanciamiento
    {
        $this->actingAs($this->usuario)->post('/movimientos', [
            'tipo' => 'cargo',
            'cliente_id' => $this->clienteId,
            'fecha' => '2026-10-06',
            'productos' => collect($productos)->map(fn ($p) => ['descripcion' => $p[0], 'cantidad' => $p[1], 'precio_unitario' => $p[2]])->all(),
            'modalidad_precio' => 'divisa',
            'plazo_meses' => 3,
            'frecuencia_pago' => 'mensual',
            'es_financiada' => true,
            'monto_inicial' => $inicial,
            'numero_cuotas_financiamiento' => $cuotas,
            'porcentaje_mora' => 10,
            ...($inicial > 0 ? ['metodo_pago_inicial' => 'efectivo'] : []),
        ])->assertRedirect();

        return PlanFinanciamiento::firstOrFail();
    }

    private function editar(MovimientoCuenta $linea, array $cambios = [])
    {
        return $this->actingAs($this->usuario)->put("/movimientos/{$linea->id}", [
            'fecha' => '2026-10-06',
            'descripcion' => $linea->descripcion,
            'moneda' => 'usd',
            'cantidad' => $linea->cantidad,
            'precio_unitario' => $linea->precio_unitario,
            'modalidad_precio' => 'divisa',
            'plazo_meses' => 3,
            'frecuencia_pago' => 'mensual',
            'motivo_edicion' => 'ajuste de precio',
            ...$cambios,
        ]);
    }

    private function borrar(MovimientoCuenta $linea)
    {
        return $this->actingAs($this->usuario)->delete("/movimientos/{$linea->id}", ['motivo' => 'producto anulado']);
    }

    private function abonar(PlanFinanciamiento $plan, float $monto)
    {
        return $this->actingAs($this->usuario)->post('/movimientos', [
            'tipo' => 'abono', 'cliente_id' => $this->clienteId, 'fecha' => '2026-10-10', 'descripcion' => 'Pago',
            'monto' => $monto, 'metodo_pago' => 'efectivo', 'comentario' => 'pago de cuota', 'plan_financiamiento_id' => $plan->id,
        ]);
    }

    /** @return list<float> */
    private function montos(PlanFinanciamiento $plan): array
    {
        return $plan->cuotas()->get()->map(fn (Cuota $c) => (float) $c->monto_pactado)->all();
    }

    private function linea(string $descripcion): MovimientoCuenta
    {
        return MovimientoCuenta::where('descripcion', $descripcion)->firstOrFail();
    }

    // ---- A-1 ----
    public function test_7200_en_7_cuotas_y_al_editar_a_7100_se_recalculan_con_el_redondeo_existente(): void
    {
        $plan = $this->financiar([['RELOJ GARMIN', 1, 7200]]);
        $this->assertSame([1028.57, 1028.57, 1028.57, 1028.57, 1028.57, 1028.57, 1028.58], $this->montos($plan));

        $this->editar($this->linea('RELOJ GARMIN'), ['precio_unitario' => 7100])->assertRedirect();

        $this->assertSame([1014.28, 1014.28, 1014.28, 1014.28, 1014.28, 1014.28, 1014.32], $this->montos($plan->refresh()));
        $this->assertSame(7100.0, round(array_sum($this->montos($plan)), 2));
        $this->assertSame(7100.0, (float) $this->linea('RELOJ GARMIN')->monto);
    }

    // ---- A-2 ----
    public function test_con_una_cuota_pagada_las_pendientes_absorben_la_diferencia_y_la_pagada_no_cambia(): void
    {
        $plan = $this->financiar([['RELOJ GARMIN', 1, 7200]]);
        $this->abonar($plan, 1028.57)->assertRedirect();

        $this->editar($this->linea('RELOJ GARMIN'), ['precio_unitario' => 7100])->assertRedirect();

        $montos = $this->montos($plan->refresh());
        $this->assertSame(1028.57, $montos[0], 'la cuota pagada no cambia');
        $this->assertSame([1011.9, 1011.9, 1011.9, 1011.9, 1011.9, 1011.93], array_slice($montos, 1));
        $this->assertSame(7100.0, round(array_sum($montos), 2));
        $this->assertSame(1028.57, (float) $plan->cuotas()->first()->monto_abonado);
    }

    // ---- A-3 ----
    public function test_un_total_menor_a_lo_abonado_da_422_y_no_cambia_nada(): void
    {
        $plan = $this->financiar([['RELOJ GARMIN', 1, 7200]]);
        $this->abonar($plan, 3000)->assertRedirect();
        $antes = $this->montos($plan);

        $this->editar($this->linea('RELOJ GARMIN'), ['precio_unitario' => 2000])->assertStatus(422);

        $this->assertSame($antes, $this->montos($plan->refresh()));
        $this->assertSame(7200.0, (float) $this->linea('RELOJ GARMIN')->monto);
    }

    // ---- A-4: una prueba por vista ----
    private function borrarSinAbonos(): PlanFinanciamiento
    {
        $plan = $this->financiar([['RELOJ GARMIN', 1, 7200]]);
        $this->assertSame(7200.0, MovimientoCuenta::saldoPendiente($this->clienteId));

        $this->borrar($this->linea('RELOJ GARMIN'))->assertRedirect();

        return $plan;
    }

    public function test_borrar_el_producto_sin_abonos_anula_el_plan_y_sus_cuotas(): void
    {
        $plan = $this->borrarSinAbonos();

        $this->assertNull(PlanFinanciamiento::find($plan->id));
        $this->assertSoftDeleted('planes_financiamiento', ['id' => $plan->id]);
        $this->assertSame(0, Cuota::where('plan_financiamiento_id', $plan->id)->count());
    }

    public function test_vista_saldo_del_cliente_excluye_el_producto_anulado(): void
    {
        $this->borrarSinAbonos();

        $this->assertSame(0.0, MovimientoCuenta::saldoPendiente($this->clienteId));
    }

    public function test_vista_cartera_ya_no_cuenta_la_deuda_del_producto_anulado(): void
    {
        $this->borrarSinAbonos();

        $props = $this->actingAs($this->usuario)->get('/cartera')->assertOk()->viewData('page')['props'];

        $this->assertSame(0.0, (float) $props['totalCarteraActiva']);
        $this->assertSame(0, $props['clientesConSaldo']);
    }

    // ---- A-5 ----
    public function test_borrar_el_producto_con_abonos_se_bloquea_y_deja_cargo_y_plan_intactos(): void
    {
        $plan = $this->financiar([['RELOJ GARMIN', 1, 7200]]);
        $this->abonar($plan, 500)->assertRedirect();
        $antes = $this->montos($plan);

        $this->borrar($this->linea('RELOJ GARMIN'))->assertStatus(422);

        $this->assertNull($this->linea('RELOJ GARMIN')->deleted_at, 'el cargo sigue vivo');
        $this->assertNotNull(PlanFinanciamiento::find($plan->id));
        $this->assertSame($antes, $this->montos($plan));
        $this->assertSame(1, MovimientoCuenta::where('tipo', 'abono')->count(), 'el abono no se toca');
    }

    // ---- A-6 ----
    public function test_editar_solo_la_descripcion_no_toca_el_plan(): void
    {
        $plan = $this->financiar([['RELOJ GARMIN', 1, 7200]]);
        $antes = $this->montos($plan);

        $this->editar($this->linea('RELOJ GARMIN'), ['descripcion' => 'RELOJ GARMIN 965'])->assertRedirect();

        $this->assertSame($antes, $this->montos($plan->refresh()));
        $this->assertSame(0, Activity::where('description', 'like', 'plan_%')->count());
    }

    // ---- A-7 ----
    public function test_otros_cargos_y_la_mora_no_afectan_al_plan_al_editarse_o_borrarse(): void
    {
        $plan = $this->financiar([['RELOJ GARMIN', 1, 7200]]);
        $antes = $this->montos($plan);
        $suelto = MovimientoCuenta::create([
            'cliente_id' => $this->clienteId, 'fecha' => '2026-10-07', 'tipo' => 'cargo', 'descripcion' => 'CASCO SUELTO',
            'cantidad' => 1, 'precio_unitario' => 50, 'monto' => 50, 'registrado_por' => $this->usuario->id,
        ]);
        $mora = MovimientoCuenta::create([
            'cliente_id' => $this->clienteId, 'fecha' => '2026-10-07', 'tipo' => 'cargo', 'descripcion' => 'Mora (10%) - RELOJ GARMIN',
            'cantidad' => 1, 'precio_unitario' => 720, 'monto' => 720, 'registrado_por' => $this->usuario->id,
        ]);

        $this->editar($suelto, ['precio_unitario' => 80])->assertRedirect();
        $this->editar($mora, ['precio_unitario' => 100])->assertRedirect();
        $this->borrar($suelto)->assertRedirect();
        $this->borrar($mora)->assertRedirect();

        $this->assertSame($antes, $this->montos($plan->refresh()));
        $this->assertNotNull(PlanFinanciamiento::find($plan->id));
    }

    // ---- A-8 ----
    public function test_un_cargo_no_puede_tener_dos_planes_activos(): void
    {
        $plan = $this->financiar([['RELOJ GARMIN', 1, 7200]]);

        $this->expectException(ValidationException::class);
        PlanFinanciamiento::crearParaCargo($plan->cargo, ['monto_inicial' => 0, 'porcentaje_mora' => 10, 'frecuencia_pago' => 'mensual', 'numero_cuotas' => 2]);
    }

    public function test_un_plan_anulado_no_impide_crear_otro_para_el_mismo_cargo(): void
    {
        $plan = $this->financiar([['BICI RALI 29', 1, 650], ['PORTA BIDON FSA', 1, 20]], 2);
        $plan->cuotas()->delete();
        $plan->delete();

        $nuevo = PlanFinanciamiento::crearParaCargo($this->linea('BICI RALI 29'), ['monto_inicial' => 0, 'porcentaje_mora' => 10, 'frecuencia_pago' => 'mensual', 'numero_cuotas' => 2]);

        $this->assertNotSame($plan->id, $nuevo->id);
    }

    // ---- A-9 ----
    public function test_el_log_guarda_valores_anteriores_y_nuevos_y_el_usuario(): void
    {
        $plan = $this->financiar([['RELOJ GARMIN', 1, 7200]]);

        $this->editar($this->linea('RELOJ GARMIN'), ['precio_unitario' => 7100])->assertRedirect();

        $log = Activity::where('description', 'plan_recalculado')->firstOrFail();
        $this->assertSame($plan->id, $log->subject_id);
        $this->assertSame($this->usuario->id, $log->causer_id);
        $this->assertSame(7200.0, (float) $log->properties['antes']['total']);
        $this->assertSame(7100.0, (float) $log->properties['despues']['total']);
        $this->assertEquals(1028.57, $log->properties['antes']['cuotas'][1]);
        $this->assertEquals(1014.28, $log->properties['despues']['cuotas'][1]);
    }

    public function test_borrar_la_ultima_linea_viva_registra_la_anulacion_del_plan(): void
    {
        $plan = $this->financiar([['RELOJ GARMIN', 1, 7200]]);

        $this->borrar($this->linea('RELOJ GARMIN'))->assertRedirect();

        $log = Activity::where('description', 'plan_anulado')->firstOrFail();
        $this->assertSame($plan->id, $log->subject_id);
        $this->assertFalse($log->properties['antes']['anulado']);
        $this->assertTrue($log->properties['despues']['anulado']);
        $this->assertSame($this->usuario->id, $log->causer_id);
    }

    // ---- carrito de varias lineas ----
    public function test_anular_la_linea_ancla_re_ancla_el_plan_a_otra_viva_y_recalcula(): void
    {
        $plan = $this->financiar([['BICI RALI 29', 1, 650], ['PORTA BIDON FSA', 1, 20]], 2);
        $ancla = MovimientoCuenta::findOrFail($plan->movimiento_cuenta_id);
        $this->assertSame('BICI RALI 29', $ancla->descripcion);

        $this->borrar($ancla)->assertRedirect();

        $plan->refresh();
        $this->assertSame('PORTA BIDON FSA', $plan->cargo->descripcion);
        $this->assertSame([10.0, 10.0], $this->montos($plan));
        $log = Activity::where('description', 'plan_reanclado')->firstOrFail();
        $this->assertSame($ancla->id, $log->properties['antes']['movimiento_cuenta_id']);
        $this->assertSame($plan->movimiento_cuenta_id, $log->properties['despues']['movimiento_cuenta_id']);
    }

    public function test_editar_una_linea_de_un_carrito_cambia_el_total_del_plan(): void
    {
        $plan = $this->financiar([['BICI RALI 29', 1, 650], ['PORTA BIDON FSA', 1, 20]], 2);

        $this->editar($this->linea('PORTA BIDON FSA'), ['precio_unitario' => 30])->assertRedirect();

        $this->assertSame(680.0, $plan->refresh()->montoTotalCompra());
        $this->assertSame(680.0, round(array_sum($this->montos($plan)), 2));
    }

    public function test_los_cargos_de_mora_no_cuentan_como_lineas_del_carrito(): void
    {
        $plan = $this->financiar([['RELOJ GARMIN', 1, 7200]]);
        $this->actingAs($this->usuario)->post("/planes-financiamiento/{$plan->id}/aplicar-mora")->assertRedirect();

        $this->assertSame(7200.0, $plan->refresh()->montoTotalCompra());
    }
}
