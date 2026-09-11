<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operador que lleva la relacion con el cliente (distinto de quien registra
     * cada movimiento). Sin asignar por defecto -- nunca se fuerza un usuario.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->foreignId('usuario_responsable_id')->nullable()->after('cedula')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('usuario_responsable_id');
        });
    }
};
