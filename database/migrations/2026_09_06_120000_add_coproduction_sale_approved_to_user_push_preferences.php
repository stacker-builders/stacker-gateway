<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_push_preferences')) {
            return;
        }

        if (! Schema::hasColumn('user_push_preferences', 'coproduction_sale_approved')) {
            Schema::table('user_push_preferences', function (Blueprint $table) {
                $table->boolean('coproduction_sale_approved')->default(true)->after('affiliate_sale_approved');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_push_preferences') && Schema::hasColumn('user_push_preferences', 'coproduction_sale_approved')) {
            Schema::table('user_push_preferences', function (Blueprint $table) {
                $table->dropColumn('coproduction_sale_approved');
            });
        }
    }
};
