<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\MovimientoCuenta;
use App\Models\PlanFinanciamiento;
use App\Models\Producto;
use App\Models\SerialProducto;
use App\Models\User;
use App\Services\VentasService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// Venta de contado (inicial == total) y seriales por unidad.
// El 422 de produccion ("La inicial debe ser menor al monto total") salia de
// MovimientoCuentaController::store: abort_if(monto_inicial >= montoTotal) -- ahora esa igualdad es contado
class VentaContadoTest extends TestCase
{
    private User $usuario;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $migraciones = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => str_contains($p, 'replace_procesado_con_estado_en_prospectos_200k'))
            ->values()->all();
        Artisan::call('migrate', ['--path' => $migraciones, '--realpath' => true]);

        $this->usuario = User::factory()->create(['name' => 'Operador']);
        $this->cliente = Cliente::create(['nombre' => 'Ana Perez', 'telefono' => '+584141234567']);
    }

    // venta financiada tal como la manda el formulario (por defecto: el caso del reporte, 220 con inicial 220)
    private function venta(array $extra = [], ?array $productos = null, bool $json = false, ?Cliente $cliente = null): TestResponse
    {
        $datos = [
            'tipo' => 'cargo', 'cliente_id' => ($cliente ?? $this->cliente)->id, 'fecha' => '2026-11-02',
            'productos' => $productos ?? [['descripcion' => 'Garmin Forerunner', 'cantidad' => 1, 'precio_unitario' => 220]],
            'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'semanal',
            'es_financiada' => true, 'monto_inicial' => 220, 'metodo_pago_inicial' => 'efectivo',
            'numero_cuotas_financiamiento' => 1, 'porcentaje_mora' => 0,
            ...$extra,
        ];

        return $json ? $this->actingAs($this->usuario)->postJson('/movimientos', $datos) : $this->actingAs($this->usuario)->post('/movimientos', $datos);
    }

    // venta a credito simple (sin financiar), para probar seriales
    private function credito(array $productos, bool $json = true, ?Cliente $cliente = null): TestResponse
    {
        return $this->venta(['es_financiada' => false, 'monto_inicial' => null, 'plazo_meses' => 3, 'frecuencia_pago' => 'mensual'], $productos, $json, $cliente);
    }

    private function linea(string $descripcion = 'Garmin Forerunner', int $cantidad = 1, float $precio = 220, array $seriales = []): array
    {
        return ['descripcion' => $descripcion, 'cantidad' => $cantidad, 'precio_unitario' => $precio] + ($seriales ? ['lleva_serial' => true, 'seriales' => $seriales] : []);
    }

    // ---- criterio 1: el caso exacto del reporte ----
    public function test_inicial_igual_al_total_no_devuelve_422(): void
    {
        $this->venta()->assertSessionHasNoErrors()->assertRedirect();
    }

    // ---- criterio 2: contado = cargo + abono, sin plan ni cuotas ----
    public function test_contado_crea_cargo_y_abono_sin_plan_ni_cuotas_con_saldo_cero(): void
    {
        $this->venta(['modalidad_precio' => 'bcv', 'tasa_cambio' => 36.5])->assertRedirect();

        $this->assertSame(0, PlanFinanciamiento::count());
        $this->assertSame(0, Cuota::count());

        $cargo = MovimientoCuenta::where('tipo', 'cargo')->firstOrFail();
        $abono = MovimientoCuenta::where('tipo', 'abono')->firstOrFail();
        $this->assertTrue($cargo->es_contado);
        $this->assertSame(220.0, (float) $cargo->monto);
        $this->assertSame(220.0, (float) $abono->monto);
        $this->assertSame($this->usuario->id, (int) $abono->registrado_por);
        $this->assertSame(36.5, (float) $abono->tasa_cambio, 'tasa por transaccion en el abono');
        $this->assertSame(36.5, (float) $cargo->tasa_cambio, 'tasa por transaccion en el cargo');
        $this->assertNull($abono->plan_financiamiento_id);
        $this->assertSame('efectivo', $abono->metodo_pago);
        $this->assertSame(0.0, MovimientoCuenta::saldoPendiente($this->cliente->id));
        $this->assertTrue(Activity::where('description', 'venta_contado')->where('subject_id', $cargo->id)->exists());
    }

    public function test_el_interruptor_de_contado_no_pide_plan_ni_inicial(): void
    {
        $this->actingAs($this->usuario)->post('/movimientos', [
            'tipo' => 'cargo', 'cliente_id' => $this->cliente->id, 'fecha' => '2026-11-02',
            'productos' => [['descripcion' => 'Casco', 'cantidad' => 2, 'precio_unitario' => 30]],
            'modalidad_precio' => 'divisa', 'es_contado' => true, 'metodo_pago_inicial' => 'zelle',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(0, PlanFinanciamiento::count());
        $this->assertSame(60.0, (float) MovimientoCuenta::where('tipo', 'abono')->value('monto'));
        $this->assertSame('zelle', MovimientoCuenta::where('tipo', 'abono')->value('metodo_pago'));
        $this->assertSame(0.0, MovimientoCuenta::saldoPendiente($this->cliente->id));
    }

    public function test_el_interruptor_exige_el_metodo_de_pago(): void
    {
        $this->actingAs($this->usuario)->postJson('/movimientos', [
            'tipo' => 'cargo', 'cliente_id' => $this->cliente->id, 'fecha' => '2026-11-02',
            'productos' => [['descripcion' => 'Casco', 'cantidad' => 1, 'precio_unitario' => 30]],
            'modalidad_precio' => 'divisa', 'es_contado' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['metodo_pago_inicial']);
    }

    // ---- criterio 3 ----
    public function test_inicial_mayor_al_total_da_422_con_mensaje_en_espanol(): void
    {
        $r = $this->venta(['monto_inicial' => 250], null, true)->assertStatus(422);

        $this->assertStringContainsString('no puede ser mayor al total', $r->json('errors.monto_inicial.0'));
        $this->assertSame(0, MovimientoCuenta::count());
    }

    public function test_inicial_menor_al_total_sigue_creando_plan_y_cuotas(): void
    {
        $this->venta(['monto_inicial' => 20, 'numero_cuotas_financiamiento' => 4, 'frecuencia_pago' => 'semanal'])->assertRedirect();

        $plan = PlanFinanciamiento::firstOrFail();
        $this->assertSame(4, $plan->cuotas()->count());
        $this->assertSame(200.0, round((float) $plan->cuotas()->sum('monto_pactado'), 2));
        $this->assertFalse(MovimientoCuenta::where('tipo', 'cargo')->firstOrFail()->es_contado);
        $this->assertTrue((bool) MovimientoCuenta::where('tipo', 'abono')->firstOrFail()->es_inicial);
    }

    // ---- criterio 4: KPI, cartera y alertas no cambian ----
    public function test_una_venta_de_contado_no_cambia_kpi_ni_mora(): void
    {
        $admin = User::factory()->create(['es_admin' => true]);
        // deuda vieja sin abonos: genera mora y alertas
        MovimientoCuenta::create([
            'cliente_id' => $this->cliente->id, 'fecha' => now()->subDays(40)->toDateString(), 'tipo' => 'cargo', 'descripcion' => 'Bici vieja',
            'cantidad' => 1, 'precio_unitario' => 100, 'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'mensual',
            'monto' => 100, 'moneda' => 'usd', 'registrado_por' => $this->usuario->id,
        ]);

        $foto = function () use ($admin) {
            $kpi = $home = null;
            $this->actingAs($admin)->get('/kpi')->assertInertia(function ($p) use (&$kpi) {
                $kpi = collect($p->toArray()['props'])->only(['dineroEnCalle', 'periodos', 'antiguedadCartera', 'saludCartera', 'ultimos6Meses', 'semanasDelMes', 'actividadCobradores'])->all();
            });
            $this->actingAs($admin)->get('/home')->assertInertia(function ($p) use (&$home) {
                $home = collect($p->toArray()['props'])->only(['carteraConMora', 'eventosUrgentes', 'clientesConSaldo'])->all();
            });

            return [$kpi, $home];
        };

        [$kpiAntes, $homeAntes] = $foto();
        $this->assertSame(100.0, (float) $kpiAntes['dineroEnCalle']);
        $this->assertNotEmpty($homeAntes['carteraConMora']);

        $this->venta(['fecha' => now()->toDateString()])->assertRedirect(); // contado de 220 al MISMO cliente

        [$kpiDespues, $homeDespues] = $foto();
        $this->assertSame($kpiAntes, $kpiDespues, 'KPI: otorgado, cobrado, cartera y salud sin cambios');
        $this->assertSame($homeAntes, $homeDespues, 'mora y alertas sin cambios (el pago de contado no reinicia los dias sin abonar)');
    }

    public function test_la_cartera_no_cuenta_la_venta_de_contado_en_otorgado_cobrado_ni_ultimo_abono(): void
    {
        MovimientoCuenta::create([
            'cliente_id' => $this->cliente->id, 'fecha' => now()->subDays(40)->toDateString(), 'tipo' => 'cargo', 'descripcion' => 'Bici vieja',
            'cantidad' => 1, 'precio_unitario' => 100, 'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'mensual',
            'monto' => 100, 'moneda' => 'usd', 'registrado_por' => $this->usuario->id,
        ]);
        $fila = function () {
            $f = null;
            $this->actingAs($this->usuario)->get('/cartera')->assertInertia(function ($p) use (&$f) {
                $f = collect($p->toArray()['props']['clientes']['data'] ?? [])->firstWhere('nombre', 'Ana Perez');
            });

            return $f;
        };

        $antes = $fila();
        $this->venta(['fecha' => now()->toDateString()])->assertRedirect();

        $this->assertNotNull($antes, 'el cliente aparece en la cartera');
        $this->assertSame($antes, $fila());
    }

    public function test_el_panel_ventas_cuenta_la_venta_de_contado_con_etiqueta_contado(): void
    {
        $this->venta(['fecha' => '2026-11-02'])->assertRedirect();
        $this->credito([$this->linea('Casco', 1, 30)])->assertRedirect();

        $r = app(VentasService::class)->resumen(\Carbon\Carbon::parse('2026-11-01'), \Carbon\Carbon::parse('2026-11-03'));
        $lineas = collect($r['lineas']);

        $this->assertSame(250.0, (float) $r['credito'], 'el contado cuenta como venta');
        $this->assertTrue($lineas->firstWhere('nombre', 'GARMIN FORERUNNER')['contado']);
        $this->assertFalse($lineas->firstWhere('nombre', 'CASCO')['contado']);
    }

    // ---- criterio 5: seriales ----
    public function test_cantidad_2_con_serial_exige_2_distintos(): void
    {
        $this->credito([$this->linea('Garmin', 2, 100, ['ABC1'])])->assertStatus(422)->assertJsonValidationErrors(['productos.0.seriales']);
        $this->credito([$this->linea('Garmin', 2, 100, ['ABC1', ' abc1 '])])->assertStatus(422)->assertJsonValidationErrors(['productos.0.seriales.1']);
        $this->assertSame(0, SerialProducto::count());

        $this->credito([$this->linea('Garmin', 2, 100, ['abc1', ' xyz2 '])])->assertRedirect();

        $this->assertSame(['ABC1', 'XYZ2'], SerialProducto::orderBy('id')->pluck('serial')->all(), 'normalizados: trim + MAYUSCULAS, uno por unidad');
        $this->assertSame(2, SerialProducto::where('registrado_por', $this->usuario->id)->where('cliente_id', $this->cliente->id)->count());
        $this->assertSame(2, Activity::where('description', 'serial_creado')->count());
    }

    public function test_serial_duplicado_vigente_da_422_con_venta_y_cliente_incluso_en_minusculas(): void
    {
        $this->credito([$this->linea('Garmin Forerunner', 1, 220, ['SN-12345'])])->assertRedirect();
        $otro = Cliente::create(['nombre' => 'Luis Gomez', 'telefono' => '+584147654321']);

        $r = $this->credito([$this->linea('Bici GW', 1, 500, ['  sn-12345 '])], true, $otro)->assertStatus(422);

        $mensaje = $r->json('errors')['productos.0.seriales.0'][0];
        $this->assertStringContainsString('SN-12345', $mensaje);
        $this->assertStringContainsString('02/11/2026', $mensaje);
        $this->assertStringContainsString('GARMIN FORERUNNER', mb_strtoupper($mensaje));
        $this->assertStringContainsString('Ana Perez', $mensaje);
        $this->assertSame(1, SerialProducto::count());
    }

    // ---- criterio 6: eliminar la linea libera los seriales ----
    public function test_eliminar_la_linea_libera_los_seriales_y_permite_registrarlos_de_nuevo(): void
    {
        $this->credito([$this->linea('Garmin', 1, 220, ['SN-1'])])->assertRedirect();
        $linea = MovimientoCuenta::where('tipo', 'cargo')->firstOrFail();

        $this->actingAs($this->usuario)->delete("/movimientos/{$linea->id}", ['motivo' => 'error de captura'])->assertRedirect();

        $this->assertSame(0, SerialProducto::count());
        $this->assertSame(1, SerialProducto::onlyTrashed()->count());
        $this->assertTrue(Activity::where('description', 'serial_liberado')->exists());

        $this->credito([$this->linea('Garmin', 1, 220, ['sn-1'])])->assertRedirect();
        $this->assertSame(1, SerialProducto::count(), 'el serial liberado se puede registrar otra vez');
    }

    public function test_la_devolucion_no_enlaza_con_la_linea_original_y_por_eso_no_libera_seriales(): void
    {
        // decision 5: el ajuste_devolucion no guarda a que linea de cargo corresponde (solo cliente y monto),
        // no se adivina: la liberacion queda solo por eliminacion de la linea
        $this->credito([$this->linea('Garmin', 1, 220, ['SN-9'])])->assertRedirect();

        $this->actingAs($this->usuario)->post('/movimientos', [
            'tipo' => 'ajuste_devolucion', 'cliente_id' => $this->cliente->id, 'fecha' => '2026-11-02',
            'descripcion' => 'Devolucion Garmin', 'monto' => 220, 'metodo_pago' => 'devolucion', 'comentario' => 'devolvio el reloj',
        ])->assertRedirect();

        $this->assertSame(1, SerialProducto::count());
    }

    // ---- criterio 7: busqueda ----
    public function test_el_buscador_de_clientes_encuentra_por_serial_en_minusculas(): void
    {
        $this->credito([$this->linea('Garmin', 1, 220, ['SN-ABC-777'])])->assertRedirect();
        Cliente::create(['nombre' => 'Otro Cliente', 'telefono' => '+584140000000']);

        $nombres = null;
        $this->actingAs($this->usuario)->get('/clientes?q='.rawurlencode('sn-abc-777'))
            ->assertInertia(function ($p) use (&$nombres) {
                $nombres = collect($p->toArray()['props']['clientes']['data'])->pluck('nombre')->all();
            });
        $this->assertSame(['Ana Perez'], $nombres);

        $this->actingAs($this->usuario)->getJson('/clientes/buscar?q='.rawurlencode(' sn-abc-777 '))->assertJsonPath('0.nombre', 'Ana Perez');
    }

    public function test_un_serial_liberado_deja_de_encontrarse(): void
    {
        $this->credito([$this->linea('Garmin', 1, 220, ['SN-LIB'])])->assertRedirect();
        $this->actingAs($this->usuario)->delete('/movimientos/'.MovimientoCuenta::where('tipo', 'cargo')->value('id'), ['motivo' => 'x']);

        $this->actingAs($this->usuario)->getJson('/clientes/buscar?q=sn-lib')->assertJson([]);
    }

    // ---- criterio 8: catalogo ----
    public function test_lleva_serial_se_guarda_en_el_catalogo_y_sale_marcado_la_proxima_vez(): void
    {
        $this->credito([$this->linea('Garmin Forerunner', 1, 220, ['SN-1'])])->assertRedirect();

        $this->assertTrue(Producto::where('nombre', 'GARMIN FORERUNNER')->firstOrFail()->lleva_serial);

        $this->actingAs($this->usuario)->getJson('/productos?q=garmin&detalle=1')
            ->assertJsonPath('0.nombre', 'GARMIN FORERUNNER')->assertJsonPath('0.lleva_serial', true);
        // sin ?detalle la respuesta sigue siendo la lista de nombres que usa Taller/Show
        $this->actingAs($this->usuario)->getJson('/productos?q=garmin')->assertExactJson(['GARMIN FORERUNNER']);
    }

    // ---- hallazgo de seguridad: la deuda nunca queda oculta tras es_contado ----
    private function editarCargo(MovimientoCuenta $cargo, array $cambios): void
    {
        $this->actingAs($this->usuario)->put("/movimientos/{$cargo->id}", [
            'fecha' => '2026-11-02', 'descripcion' => $cargo->descripcion, 'moneda' => 'usd', 'cantidad' => 1, 'precio_unitario' => 220,
            'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'mensual', 'motivo_edicion' => 'prueba', ...$cambios,
        ])->assertRedirect();
    }

    public function test_borrar_el_pago_de_una_venta_de_contado_la_vuelve_credito_y_la_deuda_se_ve_en_el_kpi(): void
    {
        $admin = User::factory()->create(['es_admin' => true]);
        $this->venta()->assertRedirect();
        $abono = MovimientoCuenta::where('tipo', 'abono')->firstOrFail();

        $this->actingAs($this->usuario)->delete("/movimientos/{$abono->id}", ['motivo' => 'error'])->assertRedirect();

        $this->assertFalse(MovimientoCuenta::where('tipo', 'cargo')->firstOrFail()->es_contado, 'ya no esta oculta');
        $this->assertSame(220.0, MovimientoCuenta::saldoPendiente($this->cliente->id));
        $this->actingAs($admin)->get('/kpi')->assertInertia(fn ($p) => $p->where('dineroEnCalle', 220)->where('periodos.historico.actual.otorgado', 220));
        $this->assertTrue(Activity::where('description', 'contado_revertido_a_credito')->exists());
    }

    public function test_cambiar_el_monto_de_la_linea_de_contado_la_vuelve_credito(): void
    {
        $this->venta()->assertRedirect();
        $cargo = MovimientoCuenta::where('tipo', 'cargo')->firstOrFail();

        $this->editarCargo($cargo, ['precio_unitario' => 300]);

        $this->assertFalse($cargo->fresh()->es_contado);
        $this->assertFalse(MovimientoCuenta::where('tipo', 'abono')->firstOrFail()->es_contado);
        $this->assertSame(80.0, MovimientoCuenta::saldoPendiente($this->cliente->id));
    }

    public function test_una_edicion_que_no_cambia_el_monto_conserva_el_contado(): void
    {
        $this->venta()->assertRedirect();
        $cargo = MovimientoCuenta::where('tipo', 'cargo')->firstOrFail();

        $this->editarCargo($cargo, ['descripcion' => 'Garmin Forerunner 265']);

        $this->assertTrue($cargo->fresh()->es_contado);
        $this->assertFalse(Activity::where('description', 'contado_revertido_a_credito')->exists());
    }

    // respaldo en BD: el indice unico sobre la columna generada impide el duplicado aunque la aplicacion falle
    public function test_el_indice_unico_de_la_bd_impide_duplicar_un_serial_vigente_pero_no_uno_liberado(): void
    {
        $this->credito([$this->linea('Garmin', 1, 220, ['SN-DB'])])->assertRedirect();
        $original = SerialProducto::firstOrFail();
        $datos = ['movimiento_cuenta_id' => $original->movimiento_cuenta_id, 'cliente_id' => $this->cliente->id, 'serial' => 'SN-DB'];

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        try {
            SerialProducto::create($datos);
        } finally {
            $original->delete(); // liberado: ahora SI se puede
            SerialProducto::create($datos);
            $this->assertSame(1, SerialProducto::count());
        }
    }

    // ---- corregir un serial queda en ActivityLog ----
    public function test_corregir_un_serial_queda_en_activity_log_y_respeta_la_unicidad(): void
    {
        $this->credito([$this->linea('Garmin', 2, 100, ['SN-1', 'SN-2'])])->assertRedirect();
        [$uno, $dos] = SerialProducto::orderBy('id')->get()->all();

        $this->actingAs($this->usuario)->put("/seriales/{$uno->id}", ['serial' => ' sn-1b '])->assertRedirect();
        $this->assertSame('SN-1B', $uno->fresh()->serial);
        $log = Activity::where('description', 'serial_editado')->firstOrFail();
        $this->assertSame('SN-1', $log->properties['antes']);
        $this->assertSame('SN-1B', $log->properties['despues']);

        $this->actingAs($this->usuario)->putJson("/seriales/{$uno->id}", ['serial' => 'sn-2'])->assertStatus(422)->assertJsonValidationErrors(['serial']);
        $this->assertSame('SN-1B', $uno->fresh()->serial);
    }
}
