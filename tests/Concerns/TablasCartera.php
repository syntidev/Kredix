<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait TablasCartera
{
    private function crearTablasCartera(): void
    {
        // Tablas minimas propias: RefreshDatabase no corre en sqlite por una
        // migracion vieja (prospectos_200k dropea una columna indexada).
        // Mismo patron que IaTallerTest.
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->boolean('es_admin')->default(true);
            $t->boolean('activo')->default(true);
            $t->boolean('es_oculto')->default(false);
            $t->boolean('acceso_conciliacion')->default(false);
            $t->boolean('rol_taller')->default(false);
            $t->timestamps();
        });

        Schema::create('clientes', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->string('telefono')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('productos', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->unsignedInteger('veces_usado')->default(0);
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('movimientos_cuenta', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id');
            $t->uuid('compra_id')->nullable()->index();
            $t->date('fecha')->nullable();
            $t->string('tipo');
            $t->string('tipo_contacto')->nullable();
            $t->date('fecha_prometida')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('cantidad', 12, 2)->nullable();
            $t->unsignedInteger('plazo_meses')->nullable();
            $t->foreignId('cuota_id')->nullable();
            $t->foreignId('plan_financiamiento_id')->nullable();
            $t->boolean('es_inicial')->default(false);
            $t->string('frecuencia_pago')->nullable();
            $t->decimal('precio_unitario', 12, 2)->nullable();
            $t->string('modalidad_precio')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('moneda')->default('usd');
            $t->decimal('tasa_cambio', 12, 4)->nullable();
            $t->string('metodo_pago')->nullable();
            $t->string('referencia')->nullable();
            $t->text('comentario')->nullable();
            $t->boolean('duplicado_confirmado')->default(false);
            $t->foreignId('registrado_por')->nullable();
            $t->string('estado_validacion')->nullable();
            $t->foreignId('validado_por')->nullable();
            $t->timestamp('validado_en')->nullable();
            $t->text('motivo_eliminacion')->nullable();
            $t->foreignId('eliminado_por')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('activity_log', function (Blueprint $t) {
            $t->id();
            $t->string('log_name')->nullable();
            $t->text('description');
            $t->nullableMorphs('subject');
            $t->string('event')->nullable();
            $t->nullableMorphs('causer');
            $t->json('properties')->nullable();
            $t->uuid('batch_uuid')->nullable();
            $t->timestamps();
        });

        Schema::create('planes_financiamiento', function (Blueprint $t) {
            $t->id();
            $t->foreignId('movimiento_cuenta_id');
            $t->decimal('monto_inicial', 12, 2)->default(0);
            $t->decimal('porcentaje_mora', 5, 2)->default(10.00);
            $t->string('frecuencia_pago');
            $t->unsignedTinyInteger('numero_cuotas');
            $t->boolean('aplicado_mora')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('cuotas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_financiamiento_id');
            $t->unsignedTinyInteger('numero');
            $t->decimal('monto_pactado', 12, 2);
            $t->date('fecha_vencimiento');
            $t->decimal('monto_abonado', 12, 2)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
    }
}
