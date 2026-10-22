<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// Panel Ventas: solo es_admin y no rol_taller (igual que KPI) y validacion del rango
class VentasAccesoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migraciones = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => str_contains($p, 'replace_procesado_con_estado_en_prospectos_200k'))
            ->values()->all();
        Artisan::call('migrate', ['--path' => $migraciones, '--realpath' => true]);
    }

    private function usuario(array $datos): User
    {
        return User::factory()->create($datos);
    }

    public function test_el_admin_entra_y_recibe_el_resumen(): void
    {
        $admin = $this->usuario(['es_admin' => true, 'rol_taller' => false]);

        $this->actingAs($admin)->get('/ventas')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Ventas/Index')
            ->where('rango', 'hoy')
            ->has('ventas.credito')->has('ventas.taller_servicio')->has('ventas.taller_repuestos')
            ->has('ventas.total')->has('ventas.articulos')->has('ventas.cargos_sin_fecha'));
    }

    public function test_un_usuario_no_admin_es_redirigido_al_inicio(): void
    {
        $operador = $this->usuario(['es_admin' => false, 'rol_taller' => false]);

        $this->actingAs($operador)->get('/ventas')->assertRedirect(route('home'));
    }

    public function test_rol_taller_recibe_403_aunque_sea_admin(): void
    {
        $this->actingAs($this->usuario(['es_admin' => false, 'rol_taller' => true]))->get('/ventas')->assertForbidden();
        $this->actingAs($this->usuario(['es_admin' => true, 'rol_taller' => true]))->get('/ventas')->assertForbidden();
    }

    public function test_sin_iniciar_sesion_va_al_login(): void
    {
        $this->get('/ventas')->assertRedirect('/login');
    }

    public function test_rango_libre_pasa_sus_fechas_al_resumen(): void
    {
        $admin = $this->usuario(['es_admin' => true, 'rol_taller' => false]);

        $this->actingAs($admin)->get('/ventas?rango=libre&desde=2026-09-01&hasta=2026-09-30')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('rango', 'libre')
            ->where('ventas.desde', '2026-09-01')
            ->where('ventas.hasta', '2026-09-30'));
    }

    public function test_rango_libre_con_desde_posterior_a_hasta_se_rechaza(): void
    {
        $admin = $this->usuario(['es_admin' => true, 'rol_taller' => false]);

        $this->actingAs($admin)->get('/ventas?rango=libre&desde=2026-09-30&hasta=2026-09-01')
            ->assertSessionHasErrors(['hasta' => 'la fecha hasta no puede ser anterior a la fecha desde']);
    }

    public function test_rango_libre_sin_fechas_o_rango_desconocido_se_rechaza(): void
    {
        $admin = $this->usuario(['es_admin' => true, 'rol_taller' => false]);

        $this->actingAs($admin)->get('/ventas?rango=libre')->assertSessionHasErrors(['desde', 'hasta']);
        $this->actingAs($admin)->get('/ventas?rango=siempre')->assertSessionHasErrors('rango');
    }
}
