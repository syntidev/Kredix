<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Fase D: FotoInput solo cambia COMO se elige la imagen; los campos que viajan al servidor
// (foto_producto, comprobante) y su almacenamiento en MediaLibrary no cambian
class EvidenciaFotosTest extends TestCase
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

        Storage::fake('public');
        $this->usuario = User::factory()->create();
        $this->cliente = Cliente::create(['nombre' => 'Ana Perez', 'telefono' => '+584141234567']);
    }

    public function test_la_compra_guarda_foto_producto_en_la_coleccion_producto(): void
    {
        $this->actingAs($this->usuario)->post('/movimientos', [
            'tipo' => 'cargo', 'cliente_id' => $this->cliente->id, 'fecha' => '2026-11-01',
            'productos' => [['descripcion' => 'Bicicleta', 'cantidad' => 1, 'precio_unitario' => 100]],
            'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'mensual',
            'foto_producto' => UploadedFile::fake()->image('producto.jpg', 1200, 800),
        ])->assertSessionHasNoErrors();

        $cargo = MovimientoCuenta::where('tipo', 'cargo')->firstOrFail();
        $this->assertCount(1, $cargo->getMedia('producto'));
    }

    public function test_el_abono_guarda_comprobante_en_la_coleccion_comprobantes(): void
    {
        $this->actingAs($this->usuario)->post('/movimientos', [
            'tipo' => 'abono', 'cliente_id' => $this->cliente->id, 'fecha' => '2026-11-01',
            'descripcion' => 'Pago', 'monto' => 20, 'metodo_pago' => 'zelle', 'referencia' => 'ABC123',
            'comentario' => 'pago', 'comprobante' => UploadedFile::fake()->image('comprobante.jpg', 800, 1200),
        ])->assertSessionHasNoErrors();

        $abono = MovimientoCuenta::where('tipo', 'abono')->firstOrFail();
        $this->assertCount(1, $abono->getMedia('comprobantes'));
    }

    public function test_la_edicion_reemplaza_foto_y_comprobante(): void
    {
        $cargo = MovimientoCuenta::create([
            'cliente_id' => $this->cliente->id, 'fecha' => '2026-11-01', 'tipo' => 'cargo', 'descripcion' => 'Bicicleta',
            'cantidad' => 1, 'precio_unitario' => 100, 'modalidad_precio' => 'divisa', 'plazo_meses' => 1,
            'frecuencia_pago' => 'mensual', 'monto' => 100, 'moneda' => 'usd', 'registrado_por' => $this->usuario->id,
        ]);

        $this->actingAs($this->usuario)->post("/movimientos/{$cargo->id}", [
            '_method' => 'put', 'fecha' => '2026-11-01', 'descripcion' => 'Bicicleta', 'moneda' => 'usd', 'cantidad' => 1,
            'precio_unitario' => 100, 'modalidad_precio' => 'divisa', 'plazo_meses' => 1, 'frecuencia_pago' => 'mensual',
            'motivo_edicion' => 'foto', 'foto_producto' => UploadedFile::fake()->image('nueva.jpg', 900, 600),
        ])->assertRedirect();

        $this->assertCount(1, $cargo->fresh()->getMedia('producto'));
    }
}
