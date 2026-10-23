<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\PlanFinanciamiento;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

// Con el esquema real: un plan anulado (producto borrado sin abonos) desaparece de la ficha,
// del PDF del estado de cuenta y del dinero en calle del KPI
class PlanVistasTest extends TestCase
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

        $this->usuario = User::factory()->create(['es_admin' => true, 'rol_taller' => false]);
        $this->cliente = Cliente::create(['nombre' => 'Cliente de prueba', 'telefono' => '+584141234567']);
    }

    private function financiarYBorrar(): void
    {
        $this->actingAs($this->usuario)->post('/movimientos', [
            'tipo' => 'cargo', 'cliente_id' => $this->cliente->id, 'fecha' => '2026-10-06',
            'productos' => [['descripcion' => 'RELOJ GARMIN', 'cantidad' => 1, 'precio_unitario' => 7200]],
            'modalidad_precio' => 'divisa', 'plazo_meses' => 3, 'frecuencia_pago' => 'mensual',
            'es_financiada' => true, 'monto_inicial' => 0, 'numero_cuotas_financiamiento' => 7, 'porcentaje_mora' => 10,
        ])->assertRedirect();

        $this->assertCount(1, $this->planesDeLaFicha(), 'antes de borrar la ficha muestra el plan');

        $linea = MovimientoCuenta::where('descripcion', 'RELOJ GARMIN')->firstOrFail();
        $this->actingAs($this->usuario)->delete("/movimientos/{$linea->id}", ['motivo' => 'producto anulado'])->assertRedirect();
    }

    private function planesDeLaFicha()
    {
        return collect($this->actingAs($this->usuario)->get("/clientes/{$this->cliente->id}")->assertOk()->viewData('page')['props']['planesFinanciamiento']);
    }

    public function test_vista_ficha_del_cliente_no_muestra_el_plan_anulado(): void
    {
        $this->financiarYBorrar();

        $this->assertCount(0, $this->planesDeLaFicha());
        $this->assertSame(0, PlanFinanciamiento::count());
    }

    public function test_vista_estado_de_cuenta_pdf_no_lista_el_plan_anulado(): void
    {
        $this->financiarYBorrar();

        $datos = null;
        View::composer('pdf.estado-cuenta', function ($vista) use (&$datos) {
            $datos = $vista->getData();
        });
        $this->actingAs($this->usuario)->get("/clientes/{$this->cliente->id}/estado-cuenta-cliente.pdf")->assertOk();

        $this->assertTrue($datos['planesFinanciamiento']->isEmpty());
    }

    public function test_vista_kpi_no_cuenta_el_dinero_en_calle_del_producto_anulado(): void
    {
        $this->financiarYBorrar();

        $props = $this->actingAs($this->usuario)->get('/kpi')->assertOk()->viewData('page')['props'];

        $this->assertEquals(0, is_array($props['dineroEnCalle']) ? array_sum(array_filter($props['dineroEnCalle'], 'is_numeric')) : $props['dineroEnCalle']);
    }
}
