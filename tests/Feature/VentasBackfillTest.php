<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\TicketTaller;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

// Migracion atendido_en: los tickets ya atendidos reciben fecha real (created_at del cargo
// vinculado) o aproximada (created_at del ticket, marcada con atendido_en_aprox)
class VentasBackfillTest extends TestCase
{
    private const MIGRACION = 'database/migrations/2026_10_22_100000_add_atendido_en_to_tickets_taller.php';

    private function migraciones(): array
    {
        return collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => str_contains($p, 'replace_procesado_con_estado_en_prospectos_200k'))
            ->values()->all();
    }

    private function ticket(User $u, array $datos = []): TicketTaller
    {
        return TicketTaller::create([
            'tipo' => 'servicio_cliente',
            'bici_marca_modelo' => 'Trek Marlin',
            'categoria_bici' => 'mtb',
            'talla_rin' => '29',
            'es_electrica' => false,
            'tipo_servicio' => 'basico',
            'diagnostico' => [],
            'mecanico_id' => $u->id,
            'registrado_por' => $u->id,
            ...$datos,
        ]);
    }

    public function test_backfill_usa_el_cargo_vinculado_cuando_existe_y_created_at_marcado_aproximado_si_no(): void
    {
        Artisan::call('migrate', ['--path' => $this->migraciones(), '--realpath' => true]);
        $u = User::factory()->create();
        $cliente = Cliente::create(['nombre' => 'MUÑOZ PÉREZ', 'telefono' => '+584141234567']);

        $cargo = MovimientoCuenta::create([
            'cliente_id' => $cliente->id, 'fecha' => '2026-10-02', 'tipo' => 'cargo', 'descripcion' => 'Servicio de taller',
            'cantidad' => 1, 'precio_unitario' => 20, 'monto' => 20, 'moneda' => 'usd', 'registrado_por' => $u->id,
        ]);
        $cargo->forceFill(['created_at' => '2026-10-02 16:30:00'])->saveQuietly();

        $conCargo = $this->ticket($u, ['cliente_id' => $cliente->id, 'movimiento_cuenta_id' => $cargo->id]);
        $sinCargo = $this->ticket($u, ['cliente_id' => $cliente->id]);
        $enProceso = $this->ticket($u);
        foreach ([$conCargo, $sinCargo] as $t) {
            $t->forceFill(['estado' => 'atendido'])->saveQuietly();
        }
        $conCargo->forceFill(['created_at' => '2026-10-01 09:00:00'])->saveQuietly();
        $sinCargo->forceFill(['created_at' => '2026-10-03 11:15:00'])->saveQuietly();

        // vuelve al esquema anterior y corre la migracion con esos datos ya existentes
        Artisan::call('migrate:rollback', ['--path' => self::MIGRACION]);
        Artisan::call('migrate', ['--path' => self::MIGRACION]);

        $conCargo->refresh();
        $this->assertSame('2026-10-02 16:30:00', $conCargo->atendido_en->format('Y-m-d H:i:s'), 'momento real del cierre');
        $this->assertFalse($conCargo->atendido_en_aprox);

        $sinCargo->refresh();
        $this->assertSame('2026-10-03 11:15:00', $sinCargo->atendido_en->format('Y-m-d H:i:s'), 'aproximado: created_at');
        $this->assertTrue($sinCargo->atendido_en_aprox);

        $enProceso->refresh();
        $this->assertNull($enProceso->atendido_en, 'un ticket en proceso no se toca');
        $this->assertFalse($enProceso->atendido_en_aprox);
    }
}
