<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->enum('metodo_pago', ['efectivo', 'zelle', 'binance', 'transferencia', 'pago_movil'])
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->enum('metodo_pago', ['efectivo', 'zelle', 'binance', 'transferencia'])
                ->nullable()
                ->change();
        });
    }
};
