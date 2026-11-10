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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// Lote 6: cierre sin trabas (foto opcional con confirmacion), fecha del trabajo, indicador "por cobrar" y metodo de pago
class TallerCierreFlexibleTest extends TestCase
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

    // equivalente al #59: En proceso, creado el 05/11, Basico $15
    private function ticket59(array $extra = [], ?Cliente $cliente = null): TicketTaller
    {
        $t = TicketTaller::create([
            'tipo' => 'servicio_cliente', 'cliente_id' => ($cliente ?? $this->cliente)->id, 'tipo_servicio' => 'basico', 'monto_servicio' => 15,
            'motivo_ingreso' => 'revision', 'bici_marca_modelo' => 'MONDRAKER BLANCA MTB', 'categoria_bici' => 'mtb', 'talla_rin' => '29',
            'es_electrica' => false, 'diagnostico' => [], 'mecanico_id' => $this->tecnico->id, 'registrado_por' => $this->tecnico->id,
            ...$extra,
        ]);
        $t->forceFill(['created_at' => '2026-11-05 09:00:00'])->save();

        return $t;
    }

    private function conFoto(TicketTaller $t): TicketTaller
    {
        $t->addMedia(UploadedFile::fake()->image('entrada.jpg', 600, 900))->toMediaCollection('entrada');

        return $t;
    }

    private function cerrar(TicketTaller $t, array $datos = [])
    {
        return $this->actingAs($this->tecnico)->patch("/taller/{$t->id}/marcar-atendido", [
            'pagado_en_taller' => false, 'texto_cliente' => 'sin_texto', ...$datos,
        ]);
    }

    private function eventos(string $descripcion)
    {
        return Activity::where('description', $descripcion);
    }

    // ---- A0: reproduccion del #59 ----
    public function test_a0_cada_requisito_falta_por_separado_y_la_foto_recien_subida_cuenta_de_inmediato(): void
    {
        $t = $this->ticket59();

        // sin texto ni revision: el servidor rechaza por el texto (la foto ya no es el obstaculo)
        $this->cerrar($t, ['confirmar_sin_foto' => true])->assertSessionHasErrors('trabajo_realizado');

        // sube la foto: la ficha la ve en la MISMA respuesta (sin recargar ni guardar)
        $this->actingAs($this->tecnico)->post("/taller/{$t->id}/fotos/entrada", ['fotos' => [UploadedFile::fake()->image('e.jpg', 600, 900)]])->assertRedirect();
        $this->actingAs($this->tecnico)->get("/taller/{$t->id}")->assertInertia(fn ($p) => $p->has('ticket.fotos_entrada', 1)->where('ticket.trabajo_realizado', null)->where('hoy', '2026-11-10'));

        // con texto guardado y foto cierra sin mas
        $t->update(['trabajo_realizado' => 'Cambio de cámara']);
        $this->cerrar($t)->assertRedirect();
        $this->assertSame('atendido', $t->fresh()->estado);
    }

    // ---- Bloque A: foto opcional con confirmacion ----
    public function test_sin_foto_exige_confirmar_y_al_aceptar_cierra_y_deja_el_evento(): void
    {
        $t = $this->ticket59(['trabajo_realizado' => 'Cambio de cámara']);

        $this->cerrar($t)->assertSessionHasErrors('fotos_entrada');
        $this->assertSame('en_proceso', $t->fresh()->estado);
        $this->assertSame(0, $this->eventos('cierre_sin_foto')->count());

        $this->cerrar($t, ['confirmar_sin_foto' => true])->assertRedirect();

        $this->assertSame('atendido', $t->fresh()->estado);
        $e = $this->eventos('cierre_sin_foto')->firstOrFail();
        $this->assertSame($t->id, (int) $e->subject_id);
        $this->assertSame($this->tecnico->id, (int) $e->causer_id);
    }

    public function test_con_foto_cierra_sin_confirmacion_y_sin_evento(): void
    {
        $t = $this->conFoto($this->ticket59(['trabajo_realizado' => 'Cambio de cámara']));

        $this->cerrar($t)->assertRedirect();

        $this->assertSame('atendido', $t->fresh()->estado);
        $this->assertSame(0, $this->eventos('cierre_sin_foto')->count());
    }

    public function test_texto_y_respuesta_de_pago_siguen_siendo_obligatorios(): void
    {
        $t = $this->conFoto($this->ticket59());
        $this->cerrar($t)->assertSessionHasErrors('trabajo_realizado');

        $t->update(['trabajo_realizado' => 'x']);
        $this->actingAs($this->tecnico)->patch("/taller/{$t->id}/marcar-atendido", ['texto_cliente' => 'sin_texto'])->assertSessionHasErrors('pagado_en_taller');
    }

    // ---- Bloque B: fecha del trabajo ----
    public function test_cerrar_con_fecha_05_11_guarda_las_18_y_registra_el_ajuste_y_el_cargo_conserva_su_fecha(): void
    {
        $t = $this->conFoto($this->ticket59(['trabajo_realizado' => 'ok']));

        $this->cerrar($t, ['fecha_trabajo' => '2026-11-05'])->assertRedirect();

        $t->refresh();
        $this->assertSame('2026-11-05 18:00:00', $t->atendido_en->toDateTimeString());
        $this->assertFalse((bool) $t->atendido_en_aprox);
        $e = $this->eventos('atendido_en_ajustado')->firstOrFail();
        $this->assertSame($this->tecnico->id, (int) $e->causer_id);
        $this->assertSame('2026-11-05 18:00:00', $e->properties['nuevo']);
        $this->assertSame('2026-11-10', MovimientoCuenta::findOrFail($t->movimiento_cuenta_id)->fecha->toDateString(), 'el cargo sigue con la fecha real de generacion');
    }

    public function test_fecha_de_hoy_usa_la_hora_actual_y_no_registra_ajuste(): void
    {
        $t = $this->conFoto($this->ticket59(['trabajo_realizado' => 'ok']));

        $this->cerrar($t, ['fecha_trabajo' => '2026-11-10'])->assertRedirect();

        $this->assertSame('2026-11-10 10:00:00', $t->fresh()->atendido_en->toDateTimeString());
        $this->assertSame(0, $this->eventos('atendido_en_ajustado')->count());
    }

    public function test_fecha_futura_o_anterior_a_la_creacion_da_422(): void
    {
        $t = $this->conFoto($this->ticket59(['trabajo_realizado' => 'ok']));

        $this->cerrar($t, ['fecha_trabajo' => '2026-11-11'])->assertSessionHasErrors('fecha_trabajo');
        $this->cerrar($t, ['fecha_trabajo' => '2026-11-04'])->assertSessionHasErrors('fecha_trabajo');
        $this->assertSame('en_proceso', $t->fresh()->estado);
    }

    public function test_un_ticket_cerrado_hoy_con_fecha_05_11_cuenta_en_esa_semana_y_no_en_la_actual(): void
    {
        $t = $this->conFoto($this->ticket59(['trabajo_realizado' => 'ok']));
        $this->cerrar($t, ['fecha_trabajo' => '2026-11-05'])->assertRedirect();

        $semanaDelTrabajo = ComisionesTaller::calcular(Carbon::parse('2026-11-02'), Carbon::parse('2026-11-07'), 50, 5);
        $semanaDelCierre = ComisionesTaller::calcular(Carbon::parse('2026-11-09'), Carbon::parse('2026-11-14'), 50, 5);

        $this->assertSame(1, $semanaDelTrabajo['porMecanico'][0]['tickets_atendidos']);
        $this->assertCount(0, $semanaDelCierre['porMecanico']);
    }

    // ---- Bloque C: indicador por cobrar ----
    private function aCuenta(Cliente $cliente, float $monto = 15): TicketTaller
    {
        $t = $this->conFoto($this->ticket59(['trabajo_realizado' => 'ok', 'monto_servicio' => $monto], $cliente));
        $this->cerrar($t)->assertRedirect();

        return $t->fresh();
    }

    private function cobroDeLista(): array
    {
        $tickets = null;
        $this->actingAs($this->tecnico)->get('/taller')->assertInertia(function ($p) use (&$tickets) {
            $tickets = collect($p->toArray()['props']['tickets']['data'])->keyBy('id')->all();
        });

        return $tickets;
    }

    public function test_a_cuenta_con_deuda_es_ambar_con_el_saldo_de_la_ficha_y_con_saldo_cero_pasa_a_cobrado(): void
    {
        $t = $this->aCuenta($this->cliente);
        MovimientoCuenta::create(['cliente_id' => $this->cliente->id, 'fecha' => '2026-11-10', 'tipo' => 'cargo', 'descripcion' => 'Otra deuda', 'cantidad' => 1, 'precio_unitario' => 35, 'monto' => 35, 'moneda' => 'usd', 'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'mensual', 'registrado_por' => $this->tecnico->id]);

        $esperado = MovimientoCuenta::saldoPendiente($this->cliente->id); // 15 del ticket + 35
        $this->assertSame(50.0, $esperado);
        $this->assertEquals(['estado' => 'por_cobrar', 'saldo' => 50.0], $this->cobroDeLista()[$t->id]['cobro']);
        $this->actingAs($this->tecnico)->get("/taller/{$t->id}")->assertInertia(fn ($p) => $p->where('ticket.cobro.estado', 'por_cobrar')->where('ticket.cobro.saldo', 50));

        MovimientoCuenta::create(['cliente_id' => $this->cliente->id, 'fecha' => '2026-11-10', 'tipo' => 'abono', 'descripcion' => 'Pago', 'monto' => 50, 'moneda' => 'usd', 'metodo_pago' => 'zelle', 'referencia' => 'Z', 'registrado_por' => $this->tecnico->id]);
        $this->assertEquals(['estado' => 'cobrado', 'saldo' => 0.0], $this->cobroDeLista()[$t->id]['cobro']);
    }

    public function test_pagado_en_el_taller_es_verde_pagado(): void
    {
        $t = $this->conFoto($this->ticket59(['trabajo_realizado' => 'ok']));
        $this->cerrar($t, ['pagado_en_taller' => true])->assertRedirect();

        $this->assertSame('pagado', $this->cobroDeLista()[$t->id]['cobro']['estado']);
    }

    public function test_filtro_por_cobrar_trae_solo_los_atendidos_a_cuenta_con_deuda_y_sin_n_mas_1(): void
    {
        $deudores = [];
        foreach (['Ana', 'Beto', 'Cata', 'Dani', 'Eli'] as $i => $nombre) {
            $c = Cliente::create(['nombre' => $nombre, 'telefono' => '+58414000000'.$i]);
            $deudores[] = $this->aCuenta($c)->id;
        }
        // cobrado del todo: sale del filtro
        $cobrado = Cliente::create(['nombre' => 'Fran', 'telefono' => '+584140000099']);
        $tCobrado = $this->aCuenta($cobrado);
        MovimientoCuenta::create(['cliente_id' => $cobrado->id, 'fecha' => '2026-11-10', 'tipo' => 'abono', 'descripcion' => 'Pago', 'monto' => 15, 'moneda' => 'usd', 'metodo_pago' => 'zelle', 'referencia' => 'Z', 'registrado_por' => $this->tecnico->id]);
        // pagado en el taller y en proceso: tampoco
        $pagado = $this->conFoto($this->ticket59(['trabajo_realizado' => 'ok'], Cliente::create(['nombre' => 'Gus', 'telefono' => '+584140000098'])));
        $this->cerrar($pagado, ['pagado_en_taller' => true])->assertRedirect();
        $this->ticket59([], Cliente::create(['nombre' => 'Hugo', 'telefono' => '+584140000097']));

        DB::enableQueryLog();
        $ids = null;
        $this->actingAs($this->tecnico)->get('/taller?por_cobrar=1')->assertInertia(function ($p) use (&$ids) {
            $ids = collect($p->toArray()['props']['tickets']['data'])->pluck('id')->sort()->values()->all();
        });
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(collect($deudores)->sort()->values()->all(), $ids);
        $this->assertNotContains($tCobrado->id, $ids);
        $this->assertLessThan(60, $consultas, 'sin una consulta de saldo por ticket');
    }

    // ---- Bloque D: metodo de pago ----
    public function test_editar_una_compra_sin_metodo_no_guarda_efectivo_y_una_con_metodo_lo_conserva(): void
    {
        $admin = User::factory()->create(['es_admin' => true]);
        $base = ['cliente_id' => $this->cliente->id, 'fecha' => '2026-11-01', 'tipo' => 'cargo', 'descripcion' => 'Pedales', 'cantidad' => 1, 'precio_unitario' => 100, 'monto' => 100, 'moneda' => 'usd', 'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'mensual', 'registrado_por' => $admin->id];
        $sin = MovimientoCuenta::create($base);
        $con = MovimientoCuenta::create([...$base, 'metodo_pago' => 'zelle', 'referencia' => 'Z1']);

        $payload = ['fecha' => '2026-11-01', 'descripcion' => 'Pedales', 'moneda' => 'usd', 'cantidad' => 1, 'precio_unitario' => 100, 'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'mensual', 'motivo_edicion' => 'prueba'];

        $this->actingAs($admin)->put("/movimientos/{$sin->id}", [...$payload, 'metodo_pago' => ''])->assertRedirect();
        $this->assertNull($sin->fresh()->metodo_pago, 'no se inyecta efectivo');

        $this->actingAs($admin)->put("/movimientos/{$con->id}", $payload)->assertRedirect();
        $this->assertSame('zelle', $con->fresh()->metodo_pago, 'conserva el que tenia');
    }
}
