<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            // {componentes:{...}, modelo, prompt_version, generado_en} -- nunca se copia solo a revision_tecnica
            $table->json('sugerencias_ia')->nullable()->after('revisado_en');
            $table->text('informe_ia')->nullable()->after('sugerencias_ia');
            // pendiente|listo|requiere_revision|error|aprobado
            $table->string('informe_ia_estado')->nullable()->after('informe_ia');
            $table->timestamp('informe_ia_generado_en')->nullable()->after('informe_ia_estado');
            $table->foreignId('informe_ia_aprobado_por')->nullable()->after('informe_ia_generado_en')->constrained('users')->nullOnDelete();
            // sha1 de la revision_tecnica usada para redactar
            $table->string('informe_ia_hash', 40)->nullable()->after('informe_ia_aprobado_por');
            // la revision cambio despues de aprobar el informe -- la pantalla lo avisa
            $table->boolean('informe_ia_desactualizado')->default(false)->after('informe_ia_hash');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropConstrainedForeignId('informe_ia_aprobado_por');
            $table->dropColumn(['sugerencias_ia', 'informe_ia', 'informe_ia_estado', 'informe_ia_generado_en', 'informe_ia_hash', 'informe_ia_desactualizado']);
        });
    }
};
