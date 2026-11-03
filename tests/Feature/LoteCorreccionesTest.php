<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\Producto;
use App\Models\SerialProducto;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// Lote de correcciones 1: por entregar en Ventas, eliminar linea de contado, catalogo de "lleva serial",
// agregar seriales a lineas existentes y el KPI frente a una venta apartada
class LoteCorreccionesTest extends TestCase
{
    private User $usuario;

    private User $admin;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        // la migracion de prospectos_200k ya es compatible con sqlite: se corren TODAS las migraciones
        Artisan::call('migrate');

        $this->usuario = User::factory()->create(['name' => 'Operador']);
        $this->admin = User::factory()->create(['name' => 'Admin', 'es_admin' => true]);
        $this->cliente = Cliente::create(['nombre' => 'Ana Perez', 'telefono' => '+584141234567']);
    }

    // venta de contado por defecto (inicial == total); $extra pisa campos
    private function venta(array $productos, array $extra = [], bool $json = false): TestResponse
    {
        $total = collect($productos)->sum(fn ($p) => $p['cantidad'] * $p['precio_unitario']);
        $datos = [
            'tipo' => 'cargo', 'cliente_id' => $this->cliente->id, 'fecha' => '2026-11-02', 'productos' => $productos,
            'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'semanal',
            'es_financiada' => true, 'monto_inicial' => $total, 'metodo_pago_inicial' => 'efectivo',
            'numero_cuotas_financiamiento' => 1, 'porcentaje_mora' => 0, ...$extra,
        ];

        return $json ? $this->actingAs($this->usuario)->postJson('/movimientos', $datos) : $this->actingAs($this->usuario)->post('/movimientos', $datos);
    }

    private function credito(array $productos, bool $json = false): TestResponse
    {
        return $this->venta($productos, ['es_financiada' => false, 'monto_inicial' => null, 'plazo_meses' => 3, 'frecuencia_pago' => 'mensual'], $json);
    }

    private function eliminar(MovimientoCuenta $m): void
    {
        $this->actingAs($this->usuario)->delete("/movimientos/{$m->id}", ['motivo' => 'prueba'])->assertRedirect();
    }

    private function porEntregar(): array
    {
        $lista = null;
        $this->actingAs($this->admin)->get('/ventas?por_entregar=1')->assertInertia(function ($p) use (&$lista) {
            $lista = $p->toArray()['props']['porEntregar'];
        });

        return $lista;
    }

    // ---- decision 1: Ventas "Solo por entregar" ----
    public function test_por_entregar_lista_una_compra_de_hace_30_dias_aunque_el_rango_sea_hoy(): void
    {
        $this->venta([['descripcion' => 'Garmin', 'cantidad' => 1, 'precio_unitario' => 220, 'pendiente_entrega' => true]], ['fecha' => now()->subDays(30)->toDateString()])->assertRedirect();
        $this->venta([['descripcion' => 'Casco', 'cantidad' => 1, 'precio_unitario' => 30]], ['fecha' => now()->toDateString()])->assertRedirect(); // entregada

        $lista = $this->porEntregar();

        $this->assertCount(1, $lista, 'la entregada no aparece');
        $this->assertSame('GARMIN', $lista[0]['articulo']);
        $this->assertSame('Ana Perez', $lista[0]['cliente']);
        $this->assertSame(now()->subDays(30)->toDateString(), $lista[0]['fecha']);
        $this->assertEquals(220.0, $lista[0]['total']);
        $this->assertEquals(220.0, $lista[0]['abono'], 'contado: todo abonado');
        $this->assertEquals(0.0, $lista[0]['saldo']);
        $this->assertTrue($lista[0]['contado']);

        $this->actingAs($this->admin)->get('/ventas?rango=hoy')->assertInertia(fn ($p) => $p->where('porEntregar', null));
    }

    public function test_por_entregar_con_plan_muestra_inicial_mas_abonos_y_el_saldo(): void
    {
        $this->venta([['descripcion' => 'Bici GW', 'cantidad' => 1, 'precio_unitario' => 220, 'pendiente_entrega' => true]], ['monto_inicial' => 20, 'numero_cuotas_financiamiento' => 4])->assertRedirect();
        $plan = \App\Models\PlanFinanciamiento::firstOrFail();
        $this->actingAs($this->usuario)->post('/movimientos', [
            'tipo' => 'abono', 'cliente_id' => $this->cliente->id, 'fecha' => '2026-11-03', 'descripcion' => 'Pago', 'monto' => 50,
            'metodo_pago' => 'efectivo', 'comentario' => 'cuota', 'plan_financiamiento_id' => $plan->id,
        ])->assertRedirect();

        [$fila] = $this->porEntregar();

        $this->assertEquals(220.0, $fila['total']);
        $this->assertEquals(70.0, $fila['abono']);
        $this->assertEquals(150.0, $fila['saldo']);
        $this->assertFalse($fila['contado']);
    }

    public function test_por_entregar_es_solo_para_admin(): void
    {
        $this->actingAs($this->usuario)->get('/ventas?por_entregar=1')->assertRedirect();
    }

    // ---- decision 2: eliminar una linea de contado ajusta su pago ----
    public function test_eliminar_la_unica_linea_de_contado_anula_el_pago_y_el_saldo_queda_en_cero(): void
    {
        $this->venta([['descripcion' => 'Garmin', 'cantidad' => 1, 'precio_unitario' => 220]])->assertRedirect();

        $this->eliminar(MovimientoCuenta::where('tipo', 'cargo')->firstOrFail());

        $this->assertSame(0, MovimientoCuenta::count(), 'cargo y pago anulados (soft-delete)');
        $this->assertSame(2, MovimientoCuenta::onlyTrashed()->count());
        $this->assertSame(0.0, MovimientoCuenta::saldoPendiente($this->cliente->id));
        $this->assertTrue(Activity::where('description', 'contado_pago_ajustado')->where('properties->pago_anulado', true)->exists());
    }

    public function test_eliminar_una_de_dos_lineas_de_contado_reduce_el_pago_y_conserva_la_tasa(): void
    {
        $this->venta([['descripcion' => 'Casco', 'cantidad' => 1, 'precio_unitario' => 100], ['descripcion' => 'Guantes', 'cantidad' => 1, 'precio_unitario' => 120]], ['modalidad_precio' => 'bcv', 'tasa_cambio' => 36.5])->assertRedirect();
        $this->assertSame(220.0, (float) MovimientoCuenta::where('tipo', 'abono')->value('monto'));

        $this->eliminar(MovimientoCuenta::where('descripcion', 'Casco')->firstOrFail());

        $pago = MovimientoCuenta::where('tipo', 'abono')->firstOrFail();
        $this->assertSame(120.0, (float) $pago->monto);
        $this->assertSame(36.5, (float) $pago->tasa_cambio, 'conserva la tasa de la transaccion');
        $this->assertTrue($pago->es_contado, 'sigue siendo contado: cargo restante y pago netean cero');
        $this->assertSame(0.0, MovimientoCuenta::saldoPendiente($this->cliente->id));

        $this->eliminar(MovimientoCuenta::where('descripcion', 'Guantes')->firstOrFail());

        $this->assertSame(0, MovimientoCuenta::where('tipo', 'abono')->count());
        $this->assertSame(0.0, MovimientoCuenta::saldoPendiente($this->cliente->id));
        $this->assertSame(2, Activity::where('description', 'contado_pago_ajustado')->count());
    }

    // ---- decision 3: desmarcar en la venta NO cambia el catalogo ----
    public function test_desmarcar_lleva_serial_en_una_venta_no_cambia_el_catalogo(): void
    {
        $this->credito([['descripcion' => 'Garmin', 'cantidad' => 1, 'precio_unitario' => 220, 'lleva_serial' => true, 'seriales' => ['SN-1']]])->assertRedirect();
        $this->assertTrue(Producto::where('nombre', 'GARMIN')->firstOrFail()->lleva_serial);

        $this->credito([['descripcion' => 'Garmin', 'cantidad' => 1, 'precio_unitario' => 220, 'lleva_serial' => false]])->assertRedirect();

        $this->assertTrue(Producto::where('nombre', 'GARMIN')->firstOrFail()->lleva_serial, 'solo marcar persiste');
    }

    // ---- decision 4: agregar seriales a una linea existente ----
    public function test_agregar_seriales_completa_la_cantidad_con_las_mismas_validaciones(): void
    {
        $this->credito([['descripcion' => 'Garmin', 'cantidad' => 2, 'precio_unitario' => 100]])->assertRedirect(); // sin seriales
        $linea = MovimientoCuenta::where('tipo', 'cargo')->firstOrFail();

        // cantidad exacta de los que faltan
        $this->actingAs($this->usuario)->postJson("/movimientos/{$linea->id}/seriales", ['seriales' => ['A1']])->assertStatus(422)->assertJsonValidationErrors(['seriales']);
        // distintos entre si, normalizados
        $this->actingAs($this->usuario)->postJson("/movimientos/{$linea->id}/seriales", ['seriales' => ['a1', ' A1 ']])->assertStatus(422)->assertJsonValidationErrors(['seriales.1']);

        $this->actingAs($this->usuario)->post("/movimientos/{$linea->id}/seriales", ['seriales' => ['a1', ' b2 ']])->assertRedirect();

        $this->assertSame(['A1', 'B2'], SerialProducto::orderBy('id')->pluck('serial')->all());
        $this->assertSame(2, Activity::where('description', 'serial_creado')->count());
        // ya completa: no se agregan mas
        $this->actingAs($this->usuario)->postJson("/movimientos/{$linea->id}/seriales", ['seriales' => ['C3']])->assertStatus(422);
    }

    public function test_agregar_un_serial_duplicado_vigente_da_422_con_venta_y_cliente(): void
    {
        $this->credito([['descripcion' => 'Garmin Forerunner', 'cantidad' => 1, 'precio_unitario' => 220, 'lleva_serial' => true, 'seriales' => ['SN-DUP']]])->assertRedirect();
        $otro = Cliente::create(['nombre' => 'Luis Gomez', 'telefono' => '+584147654321']);
        $this->actingAs($this->usuario)->postJson('/movimientos', [
            'tipo' => 'cargo', 'cliente_id' => $otro->id, 'fecha' => '2026-11-02', 'productos' => [['descripcion' => 'Bici', 'cantidad' => 1, 'precio_unitario' => 500]],
            'modalidad_precio' => 'divisa', 'plazo_meses' => 3, 'frecuencia_pago' => 'mensual',
        ])->assertRedirect();
        $lineaOtro = MovimientoCuenta::where('cliente_id', $otro->id)->firstOrFail();

        $mensaje = $this->actingAs($this->usuario)->postJson("/movimientos/{$lineaOtro->id}/seriales", ['seriales' => [' sn-dup ']])
            ->assertStatus(422)->json('errors')['seriales.0'][0];

        $this->assertStringContainsString('SN-DUP', $mensaje);
        $this->assertStringContainsString('Ana Perez', $mensaje);
        $this->assertStringContainsString('GARMIN FORERUNNER', mb_strtoupper($mensaje));
        $this->assertSame(1, SerialProducto::count());
    }

    // ---- plan, punto 5: el KPI frente a una venta apartada ----
    public function test_el_kpi_cuenta_la_venta_apartada_en_otorgado_por_su_fecha_de_venta(): void
    {
        $this->credito([['descripcion' => 'Bici entregada', 'cantidad' => 1, 'precio_unitario' => 100]])->assertRedirect();
        $otorgado = function () {
            $v = null;
            $this->actingAs($this->admin)->get('/kpi')->assertInertia(function ($p) use (&$v) {
                $v = $p->toArray()['props']['periodos']['historico']['actual']['otorgado'];
            });

            return (float) $v;
        };
        $antes = $otorgado();

        $this->credito([['descripcion' => 'Bici apartada', 'cantidad' => 1, 'precio_unitario' => 250, 'pendiente_entrega' => true]])->assertRedirect();

        // HALLAZGO (no se corrige, se reporta): el KPI cuenta la mercancia aun NO entregada como "otorgado"
        $this->assertSame($antes + 250.0, $otorgado());
    }
}
