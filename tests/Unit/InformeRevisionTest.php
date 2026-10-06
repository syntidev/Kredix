<?php

namespace Tests\Unit;

use App\Models\TicketTaller;
use App\Services\Taller\InformeRevision;
use Tests\TestCase;

class InformeRevisionTest extends TestCase
{
    private function texto(?string $paquete, array $revision): string
    {
        $ticket = new TicketTaller(['tipo_servicio' => $paquete]);
        $ticket->revision_tecnica = ['version' => 1, ...$revision];

        return (new InformeRevision)->generarTexto($ticket);
    }

    private function c(array $acciones, array $motivos = [], string $nota = ''): array
    {
        return ['acciones' => $acciones, 'motivos' => $motivos, 'nota' => $nota, 'origen' => 'manual'];
    }

    public function test_solo_ok(): void
    {
        $texto = $this->texto('basico', ['tareas' => [], 'componentes' => [
            'pastillas' => $this->c(['ok']),
            'discos' => $this->c(['ok']),
        ]]);

        $this->assertSame('Le hicimos el servicio Básico a tu bici. Revisamos el resto y está en buen estado.', $texto);
    }

    public function test_cambios_y_ajustes(): void
    {
        $texto = $this->texto('basico', [
            'tareas' => array_fill_keys(config('taller.paquetes.basico'), true),
            'componentes' => [
                'cadena' => $this->c(['cambiado']),
                'pinones' => $this->c(['lubricado']),
                'pastillas' => $this->c(['cambiado', 'ajustado']),
                'discos' => $this->c(['ajustado']),
                'mazas' => $this->c(['ok']),
            ],
        ]);

        $this->assertSame(
            'Le hicimos el servicio Básico completo a tu bici. Cambiamos la cadena y las pastillas. '
            // cada verbo con sus componentes: los discos solo se ajustaron
            .'Lubricamos los piñones. Ajustamos los discos. Revisamos el resto y está en buen estado.',
            $texto,
        );
    }

    public function test_recomendacion_normal_con_motivo_para_cliente(): void
    {
        $texto = $this->texto('full', [
            'tareas' => ['lubricar_cadena' => true],
            'componentes' => [
                'pinones' => $this->c(['recomendar'], ['desgaste']),
                'cadena' => $this->c(['recomendar'], ['holgura', 'ruido']),
            ],
        ]);

        $this->assertSame(
            'Le hicimos el servicio Full a tu bici: lubricamos la cadena. '
            .'Te recomendamos cambiar la cadena en el próximo servicio porque tiene juego y presenta ruido. '
            .'También te recomendamos cambiar los piñones porque muestran desgaste.',
            $texto,
        );
    }

    public function test_fisura_va_primero_y_nunca_se_recomienda_cambiar(): void
    {
        $texto = $this->texto('basico', ['tareas' => [], 'componentes' => [
            'cadena' => $this->c(['lubricado']),
            'discos' => $this->c(['ok']),
            'cuadro' => $this->c(['recomendar'], ['fisura', 'desgaste']),
        ]]);

        $this->assertSame(
            'Le hicimos el servicio Básico a tu bici. '
            .'Por seguridad, te recomendamos no rodar hasta que un especialista evalúe el cuadro: encontramos una fisura. '
            .'Lubricamos la cadena. Revisamos el resto y está en buen estado.',
            $texto,
        );
        $this->assertStringNotContainsString('cambiar el cuadro', $texto);
        $this->assertStringNotContainsString('Fisura/daño', $texto);
    }

    public function test_fisura_y_fuga_en_una_sola_frase(): void
    {
        $texto = $this->texto(null, ['tareas' => [], 'componentes' => [
            'sistema_freno' => $this->c(['recomendar'], ['fuga']),
            'cuadro' => $this->c(['recomendar'], ['fisura']),
            'horquilla' => $this->c(['recomendar'], ['fuga']),
        ]]);

        $this->assertSame(
            'Trabajamos en tu bici. Por seguridad, te recomendamos no rodar hasta que un especialista evalúe '
            .'el sistema de frenos, el cuadro y la horquilla: encontramos una fuga y una fisura.',
            $texto,
        );
    }

    public function test_tareas_con_el_mismo_verbo_sin_cadena_de_y(): void
    {
        $uno = $this->texto('basico', ['tareas' => ['ajustar_frenos' => true, 'ajustar_cambios' => true], 'componentes' => []]);
        $dos = $this->texto('full', ['tareas' => ['ajustar_frenos' => true, 'ajustar_cambios' => true, 'lavado' => true], 'componentes' => []]);

        $this->assertSame('Le hicimos el servicio Básico a tu bici: ajustamos los frenos y los cambios.', $uno);
        $this->assertSame('Le hicimos el servicio Full a tu bici: ajustamos los frenos, los cambios y lavamos la bici.', $dos);
    }

    public function test_tarea_sin_texto_de_cliente_usa_su_etiqueta(): void
    {
        config([
            'taller.tareas.revisar_luces' => 'Revisar luces',
            'taller.tareas.purgado' => 'Purgado',
            'taller.paquetes.basico' => [...config('taller.paquetes.basico'), 'revisar_luces', 'purgado'],
        ]);

        $texto = $this->texto('basico', ['tareas' => ['ajustar_frenos' => true, 'revisar_luces' => true, 'purgado' => true], 'componentes' => []]);

        $this->assertSame('Le hicimos el servicio Básico a tu bici: ajustamos los frenos, revisar luces y purgado.', $texto);
    }

    public function test_mas_de_tres_ajustes_se_resumen(): void
    {
        $texto = $this->texto('full', ['tareas' => [], 'componentes' => [
            'pinones' => $this->c(['ajustado', 'lubricado']),
            'cambio_trasero' => $this->c(['ajustado']),
            'discos' => $this->c(['ajustado']),
            'pedales' => $this->c(['lubricado']),
        ]]);

        $this->assertSame('Le hicimos el servicio Full a tu bici. Ajustamos y lubricamos 4 componentes más.', $texto);
    }

    public function test_revision_enorme_queda_bajo_el_tope_sin_notas_ni_cortes(): void
    {
        $nota = str_repeat('NOTA-LARGA ', 45);
        $componentes = [];
        foreach (array_keys(InformeRevision::componentes()) as $i => $clave) {
            $componentes[$clave] = $this->c($i % 2 ? ['recomendar'] : ['cambiado', 'ajustado', 'recomendar'], ['desgaste', 'holgura', 'ruido'], $nota);
        }
        $componentes['cuadro'] = $this->c(['recomendar'], ['fisura'], $nota);

        $texto = $this->texto('full', ['tareas' => array_fill_keys(config('taller.paquetes.full'), true), 'componentes' => $componentes]);

        $this->assertCount(23, $componentes);
        $this->assertLessThanOrEqual(InformeRevision::TOPE_TEXTO, mb_strlen($texto));
        $this->assertStringNotContainsString('NOTA-LARGA', $texto);
        $this->assertStringNotContainsString('Intervenido', $texto);
        $this->assertStringNotContainsString('sin novedad', $texto);
        // lo de seguridad es lo ultimo que se recorta
        $this->assertStringContainsString('un especialista evalúe el cuadro: encontramos una fisura.', $texto);
        // corta en componente completo: la frase anterior termina entera
        $this->assertMatchesRegularExpression('/(desgaste|juego|ruido|más)\. …y \d+ componentes más; ver detalle en la revisión técnica\.$/u', $texto);
    }

    public function test_componente_sin_acciones_no_cuenta_como_contenido(): void
    {
        $this->assertFalse(InformeRevision::tieneContenido(['tareas' => ['lavado' => false], 'componentes' => ['cadena' => $this->c([])]]));
        $this->assertTrue(InformeRevision::tieneContenido(['tareas' => [], 'componentes' => ['cadena' => $this->c(['ok'])]]));
        $this->assertFalse(InformeRevision::tieneContenido(null));
    }
}
