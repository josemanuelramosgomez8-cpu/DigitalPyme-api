<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('post_sale_scheduled_at')->nullable();
        });

        Schema::create('project_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('answers');
            $table->string('submitter_ip', 45)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_briefs');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('post_sale_scheduled_at');
        });
    }
};
