<?php

use App\Services\SellerIntegrationVisibility;
use App\Services\Telegram\TelegramModuleSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $now = now();

        $hasModule = DB::table('settings')
            ->whereNull('tenant_id')
            ->where('key', TelegramModuleSettings::KEY)
            ->exists();

        if (! $hasModule) {
            DB::table('settings')->insert([
                'tenant_id' => null,
                'key' => TelegramModuleSettings::KEY,
                'value' => json_encode(TelegramModuleSettings::defaults()),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $visibilityKey = SellerIntegrationVisibility::settingKey(SellerIntegrationVisibility::TELEGRAM);
        $hasVisibility = DB::table('settings')
            ->whereNull('tenant_id')
            ->where('key', $visibilityKey)
            ->exists();

        if (! $hasVisibility) {
            DB::table('settings')->insert([
                'tenant_id' => null,
                'key' => $visibilityKey,
                'value' => '1',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereNull('tenant_id')
            ->whereIn('key', [
                TelegramModuleSettings::KEY,
                SellerIntegrationVisibility::settingKey(SellerIntegrationVisibility::TELEGRAM),
            ])
            ->delete();

        DB::table('settings')
            ->where('key', 'telegram_seller_policy')
            ->delete();
    }
};
