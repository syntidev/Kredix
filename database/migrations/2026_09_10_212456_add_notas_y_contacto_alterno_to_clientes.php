<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->text('notas')->nullable()->after('cedula');
            $table->string('contacto_alterno_nombre')->nullable()->after('notas');
            $table->string('contacto_alterno_telefono')->nullable()->after('contacto_alterno_nombre');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['notas', 'contacto_alterno_nombre', 'contacto_alterno_telefono']);
        });
    }
};
