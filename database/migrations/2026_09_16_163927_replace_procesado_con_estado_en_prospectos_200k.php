<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // "descartado" NO vive en esta columna -- es un estado por par
    // cliente-prospecto (ver prospecto_descartes), no global del prospecto;
    // aqui solo quedan los 2 estados que si son del prospecto en si mismo
    public function up(): void
    {
        Schema::table('prospectos_200k', function (Blueprint $table) {
            $table->enum('estado', ['pendiente', 'fusionado'])->default('pendiente')->after('procesado');
        });

        DB::table('prospectos_200k')->where('procesado', true)->update(['estado' => 'fusionado']);

        // sqlite no puede borrar una columna que tiene un indice (pruebas en memoria): se quita el indice antes.
        // En MySQL no hace falta (al borrar la columna sale del indice), asi que su efecto no cambia
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('prospectos_200k', function (Blueprint $table) {
                $table->dropIndex('prospectos_200k_procesado_index');
            });
        }

        Schema::table('prospectos_200k', function (Blueprint $table) {
            $table->dropColumn('procesado');
        });
    }

    public function down(): void
    {
        Schema::table('prospectos_200k', function (Blueprint $table) {
            $table->boolean('procesado')->default(false)->after('correo');
        });

        DB::table('prospectos_200k')->where('estado', 'fusionado')->update(['procesado' => true]);

        Schema::table('prospectos_200k', function (Blueprint $table) {
            $table->dropColumn('estado');
        });
    }
};
