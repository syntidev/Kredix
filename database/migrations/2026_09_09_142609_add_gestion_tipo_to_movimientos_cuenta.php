<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columnas = [
        'id', 'cliente_id', 'fecha', 'tipo', 'descripcion', 'cantidad', 'precio_unitario',
        'modalidad_precio', 'monto', 'moneda', 'tasa_cambio', 'metodo_pago', 'comentario',
        'registrado_por', 'created_at', 'updated_at', 'deleted_at', 'plazo_meses', 'frecuencia_pago',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('movimientos_cuenta', function (Blueprint $table) {
                $table->enum('tipo_contacto', ['llamada', 'whatsapp', 'visita', 'otro'])->nullable()->after('tipo');
            });

            DB::statement("ALTER TABLE movimientos_cuenta MODIFY tipo ENUM('cargo', 'abono', 'ajuste_devolucion', 'gestion') NOT NULL");

            return;
        }

        // sqlite no soporta ALTER de CHECK constraints - reconstruye la tabla (mismo
        // mecanismo que Laravel hace internamente via doctrine/dbal, hecho a mano para
        // no depender de un paquete --dev que no existe en el deploy de produccion).
        Schema::create('movimientos_cuenta_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->date('fecha');
            $table->enum('tipo', ['cargo', 'abono', 'ajuste_devolucion', 'gestion']);
            $table->enum('tipo_contacto', ['llamada', 'whatsapp', 'visita', 'otro'])->nullable();
            $table->text('descripcion');
            $table->decimal('cantidad', 12, 2)->nullable();
            $table->decimal('precio_unitario', 12, 2)->nullable();
            $table->enum('modalidad_precio', ['divisa', 'bcv'])->nullable();
            $table->decimal('monto', 12, 2);
            $table->enum('moneda', ['usd', 'ves']);
            $table->decimal('tasa_cambio', 12, 4)->nullable();
            $table->enum('metodo_pago', ['efectivo', 'zelle', 'binance', 'transferencia', 'pago_movil'])->nullable();
            $table->text('comentario')->nullable();
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedTinyInteger('plazo_meses')->nullable();
            $table->enum('frecuencia_pago', ['semanal', 'quincenal', 'mensual'])->nullable();
        });

        $cols = implode(', ', $this->columnas);
        DB::statement("INSERT INTO movimientos_cuenta_new ({$cols}) SELECT {$cols} FROM movimientos_cuenta");

        Schema::drop('movimientos_cuenta');
        Schema::rename('movimientos_cuenta_new', 'movimientos_cuenta');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE movimientos_cuenta MODIFY tipo ENUM('cargo', 'abono', 'ajuste_devolucion') NOT NULL");
            Schema::table('movimientos_cuenta', fn (Blueprint $table) => $table->dropColumn('tipo_contacto'));

            return;
        }

        Schema::create('movimientos_cuenta_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->date('fecha');
            $table->enum('tipo', ['cargo', 'abono', 'ajuste_devolucion']);
            $table->text('descripcion');
            $table->decimal('cantidad', 12, 2)->nullable();
            $table->decimal('precio_unitario', 12, 2)->nullable();
            $table->enum('modalidad_precio', ['divisa', 'bcv'])->nullable();
            $table->decimal('monto', 12, 2);
            $table->enum('moneda', ['usd', 'ves']);
            $table->decimal('tasa_cambio', 12, 4)->nullable();
            $table->enum('metodo_pago', ['efectivo', 'zelle', 'binance', 'transferencia', 'pago_movil'])->nullable();
            $table->text('comentario')->nullable();
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedTinyInteger('plazo_meses')->nullable();
            $table->enum('frecuencia_pago', ['semanal', 'quincenal', 'mensual'])->nullable();
        });

        $cols = implode(', ', $this->columnas);
        DB::statement("INSERT INTO movimientos_cuenta_new ({$cols}) SELECT {$cols} FROM movimientos_cuenta");

        Schema::drop('movimientos_cuenta');
        Schema::rename('movimientos_cuenta_new', 'movimientos_cuenta');
    }
};
