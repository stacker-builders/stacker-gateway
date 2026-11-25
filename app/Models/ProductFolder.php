<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductFolder extends Model
{
    public const MAX_PER_USER = 100;

    protected $fillable = [
        'tenant_id',
        'name',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_folder_product')
            ->withTimestamps();
    }

    /**
     * Pastas da conta do seller, só com produtos que ainda aparecem na lista de quem está logado.
     *
     * @param  list<string>  $visibleIds
     * @return list<array{id: int, name: string, products_count: int, product_ids: list<string>}>
     */
    public static function payloadForUser(User $user, array $visibleIds): array
    {
        if (! Schema::hasTable('product_folders')) {
            return [];
        }

        $folders = static::query()
            ->where('tenant_id', static::tenantIdFor($user))
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($folders->isEmpty()) {
            return [];
        }

        $visible = array_fill_keys(array_map('strval', $visibleIds), true);
        $byFolder = [];
        $links = DB::table('product_folder_product')
            ->whereIn('product_folder_id', $folders->pluck('id'))
            ->get(['product_folder_id', 'product_id']);

        foreach ($links as $link) {
            $productId = (string) $link->product_id;
            if (! isset($visible[$productId])) {
                continue;
            }
            $byFolder[(int) $link->product_folder_id][] = $productId;
        }

        return $folders->map(function (self $folder) use ($byFolder) {
            $ids = $byFolder[(int) $folder->id] ?? [];

            return [
                'id' => (int) $folder->id,
                'name' => $folder->name,
                'products_count' => count($ids),
                'product_ids' => array_values($ids),
            ];
        })->values()->all();
    }

    public static function findOwned(User $user, int|string $id): ?self
    {
        if (! Schema::hasTable('product_folders')) {
            return null;
        }

        $folderId = filter_var($id, FILTER_VALIDATE_INT);
        if ($folderId === false || $folderId < 1) {
            return null;
        }

        return static::query()
            ->whereKey($folderId)
            ->where('tenant_id', static::tenantIdFor($user))
            ->first();
    }

    public static function tenantIdFor(User $user): int
    {
        return (int) ($user->tenant_id ?: $user->id);
    }
}
