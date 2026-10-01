<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TEMPORAL -- estas 3 columnas solo tienen sentido para clientes
    // soft-deleted en revision (ver ClienteController::cuentasEnRevision()).
    // Retirar junto con esa vista cuando se resuelva el tratamiento final
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('estado_revision')->default('pendiente')->after('deleted_at');
            $table->foreignId('revisado_por')->nullable()->after('estado_revision')->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable()->after('revisado_por');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revisado_por');
            $table->dropColumn(['estado_revision', 'revisado_en']);
        });
    }
};
