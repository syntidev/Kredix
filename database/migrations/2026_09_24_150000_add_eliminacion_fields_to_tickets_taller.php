<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->text('motivo_eliminacion')->nullable()->after('estado');
            $table->foreignId('eliminado_por')->nullable()->after('motivo_eliminacion')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropConstrainedForeignId('eliminado_por');
            $table->dropColumn('motivo_eliminacion');
        });
    }
};
