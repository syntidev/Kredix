<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// Fase C: ventas adelantadas ("Aun no entregado") con bloque "Por entregar" en Inicio
class EntregasPendientesTest extends TestCase
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

        $this->usuario = User::factory()->create(['name' => 'Operador']);
        $this->cliente = Cliente::create(['nombre' => 'Ana Perez', 'telefono' => '+584141234567']);
    }

    private function comprar(array $productos): void
    {
        $this->actingAs($this->usuario)->post('/movimientos', [
            'tipo' => 'cargo',
            'cliente_id' => $this->cliente->id,
            'fecha' => now()->subDays(3)->toDateString(),
            'productos' => $productos,
            'modalidad_precio' => 'divisa',
            'plazo_meses' => 3,
            'frecuencia_pago' => 'mensual',
        ])->assertSessionHasNoErrors();
    }

    private function porEntregar(): array
    {
        $lista = null;
        $this->actingAs($this->usuario)->get('/home')
            ->assertInertia(function ($p) use (&$lista) {
                $lista = $p->toArray()['props']['porEntregar'];
            });

        return $lista;
    }

    private function cargoPendiente(string $descripcion = 'Bicicleta', ?string $fecha = null): MovimientoCuenta
    {
        return MovimientoCuenta::create([
            'cliente_id' => $this->cliente->id, 'fecha' => $fecha ?? now()->toDateString(), 'tipo' => 'cargo',
            'descripcion' => $descripcion, 'cantidad' => 1, 'precio_unitario' => 100, 'modalidad_precio' => 'divisa',
            'plazo_meses' => 1, 'frecuencia_pago' => 'mensual', 'monto' => 100, 'moneda' => 'usd',
            'registrado_por' => $this->usuario->id, 'pendiente_entrega' => true,
        ]);
    }

    public function test_compra_con_dos_productos_solo_el_marcado_aparece_en_por_entregar(): void
    {
        $this->comprar([
            ['descripcion' => 'Bicicleta GW', 'cantidad' => 1, 'precio_unitario' => 300, 'pendiente_entrega' => true],
            ['descripcion' => 'Casco', 'cantidad' => 1, 'precio_unitario' => 20, 'pendiente_entrega' => false],
        ]);

        $lista = $this->porEntregar();

        $this->assertCount(1, $lista);
        $this->assertSame('Bicicleta GW', $lista[0]['producto']);
        $this->assertSame(3, $lista[0]['dias']);
        $this->assertSame('Ana Perez', $lista[0]['clienteNombre']);
    }

    public function test_marcar_entregado_lo_saca_de_la_lista_y_deja_autor_fecha_y_log(): void
    {
        $m = $this->cargoPendiente();

        $this->actingAs($this->usuario)->patch("/movimientos/{$m->id}/entregar")->assertRedirect();

        $this->assertSame([], $this->porEntregar());
        $m->refresh();
        $this->assertFalse($m->pendiente_entrega);
        $this->assertNotNull($m->entregado_en);
        $this->assertSame($this->usuario->id, (int) $m->entregado_por);
        $this->assertTrue(Activity::where('description', 'entrega_movimiento')->where('subject_id', $m->id)->exists());
    }

    public function test_entregar_un_cargo_que_no_esta_pendiente_da_422(): void
    {
        $m = $this->cargoPendiente();
        $m->update(['pendiente_entrega' => false]);

        $this->actingAs($this->usuario)->patch("/movimientos/{$m->id}/entregar")->assertStatus(422);
    }

    public function test_editar_el_cargo_marca_y_desmarca_pendiente(): void
    {
        $m = $this->cargoPendiente();
        $m->update(['pendiente_entrega' => false]);
        $datos = [
            'fecha' => now()->toDateString(), 'descripcion' => 'Bicicleta', 'moneda' => 'usd', 'cantidad' => 1,
            'precio_unitario' => 100, 'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'mensual',
            'motivo_edicion' => 'apartado',
        ];

        $this->actingAs($this->usuario)->put("/movimientos/{$m->id}", [...$datos, 'pendiente_entrega' => true])->assertRedirect();
        $this->assertCount(1, $this->porEntregar());

        $this->actingAs($this->usuario)->put("/movimientos/{$m->id}", [...$datos, 'pendiente_entrega' => false])->assertRedirect();
        $this->assertSame([], $this->porEntregar());
    }

    public function test_cargo_borrado_no_aparece_y_la_marca_no_cambia_saldo_ni_kpi(): void
    {
        $admin = User::factory()->create(['es_admin' => true]);
        $m = $this->cargoPendiente();
        $kpi = fn () => $this->actingAs($admin)->get('/kpi')->assertInertia(fn ($p) => $p->where('dineroEnCalle', 100));

        $saldoAntes = MovimientoCuenta::saldoPendiente($this->cliente->id);
        $kpi();

        $this->actingAs($this->usuario)->patch("/movimientos/{$m->id}/entregar");

        $this->assertSame($saldoAntes, MovimientoCuenta::saldoPendiente($this->cliente->id));
        $kpi();

        $otro = $this->cargoPendiente('Casco');
        $otro->delete();
        $this->assertSame([], $this->porEntregar());
    }

    public function test_los_mas_antiguos_salen_primero(): void
    {
        $this->cargoPendiente('Reciente', now()->toDateString());
        $this->cargoPendiente('Antiguo', now()->subDays(20)->toDateString());

        $this->assertSame(['Antiguo', 'Reciente'], array_column($this->porEntregar(), 'producto'));
    }
}
