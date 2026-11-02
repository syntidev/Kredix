<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\TicketTaller;
use App\Models\User;
use App\Services\Taller\AjustesTaller;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

// Fase A: cierre sin foto de salida y tecnico por defecto configurable
class TallerCierreTecnicoTest extends TestCase
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
        config(['ia.activa' => false]);
        $this->usuario = User::factory()->create(['name' => 'Bruno', 'rol_taller' => true]);
        $this->cliente = Cliente::create(['nombre' => 'Ana Perez', 'telefono' => '+584141234567']);
    }

    private function ticket(array $datos = [], bool $conSalida = false): TicketTaller
    {
        $t = TicketTaller::create([
            'tipo' => 'servicio_cliente',
            'cliente_id' => $this->cliente->id,
            'tipo_servicio' => 'basico',
            'monto_servicio' => 15,
            'motivo_ingreso' => 'frena mal',
            'bici_marca_modelo' => 'Trek Marlin',
            'categoria_bici' => 'mtb',
            'talla_rin' => '29',
            'es_electrica' => false,
            'diagnostico' => [],
            'mecanico_id' => $this->usuario->id,
            'registrado_por' => $this->usuario->id,
            ...$datos,
        ]);
        $t->addMedia(UploadedFile::fake()->image('entrada.jpg', 600, 900))->toMediaCollection('entrada');
        if ($conSalida) {
            $t->addMedia(UploadedFile::fake()->image('salida.jpg', 600, 900))->toMediaCollection('salida');
        }

        return $t;
    }

    private function cerrar(TicketTaller $t)
    {
        return $this->actingAs($this->usuario)->patch("/taller/{$t->id}/marcar-atendido", [
            'texto_cliente' => 'sin_texto', 'pagado_en_taller' => true,
        ]);
    }

    public function test_cierra_sin_foto_de_salida_con_trabajo_realizado(): void
    {
        $t = $this->ticket(['trabajo_realizado' => 'Cambio de cadena']);

        $this->cerrar($t)->assertSessionHasNoErrors();

        $this->assertSame('atendido', $t->fresh()->estado);
    }

    public function test_sin_trabajo_realizado_ni_revision_da_error_claro(): void
    {
        $t = $this->ticket(['trabajo_realizado' => '  ']);

        $this->cerrar($t)->assertSessionHasErrors(['trabajo_realizado']);

        $this->assertNotSame('atendido', $t->fresh()->estado);
    }

    public function test_el_pdf_se_genera_sin_foto_de_salida(): void
    {
        $t = $this->ticket(['trabajo_realizado' => 'Cambio de cadena']);
        $datos = null;
        View::composer('pdf.taller-atencion', function ($vista) use (&$datos) {
            $datos = $vista->getData();
        });

        $this->actingAs($this->usuario)->get("/taller/{$t->id}/atencion-trek.pdf")->assertOk();

        $html = view('pdf.taller-atencion', $datos)->render();
        $this->assertStringNotContainsString('Fotos de salida', $html);
    }

    // ---- tecnico por defecto ----
    public function test_sin_ajuste_el_defecto_es_el_primer_tecnico_por_nombre(): void
    {
        User::factory()->create(['name' => 'Zeta', 'rol_taller' => true]);
        User::factory()->create(['name' => 'Alfa', 'rol_taller' => true]);

        $this->assertSame('Alfa', User::find(AjustesTaller::tecnicoDefectoId())->name);
    }

    public function test_con_ajuste_valido_el_defecto_es_ese_usuario(): void
    {
        $zeta = User::factory()->create(['name' => 'Zeta', 'rol_taller' => true]);
        Configuracion::updateOrCreate(['clave' => AjustesTaller::CLAVE_TECNICO_DEFECTO], ['valor' => $zeta->id]);

        $this->assertSame($zeta->id, AjustesTaller::tecnicoDefectoId());
    }

    public function test_con_ajuste_a_usuario_eliminado_o_no_tecnico_cae_al_primero(): void
    {
        $alfa = User::factory()->create(['name' => 'Alfa', 'rol_taller' => true]);
        $fantasma = User::factory()->create(['name' => 'Fantasma', 'rol_taller' => true]);
        Configuracion::updateOrCreate(['clave' => AjustesTaller::CLAVE_TECNICO_DEFECTO], ['valor' => $fantasma->id]);
        $fantasma->delete();

        $this->assertSame($alfa->id, AjustesTaller::tecnicoDefectoId());

        $noTecnico = User::factory()->create(['name' => 'Aaron', 'rol_taller' => false]);
        Configuracion::updateOrCreate(['clave' => AjustesTaller::CLAVE_TECNICO_DEFECTO], ['valor' => $noTecnico->id]);

        $this->assertSame($alfa->id, AjustesTaller::tecnicoDefectoId());
    }

    public function test_sin_tecnicos_el_defecto_es_null(): void
    {
        $this->usuario->update(['rol_taller' => false]);

        $this->assertNull(AjustesTaller::tecnicoDefectoId());
    }

    public function test_nuevo_ticket_recibe_el_tecnico_defecto(): void
    {
        $this->actingAs($this->usuario)->get('/taller/nuevo')
            ->assertInertia(fn ($page) => $page->where('tecnicoDefectoId', $this->usuario->id));
    }

    // ---- configuracion (solo admin) ----
    public function test_solo_admin_guarda_ajustes_de_taller(): void
    {
        $datos = ['tecnico_defecto_id' => $this->usuario->id, 'comision_servicio_pct' => 40, 'comision_repuestos_pct' => 3.5];

        $this->actingAs($this->usuario)->put('/configuracion/taller', $datos)->assertForbidden();

        $admin = User::factory()->create(['es_admin' => true]);
        $this->actingAs($admin)->put('/configuracion/taller', $datos)->assertSessionHasNoErrors();

        $this->assertSame('40', Configuracion::valorDe(AjustesTaller::CLAVE_COMISION_SERVICIO));
        $this->assertSame(40.0, AjustesTaller::comisionServicioPct());
        $this->assertSame(3.5, AjustesTaller::comisionRepuestosPct());
    }

    public function test_porcentajes_fuera_de_rango_dan_422(): void
    {
        $admin = User::factory()->create(['es_admin' => true]);

        $this->actingAs($admin)->putJson('/configuracion/taller', ['comision_servicio_pct' => 101, 'comision_repuestos_pct' => -1])
            ->assertStatus(422)->assertJsonValidationErrors(['comision_servicio_pct', 'comision_repuestos_pct']);
    }
}
