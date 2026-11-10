<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Trabajo terminado" sin cerrar el ticket: fecha en que el tecnico hizo el trabajo (nullable, aditiva).
// El KPI de Taller cuenta el ticket en COALESCE(trabajado_en, atendido_en).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->timestamp('trabajado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropColumn('trabajado_en');
        });
    }
};
