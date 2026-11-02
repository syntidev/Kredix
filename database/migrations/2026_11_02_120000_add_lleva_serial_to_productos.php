<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Catalogo de articulos (productos): "lleva serial" se aprende al marcarlo en una venta y sale
// marcado la proxima vez. Aditiva: los articulos existentes quedan en false.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->boolean('lleva_serial')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('lleva_serial');
        });
    }
};
