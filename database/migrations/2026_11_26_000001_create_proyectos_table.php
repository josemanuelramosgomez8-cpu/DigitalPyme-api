<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proyectos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->string('cliente');
            $table->string('categoria');
            $table->enum('tipo', ['real', 'demo']);
            $table->text('descripcion');
            $table->text('imagen_url')->nullable();
            $table->text('url_preview')->nullable();
            $table->boolean('destacado')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['activo', 'destacado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyectos');
    }
};
