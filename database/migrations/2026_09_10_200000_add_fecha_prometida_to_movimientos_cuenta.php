<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->date('fecha_prometida')->nullable()->after('tipo_contacto');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->dropColumn('fecha_prometida');
        });
    }
};
