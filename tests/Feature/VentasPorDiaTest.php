<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\TicketTaller;
use App\Models\User;
use App\Services\VentasService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// Ventas "Por dia": una linea por venta con su fecha, subtotal por dia en SQL sobre todo el
// rango, fecha aproximada del taller y paginacion sin duplicar ni perder filas
class VentasPorDiaTest extends TestCase
{
    private VentasService $servicio;

    private User $usuario;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $migraciones = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => str_contains($p, 'replace_procesado_con_estado_en_prospectos_200k'))
            ->values()->all();
        Artisan::call('migrate', ['--path' => $migraciones, '--realpath' => true]);
        Schema::table('movimientos_cuenta', fn ($t) => $t->date('fecha')->nullable()->change());

        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', config('app.timezone')));

        $this->servicio = app(VentasService::class);
        $this->usuario = User::factory()->create(['es_admin' => true, 'rol_taller' => false]);
        $this->cliente = Cliente::create(['nombre' => 'MUÑOZ PÉREZ', 'telefono' => '+584141234567']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cargo(array $datos = []): MovimientoCuenta
    {
        $monto = $datos['monto'] ?? 10;

        return MovimientoCuenta::create([
            'cliente_id' => $this->cliente->id,
            'compra_id' => (string) Str::uuid(),
            'fecha' => '2026-10-22',
            'tipo' => 'cargo',
            'descripcion' => 'Reloj',
            'cantidad' => 1,
            'precio_unitario' => $monto,
            'modalidad_precio' => 'divisa',
            'monto' => $monto,
            'moneda' => 'usd',
            'registrado_por' => $this->usuario->id,
            ...$datos,
        ]);
    }

    private function ticket(array $datos = [], array $repuestos = []): TicketTaller
    {
        $atendidoEn = $datos['atendido_en'] ?? '2026-10-22 09:00:00';
        unset($datos['atendido_en']);

        $ticket = TicketTaller::create([
            'tipo' => 'servicio_cliente',
            'bici_marca_modelo' => 'Trek Marlin',
            'categoria_bici' => 'mtb',
            'talla_rin' => '29',
            'es_electrica' => false,
            'tipo_servicio' => 'basico',
            'diagnostico' => [],
            'cliente_id' => $this->cliente->id,
            'mecanico_id' => $this->usuario->id,
            'registrado_por' => $this->usuario->id,
            'monto_servicio' => 20,
            ...$datos,
        ]);
        $ticket->forceFill(['estado' => 'atendido', 'atendido_en' => $atendidoEn])->save();

        foreach ($repuestos as [$producto, $cantidad, $precio]) {
            $ticket->repuestos()->create(['producto' => $producto, 'cantidad' => $cantidad, 'precio' => $precio]);
        }

        return $ticket;
    }

    private function resumen(string $desde, string $hasta): array
    {
        return $this->servicio->resumen(Carbon::parse($desde), Carbon::parse($hasta));
    }

    // ---- B-1 ----
    public function test_un_articulo_vendido_en_tres_dias_da_tres_lineas_con_sus_tres_fechas(): void
    {
        foreach (['2026-10-20', '2026-10-21', '2026-10-22'] as $dia) {
            $this->cargo(['descripcion' => 'Reloj Lambo', 'fecha' => $dia, 'monto' => 100]);
        }

        $r = $this->resumen('2026-10-20', '2026-10-22');

        $this->assertSame(['2026-10-22', '2026-10-21', '2026-10-20'], array_column($r['lineas'], 'dia'));
        $this->assertSame(['RELOJ LAMBO', 'RELOJ LAMBO', 'RELOJ LAMBO'], array_column($r['lineas'], 'nombre'));
        $this->assertSame('MUÑOZ PÉREZ', $r['lineas'][0]['cliente']);
        $this->assertSame('tienda', $r['lineas'][0]['origen']);
        // la vista agrupada sigue siendo una fila, ahora con N ventas y la ultima fecha
        $this->assertCount(1, $r['articulos']);
        $this->assertSame(3, $r['articulos'][0]['ventas']);
        $this->assertSame('2026-10-22', $r['articulos'][0]['ultima']);
        $this->assertSame(3.0, $r['articulos'][0]['cantidad']);
    }

    public function test_dentro_del_dia_va_primero_lo_mas_reciente_y_el_taller_trae_su_ticket(): void
    {
        $this->cargo(['descripcion' => 'Casco', 'monto' => 40]);
        $t = $this->ticket(['atendido_en' => '2026-10-22 15:00:00'], [['Cadena', 2, 7.5]]);

        $r = $this->resumen('2026-10-22', '2026-10-22');

        $taller = collect($r['lineas'])->firstWhere('origen', 'taller');
        $this->assertSame($t->id, $taller['ticket_id']);
        $this->assertSame('CADENA', $taller['nombre']);
        $this->assertSame(15.0, $taller['monto']);
        $this->assertNull(collect($r['lineas'])->firstWhere('origen', 'tienda')['ticket_id']);
    }

    // ---- B-2 ----
    public function test_subtotal_por_dia_es_la_suma_de_sus_lineas_y_los_subtotales_suman_credito_mas_repuestos(): void
    {
        $this->cargo(['descripcion' => 'Casco', 'fecha' => '2026-10-21', 'monto' => 40]);
        $this->cargo(['descripcion' => 'Luces', 'fecha' => '2026-10-22', 'cantidad' => 2, 'monto' => 30]);
        $this->cargo(['descripcion' => 'Timbre', 'fecha' => '2026-10-22', 'monto' => 5.5]);
        $this->ticket(['atendido_en' => '2026-10-22 09:00:00'], [['Cadena', 2, 7.5], ['Pastillas', 1, 9.99]]);

        $r = $this->resumen('2026-10-21', '2026-10-22');

        $porDia = collect($r['dias'])->keyBy('dia');
        $this->assertSame(40.0, $porDia['2026-10-21']['monto']);
        $this->assertSame(60.49, $porDia['2026-10-22']['monto']);
        $this->assertSame(4, $porDia['2026-10-22']['ventas']);
        foreach ($r['dias'] as $d) {
            $lineas = array_filter($r['lineas'], fn ($l) => $l['dia'] === $d['dia']);
            $this->assertSame($d['monto'], round(array_sum(array_column($lineas, 'monto')), 2));
        }
        $this->assertSame(
            round($r['credito'] + $r['taller_repuestos'], 2),
            round(array_sum(array_column($r['dias'], 'monto')), 2),
        );
    }

    // ---- B-3 ----
    public function test_la_fecha_aproximada_del_taller_viaja_marcada(): void
    {
        $this->ticket(['atendido_en' => '2026-10-22 09:00:00'], [['Cadena', 1, 10]])->forceFill(['atendido_en_aprox' => true])->save();
        $this->ticket(['atendido_en' => '2026-10-22 10:00:00'], [['Pedal', 1, 10]]);

        $lineas = collect($this->resumen('2026-10-22', '2026-10-22')['lineas'])->keyBy('nombre');

        $this->assertTrue($lineas['CADENA']['aprox']);
        $this->assertFalse($lineas['PEDAL']['aprox']);
    }

    public function test_el_servicio_el_cargo_automatico_la_mora_y_lo_sin_fecha_siguen_fuera_de_las_lineas(): void
    {
        $this->cargo(['descripcion' => 'Mora (10%) - Reloj', 'compra_id' => null, 'monto' => 99]);
        $this->cargo(['descripcion' => 'Sin fecha', 'fecha' => null, 'monto' => 50]);
        $this->cargo(['descripcion' => 'Borrado', 'monto' => 70])->delete();
        $this->ticket([], [['Gratis', 1, 0]]);

        $r = $this->resumen('2026-10-22', '2026-10-22');

        $this->assertSame([], $r['lineas']);
        $this->assertSame([], $r['dias']);
        $this->assertSame(1, $r['cargos_sin_fecha']);
        $this->assertSame(20.0, $r['taller_servicio']);
    }

    // ---- B-4 ----
    public function test_con_120_ventas_ver_mas_trae_las_siguientes_sin_duplicar_ni_perder_y_los_subtotales_no_cambian(): void
    {
        for ($i = 1; $i <= 120; $i++) {
            $dia = Carbon::parse('2026-10-22')->subDays($i % 6)->toDateString();
            $this->cargo(['descripcion' => "Articulo {$i}", 'fecha' => $dia, 'monto' => $i]);
        }

        $r = $this->resumen('2026-10-17', '2026-10-22');
        $this->assertCount(50, $r['lineas']);
        $this->assertTrue($r['hay_mas']);
        $subtotales = $r['dias'];
        $this->assertSame(120, array_sum(array_column($subtotales, 'ventas')));
        $this->assertSame(7260.0, round(array_sum(array_column($subtotales, 'monto')), 2));

        $inicio = '2026-10-17 00:00:00';
        $fin = '2026-10-22 23:59:59';
        $p2 = $this->servicio->lineasPagina($inicio, $fin, 50);
        $p3 = $this->servicio->lineasPagina($inicio, $fin, 100);

        $this->assertCount(50, $p2['lineas']);
        $this->assertTrue($p2['hay_mas']);
        $this->assertCount(20, $p3['lineas']);
        $this->assertFalse($p3['hay_mas']);

        $nombres = array_column([...$r['lineas'], ...$p2['lineas'], ...$p3['lineas']], 'nombre');
        $this->assertCount(120, array_unique($nombres), 'ni duplicadas ni perdidas');
        // los subtotales se calculan sobre todo el rango: pedir otra pagina no los cambia
        $this->assertSame($subtotales, $this->resumen('2026-10-17', '2026-10-22')['dias']);
    }

    public function test_la_ruta_lineas_devuelve_la_siguiente_tanda_en_json(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->cargo(['descripcion' => "Articulo {$i}", 'monto' => $i]);
        }

        $this->actingAs($this->usuario)->getJson('/ventas/lineas?rango=hoy&offset=50')
            ->assertOk()
            ->assertJsonCount(10, 'lineas')
            ->assertJsonPath('hay_mas', false);
    }
}
