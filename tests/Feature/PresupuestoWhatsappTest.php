<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\TicketTaller;
use App\Models\User;
use App\Services\Taller\PlantillasWhatsapp;
use App\Services\Taller\PresupuestoWhatsapp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// Plantilla "Presupuesto de repuestos": lista con el total de cada linea, totales de la pantalla,
// precio faltante, recorte por largo del enlace, edicion en Configuracion y validacion
class PresupuestoWhatsappTest extends TestCase
{
    private const NOMBRE_LARGO = 'AMORTIGUADOR TRASERO ÑANDÚ 🚲 NEGRO MATE MODELO LARGO NÚMERO';

    private User $mecanico;

    private User $admin;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $migraciones = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => str_contains($p, 'replace_procesado_con_estado_en_prospectos_200k'))
            ->values()->all();
        Artisan::call('migrate', ['--path' => $migraciones, '--realpath' => true]);

        $this->mecanico = User::factory()->create(['name' => 'Kleiver', 'rol_taller' => true, 'es_admin' => false]);
        $this->admin = User::factory()->create(['es_admin' => true, 'rol_taller' => false]);
        $this->cliente = Cliente::create(['nombre' => 'MUÑOZ PÉREZ', 'telefono' => '+58 414-1234567']);
    }

    private function ticket(array $datos = [], array $repuestos = []): TicketTaller
    {
        $ticket = TicketTaller::create([
            'tipo' => 'servicio_cliente',
            'cliente_id' => $this->cliente->id,
            'bici_marca_modelo' => 'TREK NEGRA / NARANJA',
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

        foreach ($repuestos as [$producto, $cantidad, $precio]) {
            $ticket->repuestos()->create(['producto' => $producto, 'cantidad' => $cantidad, 'precio' => $precio]);
        }

        return $ticket;
    }

    private function ejemplo(): TicketTaller
    {
        return $this->ticket([], [['PUÑOS TOLS', 1, 18], ['CADENA KMC9', 1, 25], ['GUAYAS FRENOS', 2, 5]]);
    }

    private function ticketConMuchosRepuestos(): TicketTaller
    {
        $repuestos = array_map(fn ($i) => [self::NOMBRE_LARGO.' '.$i, 1, 10 + $i], range(1, 25));

        return $this->ticket([], $repuestos);
    }

    // el mensaje tal como llega a la pantalla del ticket (props de Inertia)
    private function mensajes(TicketTaller $ticket): array
    {
        return $this->actingAs($this->mecanico)->get("/taller/{$ticket->id}")->assertOk()->viewData('page')['props']['mensajesWhatsapp'];
    }

    private function guardarPlantillas(array $cambios): TestResponse
    {
        $textos = [];
        foreach (array_keys(PlantillasWhatsapp::CAMPOS) as $campo) {
            $textos[$campo] = PlantillasWhatsapp::defecto($campo);
        }

        return $this->actingAs($this->admin)->put('/configuracion/whatsapp-taller', [...$textos, ...$cambios]);
    }

    // ---- criterio 1: el caso de ejemplo, al centavo ----
    public function test_el_presupuesto_de_ejemplo_sale_exactamente_asi(): void
    {
        $esperado = "Hola Muñoz Pérez 👋 Te saludamos de parte del equipo de OnBike Margarita. Revisando tu TREK NEGRA / NARANJA encontramos piezas a reemplazar:\n"
            ."• PUÑOS TOLS × 1 — \$18.00\n"
            ."• CADENA KMC9 × 1 — \$25.00\n"
            ."• GUAYAS FRENOS × 2 — \$10.00\n"
            ."Repuestos: \$53.00\n"
            ."Servicio: \$15.00\n"
            ."Total del presupuesto: \$68.00\n"
            .'¿Nos autorizas a continuar?';

        $this->assertSame($esperado, $this->mensajes($this->ejemplo())['presupuesto']);
    }

    public function test_la_linea_muestra_el_total_cantidad_por_precio_no_el_unitario(): void
    {
        $ticket = $this->ticket([], [['GUAYAS FRENOS', 2, 5]]);

        $presupuesto = $this->mensajes($ticket)['presupuesto'];

        $this->assertStringContainsString('• GUAYAS FRENOS × 2 — $10.00', $presupuesto);
        $this->assertStringNotContainsString('— $5.00', $presupuesto);
    }

    public function test_el_servicio_del_presupuesto_incluye_el_armado_y_la_suma_cuadra(): void
    {
        // servicio = monto_servicio + monto_armado (como el KPI de taller): 15 + 25 = 40
        $ticket = $this->ticket(['incluye_armado' => true, 'monto_armado' => 25], [['A', 1, 18], ['B', 1, 25], ['C', 1, 10]]);

        $presupuesto = $this->mensajes($ticket)['presupuesto'];

        $this->assertStringContainsString('Repuestos: $53.00', $presupuesto);
        $this->assertStringContainsString('Servicio: $40.00', $presupuesto);
        $this->assertStringContainsString('Total del presupuesto: $93.00', $presupuesto);
        $this->assertSame(93.0, $ticket->refresh()->totalTicket());
    }

    public function test_sin_armado_el_servicio_es_solo_el_monto_del_servicio(): void
    {
        $ticket = $this->ticket([], [['CADENA KMC9', 1, 25]]);

        $presupuesto = $this->mensajes($ticket)['presupuesto'];

        $this->assertStringContainsString('Servicio: $15.00', $presupuesto);
        $this->assertStringContainsString('Total del presupuesto: $40.00', $presupuesto);
    }

    // ---- criterio 2: repuesto sin precio ----
    public function test_un_repuesto_sin_precio_dice_precio_por_confirmar_y_quita_las_lineas_de_total_pero_no_la_del_servicio(): void
    {
        $ticket = $this->ticket([], [['PUÑOS TOLS', 1, 18], ['CABLE DE FRENO', 3, 0]]);

        $esperado = "Hola Muñoz Pérez 👋 Te saludamos de parte del equipo de OnBike Margarita. Revisando tu TREK NEGRA / NARANJA encontramos piezas a reemplazar:\n"
            ."• PUÑOS TOLS × 1 — \$18.00\n"
            ."• CABLE DE FRENO × 3 — precio por confirmar\n"
            ."Servicio: \$15.00\n"
            .'¿Nos autorizas a continuar?';

        $this->assertSame($esperado, $this->mensajes($ticket)['presupuesto']);
    }

    public function test_el_presupuesto_esta_disponible_en_cualquier_estado_incluso_atendido(): void
    {
        $ticket = $this->ejemplo();
        $ticket->forceFill(['estado' => 'atendido'])->saveQuietly();

        $this->assertStringContainsString('Total del presupuesto: $68.00', $this->mensajes($ticket)['presupuesto']);
    }

    // ---- criterio 3: recorte por largo del enlace ----
    public function test_con_25_repuestos_el_enlace_codificado_no_pasa_de_1800_y_el_recorte_cuenta_bien(): void
    {
        $texto = $this->mensajes($this->ticketConMuchosRepuestos())['presupuesto'];

        $this->assertLessThanOrEqual(PresupuestoWhatsapp::URL_MAX, PresupuestoWhatsapp::largoDelEnlace('584141234567', $texto));

        preg_match('/• y (\d+) más \(te enviamos el detalle completo\)/', $texto, $m);
        $this->assertNotEmpty($m, 'el texto avisa cuantos faltan');
        $omitidos = (int) $m[1];
        $visibles = preg_match_all('/^• AMORTIGUADOR/m', $texto);

        $this->assertGreaterThan(0, $omitidos);
        $this->assertSame(25, $visibles + $omitidos, 'N es correcto: visibles + omitidos = 25');

        // los totales siguen siendo los de TODOS los repuestos, no solo los visibles
        $totalReal = array_sum(array_map(fn ($i) => 10 + $i, range(1, 25)));
        $this->assertStringContainsString('Repuestos: $'.number_format($totalReal, 2), $texto);
    }

    public function test_el_recorte_es_minimo_con_una_linea_mas_el_enlace_se_pasaria(): void
    {
        $texto = $this->mensajes($this->ticketConMuchosRepuestos())['presupuesto'];
        preg_match('/• y (\d+) más/', $texto, $m);
        $omitidos = (int) $m[1];

        // vuelve a poner la primera linea omitida: ya no cabe
        $siguiente = 25 - $omitidos + 1;
        $lineaSiguiente = '• '.self::NOMBRE_LARGO.' '.$siguiente.' × 1 — $'.number_format(10 + $siguiente, 2);
        $nuevoAviso = $omitidos > 1 ? "\n• y ".($omitidos - 1).' más (te enviamos el detalle completo)' : '';
        $conUnaMas = str_replace("• y {$omitidos} más (te enviamos el detalle completo)", $lineaSiguiente.$nuevoAviso, $texto);

        $this->assertGreaterThan(PresupuestoWhatsapp::URL_MAX, PresupuestoWhatsapp::largoDelEnlace('584141234567', $conUnaMas));
    }

    public function test_el_largo_se_mide_como_encodeURIComponent(): void
    {
        // encodeURIComponent("Ñ🚲 !*'()\n") = %C3%91%F0%9F%9A%B2%20!*'()%0A = 29 caracteres
        $this->assertSame(strlen('https://wa.me/584141234567?text=') + 29, PresupuestoWhatsapp::largoDelEnlace('584141234567', "Ñ🚲 !*'()\n"));
    }

    // ---- criterio 4: editar y restablecer ----
    public function test_el_texto_editado_en_configuracion_se_guarda_y_es_el_que_usa_el_mensaje_y_restablecer_vuelve_al_original(): void
    {
        $ticket = $this->ejemplo();

        $this->guardarPlantillas(['presupuesto' => "Hola {nombre}: {lista_repuestos}\nTodo: {monto} (rep {total_repuestos}, serv {monto_servicio}) para el {fecha_entrega}"])
            ->assertSessionHasNoErrors()->assertRedirect('/configuracion');

        $this->assertStringStartsWith('Hola', Configuracion::valorDe('whatsapp_taller_presupuesto'));
        $this->assertSame(
            "Hola Muñoz Pérez: • PUÑOS TOLS × 1 — \$18.00\n• CADENA KMC9 × 1 — \$25.00\n• GUAYAS FRENOS × 2 — \$10.00\nTodo: \$68.00 (rep \$53.00, serv \$15.00) para el por confirmar",
            $this->mensajes($ticket)['presupuesto'],
        );

        // restablecer = guardar el texto original: deja de guardarse el valor y vuelve al de config
        $this->guardarPlantillas(['presupuesto' => PlantillasWhatsapp::defecto('presupuesto')])->assertSessionHasNoErrors();

        $this->assertNull(Configuracion::valorDe('whatsapp_taller_presupuesto'));
        $this->assertSame(config('taller.whatsapp_presupuesto'), PlantillasWhatsapp::texto('presupuesto'));
        $this->assertStringContainsString('Total del presupuesto: $68.00', $this->mensajes($ticket)['presupuesto']);
    }

    public function test_configuracion_lista_la_plantilla_nueva_con_sus_variables(): void
    {
        $props = $this->actingAs($this->admin)->get('/configuracion')->assertOk()->viewData('page')['props']['plantillasTaller'];

        $presupuesto = collect($props)->firstWhere('campo', 'presupuesto');

        $this->assertSame('Presupuesto de repuestos', $presupuesto['etiqueta']);
        $this->assertSame(['nombre', 'bici', 'fecha_entrega', 'monto', 'lista_repuestos', 'total_repuestos', 'monto_servicio'], $presupuesto['variables']);
        $this->assertSame(config('taller.whatsapp_presupuesto'), $presupuesto['defecto']);
        $this->assertCount(6, $props);
    }

    // ---- criterio 6: validacion ----
    public function test_las_variables_nuevas_solo_se_aceptan_en_presupuesto(): void
    {
        foreach (['{lista_repuestos}', '{total_repuestos}', '{monto_servicio}'] as $variable) {
            foreach (['listo_por_cobrar', 'listo_pagado', 'repuesto', 'danos', 'libre'] as $campo) {
                $this->guardarPlantillas([$campo => "Hola {nombre} {$variable}"])
                    ->assertSessionHasErrors($campo, "$variable debe rechazarse en $campo");
            }
            $this->guardarPlantillas(['presupuesto' => "Hola {nombre} {$variable}"])->assertSessionHasNoErrors();
        }
    }

    public function test_monto_se_acepta_en_presupuesto_y_unidad_lista_y_sigue_rechazado_en_las_demas(): void
    {
        $this->guardarPlantillas(['presupuesto' => 'Total {monto}', 'listo_por_cobrar' => 'Total {monto}', 'listo_pagado' => 'Era {monto}'])->assertSessionHasNoErrors();

        foreach (['repuesto' => 'Falta un repuesto', 'danos' => 'Detalle en la revisión', 'libre' => 'Mensaje libre'] as $campo => $etiqueta) {
            $this->guardarPlantillas([$campo => 'Cuesta {monto}'])
                ->assertSessionHasErrors([$campo => "{monto} solo se permite en «Unidad lista» (por cobrar y ya pagado); quítalo de «{$etiqueta}»."]);
        }
    }

    public function test_fecha_entrega_se_acepta_en_presupuesto_y_una_variable_desconocida_se_rechaza(): void
    {
        $this->guardarPlantillas(['presupuesto' => 'Sale el {fecha_entrega}'])->assertSessionHasNoErrors();

        $this->guardarPlantillas(['presupuesto' => 'Hola {apodo}'])->assertSessionHasErrors([
            'presupuesto' => 'La variable {apodo} no es válida en «Presupuesto de repuestos». Las válidas son: {nombre}, {bici}, {fecha_entrega}, {monto}, {lista_repuestos}, {total_repuestos}, {monto_servicio}.',
        ]);
    }

    public function test_el_texto_vacio_del_presupuesto_no_se_acepta(): void
    {
        $this->guardarPlantillas(['presupuesto' => '  '])
            ->assertSessionHasErrors(['presupuesto' => 'El texto no puede quedar vacío; usa «Restablecer al texto original».']);
    }

    // ---- criterio 7: sin repuestos ----
    public function test_un_ticket_sin_repuestos_no_genera_presupuesto(): void
    {
        $ticket = $this->ticket();

        $mensajes = $this->mensajes($ticket);

        $this->assertArrayNotHasKey('presupuesto', $mensajes);
        $this->assertSame(['unidad_lista', 'repuesto', 'danos', 'libre'], array_keys($mensajes));
        $this->assertNull(PresupuestoWhatsapp::mensaje($ticket->load('repuestos', 'cliente'), PlantillasWhatsapp::texto('presupuesto'), []));
    }
}
