<?php

namespace Tests\Feature;

use App\Models\TicketTaller;
use App\Models\User;
use App\Services\Taller\FechaCompromiso;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

// Fecha de compromiso: defecto segun config, etiqueta/grupo derivados, bordes de dia en
// America/Caracas, orden de la lista y contadores
class FechaCompromisoTest extends TestCase
{
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $migraciones = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => str_contains($p, 'replace_procesado_con_estado_en_prospectos_200k'))
            ->values()->all();
        Artisan::call('migrate', ['--path' => $migraciones, '--realpath' => true]);

        $this->usuario = User::factory()->create(['rol_taller' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ahora(string $fechaHora): void
    {
        Carbon::setTestNow(Carbon::parse($fechaHora, 'America/Caracas'));
    }

    private function dia(string $fecha): Carbon
    {
        return Carbon::parse($fecha, 'America/Caracas');
    }

    private function ticket(array $datos = []): TicketTaller
    {
        return TicketTaller::create([
            'tipo' => 'armado_interno',
            'bici_marca_modelo' => 'Trek Marlin',
            'categoria_bici' => 'mtb',
            'talla_rin' => '29',
            'es_electrica' => false,
            'diagnostico' => [],
            'mecanico_id' => $this->usuario->id,
            'registrado_por' => $this->usuario->id,
            ...$datos,
        ]);
    }

    // ---- criterio 1: defecto y regla en config ----
    public function test_el_defecto_es_manana_saltando_el_domingo(): void
    {
        $this->assertSame('America/Caracas', config('app.timezone'));

        // 2026-10-22 es jueves
        $this->assertSame('2026-10-23', FechaCompromiso::porDefecto($this->dia('2026-10-22'))->toDateString(), 'jueves -> viernes');
        $this->assertSame('2026-10-24', FechaCompromiso::porDefecto($this->dia('2026-10-23'))->toDateString(), 'viernes -> sabado');
        $this->assertSame('2026-10-26', FechaCompromiso::porDefecto($this->dia('2026-10-24'))->toDateString(), 'sabado -> lunes');
        $this->assertSame('2026-10-26', FechaCompromiso::porDefecto($this->dia('2026-10-25'))->toDateString(), 'domingo -> lunes');
    }

    public function test_la_regla_vive_en_config_sin_dias_cerrados_el_sabado_da_domingo(): void
    {
        config(['taller.dias_cerrados' => []]);

        $this->assertSame('2026-10-25', FechaCompromiso::porDefecto($this->dia('2026-10-24'))->toDateString());

        config(['taller.dias_cerrados' => [0, 1]]);

        $this->assertSame('2026-10-27', FechaCompromiso::porDefecto($this->dia('2026-10-24'))->toDateString(), 'cerrado domingo y lunes -> martes');
    }

    public function test_si_todos_los_dias_estuvieran_cerrados_no_se_cuelga(): void
    {
        config(['taller.dias_cerrados' => [0, 1, 2, 3, 4, 5, 6]]);

        $this->assertSame('2026-10-23', FechaCompromiso::porDefecto($this->dia('2026-10-22'))->toDateString());
    }

    public function test_los_atajos_muestran_la_fecha_real_y_marcan_el_defecto_y_los_dias_cerrados(): void
    {
        $opciones = FechaCompromiso::opciones($this->dia('2026-10-24')); // sabado

        $this->assertSame(['2026-10-25', '2026-10-26', '2026-10-27'], array_column($opciones, 'fecha'));
        $this->assertSame([true, false, false], array_column($opciones, 'cerrado'), 'el domingo sale cerrado');
        $this->assertSame([false, true, false], array_column($opciones, 'por_defecto'), 'el lunes es el defecto');
        $this->assertSame(['Dom', 'Lun', 'Mar'], array_column($opciones, 'dia'));
    }

    public function test_si_el_defecto_cae_mas_alla_de_3_dias_se_agrega_como_atajo(): void
    {
        config(['taller.dias_cerrados' => [0, 1, 2, 3]]);

        $opciones = FechaCompromiso::opciones($this->dia('2026-10-24'));

        // cerrados dom-mie: desde el sabado 24 el primer dia abierto es el jueves 29 (+5)
        $this->assertSame(['2026-10-25', '2026-10-26', '2026-10-27', '2026-10-29'], array_column($opciones, 'fecha'));
        $this->assertSame('2026-10-29', collect($opciones)->firstWhere('por_defecto', true)['fecha']);
    }

    // ---- etiqueta y grupo ----
    public function test_etiquetas_derivadas(): void
    {
        $hoy = $this->dia('2026-10-22');

        $this->assertSame('Sin fecha', FechaCompromiso::etiqueta(null, 'en_proceso', $hoy));
        $this->assertSame('Atrasado 3 d', FechaCompromiso::etiqueta($this->dia('2026-10-19'), 'en_proceso', $hoy));
        $this->assertSame('Vence hoy', FechaCompromiso::etiqueta($this->dia('2026-10-22'), 'en_proceso', $hoy));
        $this->assertSame('Mañana', FechaCompromiso::etiqueta($this->dia('2026-10-23'), 'en_proceso', $hoy));
        $this->assertSame('Sáb 24', FechaCompromiso::etiqueta($this->dia('2026-10-24'), 'en_proceso', $hoy));
        $this->assertSame('Mié 11', FechaCompromiso::etiqueta($this->dia('2026-11-11'), 'en_proceso', $hoy));
        $this->assertSame('Lun 19', FechaCompromiso::etiqueta($this->dia('2026-10-19'), 'atendido', $hoy), 'un atendido no esta atrasado');
    }

    public function test_grupos_de_la_lista(): void
    {
        $hoy = $this->dia('2026-10-22'); // jueves; la semana termina el domingo 25

        $grupo = fn (?string $f, string $estado = 'en_proceso') => FechaCompromiso::grupo($f ? $this->dia($f) : null, $estado, $hoy);

        $this->assertSame('atrasados', $grupo('2026-10-21'));
        $this->assertSame('hoy', $grupo('2026-10-22'));
        $this->assertSame('manana', $grupo('2026-10-23'));
        $this->assertSame('semana', $grupo('2026-10-24'));
        $this->assertSame('semana', $grupo('2026-10-25'));
        $this->assertSame('despues', $grupo('2026-10-26'));
        $this->assertSame('sin_fecha', $grupo(null));
        $this->assertNull($grupo('2026-10-21', 'atendido'), 'los atendidos no llevan grupo');
    }

    // ---- criterio 5: bordes de dia en America/Caracas ----
    public function test_un_ticket_con_fecha_de_hoy_no_esta_atrasado_ni_a_las_23_59_ni_a_las_00_01(): void
    {
        $fecha = $this->dia('2026-10-22');

        $this->ahora('2026-10-22 23:59:00');
        $this->assertSame('Vence hoy', FechaCompromiso::etiqueta($fecha));
        $this->assertSame('hoy', FechaCompromiso::grupo($fecha));

        // 00:01 del dia siguiente: la fecha de ESE dia sigue siendo "hoy", la del anterior ya esta atrasada
        $this->ahora('2026-10-23 00:01:00');
        $this->assertSame('Vence hoy', FechaCompromiso::etiqueta($this->dia('2026-10-23')));
        $this->assertSame('Atrasado 1 d', FechaCompromiso::etiqueta($fecha));
        $this->assertSame('atrasados', FechaCompromiso::grupo($fecha));
    }

    public function test_a_las_23_30_de_caracas_ya_es_el_dia_siguiente_en_utc_pero_la_fecha_sigue_siendo_hoy(): void
    {
        $this->ahora('2026-10-22 23:30:00'); // 03:30 UTC del 23

        $this->assertSame('2026-10-23', FechaCompromiso::porDefecto()->toDateString());
        $this->assertSame('Vence hoy', FechaCompromiso::etiqueta($this->dia('2026-10-22')));
    }

    // ---- criterio 4: orden ----
    public function test_orden_de_los_en_proceso_atrasado_hoy_manana_futuro_sin_fecha_y_desempate_por_created_at(): void
    {
        $this->ahora('2026-10-22 10:00:00');

        $sinFecha = $this->ticket(['fecha_compromiso' => null]);
        $futuro = $this->ticket(['fecha_compromiso' => '2026-10-30']);
        $manana = $this->ticket(['fecha_compromiso' => '2026-10-23']);
        $hoyNuevo = $this->ticket(['fecha_compromiso' => '2026-10-22']);
        $hoyViejo = $this->ticket(['fecha_compromiso' => '2026-10-22']);
        $atrasado = $this->ticket(['fecha_compromiso' => '2026-10-19']);

        // mismo dia: el mas viejo primero
        $hoyViejo->forceFill(['created_at' => '2026-10-20 08:00:00'])->saveQuietly();
        $hoyNuevo->forceFill(['created_at' => '2026-10-21 08:00:00'])->saveQuietly();

        $ids = FechaCompromiso::ordenarEnProceso(TicketTaller::query())->pluck('id')->all();

        $this->assertSame([$atrasado->id, $hoyViejo->id, $hoyNuevo->id, $manana->id, $futuro->id, $sinFecha->id], $ids);
    }

    public function test_el_listado_pone_los_en_proceso_arriba_y_los_atendidos_conservan_su_orden(): void
    {
        $this->ahora('2026-10-22 10:00:00');

        $enProcesoManana = $this->ticket(['fecha_compromiso' => '2026-10-23']);
        $enProcesoAtrasado = $this->ticket(['fecha_compromiso' => '2026-10-20']);
        $atendidoViejo = $this->ticket(['fecha_compromiso' => '2026-10-19']);
        $atendidoNuevo = $this->ticket(['fecha_compromiso' => '2026-10-30']);
        $atendidoViejo->forceFill(['estado' => 'atendido', 'created_at' => '2026-10-01 08:00:00'])->saveQuietly();
        $atendidoNuevo->forceFill(['estado' => 'atendido', 'created_at' => '2026-10-10 08:00:00'])->saveQuietly();

        $ids = FechaCompromiso::ordenarListado(TicketTaller::query())->pluck('id')->all();

        $this->assertSame([$enProcesoAtrasado->id, $enProcesoManana->id, $atendidoNuevo->id, $atendidoViejo->id], $ids);
    }

    public function test_contadores_solo_cuentan_tickets_en_proceso(): void
    {
        $this->ahora('2026-10-22 10:00:00');

        $this->ticket(['fecha_compromiso' => '2026-10-20']);
        $this->ticket(['fecha_compromiso' => '2026-10-21']);
        $this->ticket(['fecha_compromiso' => '2026-10-22']);
        $this->ticket(['fecha_compromiso' => '2026-10-23']);
        $this->ticket(['fecha_compromiso' => '2026-10-23']);
        $this->ticket(['fecha_compromiso' => '2026-10-24']);
        $this->ticket(['fecha_compromiso' => null]);
        $this->ticket(['fecha_compromiso' => '2026-10-20'])->forceFill(['estado' => 'atendido'])->saveQuietly();

        $this->assertSame(['atrasados' => 2, 'hoy' => 1, 'manana' => 2], FechaCompromiso::contadores());
    }

    public function test_texto_para_whatsapp(): void
    {
        $this->assertSame('viernes 23/10', FechaCompromiso::textoEntrega($this->dia('2026-10-23')));
        $this->assertSame('por confirmar', FechaCompromiso::textoEntrega(null));
    }
}
