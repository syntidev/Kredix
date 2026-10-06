<?php

namespace Tests\Feature;

use App\Jobs\RedactarInforme;
use App\Jobs\SugerirRevision;
use App\Models\TicketTaller;
use App\Services\Ia\ClienteIa;
use App\Services\Ia\EstructuradorRevision;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IaTallerTest extends TestCase
{
    private const REVISION = [
        'tareas' => ['lubricar_cadena' => true],
        'componentes' => [
            'cadena' => ['acciones' => ['lubricado'], 'motivos' => [], 'nota' => ''],
            'pastillas' => ['acciones' => ['recomendar'], 'motivos' => ['desgaste'], 'nota' => ''],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Tabla minima propia: RefreshDatabase no corre en sqlite por una migracion vieja (prospectos_200k).
        Schema::create('tickets_taller', function (Blueprint $t) {
            $t->id();
            $t->string('tipo')->default('servicio_cliente');
            $t->foreignId('cliente_id')->nullable();
            $t->string('bici_marca_modelo')->default('Trek Marlin');
            $t->string('talla_rin')->default('29');
            $t->string('categoria_bici')->nullable();
            $t->boolean('es_electrica')->default(false);
            $t->text('motivo_ingreso')->nullable();
            $t->text('trabajo_realizado')->nullable();
            $t->json('revision_tecnica')->nullable();
            $t->json('sugerencias_ia')->nullable();
            $t->text('informe_ia')->nullable();
            $t->string('informe_ia_estado')->nullable();
            $t->timestamp('informe_ia_generado_en')->nullable();
            $t->string('informe_ia_hash')->nullable();
            $t->boolean('informe_ia_desactualizado')->default(false);
            $t->string('tipo_servicio')->nullable();
            $t->string('estado')->default('en_proceso');
            $t->foreignId('mecanico_id')->default(1);
            $t->foreignId('registrado_por')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });

        $storage = sys_get_temp_dir().'/kredix-ia-test';
        @mkdir($storage.'/logs', 0777, true);
        $this->app->useStoragePath($storage);
        config(['ia.api_key' => 'test', 'ia.modelo_texto' => 'modelo-test']);
    }

    private function fakeIa(string $contenido): void
    {
        Http::fake(['*' => Http::response(['model' => 'modelo-test', 'choices' => [['message' => ['content' => $contenido]]]])]);
    }

    private function redactar(TicketTaller $t): TicketTaller
    {
        (new RedactarInforme($t))->handle(app(ClienteIa::class));

        return $t->fresh();
    }

    public function test_sugerencias_descartan_claves_fuera_del_catalogo(): void
    {
        $limpio = SugerirRevision::filtrar(['componentes' => [
            'sillin' => ['acciones' => ['ajustado']],
            'cadena' => ['acciones' => ['purgado']],
            'bateria' => ['acciones' => ['ok']],
            'pastillas' => ['acciones' => ['cambiado'], 'motivos' => ['desgaste']],
            'discos' => ['acciones' => ['recomendar'], 'motivos' => ['desgaste', 'oxido']],
        ]], esElectrica: false);

        $this->assertSame([
            'pastillas' => ['acciones' => ['cambiado'], 'motivos' => []],
            'discos' => ['acciones' => ['recomendar'], 'motivos' => ['desgaste']],
        ], $limpio);
    }

    public function test_estructurador_rechaza_claves_fuera_del_catalogo(): void
    {
        $this->assertSame([], EstructuradorRevision::validar(['componentes' => ['cuadro' => ['acciones' => ['recomendar'], 'motivos' => ['fisura']]]]));
        $this->assertCount(2, EstructuradorRevision::validar(['componentes' => [
            'sillin' => ['acciones' => ['ok']],
            'cadena' => ['acciones' => ['cambiado'], 'motivos' => ['desgaste']],
        ]]));
    }

    public function test_con_ia_apagada_no_se_encola_nada(): void
    {
        Queue::fake();
        config(['ia.activa' => false]);

        $t = TicketTaller::create(['motivo_ingreso' => 'frena mal']);
        $t->update(['revision_tecnica' => self::REVISION]);

        Queue::assertNothingPushed();
    }

    public function test_con_ia_activa_se_encolan_en_cola_ia(): void
    {
        Queue::fake();
        config(['ia.activa' => true]);

        $t = TicketTaller::create(['motivo_ingreso' => 'frena mal']);
        $t->update(['revision_tecnica' => self::REVISION]);

        Queue::assertPushedOn('ia', SugerirRevision::class);
        Queue::assertPushedOn('ia', RedactarInforme::class);
        $this->assertSame('pendiente', $t->fresh()->informe_ia_estado);
    }

    public function test_redactar_solo_recibe_la_revision_confirmada(): void
    {
        $this->fakeIa('{"texto":"'.str_repeat('Le lubricamos la cadena a su bici. ', 3).'"}');
        $t = TicketTaller::create([
            'revision_tecnica' => self::REVISION,
            'sugerencias_ia' => ['componentes' => ['discos' => ['acciones' => ['cambiado'], 'motivos' => []]]],
        ]);

        $this->redactar($t);

        $this->assertSame(['paquete', 'bici', 'revision'], array_keys(RedactarInforme::entrada($t)));
        Http::assertSent(function (Request $r) {
            $usuario = $r['messages'][1]['content'];

            return str_contains($usuario, 'Cadena') && ! str_contains(mb_strtolower($usuario), 'disco');
        });
    }

    public function test_borrador_aprobado_con_hash_viejo_se_marca_desactualizado(): void
    {
        Queue::fake();
        config(['ia.activa' => true]);
        $t = TicketTaller::create([
            'revision_tecnica' => self::REVISION,
            'informe_ia' => 'texto aprobado',
            'informe_ia_estado' => 'aprobado',
            'informe_ia_hash' => RedactarInforme::hash(self::REVISION),
        ]);

        $t->update(['revision_tecnica' => [...self::REVISION, 'tareas' => ['lavado' => true]]]);

        $t->refresh();
        $this->assertTrue($t->informe_ia_desactualizado);
        $this->assertSame('aprobado', $t->informe_ia_estado);
        $this->assertSame('texto aprobado', $t->informe_ia);
        Queue::assertNotPushed(RedactarInforme::class);
    }

    public function test_borrador_listo_con_hash_viejo_vuelve_a_pendiente(): void
    {
        Queue::fake();
        config(['ia.activa' => true]);
        $t = TicketTaller::create([
            'revision_tecnica' => self::REVISION,
            'informe_ia_estado' => 'listo',
            'informe_ia_hash' => RedactarInforme::hash(self::REVISION),
        ]);

        $t->update(['revision_tecnica' => [...self::REVISION, 'tareas' => ['lavado' => true]]]);

        $this->assertSame('pendiente', $t->fresh()->informe_ia_estado);
        Queue::assertPushed(RedactarInforme::class);
    }

    public function test_hash_ignora_orden_de_claves(): void
    {
        $this->assertSame(
            RedactarInforme::hash(self::REVISION),
            RedactarInforme::hash(['componentes' => array_reverse(self::REVISION['componentes'], true), 'tareas' => self::REVISION['tareas']]),
        );
    }

    public function test_detector_marca_requiere_revision_si_menciona_discos(): void
    {
        $this->fakeIa('{"texto":"Le lubricamos la cadena a su bici y quedó suavecita. Le sugerimos cambiar las pastillas y revisar los discos en su próxima visita."}');
        $t = TicketTaller::create(['revision_tecnica' => self::REVISION]);

        $t = $this->redactar($t);

        $this->assertSame('requiere_revision', $t->informe_ia_estado);
        $this->assertSame(['Discos'], RedactarInforme::menciones($t->informe_ia, self::REVISION));
        $this->assertSame([], RedactarInforme::menciones('Lubricamos la cadena y le recomendamos cambiar las pastillas.', self::REVISION));
    }

    public function test_jerga_marca_requiere_revision(): void
    {
        $this->fakeIa('{"texto":"Épale pana, tu bici quedó full: le lubricamos la cadena y te recomendamos cambiar las pastillas por desgaste."}');
        $t = TicketTaller::create(['revision_tecnica' => self::REVISION]);

        $this->assertSame('requiere_revision', $this->redactar($t)->informe_ia_estado);
        $this->assertSame(['pana', 'epale', 'quedo full'], RedactarInforme::jerga($t->fresh()->informe_ia));
        $this->assertSame([], RedactarInforme::jerga('Le hicimos el servicio Full a tu Trek y ajustamos los frenos.'));
    }

    public function test_json_invalido_termina_en_error(): void
    {
        $this->fakeIa('Claro, aquí tiene el informe: la bici quedó bien.');
        $t = TicketTaller::create(['revision_tecnica' => self::REVISION]);

        $this->assertSame('error', $this->redactar($t)->informe_ia_estado);
    }

    public function test_texto_fuera_de_largo_termina_en_error(): void
    {
        $this->fakeIa('{"texto":"Listo."}');
        $t = TicketTaller::create(['revision_tecnica' => self::REVISION]);

        $this->assertSame('error', $this->redactar($t)->informe_ia_estado);
    }
}
