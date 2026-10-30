<?php

namespace App\Support;

use App\Models\Product;
use App\Models\User;
use App\Services\MemberAreaResolver;
use Illuminate\Http\Request;

/**
 * Preview administrativo da Área de Membros (moderação) — sem matrícula/compra.
 */
final class MemberAreaAdminPreview
{
    public const SESSION_KEY = 'member_area_admin_preview_product_id';

    public static function isPlatformAuditor(?User $user): bool
    {
        return $user !== null && $user->canAccessPlatformPanel();
    }

    public static function canPreviewProduct(?User $user, Product $product): bool
    {
        if (! self::isPlatformAuditor($user)) {
            return false;
        }

        return $product->type === Product::TYPE_AREA_MEMBROS;
    }

    public static function markSession(Request $request, Product $product): void
    {
        $request->session()->put(self::SESSION_KEY, (string) $product->id);
    }

    public static function isActive(Request $request, ?Product $product = null): bool
    {
        $user = $request->user();
        if (! self::isPlatformAuditor($user)) {
            return false;
        }

        $product = $product
            ?? $request->route('product')
            ?? $request->attributes->get('member_area_product');

        if (! $product instanceof Product) {
            try {
                $resolved = app(MemberAreaResolver::class)->resolve($request);
                $product = $resolved['product'] ?? null;
            } catch (\Throwable) {
                $product = null;
            }
        }

        if (! $product instanceof Product) {
            return false;
        }

        return $product->type === Product::TYPE_AREA_MEMBROS;
    }

    /**
     * Props Inertia para banner / modo somente leitura.
     *
     * @return array<string, mixed>|null
     */
    public static function inertiaPayload(Request $request, Product $product): ?array
    {
        if (! self::isActive($request, $product)) {
            return null;
        }

        $owner = $product->relationLoaded('tenantOwner')
            ? $product->tenantOwner
            : $product->tenantOwner()->first(['id', 'name', 'email']);

        return [
            'active' => true,
            'read_only' => true,
            'message' => 'Você pode abrir aulas, assistir vídeos e baixar materiais para verificar o conteúdo. Nenhuma compra ou matrícula foi criada, e ações de aluno (concluir, comentar, avaliar) estão desativadas.',
            'product_name' => $product->name,
            'seller_name' => $owner?->name,
            'seller_email' => $owner?->email,
            'product_active' => (bool) $product->is_active,
            'admin_blocked' => (bool) ($product->admin_blocked ?? false),
            'back_url' => route('plataforma.produtos.index'),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }
}
