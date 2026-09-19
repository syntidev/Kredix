<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * categoria_bici y talla_rin eran un solo campo mezclado
     * (Ruta/MTB/Rin20/Rin16/Otro). Se separan: categoria_bici es la
     * categoria (ruta/mtb/otro), talla_rin pasa a ser SOLO el tamano de
     * rueda en texto libre ("20", "16", vacio si no aplica).
     */
    public function up(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->string('categoria_bici')->nullable()->after('talla_rin');
        });

        $mapaValorFijo = [
            'Ruta' => ['categoria_bici' => 'ruta', 'talla_rin' => ''],
            'MTB' => ['categoria_bici' => 'mtb', 'talla_rin' => ''],
            // rines chicos sin categoria explicita -- MTB es la categoria mas
            // comun con esos rines, asumida por default (decision de Carlos)
            'Rin 20' => ['categoria_bici' => 'mtb', 'talla_rin' => '20'],
            'Rin 16' => ['categoria_bici' => 'mtb', 'talla_rin' => '16'],
            'Otro' => ['categoria_bici' => 'otro', 'talla_rin' => ''],
            '' => ['categoria_bici' => 'otro', 'talla_rin' => ''],
        ];

        DB::table('tickets_taller')->select('id', 'talla_rin')->get()->each(function ($fila) use ($mapaValorFijo) {
            if (array_key_exists($fila->talla_rin, $mapaValorFijo)) {
                DB::table('tickets_taller')->where('id', $fila->id)->update($mapaValorFijo[$fila->talla_rin]);

                return;
            }

            // el select viejo tenia "Otro" con texto libre -- el valor real
            // tecleado no es ninguno de los fijos de arriba; se preserva tal
            // cual en talla_rin (no se pierde informacion), solo se clasifica
            DB::table('tickets_taller')->where('id', $fila->id)->update(['categoria_bici' => 'otro']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropColumn('categoria_bici');
        });
    }
};
