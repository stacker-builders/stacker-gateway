<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'support_whatsapp_enabled')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('support_whatsapp_enabled')->default(false)->after('support_whatsapp');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'support_whatsapp_enabled')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('support_whatsapp_enabled');
        });
    }
};
