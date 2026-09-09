<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->decimal('tasa_cambio', 12, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->decimal('tasa_cambio', 12, 4)->nullable(false)->change();
        });
    }
};
