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
            $table->unsignedTinyInteger('plazo_meses')->nullable()->after('cantidad');
            $table->enum('frecuencia_pago', ['semanal', 'quincenal', 'mensual'])->nullable()->after('plazo_meses');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->dropColumn(['plazo_meses', 'frecuencia_pago']);
        });
    }
};
