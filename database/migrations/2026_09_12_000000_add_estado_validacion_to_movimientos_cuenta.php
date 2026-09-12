<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->enum('estado_validacion', ['pendiente', 'validado'])->nullable()->after('comentario');
            $table->foreignId('validado_por')->nullable()->after('estado_validacion')->constrained('users')->nullOnDelete();
            $table->timestamp('validado_en')->nullable()->after('validado_por');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validado_por');
            $table->dropColumn(['estado_validacion', 'validado_en']);
        });
    }
};
