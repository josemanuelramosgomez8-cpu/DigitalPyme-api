<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_planning', function (Blueprint $table) {
            $table->id();
            $table->foreignId('servicio_id')->unique()->constrained('servicios')->cascadeOnDelete();
            $table->string('prioridad', 16)->nullable();
            $table->string('estandarizacion', 24)->nullable();
            $table->string('estado_preparacion', 24)->nullable();
            $table->unsignedSmallInteger('tiempo_estimado_dias')->nullable();
            $table->timestamps();
            $table->index(['estado_preparacion', 'tiempo_estimado_dias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_planning');
    }
};
