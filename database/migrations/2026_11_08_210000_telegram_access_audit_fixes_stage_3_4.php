<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('telegram_access_order_links') && ! Schema::hasColumn('telegram_access_order_links', 'product_id')) {
            Schema::table('telegram_access_order_links', function (Blueprint $table) {
                $table->string('product_id')->nullable()->after('order_id')->index();
            });

            // Backfill portátil (sqlite + pgsql).
            if (Schema::hasTable('telegram_access_grants')) {
                $rows = DB::table('telegram_access_order_links')
                    ->whereNull('product_id')
                    ->orWhere('product_id', '')
                    ->get(['id', 'telegram_access_grant_id']);
                foreach ($rows as $row) {
                    $productId = DB::table('telegram_access_grants')
                        ->where('id', $row->telegram_access_grant_id)
                        ->value('product_id');
                    if ($productId !== null) {
                        DB::table('telegram_access_order_links')
                            ->where('id', $row->id)
                            ->update(['product_id' => (string) $productId]);
                    }
                }
            }

            Schema::table('telegram_access_order_links', function (Blueprint $table) {
                $table->dropUnique(['order_id']);
            });

            // Remove órfãos sem product_id antes do unique composto.
            DB::table('telegram_access_order_links')->whereNull('product_id')->delete();

            Schema::table('telegram_access_order_links', function (Blueprint $table) {
                $table->unique(['order_id', 'product_id']);
            });
        }

        if (Schema::hasTable('telegram_access_grants') && ! Schema::hasColumn('telegram_access_grants', 'identity_link_nonce')) {
            Schema::table('telegram_access_grants', function (Blueprint $table) {
                $table->string('identity_link_nonce', 64)->nullable()->after('telegram_username');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('telegram_access_order_links') && Schema::hasColumn('telegram_access_order_links', 'product_id')) {
            Schema::table('telegram_access_order_links', function (Blueprint $table) {
                $table->dropUnique(['order_id', 'product_id']);
                $table->unique('order_id');
                $table->dropColumn('product_id');
            });
        }

        if (Schema::hasTable('telegram_access_grants') && Schema::hasColumn('telegram_access_grants', 'identity_link_nonce')) {
            Schema::table('telegram_access_grants', function (Blueprint $table) {
                $table->dropColumn('identity_link_nonce');
            });
        }
    }
};
