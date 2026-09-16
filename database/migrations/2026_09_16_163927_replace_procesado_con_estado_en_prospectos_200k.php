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
