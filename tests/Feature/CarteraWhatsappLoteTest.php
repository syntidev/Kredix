<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\User;
use App\Services\CobranzaWhatsapp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// Lote 11: cola de WhatsApp en lote sobre /cartera
class CarteraWhatsappLoteTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate');
        $this->admin = User::factory()->create(['es_admin' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cargo(Cliente $c, float $monto): void
    {
        MovimientoCuenta::create(['cliente_id' => $c->id, 'fecha' => '2026-11-01', 'tipo' => 'cargo', 'descripcion' => 'Casco', 'cantidad' => 1, 'precio_unitario' => $monto, 'monto' => $monto, 'moneda' => 'usd', 'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'mensual', 'registrado_por' => $this->admin->id]);
    }

    private function cliente(string $nombre, ?string $tel = '+584141234567', float $saldo = 30): Cliente
    {
        $c = Cliente::create(['nombre' => $nombre, 'telefono' => $tel]);
        if ($saldo > 0) {
            $this->cargo($c, $saldo);
        }

        return $c;
    }

    private function cola(array $q = []): array
    {
        return $this->actingAs($this->admin)->get('/cartera/whatsapp-lote?'.http_build_query($q))->assertOk()->viewData('page')['props'];
    }

    private function nombres(array $props): array
    {
        return array_column($props['filas'], 'nombre');
    }

    public function test_rango_normaliza_acentos_ordena_y_cuenta_sin_letra(): void
    {
        foreach (['Álvarez', 'Beto', 'Luis', 'Maria', 'Ñandú', '9 Express', 'Ana'] as $n) {
            $this->cliente($n);
        }

        $ab = $this->cola(['desde' => 'A', 'hasta' => 'L']);
        $this->assertSame(['Álvarez', 'Ana', 'Beto', 'Luis'], $this->nombres($ab));
        $this->assertSame(1, $ab['sinLetra']);

        $todo = $this->cola();
        $this->assertCount(7, $todo['filas']);
        $this->assertSame('9 Express', $todo['filas'][0]['nombre']);
    }

    public function test_exclusiones_y_suma_del_resumen(): void
    {
        $this->cliente('Con saldo');
        $this->cliente('Sin tel', null);
        $this->cliente('Saldo cero', '+584141111111', 0);

        $f = collect($this->cola(['tipo' => 'promocion'])['filas']);
        $this->assertSame(1, $f->where('estado', 'pendiente')->count());
        $this->assertSame(1, $f->where('estado', 'sin_telefono')->count());
        $this->assertSame(1, $f->where('estado', 'no_aplica')->count());
        $this->assertSame(3, $f->count());
    }

    public function test_conjunto_igual_a_cartera_y_un_cliente_una_vez(): void
    {
        $a = $this->cliente('Ana');
        $this->cliente('Zeta');
        $this->cargo($a, 5);

        $ids = array_column($this->cola()['filas'], 'id');
        $cartera = $this->actingAs($this->admin)->get('/cartera')->viewData('page')['props']['clientes']['data'];
        $this->assertEqualsCanonicalizing(array_column($cartera, 'id'), $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_mensaje_y_saldo_iguales_a_la_ficha(): void
    {
        $c = $this->cliente('Ana', '+584141234567', 42.5);
        $props = $this->cola();
        $esperado = collect(CobranzaWhatsapp::paraCliente($c)['tipos'])->firstWhere('tipo', 'promocion')['texto'];

        $this->assertSame($esperado, $props['mensajes'][$c->id]);
        $this->assertSame(MovimientoCuenta::saldoPendiente($c->id), $props['filas'][0]['saldo']);

        $json = $this->actingAs($this->admin)->getJson('/cartera/whatsapp-lote/mensajes?tipo=promocion&ids[]='.$c->id)->assertOk()->json();
        $this->assertSame($esperado, $json[$c->id]);
        $this->assertLessThanOrEqual(1800, strlen('https://wa.me/584141234567?text='.rawurlencode($esperado)));
    }

    public function test_enviado_del_mes_sale_de_pendientes_y_el_mes_anterior_no_cuenta(): void
    {
        $a = $this->cliente('Ana');
        $b = $this->cliente('Beto');
        $this->actingAs($this->admin)->post("/clientes/{$a->id}/whatsapp-cobranza", ['tipo' => 'promocion'])->assertNoContent();
        $log = Activity::where('description', 'whatsapp_cobranza')->firstOrFail();
        $this->assertArrayNotHasKey('texto', $log->properties->toArray());
        $this->assertSame($this->admin->id, $log->causer_id);

        $viejo = Activity::create(['log_name' => 'default', 'description' => 'whatsapp_cobranza', 'subject_type' => $b->getMorphClass(), 'subject_id' => $b->id, 'properties' => ['tipo' => 'promocion']]);
        $viejo->forceFill(['created_at' => Carbon::now('America/Caracas')->startOfMonth()->subSecond()])->save();

        $props = $this->cola();
        $this->assertSame(['Beto'], $this->nombres($props));
        $this->assertSame(1, $props['enviadosMes']);
        $this->assertCount(2, $this->cola(['incluir_enviados' => 1])['filas']);
    }

    public function test_deshacer_registra_compensatorio_y_baja_el_conteo(): void
    {
        $a = $this->cliente('Ana');
        $this->actingAs($this->admin)->post("/clientes/{$a->id}/whatsapp-cobranza", ['tipo' => 'promocion']);
        $this->assertSame(1, $this->cola()['enviadosMes']);

        $this->actingAs($this->admin)->post("/cartera/whatsapp-lote/{$a->id}/deshacer", ['tipo' => 'promocion'])->assertNoContent();
        $props = $this->cola();
        $this->assertSame(0, $props['enviadosMes']);
        $this->assertSame('pendiente', $props['filas'][0]['estado']);
        $this->assertSame(1, Activity::where('description', 'whatsapp_cobranza_deshecho')->count());

        $this->actingAs($this->admin)->post("/cartera/whatsapp-lote/{$a->id}/deshacer", ['tipo' => 'promocion'])->assertStatus(422);
    }

    public function test_consultas_no_crecen_con_el_numero_de_clientes(): void
    {
        $contar = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->cola();

            return count(DB::getQueryLog());
        };
        for ($i = 0; $i < 8; $i++) {
            $this->cliente('Cli '.chr(65 + $i).$i);
        }
        $contar(); // calienta cachés de configuracion
        $pocos = $contar();
        for ($i = 8; $i < 233; $i++) {
            $this->cliente('Cli '.chr(65 + $i % 26).$i);
        }
        $this->assertSame($pocos, $contar());
        $this->assertCount(233, $this->cola()['filas']);
    }

    public function test_acceso(): void
    {
        $this->get('/cartera/whatsapp-lote')->assertRedirect('/login');
        $normal = User::factory()->create(['es_admin' => false]);
        $this->actingAs($normal)->get('/cartera/whatsapp-lote')->assertOk();
        $taller = User::factory()->create(['rol_taller' => true]);
        $this->actingAs($taller)->get('/cartera/whatsapp-lote')->assertForbidden();
    }
}
