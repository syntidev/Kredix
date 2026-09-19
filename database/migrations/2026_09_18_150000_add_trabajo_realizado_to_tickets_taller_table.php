<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            // nullable a nivel BD -- la obligatoriedad es de negocio, se
            // exige solo al marcar atendido (mismo patron que la foto de
            // salida), no en la creacion del ticket
            $table->text('trabajo_realizado')->nullable()->after('motivo_ingreso');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropColumn('trabajo_realizado');
        });
    }
};
