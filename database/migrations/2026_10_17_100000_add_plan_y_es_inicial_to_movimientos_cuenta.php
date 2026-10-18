<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            // Un pago a un plan es UNA fila: se liga al plan (no a una cuota) y la
            // cascada reparte el monto sobre las cuotas. es_inicial separa la
            // inicial pactada, que no entra a esa cascada. Sin backfill: las filas
            // anteriores (partidas por cuota, con cuota_id) siguen siendo validas.
            $table->foreignId('plan_financiamiento_id')->nullable()->after('cuota_id')
                ->constrained('planes_financiamiento')->nullOnDelete();
            $table->boolean('es_inicial')->default(false)->after('plan_financiamiento_id');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_financiamiento_id');
            $table->dropColumn('es_inicial');
        });
    }
};
