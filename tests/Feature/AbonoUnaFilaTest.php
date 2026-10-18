<?php

namespace Tests\Feature;

use App\Models\Cuota;
use App\Models\MovimientoCuenta;
use App\Models\PlanFinanciamiento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasCartera;
use Tests\TestCase;

class AbonoUnaFilaTest extends TestCase
{
    use TablasCartera;

    private User $usuario;

    private int $clienteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearTablasCartera();

        $this->usuario = User::create([
            'name' => 'Sistema Test',
            'email' => 'test@kredix.local',
            'password' => bcrypt('secreto'),
        ]);

        $this->clienteId = DB::table('clientes')->insertGetId([
            'nombre' => 'Cliente de prueba',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Plan con una cuota por cada monto pactado de $pactados. */
    private function plan(array $pactados): PlanFinanciamiento
    {
        $cargo = MovimientoCuenta::create([
            'cliente_id' => $this->clienteId,
            'fecha' => '2026-10-01',
            'tipo' => 'cargo',
            'descripcion' => 'Bici',
            'cantidad' => 1,
            'precio_unitario' => array_sum($pactados),
            'monto' => array_sum($pactados),
            'registrado_por' => $this->usuario->id,
        ]);

        $plan = PlanFinanciamiento::create([
            'movimiento_cuenta_id' => $cargo->id,
            'monto_inicial' => 0,
            'porcentaje_mora' => 10,
            'frecuencia_pago' => 'mensual',
            'numero_cuotas' => count($pactados),
        ]);

        foreach ($pactados as $i => $monto) {
            $plan->cuotas()->create([
                'numero' => $i + 1,
                'monto_pactado' => $monto,
                'fecha_vencimiento' => '2026-11-01',
            ]);
        }

        return $plan;
    }

    private function abonar(PlanFinanciamiento $plan, float $monto)
    {
        return $this->actingAs($this->usuario)->post('/movimientos', [
            'tipo' => 'abono',
            'cliente_id' => $this->clienteId,
            'fecha' => '2026-10-10',
            'descripcion' => 'Pago',
            'monto' => $monto,
            'metodo_pago' => 'efectivo',
            'comentario' => 'pago de cuota',
            'plan_financiamiento_id' => $plan->id,
        ]);
    }

    /** @return array<int, float> monto_abonado de cada cuota, en orden */
    private function abonado(PlanFinanciamiento $plan): array
    {
        return $plan->cuotas()->get()->map(fn (Cuota $c) => (float) $c->monto_abonado)->all();
    }

    public function test_un_pago_es_una_fila_con_el_monto_completo_y_la_cascada_reparte(): void
    {
        $plan = $this->plan([30, 40]);

        $this->abonar($plan, 100)->assertRedirect();

        $filas = MovimientoCuenta::where('tipo', 'abono')->get();
        $this->assertCount(1, $filas);
        $this->assertSame(100.0, (float) $filas[0]->monto);
        $this->assertSame($plan->id, $filas[0]->plan_financiamiento_id);
        $this->assertNull($filas[0]->cuota_id);
        $this->assertSame($this->usuario->id, (int) $filas[0]->registrado_por);
        // cuota1=30, cuota2=40, el sobrante (30) queda dentro de la misma fila
        $this->assertSame([30.0, 40.0], $this->abonado($plan));
    }

    public function test_anular_el_abono_libera_las_cuotas(): void
    {
        $plan = $this->plan([30, 40]);
        $this->abonar($plan, 100);
        $fila = MovimientoCuenta::where('tipo', 'abono')->firstOrFail();

        $this->actingAs($this->usuario)->delete("/movimientos/{$fila->id}", ['motivo' => 'error'])->assertRedirect();

        $this->assertSame([0.0, 0.0], $this->abonado($plan));
    }

    public function test_editar_el_monto_del_abono_reparte_de_nuevo(): void
    {
        $plan = $this->plan([30, 40]);
        $this->abonar($plan, 100);
        $fila = MovimientoCuenta::where('tipo', 'abono')->firstOrFail();

        $this->actingAs($this->usuario)->put("/movimientos/{$fila->id}", [
            'fecha' => '2026-10-10',
            'descripcion' => 'Pago',
            'moneda' => 'usd',
            'monto' => 50,
            'metodo_pago' => 'efectivo',
            'comentario' => 'pago de cuota',
            'motivo_edicion' => 'monto mal digitado',
        ])->assertRedirect();

        $this->assertSame([30.0, 20.0], $this->abonado($plan));
    }

    public function test_filas_viejas_partidas_dan_el_mismo_reparto_y_no_se_cuentan_dos_veces(): void
    {
        // caso real local: 500 cobrados repartidos por cuota (una cuota con dos filas)
        $plan = $this->plan([186.33, 186.33, 186.34]);
        [$c1, $c2, $c3] = $plan->cuotas()->pluck('id')->all();

        foreach ([[$c1, 186.33], [$c2, 13.67], [$c2, 172.66], [$c3, 127.34]] as [$cuotaId, $monto]) {
            MovimientoCuenta::create([
                'cliente_id' => $this->clienteId, 'fecha' => '2026-10-02', 'tipo' => 'abono',
                'monto' => $monto, 'cuota_id' => $cuotaId, 'registrado_por' => $this->usuario->id,
            ]);
        }
        // fila con plan Y cuota a la vez: debe contar una sola vez
        MovimientoCuenta::create([
            'cliente_id' => $this->clienteId, 'fecha' => '2026-10-03', 'tipo' => 'abono',
            'monto' => 10, 'cuota_id' => $c3, 'plan_financiamiento_id' => $plan->id,
            'registrado_por' => $this->usuario->id,
        ]);
        // sobrante viejo sin plan ni cuota: no pertenece a la cascada
        MovimientoCuenta::create([
            'cliente_id' => $this->clienteId, 'fecha' => '2026-10-03', 'tipo' => 'abono',
            'monto' => 999, 'registrado_por' => $this->usuario->id,
        ]);

        $plan->recalcularCascada();

        $this->assertSame([186.33, 186.33, 137.34], $this->abonado($plan));
    }

    public function test_la_inicial_guarda_plan_tasa_y_es_inicial_y_no_entra_a_la_cascada(): void
    {
        $this->actingAs($this->usuario)->post('/movimientos', [
            'tipo' => 'cargo',
            'cliente_id' => $this->clienteId,
            'fecha' => '2026-10-06',
            'productos' => [['descripcion' => 'BICI RALI 29', 'cantidad' => 1, 'precio_unitario' => 600]],
            'modalidad_precio' => 'bcv',
            'tasa_cambio' => 872.3927,
            'plazo_meses' => 3,
            'frecuencia_pago' => 'mensual',
            'es_financiada' => true,
            'monto_inicial' => 100,
            'metodo_pago_inicial' => 'efectivo',
            'numero_cuotas_financiamiento' => 5,
            'porcentaje_mora' => 10,
        ])->assertRedirect();

        $plan = PlanFinanciamiento::firstOrFail();
        $inicial = MovimientoCuenta::where('tipo', 'abono')->firstOrFail();

        $this->assertTrue($inicial->es_inicial);
        $this->assertSame($plan->id, $inicial->plan_financiamiento_id);
        $this->assertSame(872.3927, (float) $inicial->tasa_cambio);

        $plan->recalcularCascada();
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0], $this->abonado($plan));
    }

    public function test_el_segundo_registro_del_mismo_pago_a_plan_se_detecta_como_duplicado(): void
    {
        $plan = $this->plan([30, 40]);
        $this->abonar($plan, 100);

        $this->actingAs($this->usuario)
            ->postJson('/movimientos/verificar-duplicado-abono', ['cliente_id' => $this->clienteId, 'monto' => 100])
            ->assertJsonPath('duplicado.monto', 100);
    }
}
