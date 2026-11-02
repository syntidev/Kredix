<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Venta de contado: cargo + abono por el total, sin plan ni cuotas. Se marcan con es_contado
// (las lineas de cargo y su abono de pago) para sacarlas del KPI y de las alertas de mora.
// Aditiva: las filas existentes quedan en false.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->boolean('es_contado')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->dropColumn('es_contado');
        });
    }
};
