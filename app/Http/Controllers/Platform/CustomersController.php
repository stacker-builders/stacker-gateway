<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Http\Controllers\Controller;
use App\Models\MemberStudentActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\ChargebackDefensePdfService;
use App\Services\MemberStudentActivityLogService;
use App\Services\Platform\PlatformTotpService;
use App\Services\PlatformAdminDeletionService;
use App\Services\PlatformAuditService;
use App\Services\Withdrawal\WithdrawalPolicyService;
use App\Support\Csv;
use App\Support\PlatformCustomerDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomersController extends Controller
{
    use RequiresPlatformStepUp;
    public function index(Request $request): Response
    {
        $search = PlatformCustomerDirectory::searchFromRequest($request);

        $users = PlatformCustomerDirectory::listingQuery($search)
            ->paginate(30)
            ->withQueryString()
            ->through(fn (User $user) => $this->mapCustomerListRow($user));

        return Inertia::render('Platform/Customers/Index', [
            'users' => $users,
            'q' => $search,
            'pageTitle' => 'Clientes',
            'export_url' => route('plataforma.clientes.export', array_filter(['q' => $search])),
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        if (! PlatformCustomerDirectory::isViewableCustomer($user)) {
            abort(404);
        }

        $filters = $this->historyFiltersFromRequest($request);
        $perPage = $filters['per_page'];

        $ordersQuery = Order::query()
            ->where('user_id', $user->id)
            ->with([
                'product:id,name',
                'tenantOwner:id,name,email',
                'orderItems.product:id,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyHistoryFilters($ordersQuery, $filters);

        $orders = $ordersQuery
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Order $order) => $this->mapOrderRow($order));

        $pendingOrders = Order::query()
            ->where('user_id', $user->id)
            ->where('status', PlatformCustomerDirectory::STATUS_PENDING)
            ->with(['product:id,name', 'tenantOwner:id,name,email'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (Order $order) => $this->mapOrderRow($order))
            ->values()
            ->all();

        $filterOptions = $this->historyFilterOptions($user);

        PlatformAuditService::log('platform.customer.viewed', [
            'user_id' => $user->id,
        ], $request);

        $phone = Schema::hasColumn('users', 'phone') ? PlatformCustomerDirectory::formatPhone($user->phone) : null;

        return Inertia::render('Platform/Customers/Show', [
            'pageTitle' => 'Cliente #'.$user->id,
            'customer' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $phone,
                'document' => PlatformCustomerDirectory::formatCpf($user->document),
                'birth_date' => $user->birth_date?->format('Y-m-d'),
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
                'account_status' => $user->account_status ?? 'approved',
                'account_status_label' => PlatformCustomerDirectory::accountStatusLabel($user->account_status),
                'email_verified' => $user->email_verified_at !== null,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'role' => $user->role,
                'is_infoprodutor' => $user->isInfoprodutor(),
            ],
            'address' => PlatformCustomerDirectory::addressPayload($user),
            'summary' => PlatformCustomerDirectory::purchaseSummary($user),
            'orders' => $orders,
            'pending_orders' => $pendingOrders,
            'access_products' => $this->accessProductsFor($user),
            'filters' => $filters,
            'filter_options' => $filterOptions,
            'status_labels' => collect(PlatformCustomerDirectory::ORDER_STATUSES)
                ->mapWithKeys(fn (string $s) => [$s => PlatformCustomerDirectory::orderStatusLabel($s)])
                ->all(),
            'security' => [
                'totp_enabled' => PlatformTotpService::isEnabledFor($request->user()),
                'has_operation_pin' => WithdrawalPolicyService::hasManualApprovalPin(),
                'can_authorize' => WithdrawalPolicyService::hasPayoutSecurityBarrier($request->user()),
            ],
            'issued_password' => $request->session()->get('customer_access_password'),
        ]);
    }

    public function updateEmail(Request $request, User $user): RedirectResponse
    {
        $this->assertViewableCustomer($user);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'totp_code' => ['nullable', 'string', 'max:12'],
            'manual_approval_pin' => ['nullable', 'string', 'max:12'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        if (mb_strtolower((string) $user->email) === $email) {
            throw ValidationException::withMessages([
                'email' => 'Informe um e-mail diferente do atual.',
            ]);
        }

        $this->validateRequiredSecurityStepUp(
            $request,
            'Cadastre o 2FA em Meu perfil ou o PIN de operação em Financeiro > Saques para alterar o acesso deste cliente.'
        );

        $taken = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('id', '!=', $user->id)
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages([
                'email' => 'Este e-mail já está em outra conta.',
            ]);
        }

        $previous = (string) $user->email;
        $user->email = $email;
        $user->email_verified_at = null;
        $user->save();

        PlatformAuditService::log('platform.customer.email_updated', [
            'user_id' => $user->id,
            'previous_email' => $previous,
            'email' => $email,
        ], $request);

        return redirect()
            ->route('plataforma.clientes.show', $user)
            ->with('success', 'E-mail de login atualizado.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->assertViewableCustomer($user);

        $request->validate([
            'totp_code' => ['nullable', 'string', 'max:12'],
            'manual_approval_pin' => ['nullable', 'string', 'max:12'],
        ]);

        $this->validateRequiredSecurityStepUp(
            $request,
            'Cadastre o 2FA em Meu perfil ou o PIN de operação em Financeiro > Saques para alterar o acesso deste cliente.'
        );

        $plain = $this->issueAccessPassword();
        $user->forceFill([
            'password' => $plain,
            'remember_token' => Str::random(60),
        ])->save();

        PlatformAuditService::log('platform.customer.password_reset', [
            'user_id' => $user->id,
        ], $request);

        return redirect()
            ->route('plataforma.clientes.show', $user)
            ->with('success', 'Nova senha gerada. Copie abaixo; ela não aparece de novo.')
            ->with('customer_access_password', $plain);
    }

    private function assertViewableCustomer(User $user): void
    {
        if (! PlatformCustomerDirectory::isViewableCustomer($user)) {
            abort(404);
        }
    }

    private function issueAccessPassword(): string
    {
        return Str::lower(Str::random(4)).'-'.random_int(1000, 9999).'-'.Str::lower(Str::random(4));
    }

    public function export(Request $request): StreamedResponse
    {
        $search = PlatformCustomerDirectory::searchFromRequest($request);
        $baseQuery = PlatformCustomerDirectory::listingQuery($search);
        $count = (clone $baseQuery)->count();
        $filename = 'clientes-'.now()->format('Y-m-d-H-i').'.csv';

        PlatformAuditService::log('platform.customer.exported', [
            'filters' => ['q' => $search],
            'records' => $count,
            'filename' => $filename,
        ], $request);

        $query = $baseQuery->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            Csv::writeBom($out);
            Csv::writeRow($out, PlatformCustomerDirectory::exportHeaders());

            $query->chunkById(200, function ($users) use ($out) {
                foreach ($users as $user) {
                    /** @var User $user */
                    Csv::writeRow($out, PlatformCustomerDirectory::exportRow($user));
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $redirectParams = $request->query('q') ? ['q' => $request->query('q')] : [];
        $userId = $user->id;

        try {
            PlatformAdminDeletionService::deleteCustomer($user);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.clientes.index', $redirectParams)->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.clientes.index', $redirectParams)
                ->with('error', 'Não foi possível excluir o cliente.');
        }

        PlatformAuditService::log('platform.customer.deleted', ['user_id' => $userId], $request);

        return redirect()->route('plataforma.clientes.index', $redirectParams)
            ->with('success', 'Cliente excluído. Os pedidos antigos permanecem no sistema sem vínculo à conta.');
    }

    public function destroyOrderHistory(Request $request, User $user): RedirectResponse
    {
        $redirectParams = $request->query('q') ? ['q' => $request->query('q')] : [];
        $userId = $user->id;

        try {
            $count = PlatformAdminDeletionService::deleteCustomerOrderHistory($user);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.clientes.index', $redirectParams)->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.clientes.index', $redirectParams)
                ->with('error', 'Não foi possível excluir o histórico.');
        }

        PlatformAuditService::log('platform.customer.order_history_deleted', [
            'user_id' => $userId,
            'orders_deleted' => $count,
        ], $request);

        return redirect()->route('plataforma.clientes.index', $redirectParams)
            ->with('success', $count > 0
                ? "Histórico removido: {$count} pedido(s) excluído(s)."
                : 'Este cliente não tinha pedidos para excluir.');
    }

    public function dossier(Request $request, User $user, Product $produto, MemberStudentActivityLogService $activityLog): JsonResponse
    {
        $this->assertCanViewCustomerAccessDossier($user, $produto);

        PlatformAuditService::log('platform.customer.access_dossier.viewed', [
            'user_id' => $user->id,
            'product_id' => (string) $produto->id,
        ], $request);

        return response()->json($activityLog->dossierFor($user, $produto));
    }

    public function exportDossier(Request $request, User $user, Product $produto, MemberStudentActivityLogService $activityLog): StreamedResponse
    {
        $this->assertCanViewCustomerAccessDossier($user, $produto);

        PlatformAuditService::log('platform.customer.access_dossier.exported', [
            'user_id' => $user->id,
            'product_id' => (string) $produto->id,
            'format' => 'csv',
        ], $request);

        $filename = sprintf(
            'dossie-%s-%s-%s.csv',
            Str::slug((string) $user->name) ?: 'cliente',
            Str::slug((string) $produto->name) ?: 'produto',
            now()->format('Y-m-d')
        );

        return response()->streamDownload(function () use ($activityLog, $user, $produto) {
            $out = fopen('php://output', 'w');
            $activityLog->writeDossierCsv($out, $user, $produto);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportDossierPdf(Request $request, User $user, Product $produto, MemberStudentActivityLogService $activityLog): \Illuminate\Http\Response
    {
        $this->assertCanViewCustomerAccessDossier($user, $produto);

        PlatformAuditService::log('platform.customer.access_dossier.exported', [
            'user_id' => $user->id,
            'product_id' => (string) $produto->id,
            'format' => 'pdf',
        ], $request);

        $filename = sprintf(
            'dossie-%s-%s-%s.pdf',
            Str::slug((string) $user->name) ?: 'cliente',
            Str::slug((string) $produto->name) ?: 'produto',
            now()->format('Y-m-d')
        );

        $binary = $activityLog->renderDossierPdf($user, $produto);

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function exportChargebackDefense(
        Request $request,
        User $user,
        Order $order,
        ChargebackDefensePdfService $defense,
    ): \Illuminate\Http\Response {
        $this->assertCanExportChargebackDefense($user, $order);

        PlatformAuditService::log('platform.customer.chargeback_defense.exported', [
            'user_id' => $user->id,
            'order_id' => $order->id,
        ], $request);

        $filename = $defense->filename($order);
        $binary = $defense->renderPdf($order);

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function assertCanExportChargebackDefense(User $user, Order $order): void
    {
        if (! PlatformCustomerDirectory::isViewableCustomer($user)) {
            abort(404);
        }

        if ((int) $order->user_id !== (int) $user->id) {
            abort(404);
        }
    }

    private function assertCanViewCustomerAccessDossier(User $user, Product $produto): void
    {
        if (! PlatformCustomerDirectory::isViewableCustomer($user)) {
            abort(404);
        }

        if (! $this->customerHasProductRelationship($user, $produto)) {
            abort(404);
        }
    }

    private function customerHasProductRelationship(User $user, Product $produto): bool
    {
        $productId = (string) $produto->id;

        if (Schema::hasTable('product_user')) {
            $enrolled = DB::table('product_user')
                ->where('user_id', $user->id)
                ->where(function ($q) use ($produto, $productId) {
                    $q->where('product_id', $produto->id)
                        ->orWhere('product_id', $productId);
                })
                ->exists();
            if ($enrolled) {
                return true;
            }
        }

        $hasOrder = Order::query()
            ->where('user_id', $user->id)
            ->where(function ($q) use ($produto, $productId) {
                $q->where('product_id', $produto->id)
                    ->orWhere('product_id', $productId);
                if (Schema::hasTable('order_items')) {
                    $q->orWhereHas('orderItems', function ($items) use ($produto, $productId) {
                        $items->where('product_id', $produto->id)
                            ->orWhere('product_id', $productId);
                    });
                }
            })
            ->exists();
        if ($hasOrder) {
            return true;
        }

        if (! Schema::hasTable('member_student_activity_logs')) {
            return false;
        }

        return MemberStudentActivityLog::query()
            ->where('user_id', $user->id)
            ->where('product_id', $productId)
            ->exists();
    }

    /**
     * @return list<array{id: string, name: string, type: string|null, enrolled_at: string|null, seller: array{id: int, name: string}|null}>
     */
    private function accessProductsFor(User $user): array
    {
        if (! Schema::hasTable('product_user')) {
            return [];
        }

        $enrollments = DB::table('product_user')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        if ($enrollments->isEmpty()) {
            return [];
        }

        $productIds = $enrollments->pluck('product_id')->unique()->filter()->values();
        $products = Product::query()
            ->withTrashed()
            ->with(['tenantOwner:id,name'])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy(fn (Product $product) => (string) $product->id);

        return $enrollments->map(function ($row) use ($products) {
            $productId = (string) $row->product_id;
            $product = $products->get($productId);
            $seller = $product?->tenantOwner;

            return [
                'id' => $productId,
                'name' => $product?->name ?: 'Produto removido',
                'type' => $product?->type,
                'enrolled_at' => $row->created_at
                    ? \Carbon\Carbon::parse($row->created_at)->toIso8601String()
                    : null,
                'seller' => $seller
                    ? ['id' => $seller->id, 'name' => $seller->name]
                    : null,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCustomerListRow(User $user): array
    {
        $phone = Schema::hasColumn('users', 'phone')
            ? PlatformCustomerDirectory::formatPhone($user->phone)
            : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $phone,
            'document' => PlatformCustomerDirectory::formatCpf($user->document),
            'created_at' => $user->created_at?->toIso8601String(),
            'purchases_count' => (int) ($user->purchases_count ?? 0),
            'total_spent' => round((float) ($user->total_spent ?? 0), 2),
            'account_status' => $user->account_status ?? 'approved',
            'account_status_label' => PlatformCustomerDirectory::accountStatusLabel($user->account_status),
        ];
    }

    /**
     * @return array{
     *     status: ?string,
     *     product_id: ?string,
     *     seller_id: ?int,
     *     payment_method: ?string,
     *     date_from: ?string,
     *     date_to: ?string,
     *     per_page: int,
     *     pending_only: bool
     * }
     */
    private function historyFiltersFromRequest(Request $request): array
    {
        $status = trim((string) $request->query('status', ''));
        if ($status !== '' && ! in_array($status, PlatformCustomerDirectory::ORDER_STATUSES, true)) {
            $status = '';
        }

        $productId = trim((string) $request->query('product_id', ''));
        $sellerId = $request->query('seller_id');
        $sellerId = is_numeric($sellerId) ? (int) $sellerId : null;

        $paymentMethod = trim((string) $request->query('payment_method', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }

        $pendingOnly = $request->boolean('pending_only');

        return [
            'status' => $status !== '' ? $status : null,
            'product_id' => $productId !== '' ? $productId : null,
            'seller_id' => $sellerId,
            'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
            'date_from' => $dateFrom !== '' ? $dateFrom : null,
            'date_to' => $dateTo !== '' ? $dateTo : null,
            'per_page' => $perPage,
            'pending_only' => $pendingOnly,
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Order>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyHistoryFilters($query, array $filters): void
    {
        if (! empty($filters['pending_only'])) {
            $query->where('status', PlatformCustomerDirectory::STATUS_PENDING);
        } elseif (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['product_id'])) {
            $productId = $filters['product_id'];
            $query->where(function ($q) use ($productId) {
                $q->where('product_id', $productId)
                    ->orWhereHas('orderItems', fn ($items) => $items->where('product_id', $productId));
            });
        }

        if (! empty($filters['seller_id'])) {
            $query->where('tenant_id', $filters['seller_id']);
        }

        if (! empty($filters['payment_method'])) {
            $method = strtolower((string) $filters['payment_method']);
            $query->where(function ($q) use ($method) {
                $q->where('payment_method', $method)
                    ->orWhere('metadata->checkout_payment_method', $method);
            });
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
    }

    /**
     * @return array{products: list<array{id: string, name: string}>, sellers: list<array{id: int, name: string}>, payment_methods: list<string>}
     */
    private function historyFilterOptions(User $user): array
    {
        $productIds = Order::query()
            ->where('user_id', $user->id)
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id');

        $itemProductIds = [];
        if (Schema::hasTable('order_items')) {
            $itemProductIds = \App\Models\OrderItem::query()
                ->whereIn('order_id', Order::query()->where('user_id', $user->id)->select('id'))
                ->whereNotNull('product_id')
                ->distinct()
                ->pluck('product_id');
        }

        $allProductIds = $productIds->merge($itemProductIds)->unique()->filter()->values();

        $products = Product::query()
            ->whereIn('id', $allProductIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Product $p) => ['id' => (string) $p->id, 'name' => $p->name])
            ->values()
            ->all();

        $sellerIds = Order::query()
            ->where('user_id', $user->id)
            ->whereNotNull('tenant_id')
            ->distinct()
            ->pluck('tenant_id');

        $sellers = User::query()
            ->whereIn('id', $sellerIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $s) => ['id' => $s->id, 'name' => $s->name])
            ->values()
            ->all();

        $paymentMethods = Order::query()
            ->where('user_id', $user->id)
            ->whereNotNull('payment_method')
            ->distinct()
            ->pluck('payment_method')
            ->filter()
            ->map(fn ($m) => strtolower((string) $m))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'products' => $products,
            'sellers' => $sellers,
            'payment_methods' => $paymentMethods,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOrderRow(Order $order): array
    {
        $items = [];
        if ($order->relationLoaded('orderItems')) {
            foreach ($order->orderItems as $item) {
                $items[] = [
                    'product_id' => $item->product_id,
                    'name' => $item->product?->name ?? PlatformCustomerDirectory::NOT_INFORMED,
                    'amount' => round((float) ($item->amount ?? 0), 2),
                    'position' => (int) ($item->position ?? 0),
                ];
            }
        }

        $productName = $order->product?->name;
        if ($productName === null && $items !== []) {
            $productName = $items[0]['name'];
        }

        $productId = $order->product_id !== null && $order->product_id !== ''
            ? (string) $order->product_id
            : (isset($items[0]['product_id']) && $items[0]['product_id'] !== null && $items[0]['product_id'] !== ''
                ? (string) $items[0]['product_id']
                : null);

        $discount = PlatformCustomerDirectory::discountAmountFromOrder($order);
        $paid = round((float) $order->amount, 2);

        return [
            'id' => $order->id,
            'created_at' => $order->created_at?->toIso8601String(),
            'product_id' => $productId,
            'product_name' => $productName,
            'items' => $items,
            'has_multiple_items' => count($items) > 1,
            'seller' => $order->tenantOwner
                ? ['id' => $order->tenantOwner->id, 'name' => $order->tenantOwner->name]
                : null,
            'payment_method' => $order->payment_method,
            'payment_method_label' => $order->paymentMethodDisplayLabel(),
            'amount' => $paid,
            'discount_amount' => $discount,
            'coupon_code' => $order->coupon_code,
            'status' => $order->status,
            'status_label' => PlatformCustomerDirectory::orderStatusLabel($order->status),
            'charge_url' => $order->status === PlatformCustomerDirectory::STATUS_PENDING
                ? PlatformCustomerDirectory::safeChargeUrlFromOrder($order)
                : null,
            'transactions_url' => route('plataforma.transacoes.index', ['q' => (string) $order->id]),
            'has_access_dossier' => $productId !== null,
            'contestacao_url' => $order->user_id
                ? route('plataforma.clientes.contestacao', ['user' => $order->user_id, 'order' => $order->id])
                : null,
        ];
    }
}
