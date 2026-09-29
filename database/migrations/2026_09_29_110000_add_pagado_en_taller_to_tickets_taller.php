<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->boolean('pagado_en_taller')->default(false)->after('estado');
            $table->foreignId('movimiento_cuenta_id')->nullable()->after('pagado_en_taller')->constrained('movimientos_cuenta')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropConstrainedForeignId('movimiento_cuenta_id');
            $table->dropColumn('pagado_en_taller');
        });
    }
};
