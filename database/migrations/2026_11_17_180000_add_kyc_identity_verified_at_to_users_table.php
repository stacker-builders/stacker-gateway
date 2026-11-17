<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'kyc_identity_verified_at')) {
                $table->timestamp('kyc_identity_verified_at')->nullable()->after('kyc_external_session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'kyc_identity_verified_at')) {
                $table->dropColumn('kyc_identity_verified_at');
            }
        });
    }
};
