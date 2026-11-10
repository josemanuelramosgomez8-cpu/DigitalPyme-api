<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_projects', function (Blueprint $table) {
            $table->text('descripcion_larga')->nullable();
            $table->text('desafio')->nullable();
            $table->text('solucion')->nullable();
            $table->json('entregables')->nullable();
            $table->json('tecnologias')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_projects', function (Blueprint $table) {
            $table->dropColumn([
                'descripcion_larga',
                'desafio',
                'solucion',
                'entregables',
                'tecnologias',
            ]);
        });
    }
};
