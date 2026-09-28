<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A migration 2026_02_26_000001 só anulava checkout_slug no MySQL/MariaDB.
 * No PostgreSQL e no SQLite a coluna ficou NOT NULL, e excluir o checkout
 * exclusivo de uma oferta ou plano (checkout_slug = null) gerava erro 500.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['product_offers', 'subscription_plans'] as $table) {
            $this->makeCheckoutSlugNullable($table);
        }
    }

    public function down(): void
    {
        // Não reimpõe NOT NULL: ofertas e planos sem checkout exclusivo dependem de slug nulo.
    }

    private function makeCheckoutSlugNullable(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'checkout_slug')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "'.$table.'" ALTER COLUMN "checkout_slug" DROP NOT NULL');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $schema = DB::getDatabaseName();
            $row = DB::selectOne(
                "SELECT IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'checkout_slug' LIMIT 1",
                [$schema, $table]
            );
            if ($row && strtoupper((string) $row->IS_NULLABLE) === 'YES') {
                return;
            }
            DB::statement("ALTER TABLE `{$table}` MODIFY checkout_slug VARCHAR(16) NULL");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->string('checkout_slug', 16)->nullable()->change();
        });
        $this->ensureProductIdIndex($table);
    }

    /**
     * O change() do SQLite recria a tabela e pode omitir o índice de product_id.
     */
    private function ensureProductIdIndex(string $table): void
    {
        $indexes = DB::select("PRAGMA index_list('{$table}')");
        foreach ($indexes as $index) {
            $columns = DB::select("PRAGMA index_info('{$index->name}')");
            if (count($columns) === 1 && ($columns[0]->name ?? null) === 'product_id') {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->index('product_id');
        });
    }
};
