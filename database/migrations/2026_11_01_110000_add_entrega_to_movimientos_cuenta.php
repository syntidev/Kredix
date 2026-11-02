<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ventas adelantadas: un cargo marcado "pendiente de entrega" (apartado) aparece en "Por entregar"
// hasta que alguien lo marca entregado. Solo aplica a cargos y no toca saldos, planes ni KPI.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->boolean('pendiente_entrega')->default(false);
            $table->timestamp('entregado_en')->nullable();
            $table->foreignId('entregado_por')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entregado_por');
            $table->dropColumn(['pendiente_entrega', 'entregado_en']);
        });
    }
};
