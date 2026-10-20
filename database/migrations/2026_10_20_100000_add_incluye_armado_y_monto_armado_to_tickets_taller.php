<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            // "Incluye armado": marca ARMADO en etiqueta, lista y bitacora. El monto
            // es opcional (null = no suma nada al total). Sin backfill: los tickets
            // anteriores quedan en false/null.
            $table->boolean('incluye_armado')->default(false)->after('monto_servicio');
            $table->decimal('monto_armado', 10, 2)->nullable()->after('incluye_armado');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropColumn(['incluye_armado', 'monto_armado']);
        });
    }
};
