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

// Panel Ventas: lo VENDIDO en un rango (credito por fecha del cargo, taller por fecha
// en que el ticket se marco atendido), sin doble conteo ni mora
class VentasServiceTest extends TestCase
{
    private VentasService $servicio;

    private User $usuario;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        // esquema real; se salta solo la migracion vieja que no corre en sqlite
        $migraciones = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => str_contains($p, 'replace_procesado_con_estado_en_prospectos_200k'))
            ->values()->all();
        Artisan::call('migrate', ['--path' => $migraciones, '--realpath' => true]);
        // en MySQL fecha ya es nullable (2026_09_11_041626); en sqlite esa migracion no lo aplica
        Schema::table('movimientos_cuenta', fn ($t) => $t->date('fecha')->nullable()->change());

        // hoy = jueves 2026-10-22, en la zona horaria de la app
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
            'descripcion' => 'Casco',
            'cantidad' => 1,
            'precio_unitario' => $monto,
            'modalidad_precio' => 'divisa',
            'monto' => $monto,
            'moneda' => 'usd',
            'registrado_por' => $this->usuario->id,
            ...$datos,
        ]);
    }

    /** Ticket atendido; $repuestos = [[producto, cantidad, precio], ...]. */
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

    private function hoy(): array
    {
        return $this->servicio->resumen(Carbon::today(), Carbon::today());
    }

    // ---- criterio 1: un ticket a credito cuenta una sola vez ----
    public function test_ticket_a_credito_cuenta_por_el_lado_del_taller_y_su_cargo_automatico_no_suma_en_credito(): void
    {
        $cargoAuto = $this->cargo(['compra_id' => null, 'descripcion' => 'Servicio de taller — Trek Marlin', 'monto' => 35]);
        $this->ticket(['movimiento_cuenta_id' => $cargoAuto->id, 'monto_servicio' => 20], [['Cadena', 3, 5]]);

        $r = $this->hoy();

        $this->assertSame(0.0, $r['credito'], 'el cargo automatico no se cuenta otra vez como credito');
        $this->assertSame(20.0, $r['taller_servicio']);
        $this->assertSame(15.0, $r['taller_repuestos']);
        $this->assertSame(35.0, $r['total'], 'el ticket vale 35 una sola vez');
    }

    // ---- criterio 2: mora ----
    public function test_un_cargo_de_mora_no_suma_y_uno_normal_si(): void
    {
        $this->cargo(['compra_id' => null, 'descripcion' => 'Mora (10%) - Bicicleta Monza', 'monto' => 79.90]);
        $this->cargo(['descripcion' => 'Bicicleta Monza', 'monto' => 799]);

        $r = $this->hoy();

        $this->assertSame(799.0, $r['credito']);
        $this->assertCount(1, $r['articulos']);
    }

    // ---- criterio 3: sin fecha ----
    public function test_un_cargo_sin_fecha_no_suma_y_se_cuenta_aparte(): void
    {
        $this->cargo(['fecha' => null, 'monto' => 50]);
        $this->cargo(['fecha' => null, 'monto' => 70]);
        $this->cargo(['monto' => 10]);

        $r = $this->hoy();

        $this->assertSame(10.0, $r['credito']);
        $this->assertSame(2, $r['cargos_sin_fecha']);
    }

    public function test_cargos_borrados_no_suman_ni_cuentan_como_sin_fecha(): void
    {
        $this->cargo(['monto' => 99])->delete();
        $this->cargo(['fecha' => null])->delete();

        $r = $this->hoy();

        $this->assertSame(0.0, $r['credito']);
        $this->assertSame(0, $r['cargos_sin_fecha']);
    }

    public function test_cuenta_cuantos_tickets_del_rango_tienen_fecha_de_cierre_aproximada(): void
    {
        $this->ticket(['monto_servicio' => 10]);
        $this->ticket(['monto_servicio' => 10])->forceFill(['atendido_en_aprox' => true])->save();

        $r = $this->hoy();

        $this->assertSame(2, $r['tickets_atendidos']);
        $this->assertSame(1, $r['tickets_fecha_aprox']);
    }

    // ---- criterio 4: pagado en el taller (sin cargo) ----
    public function test_ticket_pagado_en_el_taller_suma_servicio_y_repuestos_sin_cargo(): void
    {
        $this->ticket(['pagado_en_taller' => true, 'monto_servicio' => 25, 'incluye_armado' => true, 'monto_armado' => 15], [['Frenos', 2, 12.5]]);

        $r = $this->hoy();

        $this->assertSame(0.0, $r['credito']);
        $this->assertSame(40.0, $r['taller_servicio'], 'servicio + armado');
        $this->assertSame(25.0, $r['taller_repuestos']);
        $this->assertSame(65.0, $r['total']);
        $this->assertSame(1, $r['tickets_atendidos']);
    }

    public function test_tickets_en_proceso_o_borrados_no_suman(): void
    {
        $this->ticket(['monto_servicio' => 20])->forceFill(['estado' => 'en_proceso'])->save();
        $this->ticket(['monto_servicio' => 30])->delete();

        $r = $this->hoy();

        $this->assertSame(0.0, $r['total']);
        $this->assertSame(0, $r['tickets_atendidos']);
    }

    // ---- criterio 5: total y articulos cuadran ----
    public function test_total_es_la_suma_de_las_tres_partes_y_los_articulos_cuadran_con_credito_mas_repuestos(): void
    {
        $this->cargo(['descripcion' => 'Casco', 'monto' => 40]);
        $this->cargo(['descripcion' => 'Luces', 'cantidad' => 2, 'monto' => 30]);
        $this->ticket(['monto_servicio' => 20], [['Cadena', 2, 7.5], ['Pastillas', 1, 9.99]]);

        $r = $this->hoy();

        $this->assertSame(70.0, $r['credito']);
        $this->assertSame(20.0, $r['taller_servicio']);
        $this->assertSame(24.99, $r['taller_repuestos']);
        $this->assertSame(114.99, $r['total']);
        $this->assertSame(
            round($r['credito'] + $r['taller_repuestos'], 2),
            round(array_sum(array_column($r['articulos'], 'monto')), 2),
        );
    }

    public function test_articulos_se_agrupan_por_nombre_normalizado_y_el_mismo_nombre_en_dos_origenes_son_dos_filas(): void
    {
        $this->cargo(['descripcion' => 'Cadena kmc', 'cantidad' => 1, 'monto' => 10]);
        $this->cargo(['descripcion' => '  CADENA   KMC ', 'cantidad' => 2, 'monto' => 20]);
        $this->ticket([], [['cadena kmc', 1, 8]]);

        $articulos = $this->hoy()['articulos'];

        $this->assertCount(2, $articulos);
        $this->assertEquals(['nombre' => 'CADENA KMC', 'origen' => 'tienda', 'cantidad' => 3.0, 'monto' => 30.0], $articulos[0]);
        $this->assertEquals(['nombre' => 'CADENA KMC', 'origen' => 'taller', 'cantidad' => 1.0, 'monto' => 8.0], $articulos[1]);
    }

    public function test_articulos_van_ordenados_por_monto_descendente(): void
    {
        $this->cargo(['descripcion' => 'Barato', 'monto' => 5]);
        $this->cargo(['descripcion' => 'Caro', 'monto' => 500]);
        $this->ticket([], [['Medio', 1, 50]]);

        $this->assertSame(['CARO', 'MEDIO', 'BARATO'], array_column($this->hoy()['articulos'], 'nombre'));
    }

    public function test_repuestos_de_un_trabajo_de_la_tienda_con_precio_cero_no_inflan_ni_rompen_el_listado(): void
    {
        $this->ticket(['tipo' => 'armado_interno', 'cliente_id' => null, 'monto_servicio' => null, 'tipo_servicio' => 'armado'], [['Tornillo', 4, 0], ['Cable', 1, 0]]);

        $r = $this->hoy();

        $this->assertSame([], $r['articulos']);
        $this->assertSame(0.0, $r['taller_repuestos']);
        $this->assertSame(0.0, $r['total']);
        $this->assertSame(1, $r['tickets_atendidos'], 'el ticket si cuenta como atendido');
    }

    // ---- criterio 6: bordes del rango (inclusivo, por dias completos) ----
    public function test_borde_del_cargo_el_ultimo_dia_entra_y_el_dia_siguiente_no(): void
    {
        $this->cargo(['fecha' => Carbon::parse('2026-10-10 23:59:00'), 'descripcion' => 'Ultimo dia', 'monto' => 1]);
        $this->cargo(['fecha' => '2026-10-11', 'descripcion' => 'Dia siguiente', 'monto' => 100]);
        $this->cargo(['fecha' => '2026-10-03', 'descripcion' => 'Dia anterior', 'monto' => 1000]);
        $this->cargo(['fecha' => '2026-10-04', 'descripcion' => 'Primer dia', 'monto' => 10]);

        $r = $this->servicio->resumen(Carbon::parse('2026-10-04'), Carbon::parse('2026-10-10'));

        $this->assertSame(11.0, $r['credito']);
    }

    public function test_borde_del_ticket_00_01_del_primer_dia_entra_y_23_59_del_ultimo_tambien_pero_fuera_no(): void
    {
        $this->ticket(['atendido_en' => '2026-10-04 00:01:00', 'monto_servicio' => 1]);
        $this->ticket(['atendido_en' => '2026-10-10 23:59:59', 'monto_servicio' => 10]);
        $this->ticket(['atendido_en' => '2026-10-03 23:59:59', 'monto_servicio' => 100]);
        $this->ticket(['atendido_en' => '2026-10-11 00:00:00', 'monto_servicio' => 1000]);

        $r = $this->servicio->resumen(Carbon::parse('2026-10-04'), Carbon::parse('2026-10-10'));

        $this->assertSame(11.0, $r['taller_servicio']);
        $this->assertSame(2, $r['tickets_atendidos']);
    }

    // ---- rangos predefinidos (zona horaria de la app) ----
    public function test_resolver_rango_presets_y_libre(): void
    {
        $dias = fn (string $rango, ?string $d = null, ?string $h = null) => array_map(fn ($c) => $c->toDateString(), $this->servicio->resolverRango($rango, $d, $h));

        $this->assertSame(['2026-10-22', '2026-10-22'], $dias('hoy'));
        $this->assertSame(['2026-10-16', '2026-10-22'], $dias('7_dias'));
        $this->assertSame(['2026-10-09', '2026-10-22'], $dias('14_dias'));
        $this->assertSame(['2026-10-01', '2026-10-22'], $dias('mes'));
        $this->assertSame(['2026-09-01', '2026-09-30'], $dias('libre', '2026-09-01', '2026-09-30'));
    }

    public function test_hoy_usa_la_zona_horaria_de_la_app_no_utc(): void
    {
        // 23:30 en Caracas del 22 = 03:30 UTC del 23: la venta sigue siendo del 22
        Carbon::setTestNow(Carbon::parse('2026-10-22 23:30:00', 'America/Caracas'));
        $this->ticket(['atendido_en' => '2026-10-22 23:15:00', 'monto_servicio' => 20]);

        [$desde, $hasta] = $this->servicio->resolverRango('hoy');

        $this->assertSame('America/Caracas', config('app.timezone'));
        $this->assertSame('2026-10-22', $desde->toDateString());
        $this->assertSame(20.0, $this->servicio->resumen($desde, $hasta)['taller_servicio']);
    }
}
