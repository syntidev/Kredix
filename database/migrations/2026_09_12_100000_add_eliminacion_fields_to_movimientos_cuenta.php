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
            $table->text('motivo_eliminacion')->nullable()->after('comentario');
            $table->foreignId('eliminado_por')->nullable()->after('motivo_eliminacion')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->dropConstrainedForeignId('eliminado_por');
            $table->dropColumn('motivo_eliminacion');
        });
    }
};
