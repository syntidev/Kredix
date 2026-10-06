<?php

namespace Tests\Feature;

use App\Models\TicketTaller;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

// Entrega A ("Que le llega al cliente"): compuerta del texto, nota interna fuera
// del PDF, fotos con tope y registro, etiquetas de una sola fuente.
class EntregaATallerTest extends TestCase
{
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        // esquema real; se salta solo la migracion vieja que no corre en sqlite
        // (backlog 2.8: dropea una columna indexada de prospectos_200k)
        $migraciones = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => str_contains($p, 'replace_procesado_con_estado_en_prospectos_200k'))
            ->values()->all();
        Artisan::call('migrate', ['--path' => $migraciones, '--realpath' => true]);

        Storage::fake('public');
        config(['ia.activa' => false]);
        $this->usuario = User::factory()->create(['name' => 'Kleiver', 'rol_taller' => true]);
    }

    private function ticket(array $datos = []): TicketTaller
    {
        return TicketTaller::create([
            'tipo' => 'armado_interno',
            'bici_marca_modelo' => 'Trek Marlin',
            'categoria_bici' => 'mtb',
            'talla_rin' => '29',
            'es_electrica' => false,
            'tipo_servicio' => 'basico',
            'diagnostico' => [],
            'mecanico_id' => $this->usuario->id,
            'registrado_por' => $this->usuario->id,
            ...$datos,
        ]);
    }

    private function revision(array $componentes, array $tareas = []): array
    {
        return ['tareas' => $tareas, 'componentes' => $componentes];
    }

    // pide el PDF y devuelve el HTML de la vista con los datos que le paso el
    // controlador (el binario del PDF sale comprimido)
    private function htmlDelPdf(TicketTaller $t, string $query = ''): string
    {
        $datos = null;
        View::composer('pdf.taller-atencion', function ($vista) use (&$datos) {
            $datos = $vista->getData();
        });
        $this->actingAs($this->usuario)->get("/taller/{$t->id}/atencion-trek.pdf{$query}")->assertOk();

        return view('pdf.taller-atencion', $datos)->render();
    }

    public function test_texto_generado_por_maquina_queda_en_borrador_y_no_sale_en_el_pdf(): void
    {
        $t = $this->ticket();
        $this->actingAs($this->usuario)->patchJson("/taller/{$t->id}/revision", $this->revision([
            'cadena' => ['acciones' => ['lubricado']],
        ]))->assertOk();

        $t->refresh();
        $this->assertSame('borrador', $t->texto_cliente_estado);
        $this->assertSame('automatico', $t->texto_cliente_origen);
        $this->assertStringContainsString('Lubricamos la cadena', $t->trabajo_realizado);
        $this->assertNull($t->textoClienteImprimible());

        $html = $this->htmlDelPdf($t);
        $this->assertStringNotContainsString('Lubricamos la cadena', $html);
        $this->assertStringNotContainsString('Trabajo realizado', $html);
    }

    public function test_texto_guardado_por_una_persona_queda_aprobado_con_nombre_y_hora_y_sale_en_el_pdf(): void
    {
        $t = $this->ticket(['revision_tecnica' => $this->revision(['cadena' => ['acciones' => ['lubricado']]])]);

        $this->actingAs($this->usuario)->patch("/taller/{$t->id}/trabajo-realizado", ['trabajo_realizado' => 'Lubricamos la cadena y quedó suave.'])
            ->assertRedirect();

        $t->refresh();
        $this->assertSame('aprobado', $t->texto_cliente_estado);
        $this->assertSame('manual', $t->texto_cliente_origen);
        $this->assertSame($this->usuario->id, $t->texto_cliente_aprobado_por);
        $this->assertNotNull($t->texto_cliente_aprobado_en);
        $this->assertStringContainsString('Lubricamos la cadena y quedó suave.', $this->htmlDelPdf($t));
    }

    public function test_si_la_revision_cambia_despues_de_aprobar_el_texto_queda_desactualizado_y_no_se_imprime(): void
    {
        $t = $this->ticket(['revision_tecnica' => $this->revision(['cadena' => ['acciones' => ['lubricado']]])]);
        $this->actingAs($this->usuario)->patch("/taller/{$t->id}/trabajo-realizado", ['trabajo_realizado' => 'Lubricamos la cadena.']);

        $this->actingAs($this->usuario)->patchJson("/taller/{$t->id}/revision", $this->revision([
            'cadena' => ['acciones' => ['lubricado']],
            'pastillas' => ['acciones' => ['cambiado']],
        ]))->assertOk();

        $t->refresh();
        $this->assertSame('Lubricamos la cadena.', $t->trabajo_realizado, 'un texto aprobado nunca se pisa');
        $this->assertTrue($t->textoClienteDesactualizado());
        $this->assertStringNotContainsString('Lubricamos la cadena.', $this->htmlDelPdf($t));

        $this->actingAs($this->usuario)->get("/taller/{$t->id}")
            ->assertInertia(fn ($pagina) => $pagina->where('ticket.texto_cliente.estado', 'desactualizado'));
    }

    public function test_marcar_atendido_solo_deja_un_borrador_que_no_se_imprime(): void
    {
        $t = $this->ticket(['revision_tecnica' => $this->revision(['cadena' => ['acciones' => ['lubricado']]])]);
        foreach (['entrada', 'salida'] as $coleccion) {
            $t->addMedia(UploadedFile::fake()->image("$coleccion.jpg", 600, 900))->toMediaCollection($coleccion);
        }

        $this->actingAs($this->usuario)->patch("/taller/{$t->id}/marcar-atendido")->assertRedirect();

        $t->refresh();
        $this->assertSame('atendido', $t->estado);
        $this->assertSame('borrador', $t->texto_cliente_estado);
        $this->assertNull($t->textoClienteImprimible());
    }

    public function test_vista_previa_imprime_el_borrador_sin_aprobarlo(): void
    {
        $t = $this->ticket(['revision_tecnica' => $this->revision(['cadena' => ['acciones' => ['lubricado']]])]);
        $this->actingAs($this->usuario)->post("/taller/{$t->id}/texto-cliente/generar", ['fuente' => 'automatico']);

        $html = $this->htmlDelPdf($t->fresh(), '?vista_previa=1');

        $this->assertStringContainsString('Lubricamos la cadena', $html);
        $this->assertSame('borrador', $t->fresh()->texto_cliente_estado, 'la vista previa no aprueba nada');
    }

    public function test_la_nota_del_tecnico_no_sale_en_el_pdf(): void
    {
        $t = $this->ticket([
            'revision_tecnica' => $this->revision([
                'pinones' => ['acciones' => ['recomendar'], 'motivos' => ['desgaste'], 'nota' => 'NOTA-SECRETA el cliente pidio presupuesto'],
            ]),
            'trabajo_realizado' => 'Te recomendamos cambiar los piñones.',
        ]);

        $html = $this->htmlDelPdf($t);

        $this->assertStringContainsString('Piñones/Cassette', $html);
        $this->assertStringNotContainsString('NOTA-SECRETA', $html);
    }

    public function test_ticket_antiguo_sin_revision_imprime_su_texto_igual_que_antes(): void
    {
        // anterior a la compuerta: estado null = texto manual aprobado
        $t = $this->ticket(['trabajo_realizado' => 'Ajuste general y lubricación.']);

        $html = $this->htmlDelPdf($t);

        $this->assertStringContainsString('Trabajo realizado', $html);
        $this->assertStringContainsString('Ajuste general y lubricación.', $html);
        $this->assertStringNotContainsString('Revisión técnica', $html);
    }

    public function test_borrar_una_foto_deja_registro_de_quien_cuando_y_cual(): void
    {
        $t = $this->ticket();
        $media = $t->addMedia(UploadedFile::fake()->image('entrada.jpg', 600, 900))->toMediaCollection('entrada');

        $this->actingAs($this->usuario)->delete("/taller/{$t->id}/fotos/entrada/{$media->id}")->assertRedirect();

        $this->assertCount(0, $t->fresh()->getMedia('entrada'));
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'taller',
            'description' => 'foto eliminada',
            'subject_id' => $t->id,
            'causer_id' => $this->usuario->id,
        ]);
        $registro = \Spatie\Activitylog\Models\Activity::latest('id')->first();
        $this->assertSame($media->id, $registro->properties['media_id']);
        $this->assertSame('entrada', $registro->properties['coleccion']);
        $this->assertNotNull($registro->created_at);
    }

    public function test_reemplazar_una_foto_cambia_el_archivo_y_deja_registro(): void
    {
        $t = $this->ticket();
        $vieja = $t->addMedia(UploadedFile::fake()->image('vieja.jpg', 600, 900))->toMediaCollection('salida');

        $this->actingAs($this->usuario)->post("/taller/{$t->id}/fotos/salida/{$vieja->id}/reemplazar", [
            'foto' => UploadedFile::fake()->image('nueva.jpg', 600, 900),
        ])->assertRedirect();

        $fotos = $t->fresh()->getMedia('salida');
        $this->assertCount(1, $fotos);
        $this->assertNotSame($vieja->id, $fotos->first()->id);
        $this->assertDatabaseHas('activity_log', ['description' => 'foto reemplazada', 'causer_id' => $this->usuario->id]);
    }

    public function test_no_se_borra_la_foto_de_otro_ticket(): void
    {
        $otro = $this->ticket();
        $media = $otro->addMedia(UploadedFile::fake()->image('x.jpg'))->toMediaCollection('entrada');

        $this->actingAs($this->usuario)->delete("/taller/{$this->ticket()->id}/fotos/entrada/{$media->id}")->assertNotFound();
        $this->assertCount(1, $otro->fresh()->getMedia('entrada'));
    }

    public function test_tope_de_fotos_por_coleccion_en_el_servidor(): void
    {
        $max = config('taller.max_fotos');
        $t = $this->ticket();
        for ($i = 0; $i < $max; $i++) {
            $t->addMedia(UploadedFile::fake()->image("e$i.jpg"))->toMediaCollection('entrada');
        }

        $this->actingAs($this->usuario)->post("/taller/{$t->id}/fotos/entrada", [
            'fotos' => [UploadedFile::fake()->image('una-mas.jpg')],
        ])->assertSessionHasErrors('fotos');
        $this->assertCount($max, $t->fresh()->getMedia('entrada'));

        // al crear el ticket tampoco pasa del tope
        $this->actingAs($this->usuario)->post('/taller', [
            'tipo' => 'armado_interno',
            'bici_marca_modelo' => 'Trek',
            'categoria_bici' => 'mtb',
            'mecanico_id' => $this->usuario->id,
            'fotos_entrada' => array_map(fn ($i) => UploadedFile::fake()->image("n$i.jpg"), range(0, $max)),
        ])->assertSessionHasErrors('fotos_entrada');
    }

    public function test_el_sistema_de_frenos_no_acepta_la_accion_cambiado(): void
    {
        $t = $this->ticket();

        $this->actingAs($this->usuario)->patchJson("/taller/{$t->id}/revision", $this->revision([
            'sistema_freno' => ['acciones' => ['cambiado']],
        ]))->assertStatus(422)->assertJsonValidationErrors('componentes.sistema_freno.acciones');
    }

    public function test_las_etiquetas_salen_de_una_sola_fuente(): void
    {
        // ninguna pantalla del taller ni el PDF repiten las etiquetas de paquete o categoria
        $archivos = [
            ...glob(resource_path('js/Pages/Taller/*.vue')),
            resource_path('views/pdf/taller-atencion.blade.php'),
            resource_path('views/taller/bitacora-imprimir.blade.php'),
            app_path('Services/Taller/InformeRevision.php'),
            app_path('Jobs/RedactarInforme.php'),
        ];
        foreach ($archivos as $archivo) {
            $codigo = file_get_contents($archivo);
            $this->assertDoesNotMatchRegularExpression("/['\"]B[aá]sico['\"]|mtb: 'MTB'|'mtb' => 'MTB'/u", $codigo, basename($archivo));
        }
        $this->actingAs($this->usuario)->get('/taller')
            ->assertInertia(fn ($pagina) => $pagina->where('etiquetas.paquetes.basico', 'Básico')->where('etiquetas.categorias.mtb', 'MTB'));
    }
}
