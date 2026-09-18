<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE movimientos_cuenta MODIFY metodo_pago ENUM('efectivo', 'zelle', 'binance', 'transferencia', 'pago_movil', 'bancamiga_divisa', 'punto_venta', 'intercambio', 'devolucion') NULL");

            return;
        }

        // sqlite: redefinir todos los enums juntos para no perder los demas
        // CHECK constraints (ver comentario en restore_enum_checks_in_movimientos_cuenta)
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->enum('tipo', ['cargo', 'abono', 'ajuste_devolucion', 'gestion'])->change();
            $table->enum('tipo_contacto', ['llamada', 'whatsapp', 'visita', 'otro'])->nullable()->change();
            $table->enum('moneda', ['usd', 'ves'])->change();
            $table->enum('frecuencia_pago', ['semanal', 'quincenal', 'mensual'])->nullable()->change();
            $table->enum('modalidad_precio', ['divisa', 'bcv'])->nullable()->change();
            $table->enum('metodo_pago', ['efectivo', 'zelle', 'binance', 'transferencia', 'pago_movil', 'bancamiga_divisa', 'punto_venta', 'intercambio', 'devolucion'])
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE movimientos_cuenta MODIFY metodo_pago ENUM('efectivo', 'zelle', 'binance', 'transferencia', 'pago_movil', 'bancamiga_divisa', 'punto_venta') NULL");

            return;
        }

        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->enum('tipo', ['cargo', 'abono', 'ajuste_devolucion', 'gestion'])->change();
            $table->enum('tipo_contacto', ['llamada', 'whatsapp', 'visita', 'otro'])->nullable()->change();
            $table->enum('moneda', ['usd', 'ves'])->change();
            $table->enum('frecuencia_pago', ['semanal', 'quincenal', 'mensual'])->nullable()->change();
            $table->enum('modalidad_precio', ['divisa', 'bcv'])->nullable()->change();
            $table->enum('metodo_pago', ['efectivo', 'zelle', 'binance', 'transferencia', 'pago_movil', 'bancamiga_divisa', 'punto_venta'])
                ->nullable()
                ->change();
        });
    }
};
