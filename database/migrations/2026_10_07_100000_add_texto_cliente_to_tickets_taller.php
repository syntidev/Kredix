<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Compuerta "Texto para el cliente": el texto sigue en trabajo_realizado; estas
// columnas dicen si ya lo aprobo una persona. Estado null con texto = ticket
// anterior a la compuerta, se trata como texto manual aprobado (se imprime igual).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            // borrador | aprobado
            $table->string('texto_cliente_estado')->nullable()->after('trabajo_realizado');
            // manual | automatico | ia
            $table->string('texto_cliente_origen')->nullable()->after('texto_cliente_estado');
            $table->foreignId('texto_cliente_aprobado_por')->nullable()->after('texto_cliente_origen')->constrained('users')->nullOnDelete();
            $table->timestamp('texto_cliente_aprobado_en')->nullable()->after('texto_cliente_aprobado_por');
            // sha1 de la revision_tecnica al aprobar: si cambia, el texto queda desactualizado
            $table->string('texto_cliente_hash', 40)->nullable()->after('texto_cliente_aprobado_en');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropConstrainedForeignId('texto_cliente_aprobado_por');
            $table->dropColumn(['texto_cliente_estado', 'texto_cliente_origen', 'texto_cliente_aprobado_en', 'texto_cliente_hash']);
        });
    }
};
