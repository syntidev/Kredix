<?php

namespace Tests\Feature;

use App\Models\Cuota;
use App\Models\MovimientoCuenta;
use App\Models\PlanFinanciamiento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasCartera;
use Tests\TestCase;

class CompraFinanciadaTest extends TestCase
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

    /** Payload de Nueva compra. $productos = [[descripcion, cantidad, precio], ...] */
    private function compra(array $productos, array $extra = []): array
    {
        return [
            'tipo' => 'cargo',
            'cliente_id' => $this->clienteId,
            'fecha' => '2026-10-06',
            'productos' => collect($productos)->map(fn ($p) => [
                'descripcion' => $p[0],
                'cantidad' => $p[1],
                'precio_unitario' => $p[2],
            ])->all(),
            'modalidad_precio' => 'divisa',
            'plazo_meses' => 3,
            'frecuencia_pago' => 'mensual',
            ...$extra,
        ];
    }

    private function financiada(array $productos, float $inicial, int $cuotas, array $extra = []): array
    {
        return $this->compra($productos, [
            'es_financiada' => true,
            'monto_inicial' => $inicial,
            'numero_cuotas_financiamiento' => $cuotas,
            'porcentaje_mora' => 10,
            ...($inicial > 0 ? ['metodo_pago_inicial' => 'efectivo'] : []),
            ...$extra,
        ]);
    }

    private function postCompra(array $datos)
    {
        return $this->actingAs($this->usuario)->post('/movimientos', $datos);
    }

    private function saldoDelCliente(): float
    {
        return (float) MovimientoCuenta::where('cliente_id', $this->clienteId)
            ->get()
            ->sum(fn (MovimientoCuenta $m) => match ($m->tipo) {
                'cargo' => (float) $m->monto,
                'abono', 'ajuste_devolucion' => -(float) $m->monto,
                default => 0,
            });
    }

    private function sumaCuotas(PlanFinanciamiento $plan): float
    {
        return round($plan->cuotas->sum(fn (Cuota $c) => (float) $c->monto_pactado), 2);
    }

    private function moraRegistrada(): float
    {
        return (float) MovimientoCuenta::where('descripcion', 'like', 'Mora%')->sum('monto');
    }

    // 1
    public function test_un_producto_financiado_se_comporta_como_antes(): void
    {
        $this->postCompra($this->financiada([['BICI RALI 29', 1, 600]], 100, 5))->assertRedirect();

        $plan = PlanFinanciamiento::with('cuotas')->firstOrFail();

        $this->assertSame(600.0, $plan->montoTotalCompra());
        $this->assertSame(500.0, $this->sumaCuotas($plan));
        $this->assertCount(5, $plan->cuotas);
        // la inicial queda como abono normal, sin cuota_id
        $this->assertSame(100.0, (float) MovimientoCuenta::where('tipo', 'abono')->whereNull('cuota_id')->sum('monto'));
    }

    // 2
    public function test_dos_productos_financiados_el_credito_es_la_suma_de_ambos(): void
    {
        $this->postCompra($this->financiada([
            ['BICICLETA GIANT PROPEL', 1, 3000],
            ['PEDALES LOOK KEO', 1, 85],
        ], 0, 3))->assertRedirect();

        $plan = PlanFinanciamiento::with('cuotas')->firstOrFail();

        $this->assertSame(3085.0, $plan->montoTotalCompra());
        $this->assertSame(3085.0, $this->sumaCuotas($plan));
        // dos cargos, un solo plan, colgado del primero
        $this->assertSame(2, MovimientoCuenta::where('tipo', 'cargo')->count());
        $this->assertSame(1, PlanFinanciamiento::count());
        $this->assertSame(
            MovimientoCuenta::where('tipo', 'cargo')->orderBy('id')->first()->id,
            $plan->movimiento_cuenta_id
        );
        // las dos lineas comparten el mismo compra_id
        $this->assertSame(1, MovimientoCuenta::where('tipo', 'cargo')->distinct()->count('compra_id'));
    }

    // 3
    public function test_tres_productos_con_inicial_financia_total_menos_inicial(): void
    {
        $this->postCompra($this->financiada([
            ['CLOUDMONSTER 3 HYPER', 1, 265],
            ['CLOUDSURFER 2', 1, 195],
            ['CLOUDSURFER 2', 1, 230],
        ], 190, 4))->assertRedirect();

        $plan = PlanFinanciamiento::with('cuotas')->firstOrFail();

        $this->assertSame(690.0, $plan->montoTotalCompra());
        $this->assertSame(500.0, $this->sumaCuotas($plan));
        $this->assertSame(190.0, (float) $plan->monto_inicial);
    }

    // 4
    public function test_dos_productos_en_bcv_guardan_la_tasa_de_esa_transaccion(): void
    {
        $this->postCompra($this->financiada([
            ['Cauchos Vittoria', 2, 100],
            ['Cinta de volante', 1, 40],
        ], 0, 2, ['modalidad_precio' => 'bcv', 'tasa_cambio' => 872.3927]))->assertRedirect();

        $cargos = MovimientoCuenta::where('tipo', 'cargo')->get();

        $this->assertCount(2, $cargos);
        // la tasa es por transaccion: queda en cada linea del mismo envio, sin tasa global
        foreach ($cargos as $cargo) {
            $this->assertSame('bcv', $cargo->modalidad_precio);
            $this->assertSame(872.3927, (float) $cargo->tasa_cambio);
        }
        $this->assertSame(240.0, PlanFinanciamiento::firstOrFail()->montoTotalCompra());
    }

    // 5
    public function test_dos_productos_sin_financiar_no_crean_plan(): void
    {
        $this->postCompra($this->compra([
            ['GARMIN EDGE 1050', 1, 720],
            ['MEDIAS KOM', 2, 10],
        ]))->assertRedirect();

        $this->assertSame(0, PlanFinanciamiento::count());
        $this->assertSame(0, Cuota::count());
        $this->assertSame(2, MovimientoCuenta::where('tipo', 'cargo')->count());
        $this->assertSame(740.0, $this->saldoDelCliente());
    }

    // 6
    public function test_plan_con_compra_id_nulo_se_comporta_identico_a_main(): void
    {
        // compra anterior al hotfix: sin compra_id, y con otra linea suelta del
        // mismo cliente y la misma fecha que NO debe entrar en el total del plan
        $cargo = MovimientoCuenta::create([
            'cliente_id' => $this->clienteId,
            'fecha' => '2026-09-01',
            'tipo' => 'cargo',
            'descripcion' => 'Compra vieja',
            'cantidad' => 1,
            'precio_unitario' => 400,
            'monto' => 400,
            'registrado_por' => $this->usuario->id,
        ]);
        MovimientoCuenta::create([
            'cliente_id' => $this->clienteId,
            'fecha' => '2026-09-01',
            'tipo' => 'cargo',
            'descripcion' => 'Otra compra del mismo dia',
            'cantidad' => 1,
            'precio_unitario' => 999,
            'monto' => 999,
            'registrado_por' => $this->usuario->id,
        ]);

        $plan = PlanFinanciamiento::create([
            'movimiento_cuenta_id' => $cargo->id,
            'monto_inicial' => 0,
            'porcentaje_mora' => 10,
            'frecuencia_pago' => 'mensual',
            'numero_cuotas' => 2,
        ]);

        $this->assertNull($cargo->compra_id);
        $this->assertSame(400.0, $plan->montoTotalCompra());

        $this->actingAs($this->usuario)->post("/planes-financiamiento/{$plan->id}/aplicar-mora")->assertRedirect();

        // mora sobre el cargo, 10% de 400 -- igual que antes del hotfix
        $this->assertSame(40.0, $this->moraRegistrada());
    }

    // 7
    public function test_suma_de_cuotas_igual_a_total_menos_inicial(): void
    {
        $this->postCompra($this->financiada([
            ['GARMIN FENIX 8 PRO', 1, 1300],
            ['SALMONES CAFECONBIKE', 1, 84],
        ], 384, 6))->assertRedirect();

        $plan = PlanFinanciamiento::with('cuotas')->firstOrFail();

        $this->assertSame(1384.0, $plan->montoTotalCompra());
        $this->assertSame(1000.0, $this->sumaCuotas($plan));
        $this->assertSame(round($plan->montoTotalCompra() - (float) $plan->monto_inicial, 2), $this->sumaCuotas($plan));
    }

    // 8
    public function test_mora_se_calcula_sobre_el_total_del_grupo(): void
    {
        $this->postCompra($this->financiada([
            ['BICICLETA FORZA F-20', 1, 1000],
            ['LUZ TRASERA ZEFAL', 1, 500],
        ], 0, 2))->assertRedirect();

        $plan = PlanFinanciamiento::firstOrFail();
        $this->actingAs($this->usuario)->post("/planes-financiamiento/{$plan->id}/aplicar-mora")->assertRedirect();

        // 10% de 1500 (total del carrito), NO 10% de 1000 (primer cargo)
        $this->assertSame(150.0, $this->moraRegistrada());
    }

    // 9
    public function test_saldo_del_cliente_es_suma_de_cargos_menos_abonos(): void
    {
        $this->postCompra($this->financiada([
            ['PINARELLO X3', 1, 4000],
            ['GARMIN EDGE 1050', 1, 720],
            ['KIT BOLSO KOM', 1, 30],
        ], 750, 5))->assertRedirect();

        // 4750 de cargos - 750 de inicial
        $this->assertSame(4000.0, $this->saldoDelCliente());

        $cargos = (float) MovimientoCuenta::where('tipo', 'cargo')->sum('monto');
        $abonos = (float) MovimientoCuenta::where('tipo', 'abono')->sum('monto');
        $this->assertSame(round($cargos - $abonos, 2), $this->saldoDelCliente());
        // las cuotas son agenda, no movimientos: no entran al saldo
        $this->assertSame(4000.0, round((float) Cuota::sum('monto_pactado'), 2));
    }

    // 10
    public function test_anular_una_linea_no_primera_del_grupo(): void
    {
        $this->postCompra($this->financiada([
            ['BICI RALI 29', 1, 650],
            ['PORTA BIDON FSA', 1, 20],
        ], 0, 2))->assertRedirect();

        $segunda = MovimientoCuenta::where('descripcion', 'PORTA BIDON FSA')->firstOrFail();
        $this->actingAs($this->usuario)
            ->delete("/movimientos/{$segunda->id}", ['motivo' => 'linea cargada por error'])
            ->assertRedirect();

        $plan = PlanFinanciamiento::with('cuotas')->firstOrFail();

        // el total del plan sigue las lineas VIVAS: sin la linea anulada, 650
        $this->assertSame(650.0, $plan->montoTotalCompra());
        $this->assertSame(650.0, $this->sumaCuotas($plan));
        $this->assertSame(650.0, $this->saldoDelCliente());
    }

    // 11
    public function test_anular_la_primera_linea_no_deja_el_plan_huerfano_ni_rompe_la_mora(): void
    {
        $this->postCompra($this->financiada([
            ['BICI RALI 29', 1, 650],
            ['PORTA BIDON FSA', 1, 20],
        ], 0, 2))->assertRedirect();

        $plan = PlanFinanciamiento::firstOrFail();
        $primera = MovimientoCuenta::findOrFail($plan->movimiento_cuenta_id);

        $this->actingAs($this->usuario)
            ->delete("/movimientos/{$primera->id}", ['motivo' => 'anulada por error'])
            ->assertRedirect();

        $plan->refresh();

        // el plan se re-ancla a la unica linea viva: no queda huerfano ni apunta a una anulada
        $this->assertNotNull($plan->cargo);
        $this->assertFalse($plan->cargo->trashed());
        $this->assertSame('PORTA BIDON FSA', $plan->cargo->descripcion);
        // el total del grupo ya no incluye la linea anulada
        $this->assertSame(20.0, $plan->montoTotalCompra());

        // aplicar mora no revienta y se calcula sobre lo que queda vivo
        $this->actingAs($this->usuario)
            ->post("/planes-financiamiento/{$plan->id}/aplicar-mora")
            ->assertRedirect();

        $this->assertSame(2.0, $this->moraRegistrada());
    }

    // 12
    public function test_tras_anular_una_linea_el_saldo_la_excluye_y_las_cuotas_se_recalculan(): void
    {
        $this->postCompra($this->financiada([
            ['CLOUDMONSTER 3', 1, 265],
            ['CLOUDSURFER 2', 1, 195],
            ['MEDIAS KOM', 4, 10],
        ], 0, 4))->assertRedirect();

        $plan = PlanFinanciamiento::with('cuotas')->firstOrFail();
        $totalPactado = $this->sumaCuotas($plan);
        $this->assertSame(500.0, $totalPactado);
        $this->assertSame(500.0, $this->saldoDelCliente());

        $medias = MovimientoCuenta::where('descripcion', 'MEDIAS KOM')->firstOrFail();
        $this->actingAs($this->usuario)
            ->delete("/movimientos/{$medias->id}", ['motivo' => 'no se entregaron'])
            ->assertRedirect();

        $plan->refresh()->load('cuotas');

        // saldo baja 40 y las cuotas se recalculan al nuevo total: 460
        $this->assertSame(460.0, $this->saldoDelCliente());
        $this->assertSame(460.0, $this->sumaCuotas($plan));
        $this->assertSame(460.0, $plan->montoTotalCompra());
    }
}
