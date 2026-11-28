<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_cart_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->json('items');
            $table->char('currency', 3)->default('PEN');
            $table->string('customer_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 24)->nullable();
            $table->timestamp('communications_consented_at')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('last_activity_at')->index();
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('cotizacion_id')->nullable()->constrained('cotizaciones')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['status', 'last_activity_at', 'reminders_sent'],
                'cart_status_activity_reminders_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_cart_sessions');
    }
};
