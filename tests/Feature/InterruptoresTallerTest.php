<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\TicketTaller;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

// Interruptores de config/taller.php: revision_tecnica_activa y trabajo_tienda_activo.
// Por defecto apagados; apagar oculta o bloquea, nunca borra ni cambia datos guardados.
class InterruptoresTallerTest extends TestCase
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

        Storage::fake('public');
        config(['ia.activa' => false]);
        $this->usuario = User::factory()->create(['name' => 'Kleiver', 'rol_taller' => true]);
        $this->cliente = Cliente::create(['nombre' => 'Ana Perez', 'telefono' => '+584141234567']);
    }

    private function revisionGuardada(): array
    {
        return [
            'tareas' => [],
            'componentes' => [
                'cadena' => ['acciones' => ['lubricado']],
                'pastillas' => ['acciones' => ['recomendar'], 'motivo' => 'desgaste'],
            ],
        ];
    }

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

    private function htmlDelPdf(TicketTaller $t): string
    {
        $datos = null;
        View::composer('pdf.taller-atencion', function ($vista) use (&$datos) {
            $datos = $vista->getData();
        });
        $this->actingAs($this->usuario)->get("/taller/{$t->id}/atencion-trek.pdf")->assertOk();

        return view('pdf.taller-atencion', $datos)->render();
    }

    private function datosNuevo(array $extra = []): array
    {
        return [
            'motivo_ingreso' => 'armar',
            'bici_marca_modelo' => 'GW nueva',
            'categoria_bici' => 'mtb',
            'mecanico_id' => $this->usuario->id,
            'fotos_entrada' => [UploadedFile::fake()->image('entrada.jpg', 600, 900)],
            ...$extra,
        ];
    }

    public function test_por_defecto_ambos_interruptores_estan_apagados(): void
    {
        $this->assertFalse(config('taller.revision_tecnica_activa'));
        $this->assertFalse(config('taller.trabajo_tienda_activo'));
    }

    // ---- Revision tecnica ----
    public function test_rutas_de_revision_dan_404_apagadas(): void
    {
        $t = $this->ticket();
        $this->actingAs($this->usuario);

        $this->get("/taller/{$t->id}/revision")->assertNotFound();
        $this->patchJson("/taller/{$t->id}/revision", [])->assertNotFound();
        $this->postJson("/taller/{$t->id}/sugerencias/cadena/descartar")->assertNotFound();
        $this->postJson("/taller/{$t->id}/texto-cliente/generar", ['fuente' => 'automatico'])->assertNotFound();
        $this->postJson("/taller/{$t->id}/informe/reintentar")->assertNotFound();
    }

    public function test_rutas_de_revision_responden_encendidas(): void
    {
        config(['taller.revision_tecnica_activa' => true]);
        $t = $this->ticket();

        $this->actingAs($this->usuario)->get("/taller/{$t->id}/revision")->assertOk();
    }

    public function test_show_sin_revision_ni_ia_apagada_aunque_el_ticket_tenga_revision_guardada(): void
    {
        $t = $this->ticket(['revision_tecnica' => $this->revisionGuardada()]);

        $props = $this->actingAs($this->usuario)->get("/taller/{$t->id}")->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['funciones']['revision_tecnica']);
        $this->assertNull($props['ticket']['revision']);
        $this->assertNull($props['ticket']['texto_cliente']['ia']);
        // el dato guardado no se toca
        $this->assertSame('lubricado', $t->fresh()->revision_tecnica['componentes']['cadena']['acciones'][0]);
    }

    public function test_show_con_revision_encendida_la_entrega(): void
    {
        config(['taller.revision_tecnica_activa' => true]);
        $t = $this->ticket(['revision_tecnica' => $this->revisionGuardada()]);

        $props = $this->actingAs($this->usuario)->get("/taller/{$t->id}")->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['funciones']['revision_tecnica']);
        $this->assertNotNull($props['ticket']['revision']);
    }

    public function test_el_tecnico_no_recibe_lista_mis_tickets_apagada_y_si_encendida(): void
    {
        $this->ticket();

        $apagada = $this->actingAs($this->usuario)->get('/taller')->assertOk()->viewData('page')['props'];
        $this->assertNull($apagada['misTickets']);

        config(['taller.revision_tecnica_activa' => true]);
        $encendida = $this->actingAs($this->usuario)->get('/taller')->assertOk()->viewData('page')['props'];
        $this->assertCount(1, $encendida['misTickets']);
    }

    public function test_pdf_de_ticket_con_revision_guardada_sale_sin_secciones_apagada(): void
    {
        $t = $this->ticket(['revision_tecnica' => $this->revisionGuardada(), 'revisado_por' => $this->usuario->id, 'revisado_en' => now()]);

        $html = $this->htmlDelPdf($t);

        $this->assertStringNotContainsString('Revisión técnica', $html);
        $this->assertStringNotContainsString('Recomendaciones', $html);
        $this->assertStringNotContainsString('Revisado por', $html);
    }

    public function test_pdf_con_revision_encendida_mantiene_las_secciones(): void
    {
        config(['taller.revision_tecnica_activa' => true]);
        $t = $this->ticket(['revision_tecnica' => $this->revisionGuardada(), 'revisado_por' => $this->usuario->id, 'revisado_en' => now()]);

        $html = $this->htmlDelPdf($t);

        $this->assertStringContainsString('Revisión técnica', $html);
        $this->assertStringContainsString('Recomendaciones', $html);
        $this->assertStringContainsString('Revisado por', $html);
    }

    public function test_cierra_con_trabajo_realizado_manual_y_sin_revision_apagada(): void
    {
        $t = $this->ticket(['trabajo_realizado' => 'Ajuste de frenos y lubricación.']);

        $this->actingAs($this->usuario)
            ->patch("/taller/{$t->id}/marcar-atendido", ['texto_cliente' => 'aprobar', 'pagado_en_taller' => false])
            ->assertSessionHasNoErrors();

        $this->assertSame('atendido', $t->fresh()->estado);
    }

    public function test_sin_revision_ni_trabajo_realizado_no_cierra(): void
    {
        $t = $this->ticket();

        $this->actingAs($this->usuario)
            ->patch("/taller/{$t->id}/marcar-atendido", ['texto_cliente' => 'sin_texto', 'pagado_en_taller' => false])
            ->assertSessionHasErrors('trabajo_realizado');

        $this->assertSame('en_proceso', $t->fresh()->estado);
    }

    public function test_apagada_la_revision_no_se_encola_ia_aunque_ia_activa(): void
    {
        config(['ia.activa' => true]);
        Queue::fake();

        $this->ticket(['revision_tecnica' => $this->revisionGuardada()])->update(['revision_tecnica' => ['tareas' => [], 'componentes' => ['cadena' => ['acciones' => ['ok']]]]]);

        Queue::assertNothingPushed();
    }

    // ---- Trabajo de la tienda ----
    public function test_nuevo_informa_el_interruptor_de_trabajo_de_tienda(): void
    {
        $apagado = $this->actingAs($this->usuario)->get('/taller/nuevo')->assertOk()->viewData('page')['props'];
        $this->assertFalse($apagado['funciones']['trabajo_tienda']);

        config(['taller.trabajo_tienda_activo' => true]);
        $encendido = $this->actingAs($this->usuario)->get('/taller/nuevo')->assertOk()->viewData('page')['props'];
        $this->assertTrue($encendido['funciones']['trabajo_tienda']);
    }

    public function test_crear_sin_cliente_da_422_apagado(): void
    {
        $this->actingAs($this->usuario)
            ->postJson('/taller', $this->datosNuevo(['trabajo_tienda' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cliente_id' => 'indica el cliente: crear trabajos de la tienda (sin cliente) está desactivado']);

        $this->assertSame(0, TicketTaller::count());
    }

    public function test_crear_sin_cliente_funciona_encendido(): void
    {
        config(['taller.trabajo_tienda_activo' => true]);

        $this->actingAs($this->usuario)->post('/taller', $this->datosNuevo(['trabajo_tienda' => true]))->assertRedirect();

        $t = TicketTaller::firstOrFail();
        $this->assertNull($t->cliente_id);
        $this->assertSame('armado_interno', $t->tipo);
    }

    public function test_crear_con_cliente_y_armado_funciona_apagado(): void
    {
        $this->actingAs($this->usuario)->post('/taller', $this->datosNuevo([
            'cliente_id' => $this->cliente->id, 'tipo_servicio' => 'basico', 'monto_servicio' => 15,
            'incluye_armado' => true, 'monto_armado' => 25,
        ]))->assertRedirect();

        $t = TicketTaller::firstOrFail();
        $this->assertSame($this->cliente->id, $t->cliente_id);
        $this->assertTrue($t->incluye_armado);
        $this->assertSame(40.0, $t->totalTicket());
    }

    public function test_ticket_existente_sin_cliente_se_lista_abre_edita_y_cierra_apagado(): void
    {
        $t = $this->tienda(['trabajo_realizado' => 'Armado completo.']);
        $this->actingAs($this->usuario);

        $lista = $this->get('/taller')->assertOk()->viewData('page')['props']['tickets']['data'];
        $this->assertCount(1, $lista);

        $this->get("/taller/{$t->id}")->assertOk();

        $this->put("/taller/{$t->id}", [
            'trabajo_tienda' => true,
            'motivo_ingreso' => 'armar bici nueva (editado)',
            'bici_marca_modelo' => 'Trek Marlin',
            'categoria_bici' => 'mtb',
            'mecanico_id' => $this->usuario->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame('armar bici nueva (editado)', $t->fresh()->motivo_ingreso);
        $this->assertNull($t->fresh()->cliente_id);

        $this->patch("/taller/{$t->id}/marcar-atendido", ['texto_cliente' => 'aprobar'])->assertSessionHasNoErrors();
        $this->assertSame('atendido', $t->fresh()->estado);
    }
}
