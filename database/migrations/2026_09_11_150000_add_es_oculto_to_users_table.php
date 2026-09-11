<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuentas de sistema (ej. "Sistema", usada como causer de imports/fixes) nunca
     * deben aparecer como opcion de "Atendido por" -- pero siguen pudiendo ser
     * asignadas como responsable via backend directo si hace falta.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('es_oculto')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('es_oculto');
        });
    }
};
