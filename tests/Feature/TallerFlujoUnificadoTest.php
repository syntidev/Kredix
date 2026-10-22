<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\TicketTaller;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

// Flujo unificado de Taller: sin selector de tipo (la UI decide por presencia de
// cliente), "Incluye armado" con monto opcional, trabajo de la tienda sin cifras
// y total destacado en la lista
class TallerFlujoUnificadoTest extends TestCase
{
    private User $usuario;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        // mismo esquema real que EntregaATallerTest (salta la migracion vieja de sqlite)
        $migraciones = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => str_contains($p, 'replace_procesado_con_estado_en_prospectos_200k'))
            ->values()->all();
        Artisan::call('migrate', ['--path' => $migraciones, '--realpath' => true]);

        Storage::fake('public');
        config(['ia.activa' => false, 'taller.revision_tecnica_activa' => true, 'taller.trabajo_tienda_activo' => true]);
        $this->usuario = User::factory()->create(['name' => 'Kleiver', 'rol_taller' => true]);
        $this->cliente = Cliente::create(['nombre' => 'Ana Perez', 'telefono' => '+584141234567']);
    }

    private function datos(array $extra = []): array
    {
        return [
            'motivo_ingreso' => 'frena mal',
            'bici_marca_modelo' => 'GW nueva',
            'categoria_bici' => 'mtb',
            'mecanico_id' => $this->usuario->id,
            'fotos_entrada' => [UploadedFile::fake()->image('entrada.jpg', 600, 900)],
            ...$extra,
        ];
    }

    private function crear(array $extra)
    {
        return $this->actingAs($this->usuario)->post('/taller', $this->datos($extra));
    }

    /** Ticket listo para cerrar: fotos y revision. */
    private function ticket(array $datos = []): TicketTaller
    {
        $t = TicketTaller::create([
            'tipo' => 'servicio_cliente',
            'cliente_id' => $this->cliente->id,
            'tipo_servicio' => 'basico',
            'monto_servicio' => 15,
            'motivo_ingreso' => 'frena mal',
            'bici_marca_modelo' => 'Trek Marlin',
            'categoria_bici' => 'mtb',
            'talla_rin' => '29',
            'es_electrica' => false,
            'diagnostico' => [],
            'mecanico_id' => $this->usuario->id,
            'registrado_por' => $this->usuario->id,
            'revision_tecnica' => ['tareas' => [], 'componentes' => ['cadena' => ['acciones' => ['lubricado']]]],
            ...$datos,
        ]);
        foreach (['entrada', 'salida'] as $coleccion) {
            $t->addMedia(UploadedFile::fake()->image("$coleccion.jpg", 600, 900))->toMediaCollection($coleccion);
        }

        return $t;
    }

    private function tienda(array $datos = []): TicketTaller
    {
        return $this->ticket([
            'tipo' => 'armado_interno', 'cliente_id' => null, 'tipo_servicio' => null, 'monto_servicio' => null,
            'motivo_ingreso' => 'armar bici nueva', ...$datos,
        ]);
    }

    private function cerrar(TicketTaller $t, array $extra = [])
    {
        return $this->actingAs($this->usuario)->patch("/taller/{$t->id}/marcar-atendido", ['texto_cliente' => 'sin_texto', ...$extra]);
    }

    // ---- 1. cliente + servicio ----
    public function test_con_cliente_el_ticket_es_servicio_sin_enviar_tipo(): void
    {
        $this->crear(['cliente_id' => $this->cliente->id, 'tipo_servicio' => 'basico', 'monto_servicio' => 15])->assertRedirect();

        $t = TicketTaller::firstOrFail();
        $this->assertSame('servicio_cliente', $t->tipo);
        $this->assertSame($this->cliente->id, $t->cliente_id);
        $this->assertFalse($t->incluye_armado);
        $this->assertNull($t->monto_armado);
        $this->assertSame(15.0, $t->totalTicket());
    }

    // ---- 2. cliente + incluye armado, con y sin monto ----
    public function test_incluye_armado_con_monto_suma_al_total(): void
    {
        $this->crear(['cliente_id' => $this->cliente->id, 'tipo_servicio' => 'basico', 'monto_servicio' => 15, 'incluye_armado' => true, 'monto_armado' => 25])->assertRedirect();

        $t = TicketTaller::firstOrFail();
        $this->assertTrue($t->incluye_armado);
        $this->assertSame(25.0, (float) $t->monto_armado);
        $this->assertSame(40.0, $t->totalTicket());
    }

    public function test_incluye_armado_sin_monto_marca_pero_no_suma(): void
    {
        $this->crear(['cliente_id' => $this->cliente->id, 'tipo_servicio' => 'basico', 'monto_servicio' => 15, 'incluye_armado' => true, 'monto_armado' => ''])->assertRedirect();

        $t = TicketTaller::firstOrFail();
        $this->assertTrue($t->incluye_armado);
        $this->assertNull($t->monto_armado);
        $this->assertSame(15.0, $t->totalTicket());
    }

    public function test_el_cargo_al_cerrar_usa_el_total_con_armado_y_repuestos(): void
    {
        $t = $this->ticket(['incluye_armado' => true, 'monto_armado' => 25]);
        $t->repuestos()->create(['producto' => 'Cadena', 'cantidad' => 2, 'precio' => 5]);

        $this->cerrar($t, ['pagado_en_taller' => false])->assertRedirect();

        // 15 servicio + 25 armado + 2 x 5 repuestos
        $this->assertSame(50.0, (float) MovimientoCuenta::where('tipo', 'cargo')->firstOrFail()->monto);
    }

    // ---- 3. cliente + tipo de servicio Armado ----
    public function test_tipo_de_servicio_armado_no_acepta_incluye_armado(): void
    {
        $this->crear(['cliente_id' => $this->cliente->id, 'tipo_servicio' => 'armado', 'monto_servicio' => 30, 'incluye_armado' => true, 'monto_armado' => 25])->assertRedirect();

        $t = TicketTaller::firstOrFail();
        $this->assertSame('armado', $t->tipo_servicio);
        $this->assertFalse($t->incluye_armado);
        $this->assertNull($t->monto_armado);
        $this->assertSame(30.0, $t->totalTicket());
    }

    // ---- 4. trabajo de la tienda ----
    public function test_trabajo_de_la_tienda_no_guarda_cliente_ni_cifras(): void
    {
        // aunque el cliente envie cifras o cliente, el servidor no los registra
        $this->crear([
            'trabajo_tienda' => true, 'cliente_id' => $this->cliente->id, 'tipo_servicio' => 'basico',
            'monto_servicio' => 15, 'incluye_armado' => true, 'monto_armado' => 25,
        ])->assertRedirect();

        $t = TicketTaller::firstOrFail();
        $this->assertSame('armado_interno', $t->tipo);
        $this->assertNull($t->cliente_id);
        $this->assertNull($t->tipo_servicio);
        $this->assertNull($t->monto_servicio);
        $this->assertFalse($t->incluye_armado);
        $this->assertNull($t->monto_armado);
        $this->assertNull($t->totalVisible());
    }

    public function test_trabajo_de_la_tienda_cierra_sin_cargo_ni_pregunta(): void
    {
        $t = $this->tienda();

        $this->cerrar($t)->assertRedirect();

        $this->assertSame(0, MovimientoCuenta::count());
        $this->assertSame('atendido', $t->fresh()->estado);
    }

    // ---- 5. sin cliente y sin trabajo de la tienda ----
    public function test_sin_cliente_y_sin_trabajo_de_la_tienda_responde_422(): void
    {
        $this->actingAs($this->usuario)
            ->postJson('/taller', $this->datos(['tipo_servicio' => 'basico', 'monto_servicio' => 15]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cliente_id' => 'Trabajo de la tienda']);

        $this->assertSame(0, TicketTaller::count());
    }

    // ---- edicion ----
    public function test_se_asigna_cliente_a_un_trabajo_de_la_tienda_existente(): void
    {
        $t = $this->tienda();

        $this->actingAs($this->usuario)->put("/taller/{$t->id}", [
            'cliente_id' => $this->cliente->id, 'motivo_ingreso' => 'armar bici nueva', 'bici_marca_modelo' => 'Trek Marlin',
            'categoria_bici' => 'mtb', 'mecanico_id' => $this->usuario->id, 'tipo_servicio' => 'armado', 'monto_servicio' => 30,
        ])->assertRedirect();

        $t->refresh();
        $this->assertSame('servicio_cliente', $t->tipo);
        $this->assertSame($this->cliente->id, $t->cliente_id);
        $this->assertSame(30.0, $t->totalTicket());
    }

    public function test_no_se_quita_el_cliente_de_un_ticket_ya_cargado_a_su_cuenta(): void
    {
        $t = $this->ticket();
        $this->cerrar($t, ['pagado_en_taller' => false])->assertRedirect();

        $this->actingAs($this->usuario)->put("/taller/{$t->id}", [
            'trabajo_tienda' => true, 'motivo_ingreso' => 'x', 'bici_marca_modelo' => 'Trek Marlin',
            'categoria_bici' => 'mtb', 'mecanico_id' => $this->usuario->id,
        ])->assertStatus(422);

        $this->assertSame($this->cliente->id, $t->fresh()->cliente_id);
    }

    // ---- lista ----
    public function test_la_lista_trae_total_y_etiqueta_solo_con_cliente(): void
    {
        $enProceso = $this->ticket(['incluye_armado' => true, 'monto_armado' => 25]);
        $pagado = $this->ticket(['estado' => 'atendido', 'pagado_en_taller' => true]);
        $aCuenta = $this->ticket();
        $this->cerrar($aCuenta, ['pagado_en_taller' => false]);
        $tienda = $this->tienda();

        $filas = null;
        $this->actingAs($this->usuario)->get('/taller')
            ->assertInertia(function ($pagina) use (&$filas) {
                $filas = collect($pagina->toArray()['props']['tickets']['data'])->keyBy('id');
            });

        $this->assertSame(40.0, (float) $filas[$enProceso->id]['total']);
        $this->assertSame('acumulado', $filas[$enProceso->id]['etiqueta_total']);
        $this->assertTrue($filas[$enProceso->id]['incluye_armado']);
        $this->assertSame('pagado', $filas[$pagado->id]['etiqueta_total']);
        $this->assertSame('a_cuenta', $filas[$aCuenta->id]['etiqueta_total']);
        $this->assertNull($filas[$tienda->id]['total']);
        $this->assertNull($filas[$tienda->id]['etiqueta_total']);
    }

    // ---- Show y repuestos de un trabajo de la tienda ----
    public function test_show_de_un_trabajo_de_la_tienda_no_trae_cifras(): void
    {
        $t = $this->tienda();
        $t->repuestos()->create(['producto' => 'Cadena', 'cantidad' => 2, 'precio' => 0]);

        $ticket = null;
        $this->actingAs($this->usuario)->get("/taller/{$t->id}")
            ->assertInertia(function ($pagina) use (&$ticket) {
                $ticket = $pagina->toArray()['props']['ticket'];
            });

        $this->assertNull($ticket['total']);
        $this->assertNull($ticket['etiqueta_total']);
        $this->assertArrayNotHasKey('precio', $ticket['repuestos'][0]);
    }

    public function test_un_repuesto_de_un_trabajo_de_la_tienda_se_agrega_sin_precio(): void
    {
        $t = $this->tienda();

        $this->actingAs($this->usuario)->post("/taller/{$t->id}/repuestos", ['producto' => 'Cadena', 'cantidad' => 1])->assertRedirect();

        $this->assertSame(1, $t->repuestos()->count());
    }

    public function test_un_repuesto_de_un_ticket_con_cliente_exige_precio(): void
    {
        $t = $this->ticket();

        $this->actingAs($this->usuario)->post("/taller/{$t->id}/repuestos", ['producto' => 'Cadena', 'cantidad' => 1])
            ->assertSessionHasErrors('precio');
    }

    // ---- regla de lenguaje ----
    private function textoVisible(string $html): string
    {
        return strip_tags(preg_replace('~<style.*?</style>~s', '', $html));
    }

    public function test_pdf_y_bitacora_de_un_trabajo_de_la_tienda_no_tienen_texto_de_cifras(): void
    {
        $t = $this->tienda();
        $t->repuestos()->create(['producto' => 'Cadena', 'cantidad' => 2, 'precio' => 0]);

        $datos = null;
        View::composer('pdf.taller-atencion', function ($vista) use (&$datos) {
            $datos = $vista->getData();
        });
        $this->actingAs($this->usuario)->get("/taller/{$t->id}/atencion-trek.pdf")->assertOk();
        $pdf = $this->textoVisible(view('pdf.taller-atencion', $datos)->render());
        $bitacora = $this->textoVisible($this->actingAs($this->usuario)->get('/taller/bitacora/imprimir')->assertOk()->getContent());

        foreach ([$pdf, $bitacora] as $texto) {
            $this->assertStringContainsString('Trabajo de la tienda', $texto);
            $this->assertDoesNotMatchRegularExpression('/cobro|costo|precio|monto|remuneraci|total|\$/i', $texto);
        }
        $this->assertStringContainsString('Cadena', $pdf);
    }

    // ---- KPI de Taller ----
    private function montoServicioKpi(): float
    {
        $c = new \App\Http\Controllers\KpiController();
        $m = new \ReflectionMethod($c, 'kpiTaller');
        $m->setAccessible(true);

        return (float) $m->invoke($c, \Illuminate\Http\Request::create('/kpi'))['totales']['monto_servicio'];
    }

    public function test_el_kpi_suma_servicio_y_armado_y_la_tienda_aporta_cero(): void
    {
        $this->ticket(['estado' => 'atendido', 'incluye_armado' => true, 'monto_armado' => 25]); // 15 + 25
        $this->ticket(['estado' => 'atendido']); // 15, sin armado: no cambia
        $this->tienda(['estado' => 'atendido']); // 0

        $this->assertSame(55.0, $this->montoServicioKpi());
    }

    // ---- aviso de repuestos sin precio ----
    private function repuestosSinPrecio(TicketTaller $t): array
    {
        $ticket = null;
        $this->actingAs($this->usuario)->get("/taller/{$t->id}")
            ->assertInertia(function ($pagina) use (&$ticket) {
                $ticket = $pagina->toArray()['props']['ticket'];
            });

        return $ticket['repuestos_sin_precio'];
    }

    public function test_el_aviso_lista_los_repuestos_con_precio_cero_de_un_ticket_con_cliente_en_proceso(): void
    {
        $t = $this->ticket();
        $t->repuestos()->create(['producto' => 'Cadena', 'cantidad' => 1, 'precio' => 0]);
        $t->repuestos()->create(['producto' => 'Cauchos', 'cantidad' => 2, 'precio' => 12]);

        $this->assertSame(['Cadena'], $this->repuestosSinPrecio($t));
    }

    public function test_el_aviso_no_aparece_con_precios_atendido_ni_en_la_tienda(): void
    {
        $conPrecio = $this->ticket();
        $conPrecio->repuestos()->create(['producto' => 'Cadena', 'cantidad' => 1, 'precio' => 5]);
        $atendido = $this->ticket(['estado' => 'atendido']);
        $atendido->repuestos()->create(['producto' => 'Cadena', 'cantidad' => 1, 'precio' => 0]);
        $tienda = $this->tienda();
        $tienda->repuestos()->create(['producto' => 'Cadena', 'cantidad' => 1, 'precio' => 0]);

        $this->assertSame([], $this->repuestosSinPrecio($conPrecio));
        $this->assertSame([], $this->repuestosSinPrecio($atendido));
        $this->assertSame([], $this->repuestosSinPrecio($tienda));
    }

    // ---- marca ARMADO impresa sin depender de fondos ----
    public function test_la_marca_armado_impresa_no_depende_de_print_color_adjust(): void
    {
        $t = $this->ticket(['incluye_armado' => true, 'monto_armado' => 25]);

        $datos = null;
        View::composer('pdf.taller-atencion', function ($vista) use (&$datos) {
            $datos = $vista->getData();
        });
        $this->actingAs($this->usuario)->get("/taller/{$t->id}/atencion-trek.pdf")->assertOk();
        $pdf = view('pdf.taller-atencion', $datos)->render();
        $bitacora = $this->actingAs($this->usuario)->get('/taller/bitacora/imprimir')->assertOk()->getContent();

        foreach ([$pdf, $bitacora] as $html) {
            $this->assertStringContainsString('marca-armado', $html);
            $this->assertStringContainsString('border: 2px solid #000', $html);
            $this->assertStringNotContainsString('print-color-adjust', $html);
        }
        $this->assertStringContainsString('+ ARMADO', $pdf);
    }

    public function test_la_bitacora_con_cliente_muestra_total_y_marca_armado(): void
    {
        $this->ticket(['incluye_armado' => true, 'monto_armado' => 25]);
        $this->tienda();

        $texto = $this->textoVisible($this->actingAs($this->usuario)->get('/taller/bitacora/imprimir')->assertOk()->getContent());

        $this->assertStringContainsString('$40.00', $texto);
        $this->assertStringContainsString('ACUMULADO', $texto);
        $this->assertStringContainsString('+ ARMADO', $texto);
        $this->assertStringContainsString('Trabajo de la tienda', $texto);
    }
}
