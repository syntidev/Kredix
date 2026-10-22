<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fecha que se le promete al cliente para entregar la bici. Se calcula al crear el ticket
// (FechaCompromiso::porDefecto) y se puede editar. Los tickets que ya existen quedan en
// NULL a proposito: no se inventan compromisos (se muestran "Sin fecha").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->date('fecha_compromiso')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropColumn('fecha_compromiso');
        });
    }
};
