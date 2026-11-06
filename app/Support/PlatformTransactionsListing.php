<?php

namespace App\Support;

use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Services\PlatformPaymentMethods;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Filtros e paginação da listagem admin de transações (orders).
 */
final class PlatformTransactionsListing
{
    public const STATUS_OPTIONS = ['all', 'pending', 'completed', 'disputed', 'cancelled', 'refunded', 'refund_requests'];

    /** Pedidos com solicitação de reembolso do cliente ainda pendente (badge do menu). */
    public const STATUS_REFUND_REQUESTS = 'refund_requests';

    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public const DEFAULT_PER_PAGE = 25;

    public static function normalizeStatus(?string $status): string
    {
        $status = is_string($status) ? trim($status) : 'all';
        if (! in_array($status, self::STATUS_OPTIONS, true)) {
            return 'all';
        }

        return $status;
    }

    public static function normalizePerPage(mixed $perPage): int
    {
        $perPage = (int) $perPage;
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            return self::DEFAULT_PER_PAGE;
        }

        return $perPage;
    }

    /**
     * @param  Builder<\App\Models\Order>  $query
     */
    public static function applyStatusFilter(Builder $query, string $status): void
    {
        if ($status === 'all') {
            return;
        }

        if ($status === self::STATUS_REFUND_REQUESTS) {
            $query->where(function ($w) {
                $w->where('orders.status', 'refund_pending');
                if (Schema::hasTable('refund_requests')) {
                    $w->orWhereHas('refundRequests', function ($rr) {
                        $rr->where('status', \App\Models\RefundRequest::STATUS_PENDING);
                    });
                }
            });

            return;
        }

        $query->where('orders.status', $status);
    }

    public static function normalizeGateway(mixed $gateway): ?string
    {
        $gateway = is_string($gateway) ? strtolower(trim($gateway)) : '';
        if ($gateway === '' || $gateway === 'all') {
            return null;
        }
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $gateway)) {
            return null;
        }

        return $gateway;
    }

    public static function normalizePaymentMethod(mixed $method): ?string
    {
        $method = is_string($method) ? strtolower(trim($method)) : '';
        if ($method === '' || $method === 'all') {
            return null;
        }
        if (! in_array($method, PlatformPaymentMethods::METHOD_KEYS, true)) {
            return null;
        }

        return $method;
    }

    /**
     * @return list<string>
     */
    public static function paymentMethodAliases(string $method): array
    {
        return match ($method) {
            'card' => ['card', 'credit_card', 'creditcard'],
            default => [$method],
        };
    }

    /**
     * @param  Builder<\App\Models\Order>  $query
     */
    public static function applyGatewayFilter(Builder $query, ?string $gateway): void
    {
        $gateway = self::normalizeGateway($gateway);
        if ($gateway === null) {
            return;
        }

        $query->whereRaw('LOWER(orders.gateway) = ?', [$gateway]);
    }

    /**
     * Método exibido na transação: metadata do checkout tem prioridade sobre a coluna.
     *
     * @param  Builder<\App\Models\Order>  $query
     */
    public static function applyPaymentMethodFilter(Builder $query, ?string $method): void
    {
        $method = self::normalizePaymentMethod($method);
        if ($method === null) {
            return;
        }

        $aliases = self::paymentMethodAliases($method);
        $query->where(function (Builder $w) use ($aliases) {
            $w->whereIn('orders.metadata->checkout_payment_method', $aliases)
                ->orWhere(function (Builder $fallback) use ($aliases) {
                    $fallback->where(function (Builder $emptyMeta) {
                        $emptyMeta->whereNull('orders.metadata->checkout_payment_method')
                            ->orWhere('orders.metadata->checkout_payment_method', '');
                    })->whereIn('orders.payment_method', $aliases);
                });
        });
    }

    /**
     * Adquirentes conhecidas, ligadas ou não. Desligada = nenhuma credencial conectada e habilitada.
     *
     * @return list<array{value: string, label: string, connected: bool}>
     */
    public static function gatewayOptions(): array
    {
        $bySlug = [];
        foreach (GatewayRegistry::all() as $gateway) {
            $slug = strtolower(trim((string) ($gateway['slug'] ?? '')));
            if ($slug === '' || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
                continue;
            }
            $name = trim((string) ($gateway['name'] ?? ''));
            $bySlug[$slug] = $name !== '' ? $name : Str::headline(str_replace(['_', '-'], ' ', $slug));
        }
        $bySlug['manual'] = $bySlug['manual'] ?? 'Manual';

        if (Schema::hasTable('gateway_credentials')) {
            $extra = GatewayCredential::query()->distinct()->pluck('gateway_slug');
            foreach ($extra as $slug) {
                $slug = strtolower(trim((string) $slug));
                if ($slug === '' || isset($bySlug[$slug]) || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
                    continue;
                }
                $def = GatewayRegistry::get($slug);
                $name = is_array($def) ? trim((string) ($def['name'] ?? '')) : '';
                $bySlug[$slug] = $name !== '' ? $name : Str::headline(str_replace(['_', '-'], ' ', $slug));
            }
        }

        $active = [];
        if (Schema::hasTable('gateway_credentials')) {
            $active = GatewayCredential::query()
                ->where('is_connected', true)
                ->enabledForPayments()
                ->pluck('gateway_slug')
                ->map(fn ($slug) => strtolower(trim((string) $slug)))
                ->filter(fn (string $slug) => $slug !== '')
                ->all();
        }
        $activeSet = array_flip($active);

        $options = [];
        foreach ($bySlug as $slug => $name) {
            $connected = isset($activeSet[$slug]);
            $options[] = [
                'value' => $slug,
                'name' => $name,
                'label' => $connected ? $name : $name.' (desligada)',
                'connected' => $connected,
            ];
        }

        usort($options, function (array $a, array $b) {
            if ($a['connected'] !== $b['connected']) {
                return $a['connected'] ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return array_map(fn (array $row) => [
            'value' => $row['value'],
            'label' => $row['label'],
            'connected' => $row['connected'],
        ], $options);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function paymentMethodOptions(): array
    {
        return array_map(fn (array $row) => [
            'value' => $row['key'],
            'label' => $row['label'],
        ], PlatformPaymentMethods::labelsForAdmin());
    }

    /**
     * Busca global server-side (não depende da página carregada).
     *
     * @param  Builder<\App\Models\Order>  $query
     */
    public static function applySearchFilter(Builder $query, string $q): void
    {
        $q = trim($q);
        if ($q === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $likeLower = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($q)).'%';
        $digits = preg_replace('/\D+/', '', $q) ?? '';

        $query->where(function ($w) use ($q, $like, $likeLower, $digits) {
            $w->whereRaw('LOWER(orders.email) LIKE ?', [$likeLower])
                ->orWhereHas('user', function ($u) use ($likeLower) {
                    $u->whereRaw('LOWER(name) LIKE ?', [$likeLower])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$likeLower]);
                    if (Schema::hasColumn('users', 'document')) {
                        $u->orWhere('document', 'like', $likeLower);
                    }
                    if (Schema::hasColumn('users', 'phone')) {
                        $u->orWhere('phone', 'like', $likeLower);
                    }
                })
                ->orWhereHas('tenantOwner', function ($u) use ($likeLower) {
                    $u->whereRaw('LOWER(name) LIKE ?', [$likeLower])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$likeLower]);
                })
                ->orWhereHas('product', function ($p) use ($likeLower) {
                    $p->whereRaw('LOWER(name) LIKE ?', [$likeLower]);
                });

            if (Schema::hasColumn('orders', 'gateway_id')) {
                $w->orWhere('orders.gateway_id', 'like', $like);
            }

            if ($digits !== '') {
                if (Schema::hasColumn('orders', 'cpf')) {
                    $w->orWhere('orders.cpf', 'like', '%'.$digits.'%');
                }
                if (Schema::hasColumn('orders', 'phone')) {
                    $w->orWhere('orders.phone', 'like', '%'.$digits.'%');
                }
                if (Schema::hasColumn('users', 'document') || Schema::hasColumn('users', 'phone')) {
                    $w->orWhereHas('user', function ($u) use ($digits) {
                        $u->where(function ($inner) use ($digits) {
                            if (Schema::hasColumn('users', 'document')) {
                                $inner->orWhere('document', 'like', '%'.$digits.'%');
                            }
                            if (Schema::hasColumn('users', 'phone')) {
                                $inner->orWhere('phone', 'like', '%'.$digits.'%');
                            }
                        });
                    });
                }
            }

            if (ctype_digit($q)) {
                $w->orWhere('orders.id', (int) $q);
            }
        });
    }
}
