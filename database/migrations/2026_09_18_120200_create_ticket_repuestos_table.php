<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_repuestos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets_taller')->cascadeOnDelete();
            $table->string('producto');
            $table->unsignedInteger('cantidad');
            $table->decimal('precio', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_repuestos');
    }
};
