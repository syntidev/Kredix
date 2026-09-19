<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            // nullable a nivel BD -- armado_interno no tiene cliente
            // reportando una falla, la obligatoriedad para servicio_cliente
            // se aplica en la validacion del controlador, no aqui
            $table->text('motivo_ingreso')->nullable()->after('es_electrica');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropColumn('motivo_ingreso');
        });
    }
};
