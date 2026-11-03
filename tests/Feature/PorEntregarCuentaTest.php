<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

// Lote 2: la tarjeta "Por entregar" muestra Total / Abono / Saldo con el MISMO calculo de la ficha del cliente
class PorEntregarCuentaTest extends TestCase
{
    private User $usuario;

    private User $admin;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate');
        $this->usuario = User::factory()->create();
        $this->admin = User::factory()->create(['es_admin' => true]);
        $this->cliente = Cliente::create(['nombre' => 'Miguel Termini', 'telefono' => '+584141234567']);
    }

    private function venta(Cliente $cliente, string $articulo, float $precio, bool $contado, bool $pendiente = true): void
    {
        $this->actingAs($this->usuario)->post('/movimientos', [
            'tipo' => 'cargo', 'cliente_id' => $cliente->id, 'fecha' => '2026-11-01',
            'productos' => [['descripcion' => $articulo, 'cantidad' => 1, 'precio_unitario' => $precio, 'pendiente_entrega' => $pendiente]],
            'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'mensual',
            'es_financiada' => $contado, 'monto_inicial' => $contado ? $precio : null, 'metodo_pago_inicial' => $contado ? 'efectivo' : null,
            'numero_cuotas_financiamiento' => 1, 'porcentaje_mora' => 0,
        ])->assertRedirect();
    }

    private function abonoGeneral(Cliente $cliente, float $monto): void
    {
        $this->actingAs($this->usuario)->post('/movimientos', [
            'tipo' => 'abono', 'cliente_id' => $cliente->id, 'fecha' => '2026-11-01', 'descripcion' => 'Zelle',
            'monto' => $monto, 'metodo_pago' => 'zelle', 'referencia' => 'ZL1', 'comentario' => 'abono',
        ])->assertRedirect();
    }

    private function tarjeta(Cliente $cliente): array
    {
        $lista = null;
        $this->actingAs($this->admin)->get('/ventas?por_entregar=1')->assertInertia(function ($p) use (&$lista) {
            $lista = $p->toArray()['props']['porEntregar'];
        });

        return collect($lista)->firstWhere('clienteId', $cliente->id);
    }

    private function ficha(Cliente $cliente): array
    {
        $props = null;
        $this->actingAs($this->admin)->get("/clientes/{$cliente->id}")->assertInertia(function ($p) use (&$props) {
            $props = $p->toArray()['props'];
        });

        return $props;
    }

    private function assertIgualAFicha(Cliente $cliente, array $t): void
    {
        $ficha = $this->ficha($cliente);
        $this->assertEquals($ficha['totalCobrado'], $t['abono'], 'Abono == Total cobrado de la ficha');
        $this->assertEquals($ficha['saldoPendiente'], $t['saldo'], 'Saldo == Saldo pendiente de la ficha');
    }

    public function test_caso_miguel_credito_con_abono_general_sin_vinculo_a_la_compra(): void
    {
        $this->venta($this->cliente, 'Pedales Garmin RS210', 1199, false);
        $this->abonoGeneral($this->cliente, 250);

        $t = $this->tarjeta($this->cliente);

        $this->assertEquals(1199.0, $t['total']);
        $this->assertEquals(250.0, $t['abono']);
        $this->assertEquals(949.0, $t['saldo']);
        $this->assertFalse($t['cuenta_con_otros_cargos'], 'cuenta de una sola compra: sin nota');
        $this->assertIgualAFicha($this->cliente, $t);
    }

    public function test_caso_aponte_contado_total_igual_abono_y_saldo_cero(): void
    {
        $aponte = Cliente::create(['nombre' => 'Carlos Aponte', 'telefono' => '+584141230000']);
        $this->venta($aponte, 'Garmin Fenix 9 Pro', 1150, true);

        $t = $this->tarjeta($aponte);

        $this->assertEquals(1150.0, $t['total']);
        $this->assertEquals(1150.0, $t['abono']);
        $this->assertEquals(0.0, $t['saldo']);
        $this->assertTrue($t['contado']);
        $this->assertIgualAFicha($aponte, $t);
    }

    public function test_con_otra_deuda_vigente_la_tarjeta_lleva_la_nota_de_cuenta_completa(): void
    {
        $this->venta($this->cliente, 'Pedales', 1199, false);
        $this->venta($this->cliente, 'Casco', 80, false, false); // otra compra, ya entregada
        $this->abonoGeneral($this->cliente, 250);

        $t = $this->tarjeta($this->cliente);

        $this->assertTrue($t['cuenta_con_otros_cargos']);
        $this->assertEquals(1199.0, $t['total'], 'Total es solo el de esa compra');
        $this->assertEquals(250.0, $t['abono']);
        $this->assertEquals(1029.0, $t['saldo'], 'saldo de toda la cuenta: 1199 + 80 - 250');
        $this->assertIgualAFicha($this->cliente, $t);
    }

    public function test_un_abono_o_cargo_anulado_no_cuenta(): void
    {
        $this->venta($this->cliente, 'Pedales', 1199, false);
        $this->abonoGeneral($this->cliente, 250);
        MovimientoCuenta::where('tipo', 'abono')->firstOrFail()->delete();

        $t = $this->tarjeta($this->cliente);

        $this->assertEquals(0.0, $t['abono']);
        $this->assertEquals(1199.0, $t['saldo']);
        $this->assertIgualAFicha($this->cliente, $t);
    }
}
