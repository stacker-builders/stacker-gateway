<?php

namespace App\Services\Telegram;

use App\Models\Product;
use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessGrant;
use App\Services\TeamAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

class TelegramSellerSubscribersService
{
    /**
     * @return list<array{id: string, name: string}>
     */
    public function telegramProductsForTenant(int $tenantId, $user = null): array
    {
        if ($tenantId <= 0 || ! Schema::hasTable('products')) {
            return [];
        }

        $query = Product::query()
            ->forTenant($tenantId)
            ->where('type', Product::TYPE_TELEGRAM)
            ->where('is_active', true)
            ->orderBy('name');

        if ($user && method_exists($user, 'isTeam') && $user->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor($user);
            $query->whereIn('id', $allowed ?: ['__none__']);
        }

        return $query
            ->get(['id', 'name'])
            ->map(fn (Product $p) => ['id' => (string) $p->id, 'name' => (string) $p->name])
            ->values()
            ->all();
    }

    public function tenantHasActiveTelegramProduct(int $tenantId, $user = null): bool
    {
        return $this->telegramProductsForTenant($tenantId, $user) !== [];
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(
        int $tenantId,
        ?string $productId = null,
        ?string $email = null,
        ?string $name = null,
        int $perPage = 20,
        $user = null,
    ): LengthAwarePaginator {
        if ($tenantId <= 0 || ! Schema::hasTable('telegram_access_grants')) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        $allowedProductIds = collect($this->telegramProductsForTenant($tenantId, $user))
            ->pluck('id')
            ->all();

        if ($allowedProductIds === []) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        $query = TelegramAccessGrant::query()
            ->forTenant($tenantId)
            ->whereIn('product_id', $allowedProductIds);

        if ($productId !== null && $productId !== '') {
            if (! in_array($productId, $allowedProductIds, true)) {
                return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
            }
            $query->where('product_id', $productId);
        }

        $email = $email !== null ? trim($email) : '';
        if ($email !== '') {
            $like = '%'.addcslashes($email, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->whereHas('user', fn ($u) => $u->where('email', 'like', $like))
                    ->orWhereHas('order', fn ($o) => $o->where('email', 'like', $like));
            });
        }

        $name = $name !== null ? trim($name) : '';
        if ($name !== '') {
            $like = '%'.addcslashes($name, '%_\\').'%';
            $query->whereHas('user', fn ($u) => $u->where('name', 'like', $like));
        }

        return $query
            ->with([
                'product:id,name,type',
                'user:id,name,email',
                'order:id,email',
                'communities:id,telegram_access_grant_id,status,joined_at',
            ])
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (TelegramAccessGrant $grant) => $this->mapRow($grant));
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(TelegramAccessGrant $grant): array
    {
        $tz = (string) config('app.timezone', 'UTC');
        $entry = $this->resolveEntryMoment($grant);

        return [
            'id' => $grant->id,
            'product' => $grant->product
                ? ['id' => (string) $grant->product->id, 'name' => $grant->product->name]
                : null,
            'buyer_name' => $grant->user?->name,
            'buyer_email' => $grant->user?->email ?: $grant->order?->email,
            'telegram_username' => $grant->telegram_username,
            'telegram_username_label' => $this->formatUsername($grant->telegram_username),
            'paid_at_label' => $grant->displayInAppTimezone('paid_at'),
            'entry_at_label' => $entry['label'],
            'entry_kind' => $entry['kind'],
            'access_ends_at_label' => $grant->is_lifetime
                ? 'Vitalício'
                : $grant->displayInAppTimezone('access_ends_at'),
            'is_lifetime' => (bool) $grant->is_lifetime,
            'status' => $grant->status,
            'status_label' => $this->statusLabel($grant->status),
            'delivery_status' => $grant->delivery_status,
            'delivery_status_label' => $this->deliveryLabel($grant->delivery_status),
            'timezone' => $tz,
        ];
    }

    /**
     * @return array{label: ?string, kind: ?string}
     */
    private function resolveEntryMoment(TelegramAccessGrant $grant): array
    {
        $tz = (string) config('app.timezone', 'UTC');
        $joined = $grant->communities
            ->filter(fn (TelegramAccessCommunity $c) => $c->joined_at !== null)
            ->sortBy('joined_at')
            ->first();

        if ($joined?->joined_at) {
            return [
                'label' => $joined->joined_at->timezone($tz)->format('d/m/Y H:i'),
                'kind' => 'joined',
            ];
        }

        $linked = $grant->displayInAppTimezone('identity_linked_at');
        if ($linked) {
            return [
                'label' => $linked,
                'kind' => 'identity',
            ];
        }

        return ['label' => null, 'kind' => null];
    }

    private function formatUsername(?string $username): ?string
    {
        $username = trim((string) $username);
        if ($username === '') {
            return null;
        }

        return str_starts_with($username, '@') ? $username : '@'.$username;
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            TelegramAccessGrant::STATUS_PENDING_IDENTITY => 'Aguardando vínculo',
            TelegramAccessGrant::STATUS_PENDING_INVITE => 'Aguardando convite',
            TelegramAccessGrant::STATUS_PENDING_ENTRY => 'Aguardando entrada',
            TelegramAccessGrant::STATUS_PARTIAL => 'Parcial',
            TelegramAccessGrant::STATUS_COMPLETED => 'Ativo',
            TelegramAccessGrant::STATUS_FAILED => 'Falha',
            TelegramAccessGrant::STATUS_REVOKED => 'Revogado',
            TelegramAccessGrant::STATUS_EXPIRED => 'Expirado',
            default => $status ?: '—',
        };
    }

    private function deliveryLabel(?string $status): string
    {
        return match ($status) {
            TelegramAccessGrant::DELIVERY_PENDING => 'Pendente',
            TelegramAccessGrant::DELIVERY_PARTIAL => 'Parcial',
            TelegramAccessGrant::DELIVERY_COMPLETED => 'Concluída',
            TelegramAccessGrant::DELIVERY_FAILED => 'Falhou',
            default => $status ?: '—',
        };
    }
}
