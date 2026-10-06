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

    public function test_paquete_completo_en_una_frase_y_cambiados_uno_por_uno(): void
    {
        $texto = $this->texto('basico', [
            'tareas' => array_fill_keys(config('taller.paquetes.basico'), true),
            'componentes' => [
                'cadena' => $this->c(['cambiado']),
                'pastillas' => $this->c(['cambiado', 'ajustado']),
                'discos' => $this->c(['ok']),
            ],
        ]);

        $this->assertSame(
            'Le hicimos el servicio Básico completo a tu bici. Cambiamos la cadena y las pastillas. Revisamos el resto y está en buen estado.',
            $texto,
        );
    }

    public function test_tareas_parciales_sin_repetir_al_componente(): void
    {
        $texto = $this->texto('full', [
            'tareas' => ['lubricar_cadena' => true, 'lavado' => true, 'ajustar_frenos' => false],
            'componentes' => ['cadena' => $this->c(['lubricado', 'ajustado'])],
        ]);

        // "lubricamos la cadena" sobra: la frase del componente ya lo dice
        $this->assertSame('Le hicimos el servicio Full a tu bici: lavamos la bici. Ajustamos y lubricamos la cadena.', $texto);
    }

    public function test_tareas_con_el_mismo_verbo_sin_cadena_de_y(): void
    {
        $uno = $this->texto('basico', ['tareas' => ['ajustar_frenos' => true, 'ajustar_cambios' => true], 'componentes' => []]);
        $dos = $this->texto('full', ['tareas' => ['ajustar_frenos' => true, 'ajustar_cambios' => true, 'lavado' => true], 'componentes' => []]);

        $this->assertSame('Le hicimos el servicio Básico a tu bici: ajustamos los frenos y los cambios.', $uno);
        $this->assertSame('Le hicimos el servicio Full a tu bici: ajustamos los frenos, los cambios y lavamos la bici.', $dos);
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

    public function test_seguridad_primero_y_texto_corto_sin_y(): void
    {
        $texto = $this->texto(null, ['tareas' => [], 'componentes' => [
            'pinones' => $this->c(['recomendar'], ['desgaste']),
            'platos_bielas' => $this->c(['recomendar'], ['holgura']),
            'cauchos' => $this->c(['ajustado', 'recomendar'], ['fisura', 'desgaste'], 'trasero'),
        ]]);

        $this->assertSame(
            'Trabajamos en tu bici. Ajustamos los cauchos. '
            .'Por seguridad, te recomendamos no volver a rodar hasta cambiar los cauchos: encontramos fisura o daño. '
            .'Te recomendamos cambiar los piñones en el próximo servicio por desgaste. '
            .'También te recomendamos cambiar los platos por holgura.',
            $texto,
        );
    }

    public function test_revision_enorme_queda_bajo_el_tope_y_sin_notas(): void
    {
        $nota = str_repeat('NOTA-LARGA ', 45);
        $componentes = [];
        foreach (array_keys(InformeRevision::componentes()) as $i => $clave) {
            $componentes[$clave] = $this->c($i % 2 ? ['recomendar'] : ['cambiado', 'ajustado', 'limpiado', 'lubricado', 'recomendar'], array_keys(config('taller.motivos')), $nota);
        }

        $texto = $this->texto('full', ['tareas' => array_fill_keys(config('taller.paquetes.full'), true), 'componentes' => $componentes]);

        $this->assertCount(23, $componentes);
        $this->assertLessThanOrEqual(InformeRevision::TOPE_TEXTO, mb_strlen($texto));
        $this->assertStringNotContainsString('NOTA-LARGA', $texto);
        $this->assertStringNotContainsString('Intervenido', $texto);
        $this->assertStringNotContainsString('sin novedad', $texto);
        // lo de seguridad es lo ultimo que se recorta
        $this->assertStringContainsString('Por seguridad, te recomendamos no volver a rodar hasta cambiar la cadena', $texto);
        // corta en componente completo: la ultima frase termina entera antes de los omitidos
        $this->assertMatchesRegularExpression('/fisura o daño y fuga\. …y \d+ componentes más; ver detalle en la revisión técnica\.$/u', $texto);
    }

    public function test_componente_sin_acciones_no_cuenta_como_contenido(): void
    {
        $this->assertFalse(InformeRevision::tieneContenido(['tareas' => ['lavado' => false], 'componentes' => ['cadena' => $this->c([])]]));
        $this->assertTrue(InformeRevision::tieneContenido(['tareas' => [], 'componentes' => ['cadena' => $this->c(['ok'])]]));
        $this->assertFalse(InformeRevision::tieneContenido(null));
    }
}
