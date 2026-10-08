<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uazapi_instances', function (Blueprint $table) {
            $table->boolean('order_paid_enabled')->default(false)->after('pix_recovery_enabled');
            $table->text('message_order_paid')->nullable()->after('message_pix');
        });

        Schema::table('evolution_instances', function (Blueprint $table) {
            $table->boolean('order_paid_enabled')->default(false)->after('pix_recovery_enabled');
            $table->text('message_order_paid')->nullable()->after('message_pix');
        });
    }

    public function down(): void
    {
        Schema::table('uazapi_instances', function (Blueprint $table) {
            $table->dropColumn(['order_paid_enabled', 'message_order_paid']);
        });

        Schema::table('evolution_instances', function (Blueprint $table) {
            $table->dropColumn(['order_paid_enabled', 'message_order_paid']);
        });
    }
};
