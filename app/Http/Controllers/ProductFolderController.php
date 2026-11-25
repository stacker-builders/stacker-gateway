<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductFolder;
use App\Models\User;
use App\Support\SellerProductListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductFolderController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->ensureBelowFolderLimit($user);
        $validated = $this->validateFolder($request, $user);

        $folder = DB::transaction(function () use ($user, $validated) {
            $created = ProductFolder::query()->create([
                'tenant_id' => ProductFolder::tenantIdFor($user),
                'name' => $validated['name'],
            ]);
            $this->assignProducts($created, $user, $validated['product_ids']);

            return $created;
        });

        return redirect()->route('produtos.index', $this->redirectQuery($request, $folder->id));
    }

    public function update(Request $request, int $folder): RedirectResponse
    {
        $user = $request->user();
        $model = $this->ownedFolder($user, $folder);
        $validated = $this->validateFolder($request, $user, $model->id);

        DB::transaction(function () use ($model, $user, $validated) {
            $model->update(['name' => $validated['name']]);
            $this->assignProducts($model, $user, $validated['product_ids']);
        });

        return redirect()->route('produtos.index', $this->redirectQuery($request, $model->id));
    }

    public function destroy(Request $request, int $folder): RedirectResponse
    {
        $model = $this->ownedFolder($request->user(), $folder);
        $model->delete();

        return redirect()->route('produtos.index', $this->redirectQuery($request, null));
    }

    private function ownedFolder(User $user, int $folder): ProductFolder
    {
        $model = ProductFolder::findOwned($user, $folder);
        abort_unless($model instanceof ProductFolder, 404);

        return $model;
    }

    /**
     * @return array{name: string, product_ids: list<string>}
     */
    private function validateFolder(Request $request, User $user, ?int $ignoreId = null): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $request->input('name')));
        $request->merge(['name' => $name]);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('product_folders', 'name')->where(fn ($query) => $query->where('tenant_id', ProductFolder::tenantIdFor($user)))->ignore($ignoreId),
            ],
            'product_ids' => ['present', 'array', 'max:500'],
            'product_ids.*' => ['string', 'max:36'],
            'return_category' => ['nullable', 'string', 'max:80'],
        ], [
            'name.required' => 'Dê um nome para a pasta.',
            'name.unique' => 'Você já tem uma pasta com esse nome.',
            'name.max' => 'O nome da pasta pode ter no máximo 80 caracteres.',
            'product_ids.max' => 'Uma pasta pode ter no máximo 500 produtos.',
        ]);

        $productIds = collect($validated['product_ids'])
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'name' => $validated['name'],
            'product_ids' => $productIds,
        ];
    }

    private function ensureBelowFolderLimit(User $user): void
    {
        $count = ProductFolder::query()->where('tenant_id', ProductFolder::tenantIdFor($user))->count();
        if ($count >= ProductFolder::MAX_PER_USER) {
            throw ValidationException::withMessages([
                'name' => 'Você atingiu o limite de '.ProductFolder::MAX_PER_USER.' pastas.',
            ]);
        }
    }

    /**
     * @param  list<string>  $productIds
     */
    private function assignProducts(ProductFolder $folder, User $user, array $productIds): void
    {
        $visible = array_fill_keys(
            SellerProductListing::forUser($user)['query']->pluck('id')->map(fn ($id) => (string) $id)->all(),
            true
        );

        $requested = collect($productIds)->values();
        $invalid = $requested->filter(fn (string $id) => ! isset($visible[$id]));
        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'product_ids' => 'Escolha apenas produtos da sua lista.',
            ]);
        }

        $hidden = DB::table('product_folder_product')
            ->where('product_folder_id', $folder->id)
            ->pluck('product_id')
            ->map(fn ($id) => (string) $id)
            ->filter(fn (string $id) => ! isset($visible[$id]))
            ->values();

        $folder->products()->sync($requested->merge($hidden)->unique()->values()->all());
    }

    /**
     * @return array<string, int|string>
     */
    private function redirectQuery(Request $request, ?int $folderId): array
    {
        $category = Product::normalizeCategoryFilter($request->input('return_category', $request->query('category')));

        return array_filter([
            'folder' => $folderId,
            'category' => $category !== '' ? $category : null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
