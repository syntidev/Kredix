<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $introActual = DB::table('configuraciones')->where('clave', 'whatsapp_intro')->value('valor');

        $filas = [
            'whatsapp_intro_1' => $introActual,
            'whatsapp_intro_2' => null,
            'whatsapp_intro_3' => null,
            'whatsapp_plantilla_activa' => '1',
        ];

        foreach ($filas as $clave => $valor) {
            DB::table('configuraciones')->updateOrInsert(
                ['clave' => $clave],
                ['valor' => $valor, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('configuraciones')->whereIn('clave', [
            'whatsapp_intro_1', 'whatsapp_intro_2', 'whatsapp_intro_3', 'whatsapp_plantilla_activa',
        ])->delete();
    }
};
