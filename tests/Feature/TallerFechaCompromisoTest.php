<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\TicketTaller;
use App\Models\User;
use App\Services\Taller\PlantillasWhatsapp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// Fecha de compromiso en el flujo real: alta, edicion, activity log, listado, WhatsApp
class TallerFechaCompromisoTest extends TestCase
{
    private User $mecanico;

    private User $admin;

    private Cliente $cliente;

    private const RUTA_MIGRACION = 'database/migrations/2026_10_22_200000_add_fecha_compromiso_to_tickets_taller.php';

    protected function setUp(): void
    {
        parent::setUp();

        $migraciones = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => str_contains($p, 'replace_procesado_con_estado_en_prospectos_200k'))
            ->values()->all();
        Artisan::call('migrate', ['--path' => $migraciones, '--realpath' => true]);

        // jueves 22 de octubre de 2026, hora de Caracas
        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00', 'America/Caracas'));

        $this->mecanico = User::factory()->create(['name' => 'Kleiver', 'rol_taller' => true, 'es_admin' => false]);
        $this->admin = User::factory()->create(['es_admin' => true, 'rol_taller' => false]);
        $this->cliente = Cliente::create(['nombre' => 'MUÑOZ PÉREZ', 'telefono' => '+584141234567']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // VIP: no exige fotos al crear (se agenda por telefono), asi el alta se prueba sin subir archivos
    private function datos(array $cambios = []): array
    {
        return [
            'cliente_id' => $this->cliente->id,
            'motivo_ingreso' => 'frena mal',
            'bici_marca_modelo' => 'Trek Marlin 7',
            'categoria_bici' => 'mtb',
            'talla_rin' => '29',
            'tipo_servicio' => 'vip',
            'domicilio_direccion' => 'Av. Principal, Porlamar',
            'monto_servicio' => 25,
            'mecanico_id' => $this->mecanico->id,
            ...$cambios,
        ];
    }

    private function ticket(array $datos = []): TicketTaller
    {
        return TicketTaller::create([
            'tipo' => 'servicio_cliente',
            'cliente_id' => $this->cliente->id,
            'bici_marca_modelo' => 'Trek Marlin 7',
            'categoria_bici' => 'mtb',
            'talla_rin' => '29',
            'es_electrica' => false,
            'tipo_servicio' => 'basico',
            'monto_servicio' => 15,
            'diagnostico' => [],
            'mecanico_id' => $this->mecanico->id,
            'registrado_por' => $this->mecanico->id,
            ...$datos,
        ]);
    }

    // ---- alta: defecto y fecha elegida ----
    public function test_crear_sin_elegir_fecha_asigna_manana_y_un_sabado_salta_al_lunes(): void
    {
        $this->actingAs($this->mecanico)->post('/taller', $this->datos())->assertRedirect();
        $this->assertSame('2026-10-23', TicketTaller::latest('id')->first()->fecha_compromiso->toDateString(), 'jueves -> viernes');

        Carbon::setTestNow(Carbon::parse('2026-10-24 10:00:00', 'America/Caracas')); // sabado
        $this->actingAs($this->mecanico)->post('/taller', $this->datos())->assertRedirect();
        $this->assertSame('2026-10-26', TicketTaller::latest('id')->first()->fecha_compromiso->toDateString(), 'sabado -> lunes');
    }

    // ---- criterio 6 (servidor): un dia cerrado no bloquea ----
    public function test_elegir_un_domingo_se_guarda_sin_bloqueo(): void
    {
        $this->actingAs($this->mecanico)->post('/taller', $this->datos(['fecha_compromiso' => '2026-10-25']))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('2026-10-25', TicketTaller::latest('id')->first()->fecha_compromiso->toDateString());
    }

    public function test_una_fecha_con_formato_invalido_se_rechaza(): void
    {
        $this->actingAs($this->mecanico)->post('/taller', $this->datos(['fecha_compromiso' => '25/10/2026']))
            ->assertSessionHasErrors('fecha_compromiso');
    }

    // ---- criterios 2 y 8: edicion, activity log, rol_taller ----
    public function test_un_rol_taller_edita_la_fecha_y_el_cambio_queda_en_el_activity_log_con_valor_anterior_y_nuevo(): void
    {
        $ticket = $this->ticket(['fecha_compromiso' => '2026-10-23']);

        $this->actingAs($this->mecanico)
            ->put("/taller/{$ticket->id}", $this->datos(['tipo_servicio' => 'basico', 'domicilio_direccion' => null, 'monto_servicio' => 15, 'fecha_compromiso' => '2026-10-27']))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('2026-10-27', $ticket->refresh()->fecha_compromiso->toDateString());

        $registro = Activity::where('subject_type', TicketTaller::class)->where('subject_id', $ticket->id)->latest('id')->first();
        $this->assertNotNull($registro, 'el cambio de fecha deja un registro');
        $this->assertSame('taller', $registro->log_name);
        $this->assertSame($this->mecanico->id, $registro->causer_id);
        $this->assertStringContainsString('2026-10-27', (string) $registro->properties['attributes']['fecha_compromiso']);
        $this->assertStringContainsString('2026-10-23', (string) $registro->properties['old']['fecha_compromiso']);
    }

    public function test_editar_sin_enviar_la_fecha_no_la_toca_ni_deja_registro(): void
    {
        $ticket = $this->ticket(['fecha_compromiso' => '2026-10-23']);
        $antes = Activity::where('subject_id', $ticket->id)->count();

        $datos = $this->datos(['tipo_servicio' => 'basico', 'domicilio_direccion' => null, 'monto_servicio' => 15]);
        $this->actingAs($this->mecanico)->put("/taller/{$ticket->id}", $datos)->assertSessionHasNoErrors();

        $this->assertSame('2026-10-23', $ticket->refresh()->fecha_compromiso->toDateString());
        $this->assertSame($antes, Activity::where('subject_id', $ticket->id)->count());
    }

    public function test_un_rol_taller_puede_fijar_la_fecha_al_crear(): void
    {
        $this->actingAs($this->mecanico)->post('/taller', $this->datos(['fecha_compromiso' => '2026-10-28']))->assertRedirect();

        $this->assertSame('2026-10-28', TicketTaller::latest('id')->first()->fecha_compromiso->toDateString());
    }

    // ---- criterio 3: existentes en NULL ----
    public function test_los_tickets_existentes_siguen_en_null_tras_migrar_y_se_muestran_sin_fecha(): void
    {
        // esquema anterior a la migracion, con un ticket ya creado
        Artisan::call('migrate:rollback', ['--path' => self::RUTA_MIGRACION]);
        $existente = $this->ticket();
        Artisan::call('migrate', ['--path' => self::RUTA_MIGRACION]);

        $this->assertNull($existente->refresh()->fecha_compromiso, 'la migracion no inventa fechas');

        $this->actingAs($this->mecanico)->get('/taller')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('tickets.data.0.compromiso.etiqueta', 'Sin fecha')
            ->where('tickets.data.0.compromiso.grupo', 'sin_fecha')
            ->where('contadoresCompromiso', ['atrasados' => 0, 'hoy' => 0, 'manana' => 0]));

        $this->actingAs($this->mecanico)->get("/taller/{$existente->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('ticket.fecha_compromiso', null)
            ->where('ticket.compromiso.etiqueta', 'Sin fecha'));
    }

    // ---- criterio 4: el orden y los grupos llegan a la pantalla ----
    public function test_la_lista_ordena_por_prioridad_y_manda_el_grupo_de_cada_ticket(): void
    {
        $sinFecha = $this->ticket(['fecha_compromiso' => null]);
        $futuro = $this->ticket(['fecha_compromiso' => '2026-11-05']);
        $manana = $this->ticket(['fecha_compromiso' => '2026-10-23']);
        $hoy = $this->ticket(['fecha_compromiso' => '2026-10-22']);
        $atrasado = $this->ticket(['fecha_compromiso' => '2026-10-19']);
        $atendido = $this->ticket(['fecha_compromiso' => '2026-10-01']);
        $atendido->forceFill(['estado' => 'atendido'])->saveQuietly();

        $props = $this->actingAs($this->mecanico)->get('/taller')->assertOk()->viewData('page')['props'];

        $this->assertSame([$atrasado->id, $hoy->id, $manana->id, $futuro->id, $sinFecha->id, $atendido->id], array_column($props['tickets']['data'], 'id'));
        $this->assertSame(['atrasados', 'hoy', 'manana', 'despues', 'sin_fecha', null], array_map(fn ($t) => $t['compromiso']['grupo'], $props['tickets']['data']));
        $this->assertSame('Atrasado 3 d', $props['tickets']['data'][0]['compromiso']['etiqueta']);
        $this->assertSame(['atrasados' => 1, 'hoy' => 1, 'manana' => 1], $props['contadoresCompromiso']);
        $this->assertSame('Sin fecha', $props['gruposCompromiso']['sin_fecha']);
    }

    // ---- criterio 7: {fecha_entrega} ----
    public function test_fecha_entrega_se_acepta_en_las_plantillas_y_se_sustituye_con_por_confirmar_si_no_hay_fecha(): void
    {
        $textos = [];
        foreach (array_keys(PlantillasWhatsapp::CAMPOS) as $campo) {
            $textos[$campo] = PlantillasWhatsapp::defecto($campo);
        }
        $textos['libre'] = 'Hola {nombre}, tu {bici} sale el {fecha_entrega}';

        $this->actingAs($this->admin)->put('/configuracion/whatsapp-taller', $textos)
            ->assertSessionHasNoErrors()->assertRedirect('/configuracion');

        $conFecha = $this->ticket(['fecha_compromiso' => '2026-10-23']);
        $sinFecha = $this->ticket(['fecha_compromiso' => null]);

        $this->actingAs($this->mecanico)->get("/taller/{$conFecha->id}")->assertInertia(fn (Assert $p) => $p
            ->where('mensajesWhatsapp.libre', 'Hola Muñoz Pérez, tu Trek Marlin 7 sale el viernes 23/10'));
        $this->actingAs($this->mecanico)->get("/taller/{$sinFecha->id}")->assertInertia(fn (Assert $p) => $p
            ->where('mensajesWhatsapp.libre', 'Hola Muñoz Pérez, tu Trek Marlin 7 sale el por confirmar'));
    }

    public function test_los_textos_por_defecto_de_whatsapp_no_cambian(): void
    {
        foreach (array_keys(PlantillasWhatsapp::CAMPOS) as $campo) {
            $this->assertStringNotContainsString('{fecha_entrega}', PlantillasWhatsapp::defecto($campo));
        }
    }

    public function test_una_variable_desconocida_sigue_rechazandose_y_el_mensaje_lista_fecha_entrega(): void
    {
        $textos = [];
        foreach (array_keys(PlantillasWhatsapp::CAMPOS) as $campo) {
            $textos[$campo] = PlantillasWhatsapp::defecto($campo);
        }
        $textos['repuesto'] = 'Hola {apodo}';

        $this->actingAs($this->admin)->put('/configuracion/whatsapp-taller', $textos)
            ->assertSessionHasErrors(['repuesto' => 'La variable {apodo} no es válida en «Falta un repuesto». Las válidas son: {nombre}, {bici}, {fecha_entrega}.']);
    }

    // ---- Show y Nuevo: el selector recibe sus datos ----
    public function test_nuevo_y_show_reciben_la_configuracion_del_selector_calculada_en_el_servidor(): void
    {
        $ticket = $this->ticket(['fecha_compromiso' => '2026-10-23']);

        $nuevo = $this->actingAs($this->mecanico)->get('/taller/nuevo')->assertOk()->viewData('page')['props']['compromisoConfig'];
        $this->assertSame('2026-10-23', $nuevo['defecto']);
        $this->assertSame([0], $nuevo['dias_cerrados']);
        $this->assertSame(['2026-10-23', '2026-10-24', '2026-10-25'], array_column($nuevo['opciones'], 'fecha'));

        $this->actingAs($this->mecanico)->get("/taller/{$ticket->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('ticket.fecha_compromiso', '2026-10-23')
            ->has('compromisoConfig.opciones'));
    }
}
