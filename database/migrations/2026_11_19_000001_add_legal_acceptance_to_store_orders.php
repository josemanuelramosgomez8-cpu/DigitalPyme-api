<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->timestamp('legal_accepted_at')->nullable();
            $table->string('legal_accepted_ip', 45)->nullable();
            $table->string('legal_policy_version', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn([
                'legal_accepted_at',
                'legal_accepted_ip',
                'legal_policy_version',
            ]);
        });
    }
};
