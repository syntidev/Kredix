<?php

namespace Tests\Unit;

use App\Services\Ia\EstructuradorRevision;
use PHPUnit\Framework\TestCase;

class EstructuradorRevisionTest extends TestCase
{
    public function test_valida_catalogo(): void
    {
        $ok = ['componentes' => ['cuadro' => ['acciones' => ['recomendar'], 'motivos' => ['fisura'], 'nota' => '']]];
        $this->assertSame([], EstructuradorRevision::validar($ok));

        $mal = ['componentes' => [
            'sillin' => ['acciones' => ['ok'], 'motivos' => [], 'nota' => ''],
            'cadena' => ['acciones' => ['purgado'], 'motivos' => [], 'nota' => ''],
            'pastillas' => ['acciones' => ['cambiado'], 'motivos' => ['desgaste'], 'nota' => ''],
            'discos' => ['acciones' => ['recomendar'], 'motivos' => ['oxido'], 'nota' => ''],
        ]];
        $this->assertCount(4, EstructuradorRevision::validar($mal));

        $this->assertNotEmpty(EstructuradorRevision::validar(['cuadro' => []]));
        $this->assertNotEmpty(EstructuradorRevision::validar(['componentes' => ['cadena' => ['acciones' => []]]]));
    }
}
