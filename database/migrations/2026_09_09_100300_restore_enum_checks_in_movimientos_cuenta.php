<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SQLite rebuilds the whole table on any column change and only reapplies the
     * CHECK constraint for the enum column(s) explicitly redefined in the same
     * closure, silently dropping it for every other enum column. The two prior
     * migrations in this batch (tasa_cambio, metodo_pago) each ran in separate
     * closures, so this redefines every enum together to restore all constraints
     * at once. MySQL (production) alters columns in place and is unaffected.
     */
    public function up(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->enum('tipo', ['cargo', 'abono', 'ajuste_devolucion'])->change();
            $table->enum('moneda', ['usd', 'ves'])->change();
            $table->enum('frecuencia_pago', ['semanal', 'quincenal', 'mensual'])->nullable()->change();
            $table->enum('metodo_pago', ['efectivo', 'zelle', 'binance', 'transferencia', 'pago_movil'])
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        //
    }
};
