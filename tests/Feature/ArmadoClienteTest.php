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

// Armado con cliente opcional: se crea y edita con cliente y monto de servicio, y
// al cerrar genera cargo solo con cliente + monto > 0 + no pagado en taller
class ArmadoClienteTest extends TestCase
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
        config(['ia.activa' => false, 'taller.revision_tecnica_activa' => true]);
        $this->usuario = User::factory()->create(['name' => 'Kleiver', 'rol_taller' => true]);
        $this->cliente = Cliente::create(['nombre' => 'Ana Perez', 'telefono' => '+584141234567']);
    }

    /** Armado listo para cerrar: con fotos y revision. */
    private function armado(array $datos = []): TicketTaller
    {
        $t = TicketTaller::create([
            'tipo' => 'armado_interno',
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

    private function cerrar(TicketTaller $t, array $extra = [])
    {
        return $this->actingAs($this->usuario)->patch("/taller/{$t->id}/marcar-atendido", [
            'texto_cliente' => 'sin_texto',
            ...$extra,
        ]);
    }

    public function test_con_cliente_y_monto_a_credito_genera_el_cargo_por_el_total(): void
    {
        $t = $this->armado(['cliente_id' => $this->cliente->id, 'monto_servicio' => 30]);
        $t->repuestos()->create(['producto' => 'Cadena', 'cantidad' => 2, 'precio' => 5]);

        $this->cerrar($t, ['pagado_en_taller' => false])->assertRedirect();

        $cargo = MovimientoCuenta::where('tipo', 'cargo')->firstOrFail();
        $this->assertSame($this->cliente->id, $cargo->cliente_id);
        $this->assertSame(40.0, (float) $cargo->monto);
        $t->refresh();
        $this->assertSame('atendido', $t->estado);
        $this->assertSame($cargo->id, $t->movimiento_cuenta_id);
    }

    public function test_con_cliente_y_monto_pagado_en_taller_no_genera_cargo(): void
    {
        $t = $this->armado(['cliente_id' => $this->cliente->id, 'monto_servicio' => 30]);

        $this->cerrar($t, ['pagado_en_taller' => true])->assertRedirect();

        $this->assertSame(0, MovimientoCuenta::count());
        $this->assertSame('atendido', $t->fresh()->estado);
    }

    public function test_con_cliente_y_monto_exige_decir_si_se_pago(): void
    {
        $t = $this->armado(['cliente_id' => $this->cliente->id, 'monto_servicio' => 30]);

        $this->cerrar($t)->assertSessionHasErrors('pagado_en_taller');

        $this->assertSame('en_proceso', $t->fresh()->estado);
    }

    public function test_con_cliente_sin_monto_cierra_sin_cargo_ni_pregunta_de_pago(): void
    {
        $t = $this->armado(['cliente_id' => $this->cliente->id]);

        $this->cerrar($t)->assertRedirect();

        $this->assertSame(0, MovimientoCuenta::count());
        $this->assertSame('atendido', $t->fresh()->estado);
    }

    public function test_sin_cliente_con_monto_cierra_sin_cargo(): void
    {
        $t = $this->armado(['monto_servicio' => 30]);

        $this->cerrar($t)->assertRedirect();

        $this->assertSame(0, MovimientoCuenta::count());
        $this->assertSame('atendido', $t->fresh()->estado);
    }

    public function test_sin_cliente_ni_monto_cierra_sin_cargo(): void
    {
        $t = $this->armado();

        $this->cerrar($t)->assertRedirect();

        $this->assertSame(0, MovimientoCuenta::count());
    }

    public function test_el_total_de_un_armado_antiguo_sin_monto_no_cambia(): void
    {
        $t = $this->armado();
        $t->repuestos()->create(['producto' => 'Cadena', 'cantidad' => 2, 'precio' => 5]);

        $this->assertSame(10.0, $t->fresh()->totalTicket());
        $this->assertFalse($t->fresh()->seCobra());
    }

    public function test_el_pdf_de_un_armado_viejo_con_cliente_se_ve_igual(): void
    {
        $conCliente = $this->armado(['cliente_id' => $this->cliente->id, 'monto_servicio' => 30]);
        $sinCliente = $this->armado();

        $html = $this->htmlDelPdf($conCliente);
        $this->assertStringContainsString('Ana Perez', $html);
        $this->assertStringNotContainsString('Trabajo de la tienda', $html);
        $this->assertStringContainsString('Servicio de armado', $html);

        $this->assertStringContainsString('Trabajo de la tienda', $this->htmlDelPdf($sinCliente));
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
}
