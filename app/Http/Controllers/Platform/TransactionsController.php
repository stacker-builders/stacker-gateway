<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Http\Controllers\Controller;
use App\Models\CheckoutSession;
use App\Models\MedDispute;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Withdrawal;
use App\Services\ManualOrderRefundService;
use App\Services\OrderFeeBreakdownService;
use App\Services\OrderManualApprovalService;
use App\Services\Platform\PlatformAdminAlertCounts;
use App\Services\PlatformAdminDeletionService;
use App\Services\PlatformAuditService;
use App\Services\PlatformOrderAdminService;
use App\Services\WithdrawalPixReceiptService;
use App\Support\DemoMode;
use App\Support\Demo\DemoPlatformData;
use App\Support\OrderManualRefund;
use App\Support\PlatformTransactionsListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class TransactionsController extends Controller
{
    use RequiresPlatformStepUp;

    public function __construct(
        protected WithdrawalPixReceiptService $receiptService,
    ) {}

    private function productDisplayName(Order $order): string
    {
        return $this->orderProductLabel($order);
    }

    private function paymentTypeLabel(Order $order): string
    {
        if ($order->subscription_plan_id || $order->is_renewal) {
            return 'Pagamento recorrente';
        }

        return 'Pagamento único';
    }

    public function index(Request $request): Response
    {
        $status = PlatformTransactionsListing::normalizeStatus($request->query('status', 'all'));
        $q = trim((string) $request->query('q', ''));
        $perPage = PlatformTransactionsListing::normalizePerPage($request->query('per_page'));
        $gateway = PlatformTransactionsListing::normalizeGateway($request->query('gateway'));
        $method = PlatformTransactionsListing::normalizePaymentMethod($request->query('method'));

        if (DemoMode::isEnabled()) {
            $payload = DemoPlatformData::transactions(
                $status,
                $q,
                $request->url(),
                $request->query(),
                $perPage,
                $gateway,
                $method
            );
            $payload['refund_requests_pending_count'] = app(PlatformAdminAlertCounts::class)->refundRequestsPendingCount();
            $payload['gateway_options'] = PlatformTransactionsListing::gatewayOptions();
            $payload['payment_method_options'] = PlatformTransactionsListing::paymentMethodOptions();

            return Inertia::render('Platform/Transactions/Index', $payload);
        }

        $ordersPaginator = new LengthAwarePaginator([], 0, $perPage, 1, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        if (Schema::hasTable('orders')) {
            $query = $this->baseOrdersQuery();
            PlatformTransactionsListing::applyStatusFilter($query, $status);
            PlatformTransactionsListing::applyGatewayFilter($query, $gateway);
            PlatformTransactionsListing::applyPaymentMethodFilter($query, $method);
            PlatformTransactionsListing::applySearchFilter($query, $q);

            if ($status === PlatformTransactionsListing::STATUS_REFUND_REQUESTS) {
                $query->reorder()
                    ->orderByRaw("CASE WHEN orders.status = 'refund_pending' THEN 0 ELSE 1 END")
                    ->orderByDesc('orders.updated_at');
            }

            $paginated = $query->paginate($perPage)->withQueryString();
            $openMedOrderIds = MedDispute::query()
                ->whereIn('order_id', $paginated->getCollection()->pluck('id'))
                ->open()
                ->pluck('order_id')
                ->flip()
                ->all();

            $viewableCustomerIds = $this->viewableCustomerIdSet($paginated->getCollection());
            $ordersPaginator = $paginated->through(fn (Order $o) => $this->mapOrderForAdmin($o, $openMedOrderIds, $viewableCustomerIds));
        }

        return Inertia::render('Platform/Transactions/Index', [
            'orders' => $ordersPaginator,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'per_page' => $perPage,
                'gateway' => $gateway ?? '',
                'method' => $method ?? '',
            ],
            'gateway_options' => PlatformTransactionsListing::gatewayOptions(),
            'payment_method_options' => PlatformTransactionsListing::paymentMethodOptions(),
            'refund_requests_pending_count' => app(PlatformAdminAlertCounts::class)->refundRequestsPendingCount(),
        ]);
    }

    public function apiIndex(Request $request): Response
    {
        $status = PlatformTransactionsListing::normalizeStatus($request->query('status', 'all'));
        $q = trim((string) $request->query('q', ''));
        $perPage = PlatformTransactionsListing::normalizePerPage($request->query('per_page'));

        $ordersPaginator = new LengthAwarePaginator([], 0, $perPage, 1, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        if (Schema::hasTable('orders')) {
            $query = $this->baseOrdersQuery()
                ->whereNotNull('orders.api_application_id')
                ->where('orders.payment_method', 'pix')
                ->where('orders.metadata->source', 'api');

            PlatformTransactionsListing::applyStatusFilter($query, $status);
            PlatformTransactionsListing::applySearchFilter($query, $q);

            $paginated = $query->paginate($perPage)->withQueryString();
            $openMedOrderIds = MedDispute::query()
                ->whereIn('order_id', $paginated->getCollection()->pluck('id'))
                ->open()
                ->pluck('order_id')
                ->flip()
                ->all();

            $viewableCustomerIds = $this->viewableCustomerIdSet($paginated->getCollection());
            $ordersPaginator = $paginated->through(fn (Order $o) => $this->mapOrderForAdmin($o, $openMedOrderIds, $viewableCustomerIds));
        }

        $apiCashoutsPaginator = new LengthAwarePaginator([], 0, $perPage, 1, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        if (Schema::hasTable('withdrawals')) {
            $cashoutQuery = Withdrawal::query()
                ->with(['tenantOwner:id,name,email', 'apiApplication:id,name'])
                ->whereNotNull('api_application_id')
                ->orderByDesc('created_at');

            $apiCashoutsPaginator = $cashoutQuery
                ->paginate($perPage, ['*'], 'cashout_page')
                ->withQueryString()
                ->through(fn (Withdrawal $w) => $this->receiptService->mapWithdrawalListItem($w));
        }

        return Inertia::render('Platform/ApiTransactions/Index', [
            'orders' => $ordersPaginator,
            'api_cashouts' => $apiCashoutsPaginator,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Order>
     */
    private function baseOrdersQuery()
    {
        $query = Order::query()
            ->with([
                'user:id,name,email',
                'tenantOwner:id,name,email',
                'product:id,name,slug,checkout_slug',
                'productOffer:id,name,checkout_slug',
                'subscriptionPlan:id,name,checkout_slug',
                'checkoutSession:'.CheckoutSession::eagerSelectForOrderRelation(),
                'orderItems:id,order_id,product_id,product_offer_id,subscription_plan_id,amount,position',
                'orderItems.product:id,name',
                'orderItems.productOffer:id,name',
                'orderItems.subscriptionPlan:id,name',
                'apiApplication:id,name',
                'cajupayAccount:id,name,is_default',
            ])
            ->orderByDesc('created_at');

        if (Schema::hasTable('refund_requests')) {
            $query->with(['refundRequests' => function ($rr) {
                $rr->where('status', RefundRequest::STATUS_PENDING)->orderByDesc('id');
            }]);
        }

        return $query;
    }

    /**
     * Compradores que podem abrir a ficha em Clientes.
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     * @return array<int, true>
     */
    private function viewableCustomerIdSet($orders): array
    {
        $set = [];
        foreach ($orders as $order) {
            $user = $order->user;
            if ($user === null || $user->isPlatformAdmin() || $user->isTeam()) {
                continue;
            }
            $set[(int) $user->id] = true;
        }

        return $set;
    }

    /**
     * @param  array<int, int>  $openMedOrderIds
     * @param  array<int, true>  $viewableCustomerIds
     * @return array<string, mixed>
     */
    private function mapOrderForAdmin(Order $o, array $openMedOrderIds, array $viewableCustomerIds = []): array
    {
        $arr = $o->toArray();
        $breakdown = OrderFeeBreakdownService::forOrder($o);
        $meta = is_array($o->metadata) ? $o->metadata : [];
        $partnerCheckoutUrl = trim((string) ($meta['partner_checkout_url'] ?? ''));

        $arr['gateway_label'] = $o->paymentMethodDisplayLabel();
        $arr['recebedor'] = $o->acquirerDisplayName();
        $arr['product_display_name'] = $this->productDisplayName($o);
        $arr['checkout_url'] = url('/c/'.$o->getCheckoutSlug());
        $arr['partner_checkout_url'] = $partnerCheckoutUrl !== '' ? $partnerCheckoutUrl : null;
        $arr['payment_type_label'] = $this->paymentTypeLabel($o);
        $arr['amount_total'] = $breakdown['gross'];
        $arr['amount_gross'] = $breakdown['gross'];
        $arr['amount_fee'] = $breakdown['fee'];
        $arr['amount_net'] = $breakdown['net'];
        $arr['product_label'] = $this->orderProductLabel($o);
        $customerId = $o->user_id !== null && $o->user_id !== '' ? (int) $o->user_id : null;
        $arr['customer_id'] = $customerId;
        $arr['customer_url'] = ($customerId !== null && isset($viewableCustomerIds[$customerId]))
            ? route('plataforma.clientes.show', ['user' => $customerId])
            : null;
        $arr['customer_name'] = $o->user?->name ?? '—';
        $arr['customer_email'] = $o->user?->email ?? $o->email ?? '—';
        $arr['infoprodutor_name'] = $o->tenantOwner?->name ?? '—';
        $arr['infoprodutor_email'] = $o->tenantOwner?->email;
        $arr['payment_method_label'] = $o->paymentMethodDisplayLabel();
        $arr['has_open_med_dispute'] = isset($openMedOrderIds[$o->id]);
        $arr['api_application_name'] = $o->apiApplication?->name;
        $arr['is_pixgo'] = $o->isPixGoSale();
        $arr['pixgo_label'] = \App\Services\PixGoAccess::sidebarLabel();
        $cajupayBadge = $this->cajupayAccountBadge($o);
        $arr['cajupay_account_id'] = $o->cajupay_account_id;
        $arr['cajupay_account_badge'] = $cajupayBadge;
        $arr['can_manual_refund'] = OrderManualRefund::canManualRefund($o);
        $arr['manual_refund'] = OrderManualRefund::snapshot($o);
        $arr['status_label'] = OrderManualRefund::statusLabel($o);
        $arr['pending_refund_request'] = $this->mapPendingRefundRequest($o);

        return $arr;
    }

    /**
     * @return array{id: int, status: string, customer_reason: ?string, created_at: ?string}|null
     */
    private function mapPendingRefundRequest(Order $o): ?array
    {
        if (! $o->relationLoaded('refundRequests')) {
            return null;
        }

        $rr = $o->refundRequests
            ->first(fn (RefundRequest $r) => $r->status === RefundRequest::STATUS_PENDING);

        if ($rr === null) {
            return null;
        }

        return [
            'id' => $rr->id,
            'status' => $rr->status,
            'customer_reason' => $rr->customer_reason,
            'created_at' => $rr->created_at?->toIso8601String(),
        ];
    }

    private function cajupayAccountBadge(Order $order): ?string
    {
        if ($order->gateway !== 'cajupay') {
            return null;
        }

        $account = $order->cajupayAccount;
        if ($account !== null) {
            $label = trim((string) $account->name);
            if ($label === '') {
                $label = 'Conta #'.$account->id;
            }
            if ($account->is_default) {
                $label .= ' (padrão)';
            }

            return $label;
        }

        return 'Conta padrão';
    }

    private function orderActionRedirectParams(Request $request): array
    {
        $status = $request->input('status', $request->query('status'));
        $q = $request->input('q', $request->query('q'));
        $perPage = $request->input('per_page', $request->query('per_page'));
        $page = $request->input('page', $request->query('page'));
        $gateway = $request->input('gateway', $request->query('gateway'));
        $method = $request->input('method', $request->query('method'));

        return array_filter([
            'status' => $status,
            'q' => $q,
            'per_page' => $perPage,
            'page' => $page,
            'gateway' => $gateway,
            'method' => $method,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function bulkDestroyPending(Request $request): RedirectResponse
    {
        $redirectParams = $this->orderActionRedirectParams($request);
        // Após exclusão em massa, volta à página 1 com os mesmos filtros.
        unset($redirectParams['page']);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'ids.required' => 'Selecione ao menos uma transação pendente.',
            'ids.max' => 'É possível excluir no máximo 100 transações por operação.',
        ]);

        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));
        $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : '';
        $reason = $reason !== '' ? $reason : null;

        try {
            $deleted = PlatformAdminDeletionService::deletePendingOrdersAllOrNothing($ids);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', 'Não foi possível excluir as transações selecionadas.');
        }

        $count = count($deleted);
        foreach ($deleted as $orderId) {
            PlatformAuditService::log('platform.order.deleted', [
                'order_id' => $orderId,
                'bulk' => true,
                'reason' => $reason,
            ], $request);
        }

        PlatformAuditService::log('platform.order.bulk_deleted', [
            'deleted_count' => $count,
            'deleted_ids' => $deleted,
            'reason' => $reason,
        ], $request);

        $message = $count === 1
            ? '1 transação pendente foi excluída com sucesso.'
            : "{$count} transações pendentes foram excluídas com sucesso.";

        return redirect()->route('plataforma.transacoes.index', $redirectParams)
            ->with('success', $message);
    }

    public function approveManualOrder(Request $request, Order $order): RedirectResponse
    {
        $redirectParams = $this->orderActionRedirectParams($request);

        try {
            OrderManualApprovalService::approve($order);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', 'Não foi possível concluir a aprovação: '.$e->getMessage());
        }

        PlatformAuditService::log('platform.order.approved_manually', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
        ], $request);

        return redirect()->route('plataforma.transacoes.index', $redirectParams)
            ->with('success', 'Pedido #'.$order->id.' aprovado. O cliente recebeu acesso conforme o produto.');
    }

    public function cancelOrder(Request $request, Order $order): RedirectResponse
    {
        $redirectParams = $this->orderActionRedirectParams($request);

        try {
            PlatformOrderAdminService::cancelPending($order);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', 'Não foi possível cancelar: '.$e->getMessage());
        }

        PlatformAuditService::log('platform.order.cancelled', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
        ], $request);

        return redirect()->route('plataforma.transacoes.index', $redirectParams)
            ->with('success', 'Pedido #'.$order->id.' cancelado.');
    }

    public function refundOrder(Request $request, Order $order, ManualOrderRefundService $refundService): RedirectResponse
    {
        $redirectParams = $this->orderActionRedirectParams($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'Informe o motivo do reembolso para o infoprodutor.',
            'reason.min' => 'O motivo deve ter pelo menos 3 caracteres.',
        ]);

        try {
            $result = $refundService->refund(
                $order,
                $request->user(),
                'platform',
                $validated['reason']
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', 'Não foi possível reembolsar: '.$e->getMessage());
        }

        if (! $result['success']) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', $result['message']);
        }

        if ($result['gateway_status'] === 'gateway_pending') {
            PlatformAuditService::log('platform.order.refund_pending', [
                'order_id' => $order->id,
                'tenant_id' => $order->tenant_id,
                'reason' => $validated['reason'],
            ], $request);

            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('success', $result['message']);
        }

        PlatformAuditService::log('platform.order.refunded', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'reason' => $validated['reason'],
        ], $request);

        return redirect()->route('plataforma.transacoes.index', $redirectParams)
            ->with('success', $result['message']);
    }

    public function refundOrderOffline(Request $request, Order $order, ManualOrderRefundService $refundService): RedirectResponse
    {
        $redirectParams = $this->orderActionRedirectParams($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'totp_code' => ['nullable', 'string', 'max:12'],
        ], [
            'reason.required' => 'Informe o motivo do reembolso manual para o infoprodutor.',
            'reason.min' => 'O motivo deve ter pelo menos 3 caracteres.',
        ]);

        $this->validatePlatformStepUp($request);

        try {
            $result = $refundService->refundOffline(
                $order,
                $request->user(),
                'platform',
                $validated['reason']
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', 'Não foi possível registrar o reembolso manual: '.$e->getMessage());
        }

        if (! $result['success']) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', $result['message']);
        }

        PlatformAuditService::log('platform.order.refunded_offline', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'reason' => $validated['reason'],
        ], $request);

        return redirect()->route('plataforma.transacoes.index', $redirectParams)
            ->with('success', $result['message']);
    }

    public function markDisputedOrder(Request $request, Order $order): RedirectResponse
    {
        $redirectParams = $this->orderActionRedirectParams($request);

        try {
            PlatformOrderAdminService::markDisputed($order);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', 'Não foi possível atualizar: '.$e->getMessage());
        }

        PlatformAuditService::log('platform.order.disputed', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
        ], $request);

        return redirect()->route('plataforma.transacoes.index', $redirectParams)
            ->with('success', 'Pedido #'.$order->id.' marcado como MED.');
    }

    public function destroyOrder(Request $request, Order $order): RedirectResponse
    {
        $redirectParams = $this->orderActionRedirectParams($request);
        $orderId = $order->id;

        try {
            PlatformAdminDeletionService::deleteOrder($order);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', 'Não foi possível excluir o pedido: '.$e->getMessage());
        }

        PlatformAuditService::log('platform.order.deleted', [
            'order_id' => $orderId,
        ], $request);

        return redirect()->route('plataforma.transacoes.index', $redirectParams)
            ->with('success', 'Pedido #'.$orderId.' removido do histórico.');
    }

    private function orderProductLabel(Order $order): string
    {
        if ($order->isPixGoSale()) {
            return 'Venda '.\App\Services\PixGoAccess::sidebarLabel();
        }

        $product = $order->product;
        if (! $product) {
            return '—';
        }
        $name = $product->name;
        if ($order->productOffer) {
            $name .= ' - '.$order->productOffer->name;
        } elseif ($order->subscriptionPlan) {
            $name .= ' - '.$order->subscriptionPlan->name;
        }

        return $name;
    }
}
