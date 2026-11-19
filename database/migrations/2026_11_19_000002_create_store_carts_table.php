<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email')->index();
            $table->json('items');
            $table->char('currency', 3)->default('PEN');
            $table->timestamp('last_activity_at')->index();
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
            $table->timestamp('recovered_at')->nullable()->index();
            $table->timestamp('communications_consented_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->index(['reminders_sent', 'last_activity_at']);
        });

        Schema::table('store_orders', function (Blueprint $table) {
            $table->foreignId('store_cart_id')->nullable()->constrained('store_carts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_cart_id');
        });
        Schema::dropIfExists('store_carts');
    }
};
