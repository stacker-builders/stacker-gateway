<?php

namespace App\Support\Demo;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

class DemoPlatformData
{
    private const PRODUCT_NAMES = [
        'Curso Marketing Digital',
        'Mentoria Premium',
        'E-book Vendas Online',
        'Comunidade VIP',
        'Template Notion Pro',
        'Workshop Instagram',
    ];

    private const GATEWAYS = ['cajupay', 'spacepag', 'efi', 'mercadopago'];

    private const STATUSES = ['completed', 'completed', 'completed', 'pending', 'completed', 'cancelled', 'refunded'];

    /**
     * @return array<string, mixed>
     */
    public static function dashboard(string $period): array
    {
        $seed = self::periodSeed($period);
        $multiplier = self::periodMultiplier($period);

        $quantidadeVendas = (int) round(180 * $multiplier + ($seed % 40));
        $vendasTotais = round($quantidadeVendas * (97.5 + ($seed % 50) / 10), 2);
        $ticketMedio = $quantidadeVendas > 0 ? round($vendasTotais / $quantidadeVendas, 2) : 0.0;

        $taxasCobradas = round($vendasTotais * 0.034, 2);
        $custoAdqVendas = round($vendasTotais * 0.012, 2);
        $custoAdqSaques = round(850 + ($seed % 200), 2);
        $faturamentoLiquido = round($taxasCobradas - $custoAdqVendas - $custoAdqSaques, 2);

        $novosInfoprodutores = 8 + ($seed % 9);
        $novosCompradores = 40 + ($seed % 25);
        $produtosCriados = 5 + ($seed % 7);
        $graficoVendas = self::chart($period, $seed, $vendasTotais);
        $compare = $period !== 'total';

        return [
            'period' => $period,
            'from' => null,
            'to' => null,
            'kpis' => [
                'wallet_available' => round(84250.75 + ($seed % 5000), 2),
                'wallet_pending' => round(12340.20 + ($seed % 2000), 2),
                'vendas_totais' => $vendasTotais,
                'quantidade_vendas' => $quantidadeVendas,
                'ticket_medio' => $ticketMedio,
                'withdrawals_total' => round(45200.00 * min($multiplier, 1.5), 2),
                'withdrawals_pending' => round(3200.00 + ($seed % 800), 2),
                'withdrawals_paid_count' => (int) round(18 * min($multiplier, 1.5)),
                'withdrawals_pending_count' => 3 + ($seed % 4),
                'infoprodutores_count' => 128 + ($seed % 15),
                'faturamento_taxas_cobradas' => $taxasCobradas,
                'faturamento_custo_adquirente_vendas' => $custoAdqVendas,
                'faturamento_custo_adquirente_saques' => $custoAdqSaques,
                'faturamento_liquido' => $faturamentoLiquido,
            ],
            'growth' => [
                'novos_infoprodutores' => $novosInfoprodutores,
                'infoprodutores_ativos' => 96 + ($seed % 8),
                'infoprodutores_total' => 128 + ($seed % 15),
                'infoprodutores_com_vendas' => 42 + ($seed % 10),
                'novos_compradores' => $novosCompradores,
                'compradores_recorrentes' => 18 + ($seed % 7),
                'produtos_criados' => $produtosCriados,
                'taxa_aprovacao' => 72.4,
            ],
            'comparisons' => $compare ? [
                'vendas_totais' => ['current' => $vendasTotais, 'previous' => round($vendasTotais * 0.86, 2), 'delta_percent' => 16.3],
                'quantidade_vendas' => ['current' => $quantidadeVendas, 'previous' => (int) round($quantidadeVendas * 0.9), 'delta_percent' => 11.1],
                'ticket_medio' => ['current' => $ticketMedio, 'previous' => round($ticketMedio * 0.97, 2), 'delta_percent' => 3.1],
                'faturamento_liquido' => ['current' => $faturamentoLiquido, 'previous' => round($faturamentoLiquido * 0.88, 2), 'delta_percent' => 13.6],
                'novos_infoprodutores' => ['current' => $novosInfoprodutores, 'previous' => max(1, $novosInfoprodutores - 3), 'delta_percent' => 27.3],
                'novos_compradores' => ['current' => $novosCompradores, 'previous' => (int) round($novosCompradores * 0.8), 'delta_percent' => 25.0],
                'produtos_criados' => ['current' => $produtosCriados, 'previous' => max(1, $produtosCriados - 2), 'delta_percent' => 40.0],
            ] : null,
            'funnel' => [
                'eventos' => 1284,
                'taxa_aprovacao' => 72.7,
                'items' => [
                    ['key' => 'completed', 'label' => 'Aprovadas', 'quantidade' => 934, 'percent' => 72.7],
                    ['key' => 'rejected', 'label' => 'Recusadas', 'quantidade' => 328, 'percent' => 25.5],
                    ['key' => 'pending', 'label' => 'Pendentes', 'quantidade' => 12, 'percent' => 0.9],
                    ['key' => 'cancelled', 'label' => 'Canceladas', 'quantidade' => 8, 'percent' => 0.6],
                    ['key' => 'refunded', 'label' => 'Reembolsadas', 'quantidade' => 22, 'percent' => 1.7],
                    ['key' => 'disputed', 'label' => 'Em disputa', 'quantidade' => 2, 'percent' => 0.2],
                    ['key' => 'refund_pending', 'label' => 'Reembolso pendente', 'quantidade' => 0, 'percent' => 0.0],
                ],
            ],
            'payment_methods' => [
                ['metodo' => 'pix', 'label' => 'PIX', 'total' => round($vendasTotais * 0.64, 2), 'quantidade' => (int) round($quantidadeVendas * 0.62), 'percent' => 64.0],
                ['metodo' => 'card', 'label' => 'Cartão', 'total' => round($vendasTotais * 0.31, 2), 'quantidade' => (int) round($quantidadeVendas * 0.33), 'percent' => 31.0],
                ['metodo' => 'boleto', 'label' => 'Boleto', 'total' => round($vendasTotais * 0.05, 2), 'quantidade' => (int) round($quantidadeVendas * 0.05), 'percent' => 5.0],
            ],
            'acquirers' => [
                ['slug' => 'cajupay', 'nome' => 'CajuPay', 'volume' => round($vendasTotais * 0.48, 2), 'transacoes' => 198, 'aprovadas' => 162, 'recusadas' => 36, 'taxa_aprovacao' => 81.8],
                ['slug' => 'efi', 'nome' => 'Efí', 'volume' => round($vendasTotais * 0.32, 2), 'transacoes' => 143, 'aprovadas' => 106, 'recusadas' => 37, 'taxa_aprovacao' => 74.1],
                ['slug' => 'mercadopago', 'nome' => 'Mercado Pago', 'volume' => round($vendasTotais * 0.20, 2), 'transacoes' => 88, 'aprovadas' => 70, 'recusadas' => 18, 'taxa_aprovacao' => 79.5],
            ],
            'acquirer_wallets' => [
                ['id' => 'demo-cajupay', 'slug' => 'cajupay', 'nome' => 'CajuPay', 'conta' => 'Conta principal', 'image' => 'images/gateways/cajupay.png', 'status' => 'ok', 'available' => round(18450.30 + ($seed % 800), 2), 'currency' => 'BRL', 'error' => null],
                ['id' => 'demo-efi', 'slug' => 'efi', 'nome' => 'Efí', 'conta' => null, 'image' => 'images/gateways/efi.png', 'status' => 'ok', 'available' => round(6120.80 + ($seed % 350), 2), 'currency' => 'BRL', 'error' => null],
                ['id' => 'demo-woovi', 'slug' => 'woovi', 'nome' => 'Woovi', 'conta' => null, 'image' => 'images/gateways/woovi.png', 'status' => 'ok', 'available' => round(7340.20 + ($seed % 280), 2), 'currency' => 'BRL', 'error' => null],
                ['id' => 'demo-mercadopago', 'slug' => 'mercadopago', 'nome' => 'Mercado Pago', 'conta' => null, 'image' => 'images/gateways/mercado-pago.webp', 'status' => 'ok', 'available' => round(4100.00 + ($seed % 250), 2), 'currency' => 'BRL', 'error' => null],
                ['id' => 'demo-stripe', 'slug' => 'stripe', 'nome' => 'Stripe', 'conta' => null, 'image' => 'images/gateways/stripe.png', 'status' => 'ok', 'available' => round(880.50 + ($seed % 120), 2), 'currency' => 'BRL', 'error' => null],
                ['id' => 'demo-versell', 'slug' => 'versell', 'nome' => 'Versell', 'conta' => null, 'image' => 'images/gateways/versell-logo.svg', 'status' => 'ok', 'available' => round(15670.00 + ($seed % 600), 2), 'currency' => 'BRL', 'error' => null],
                ['id' => 'demo-xflow', 'slug' => 'xflow', 'nome' => 'Xflow', 'conta' => null, 'image' => 'images/gateways/xflow_logo1.svg', 'status' => 'ok', 'available' => round(4820.40 + ($seed % 220), 2), 'currency' => 'BRL', 'error' => null],
                ['id' => 'demo-okto', 'slug' => 'okto', 'nome' => 'Okto', 'conta' => null, 'image' => 'images/gateways/okto_payments.svg', 'status' => 'ok', 'available' => round(3510.70 + ($seed % 180), 2), 'currency' => 'BRL', 'error' => null],
                ['id' => 'demo-xpag', 'slug' => 'xpag', 'nome' => 'XPag', 'conta' => null, 'image' => 'images/gateways/xpag_logo.svg', 'status' => 'ok', 'available' => round(2740.30 + ($seed % 160), 2), 'currency' => 'BRL', 'error' => null],
            ],
            'top_sellers' => [
                ['tenant_id' => 1, 'nome' => 'Academia Digital', 'email' => 'a@demo.local', 'quantidade' => 86, 'volume' => round($vendasTotais * 0.28, 2), 'ticket_medio' => 142.5],
                ['tenant_id' => 2, 'nome' => 'Mentoria Prime', 'email' => 'b@demo.local', 'quantidade' => 54, 'volume' => round($vendasTotais * 0.19, 2), 'ticket_medio' => 198.0],
                ['tenant_id' => 3, 'nome' => 'Cursos VIP', 'email' => 'c@demo.local', 'quantidade' => 41, 'volume' => round($vendasTotais * 0.14, 2), 'ticket_medio' => 97.4],
                ['tenant_id' => 4, 'nome' => 'Info Start', 'email' => 'd@demo.local', 'quantidade' => 33, 'volume' => round($vendasTotais * 0.11, 2), 'ticket_medio' => 88.2],
                ['tenant_id' => 5, 'nome' => 'Studio Growth', 'email' => 'e@demo.local', 'quantidade' => 21, 'volume' => round($vendasTotais * 0.08, 2), 'ticket_medio' => 121.0],
            ],
            'top_products' => [
                ['product_id' => 1, 'produto' => 'Curso Marketing Digital', 'seller' => 'Academia Digital', 'quantidade' => 64, 'volume' => round($vendasTotais * 0.22, 2)],
                ['product_id' => 2, 'produto' => 'Mentoria Premium', 'seller' => 'Mentoria Prime', 'quantidade' => 38, 'volume' => round($vendasTotais * 0.17, 2)],
                ['product_id' => 3, 'produto' => 'E-book Vendas Online', 'seller' => 'Cursos VIP', 'quantidade' => 51, 'volume' => round($vendasTotais * 0.09, 2)],
                ['product_id' => 4, 'produto' => 'Comunidade VIP', 'seller' => 'Info Start', 'quantidade' => 22, 'volume' => round($vendasTotais * 0.08, 2)],
                ['product_id' => 5, 'produto' => 'Workshop Instagram', 'seller' => 'Studio Growth', 'quantidade' => 18, 'volume' => round($vendasTotais * 0.06, 2)],
            ],
            'alerts' => [
                ['key' => 'saques', 'label' => 'saques pendentes ou com falha', 'count' => 3, 'href' => '/plataforma/saques'],
                ['key' => 'reembolsos', 'label' => 'reembolsos pendentes', 'count' => 5, 'href' => '/plataforma/transacoes?status=refund_requests'],
                ['key' => 'disputas', 'label' => 'disputas MED abertas', 'count' => 2, 'href' => '/plataforma/disputas'],
            ],
            'grafico_vendas' => $graficoVendas,
            'grafico' => [
                'granularity' => in_array($period, ['hoje', 'ontem'], true) ? 'hour' : (in_array($period, ['ano', 'total'], true) ? 'month' : 'day'),
                'compare' => $compare,
                'points' => array_map(function (array $p) {
                    $count = max(1, (int) round($p['total'] / 97));

                    return [
                        'key' => $p['data'],
                        'label' => $p['data'],
                        'volume' => $p['total'],
                        'count' => $count,
                        'ticket' => round($p['total'] / $count, 2),
                        'revenue' => round($p['total'] * 0.034, 2),
                        'refunds' => round($p['total'] * 0.02, 2),
                        'prev_volume' => round($p['total'] * 0.86, 2),
                        'prev_count' => max(1, (int) round($count * 0.9)),
                        'prev_ticket' => round($p['total'] * 0.86 / max(1, $count * 0.9), 2),
                        'prev_revenue' => round($p['total'] * 0.03, 2),
                        'prev_refunds' => round($p['total'] * 0.015, 2),
                    ];
                }, $graficoVendas),
            ],
            'ultimas_transacoes' => self::recentTransactions(10, $seed),
        ];
    }

    /**
     * @return array{orders: LengthAwarePaginator, filters: array{status: string, q: string, per_page: int, gateway: string, method: string}}
     */
    public static function transactions(
        string $status,
        string $q,
        string $path,
        array $query,
        int $perPage = 25,
        ?string $gateway = null,
        ?string $method = null,
    ): array {
        $seed = crc32($status.'|'.$q);
        $items = [];

        for ($i = 0; $i < 15; $i++) {
            $idx = ($seed + $i) % count(self::PRODUCT_NAMES);
            $orderStatus = self::STATUSES[($seed + $i) % count(self::STATUSES)];
            if ($status === 'refund_requests') {
                $orderStatus = 'completed';
            } elseif ($status !== 'all' && $orderStatus !== $status) {
                $orderStatus = $status === 'disputed' ? 'completed' : $status;
            }

            $amount = round(47.0 + (($seed + $i * 17) % 450), 2);
            $fee = round($amount * 0.039, 2);
            $created = Carbon::now()->subHours($i * 3 + 2)->toIso8601String();

            $row = [
                'id' => 900000 + $seed + $i,
                'email' => 'cliente'.($i + 1).'@demo.exemplo',
                'status' => $orderStatus,
                'amount' => $amount,
                'amount_total' => $amount,
                'amount_gross' => $amount,
                'amount_fee' => $fee,
                'amount_net' => round($amount - $fee, 2),
                'gateway' => self::GATEWAYS[($seed + $i) % count(self::GATEWAYS)],
                'payment_method' => ($i % 2 === 0) ? 'pix' : 'card',
                'gateway_label' => 'PIX',
                'recebedor' => match (self::GATEWAYS[($seed + $i) % count(self::GATEWAYS)]) {
                    'cajupay' => 'CajuPay',
                    'spacepag' => 'Spacepag',
                    'efi' => 'Efí',
                    'mercadopago' => 'Mercado Pago',
                    default => '—',
                },
                'payment_method_label' => ($i % 2 === 0) ? 'PIX' : 'Cartão',
                'product_display_name' => self::PRODUCT_NAMES[$idx],
                'product_label' => self::PRODUCT_NAMES[$idx],
                'product_name' => self::PRODUCT_NAMES[$idx],
                'payment_type_label' => ($i % 4 === 0) ? 'Pagamento recorrente' : 'Pagamento único',
                'sale_type_label' => ($i % 4 === 0) ? 'Assinatura' : 'Único',
                'customer_name' => 'Cliente Demo '.($i + 1),
                'customer_email' => 'cliente'.($i + 1).'@demo.exemplo',
                'infoprodutor_name' => 'Vendedor Demo',
                'infoprodutor_email' => 'demo-vendedor@demo.local',
                'checkout_url' => url('/c/demo-produto'),
                'has_open_med_dispute' => $orderStatus === 'completed' && $i === 2,
                'created_at' => $created,
                'pending_refund_request' => null,
            ];

            if ($status === 'refund_requests' || ($orderStatus === 'completed' && $i % 5 === 0)) {
                $row['pending_refund_request'] = [
                    'id' => 7000 + $i,
                    'status' => 'pending',
                    'customer_reason' => 'Solicitação demo de reembolso #'.($i + 1),
                    'created_at' => Carbon::now()->subHours($i + 1)->toIso8601String(),
                ];
            }

            $items[] = $row;
        }

        if ($status === 'refund_requests') {
            $items = array_values(array_filter(
                $items,
                fn (array $row) => ! empty($row['pending_refund_request'])
            ));
        }

        if ($gateway !== null && $gateway !== '') {
            $items = array_values(array_filter(
                $items,
                fn (array $row) => ($row['gateway'] ?? '') === $gateway
            ));
        }

        if ($method !== null && $method !== '') {
            $items = array_values(array_filter(
                $items,
                fn (array $row) => ($row['payment_method'] ?? '') === $method
            ));
        }

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $items = array_values(array_filter($items, function (array $row) use ($needle) {
                return str_contains(mb_strtolower((string) $row['email']), $needle)
                    || str_contains(mb_strtolower((string) $row['product_name']), $needle)
                    || str_contains(mb_strtolower((string) $row['customer_name']), $needle)
                    || str_contains(mb_strtolower((string) $row['infoprodutor_name']), $needle)
                    || str_contains(mb_strtolower((string) $row['infoprodutor_email']), $needle)
                    || str_contains((string) $row['id'], $needle);
            }));
        }

        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $page = max(1, (int) ($query['page'] ?? 1));
        $total = count($items);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        $paginator = new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => $path, 'query' => $query]
        );

        return [
            'orders' => $paginator,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'per_page' => $perPage,
                'gateway' => $gateway ?? '',
                'method' => $method ?? '',
            ],
        ];
    }

    /**
     * @return array{merchants: LengthAwarePaginator, filters: array{q: string, has_balance: bool}}
     */
    public static function balances(string $search, bool $hasBalance, string $path, array $query): array
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $available = round(500 + ($i * 1379.45) % 25000, 2);
            $pending = round(100 + ($i * 421.11) % 8000, 2);
            $rows[] = [
                'id' => 1000 + $i,
                'name' => 'Infoprodutor Demo '.($i + 1),
                'email' => 'vendedor'.($i + 1).'@demo.exemplo',
                'tenant_id' => 2000 + $i,
                'available_total' => $available,
                'pending_total' => $pending,
                'med_total' => $i === 1 ? 450.0 : 0.0,
            ];
        }

        if ($search !== '') {
            $rows = array_values(array_filter($rows, function (array $row) use ($search) {
                return str_contains(strtolower($row['name']), strtolower($search))
                    || str_contains(strtolower($row['email']), strtolower($search));
            }));
        }

        if ($hasBalance) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['available_total'] > 0 || $row['pending_total'] > 0));
        }

        $paginator = new LengthAwarePaginator(
            $rows,
            count($rows),
            40,
            1,
            ['path' => $path, 'query' => $query]
        );

        return [
            'merchants' => $paginator,
            'filters' => [
                'q' => $search,
                'has_balance' => $hasBalance,
            ],
        ];
    }

    /**
     * @return array<int, array{data: string, total: float}>
     */
    private static function chart(string $period, int $seed, float $total): array
    {
        $isHourly = in_array($period, ['hoje', 'ontem'], true);

        if ($isHourly) {
            $result = [];
            $weights = [];
            $sum = 0;
            for ($h = 0; $h <= 23; $h++) {
                $w = 1 + (($seed + $h * 7) % 9);
                $weights[$h] = $w;
                $sum += $w;
            }
            foreach ($weights as $h => $w) {
                $result[] = [
                    'data' => (string) $h,
                    'total' => round($total * ($w / max($sum, 1)), 2),
                ];
            }

            return $result;
        }

        $days = match ($period) {
            '7dias' => 7,
            'mes' => 28,
            'ano' => 12,
            'total' => 12,
            default => 7,
        };

        $result = [];
        $weights = [];
        $sum = 0;
        for ($d = 0; $d < $days; $d++) {
            $w = 1 + (($seed + $d * 11) % 12);
            $weights[] = $w;
            $sum += $w;
        }

        foreach ($weights as $d => $w) {
            $label = in_array($period, ['ano', 'total'], true)
                ? Carbon::now()->startOfYear()->addMonths($d)->format('Y-m')
                : Carbon::now()->subDays($days - $d - 1)->format('Y-m-d');

            $result[] = [
                'data' => $label,
                'total' => round($total * ($w / max($sum, 1)), 2),
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function recentTransactions(int $count, int $seed): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $idx = ($seed + $i) % count(self::PRODUCT_NAMES);
            $rows[] = [
                'id' => 800000 + $seed + $i,
                'email' => 'comprador'.($i + 1).'@demo.exemplo',
                'product_name' => self::PRODUCT_NAMES[$idx],
                'amount' => round(57 + (($seed + $i * 13) % 320), 2),
                'status' => 'completed',
                'gateway' => self::GATEWAYS[($seed + $i) % count(self::GATEWAYS)],
                'gateway_label' => match (self::GATEWAYS[($seed + $i) % count(self::GATEWAYS)]) {
                    'cajupay' => 'CajuPay',
                    'spacepag' => 'Spacepag',
                    'efi' => 'Efí',
                    default => 'Mercado Pago',
                },
                'payment_method' => $i % 5 === 0 ? 'Cartão' : 'PIX',
                'seller_name' => 'Vendedor Demo '.(($i % 3) + 1),
                'created_at' => Carbon::now()->subMinutes($i * 18 + 5)->toIso8601String(),
            ];
        }

        return $rows;
    }

    private static function periodSeed(string $period): int
    {
        return abs(crc32($period));
    }

    private static function periodMultiplier(string $period): float
    {
        return match ($period) {
            'hoje' => 0.08,
            'ontem' => 0.07,
            '7dias' => 0.45,
            'mes' => 1.0,
            'ano' => 8.5,
            'total' => 14.0,
            default => 1.0,
        };
    }
}
