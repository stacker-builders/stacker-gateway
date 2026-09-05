<?php

namespace Database\Seeders;

use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Models\SellerActivityLog;
use App\Models\TeamRole;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\PlatformOrderAdminService;
use App\Services\SellerActivityLogService;
use App\Support\OrderManualRefund;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Dados locais para testar /plataforma/transacoes (reembolso, reembolso manual, MED, saldos).
 *
 * Uso: php artisan db:seed --class=LocalPlatformTransactionsSeeder
 *
 * Senha dos sellers/clientes: password
 * Admin plataforma: admin@admin.com / 12345678
 */
class LocalPlatformTransactionsSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const GATEWAY = 'local_demo';

    private const FEE_RATE = 0.05;

    public function run(): void
    {
        $this->ensurePlatformAdmin();

        $alpha = $this->upsertSeller(
            'seller.alpha@local.test',
            'Marina Costa',
            '11987654321',
            '39053344705'
        );
        $beta = $this->upsertSeller(
            'seller.beta@local.test',
            'Rafael Souza',
            '21988776655',
            '11144477735'
        );
        $gamma = $this->upsertSeller(
            'seller.gamma@local.test',
            'Camila Ferreira',
            '31991112233',
            '22233344456'
        );

        $this->purgePreviousDemo($alpha, $beta, $gamma);

        $mentoria = $this->upsertProduct($alpha, [
            'slug' => 'local-mentoria',
            'checkout_slug' => 'locment01',
            'name' => 'Mentoria Completa — Área de Membros',
            'price' => 197.00,
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);
        $this->seedCurriculum($mentoria);

        $ebook = $this->upsertProduct($alpha, [
            'slug' => 'local-ebook',
            'checkout_slug' => 'locebook1',
            'name' => 'E-book Funil de Vendas',
            'price' => 47.90,
            'type' => Product::TYPE_LINK,
        ]);

        $copy = $this->upsertProduct($beta, [
            'slug' => 'local-copy',
            'checkout_slug' => 'loccopy01',
            'name' => 'Mentoria de Copywriting',
            'price' => 497.00,
            'type' => Product::TYPE_LINK,
        ]);

        $templates = $this->upsertProduct($gamma, [
            'slug' => 'local-templates',
            'checkout_slug' => 'loctempl1',
            'name' => 'Pack de Templates Canva',
            'price' => 67.00,
            'type' => Product::TYPE_LINK,
        ]);

        $ana = $this->upsertCustomer('ana.tx@local.test', 'Ana Clara Souza', '11999887766', '52998224725');
        $bruno = $this->upsertCustomer('bruno.tx@local.test', 'Bruno Lima', '21991234567', '15350946056');
        $carla = $this->upsertCustomer('carla.tx@local.test', 'Carla Mendes', '31995554433', '10000000019');
        $diego = $this->upsertCustomer('diego.tx@local.test', 'Diego Ferreira', '41997776655', '10000000108');
        $elena = $this->upsertCustomer('elena.tx@local.test', 'Elena Rocha', '51996665544', '10000000280');
        $fabio = $this->upsertCustomer('fabio.tx@local.test', 'Fábio Nunes', '61994443322', '10000000361');
        $gabriela = $this->upsertCustomer('gabriela.tx@local.test', 'Gabriela Alves', '71993332211', '10000000442');
        $helena = $this->upsertCustomer('helena.tx@local.test', 'Helena Dias', '81992221100', '10000000523');
        $igor = $this->upsertCustomer('igor.tx@local.test', 'Igor Martins', '85991110099', '10000000604');

        $admin = User::query()->where('email', 'admin@admin.com')->first();

        // Pedidos pagos com saldo + acesso — prontos para Reembolsar / Reembolso manual
        $paidMentoria = $this->createPaidOrder($ana, $alpha, $mentoria, [
            'amount' => 197.00,
            'payment_method' => 'pix',
            'days_ago' => 6,
            'grant_access' => true,
        ]);
        $this->createPaidOrder($bruno, $alpha, $ebook, [
            'amount' => 47.90,
            'payment_method' => 'pix',
            'days_ago' => 4,
        ]);
        $this->createPaidOrder($igor, $beta, $copy, [
            'amount' => 497.00,
            'payment_method' => 'card',
            'days_ago' => 3,
        ]);
        $this->createPaidOrder($ana, $gamma, $templates, [
            'amount' => 67.00,
            'payment_method' => 'pix',
            'days_ago' => 5,
        ]);
        $this->createPaidOrder($bruno, $gamma, $templates, [
            'amount' => 67.00,
            'payment_method' => 'pix',
            'days_ago' => 2,
        ]);

        $medOrder = $this->createPaidOrder($carla, $alpha, $mentoria, [
            'amount' => 197.00,
            'payment_method' => 'card',
            'days_ago' => 8,
            'grant_access' => true,
        ]);
        PlatformOrderAdminService::markDisputed($medOrder->fresh());

        $this->createOrder($diego, $alpha, $mentoria, [
            'status' => 'pending',
            'amount' => 197.00,
            'payment_method' => 'pix',
            'days_ago' => 1,
        ]);
        $this->createOrder($elena, $alpha, $ebook, [
            'status' => 'cancelled',
            'amount' => 47.90,
            'payment_method' => 'boleto',
            'days_ago' => 12,
        ]);

        $gatewayRefunded = $this->createPaidOrder($fabio, $beta, $copy, [
            'amount' => 497.00,
            'payment_method' => 'pix',
            'days_ago' => 14,
            'grant_access' => true,
        ]);
        PlatformOrderAdminService::refundPaidOrDisputed(
            $gatewayRefunded->fresh(),
            OrderManualRefund::buildMeta(
                $admin ?? $alpha,
                'platform',
                'Estorno de demonstração via gateway.',
                ['status' => 'skipped', 'note' => 'Seed local']
            ),
            'platform_manual_refund'
        );

        $offlineRefunded = $this->createPaidOrder($gabriela, $alpha, $mentoria, [
            'amount' => 197.00,
            'payment_method' => 'pix',
            'days_ago' => 11,
            'grant_access' => true,
        ]);
        PlatformOrderAdminService::refundPaidOrDisputed(
            $offlineRefunded->fresh(),
            OrderManualRefund::buildMeta(
                $admin ?? $alpha,
                'platform',
                'Reembolso feito no painel do adquirente após falha no sistema.',
                ['status' => 'offline', 'note' => 'Seed local', 'offline' => true]
            ),
            'platform_offline_refund'
        );

        $pendingRefundOrder = $this->createPaidOrder($helena, $beta, $copy, [
            'amount' => 497.00,
            'payment_method' => 'pix',
            'days_ago' => 2,
            'grant_access' => true,
        ]);
        if (Schema::hasTable('refund_requests')) {
            RefundRequest::query()->create([
                'order_id' => $pendingRefundOrder->id,
                'user_id' => $helena->id,
                'tenant_id' => $beta->id,
                'status' => RefundRequest::STATUS_PENDING,
                'customer_reason' => 'Não consegui acessar o conteúdo após a compra.',
            ]);
        }

        $alphaWallet = TenantWallet::query()->where('tenant_id', $alpha->id)->first();
        $betaWallet = TenantWallet::query()->where('tenant_id', $beta->id)->first();
        $gammaWallet = TenantWallet::query()->where('tenant_id', $gamma->id)->first();

        $equipeAlpha = $this->seedTeamAndWithdrawals($alpha, $beta, $gamma);
        $this->seedActivityLogs($alpha, $beta, $gamma, $equipeAlpha, $paidMentoria, $pendingRefundOrder);

        $this->command?->info('Seed local de transações OK.');
        $this->command?->table(
            ['Item', 'Valor'],
            [
                ['Admin plataforma', 'admin@admin.com / 12345678'],
                ['Seller Alpha (mentoria + e-book)', 'seller.alpha@local.test / password'],
                ['Seller Beta (copy)', 'seller.beta@local.test / password'],
                ['Seller Gamma (templates)', 'seller.gamma@local.test / password'],
                ['Equipe Alpha', 'equipe.alpha@local.test / password'],
                ['Saldo Alpha disponível PIX', 'R$ '.number_format((float) ($alphaWallet?->available_pix ?? 0), 2, ',', '.')],
                ['Saldo Alpha pendente (MED)', 'R$ '.number_format((float) ($alphaWallet?->pending_card ?? 0) + (float) ($alphaWallet?->pending_pix ?? 0), 2, ',', '.')],
                ['Saldo Beta disponível', 'R$ '.number_format((float) ($betaWallet?->available_pix ?? 0) + (float) ($betaWallet?->available_card ?? 0), 2, ',', '.')],
                ['Saldo Gamma disponível PIX', 'R$ '.number_format((float) ($gammaWallet?->available_pix ?? 0), 2, ',', '.')],
                ['Pedido pago c/ acesso (Reembolso manual)', '#'.$paidMentoria->id.' — '.$ana->email],
                ['Pedido em MED', '#'.$medOrder->fresh()->id.' — '.$carla->email],
                ['Pedido reembolso pendente (filtro Reembolsos)', '#'.$pendingRefundOrder->id.' — '.$helena->email],
                ['Status Reembolso manual (já feito)', '#'.$offlineRefunded->fresh()->id.' — '.$gabriela->email],
                ['Log Infoprodutor', url('/plataforma/log-infoprodutor')],
                ['Log sistema', url('/plataforma/log-sistema')],
                ['Transações', url('/plataforma/transacoes')],
            ]
        );
    }

    private function ensurePlatformAdmin(): void
    {
        $email = 'admin@admin.com';
        $existing = User::query()->where('email', $email)->first();
        $payload = [
            'name' => 'Admin Plataforma',
            'email' => $email,
            'password' => '12345678',
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
            'account_status' => 'approved',
            'email_verified_at' => now(),
        ];
        if ($existing === null) {
            User::query()->create($payload);

            return;
        }

        $existing->forceFill($payload)->save();
    }

    private function upsertSeller(string $email, string $name, string $phone, string $document): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => self::PASSWORD,
                'role' => User::ROLE_INFOPRODUTOR,
                'account_status' => 'approved',
                'kyc_status' => User::KYC_APPROVED,
                'person_type' => 'pf',
                'phone' => $phone,
                'document' => $document,
                'email_verified_at' => now(),
                'seller_onboarded_at' => now()->subMonths(2),
                'privacy_policy_accepted_at' => now()->subMonths(2),
                'terms_accepted_at' => now()->subMonths(2),
                'payout_settings' => [
                    'payout_pix_key' => $email,
                    'payout_pix_key_type' => 'email',
                    'payout_pix_label' => 'PIX '.$name,
                ],
            ]
        );
        $user->forceFill(['tenant_id' => $user->id])->save();

        if (Schema::hasTable('tenant_wallets')) {
            TenantWallet::query()->firstOrCreate(
                ['tenant_id' => $user->id],
                [
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'available_pix' => 0,
                    'available_card' => 0,
                    'available_boleto' => 0,
                    'pending_pix' => 0,
                    'pending_card' => 0,
                    'pending_boleto' => 0,
                    'currency' => 'BRL',
                ]
            );
        }

        return $user->fresh();
    }

    private function upsertCustomer(string $email, string $name, string $phone, string $document): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => self::PASSWORD,
                'role' => User::ROLE_CLIENTE,
                'tenant_id' => null,
                'account_status' => 'approved',
                'person_type' => 'pf',
                'phone' => $phone,
                'document' => $document,
                'email_verified_at' => now(),
            ]
        )->fresh();
    }

    /**
     * @param  array{slug: string, checkout_slug: string, name: string, price: float, type: string}  $data
     */
    private function upsertProduct(User $seller, array $data): Product
    {
        $existing = Product::query()
            ->where('tenant_id', $seller->id)
            ->where('slug', $data['slug'])
            ->first();

        $payload = [
            'tenant_id' => $seller->id,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'checkout_slug' => $data['checkout_slug'],
            'type' => $data['type'],
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => $data['price'],
            'currency' => 'BRL',
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
            'approval_source' => Product::APPROVAL_SOURCE_MANUAL,
            'description' => 'Produto fictício para testes locais.',
        ];
        if ($data['type'] === Product::TYPE_AREA_MEMBROS) {
            $payload['member_area_config'] = Product::defaultMemberAreaConfig();
        }
        if (Schema::hasColumn('products', 'admin_blocked')) {
            $payload['admin_blocked'] = false;
        }

        if ($existing) {
            $existing->forceFill($payload)->save();

            return $existing->fresh();
        }

        $product = new Product;
        $product->forceFill($payload);
        $product->save();

        return $product->fresh();
    }

    private function seedCurriculum(Product $product): void
    {
        if (! Schema::hasTable('member_sections') || ! Schema::hasTable('member_modules') || ! Schema::hasTable('member_lessons')) {
            return;
        }

        MemberLesson::query()->where('product_id', $product->id)->delete();
        MemberModule::query()->where('product_id', $product->id)->delete();
        MemberSection::query()->where('product_id', $product->id)->delete();

        $section = MemberSection::query()->create([
            'product_id' => $product->id,
            'title' => 'Módulo inicial',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);
        $module = MemberModule::query()->create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Boas-vindas',
            'position' => 1,
            'show_title_on_cover' => true,
        ]);
        MemberLesson::query()->create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 1 — Comece por aqui',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>Conteúdo fictício da mentoria. O reembolso deve remover este acesso.</p>',
            'is_free' => false,
        ]);
    }

    private function purgePreviousDemo(User $alpha, User $beta, User $gamma): void
    {
        $tenantIds = [$alpha->id, $beta->id, $gamma->id];

        $this->purgeDemoExtras($tenantIds);

        $orderIds = Order::query()
            ->where('gateway', self::GATEWAY)
            ->whereIn('tenant_id', $tenantIds)
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            $this->resetWallets($alpha, $beta, $gamma);

            return;
        }

        if (Schema::hasTable('refund_requests')) {
            RefundRequest::query()->whereIn('order_id', $orderIds)->delete();
        }
        if (Schema::hasTable('wallet_transactions')) {
            WalletTransaction::query()->whereIn('order_id', $orderIds)->delete();
        }
        if (Schema::hasTable('order_items')) {
            OrderItem::query()->whereIn('order_id', $orderIds)->delete();
        }
        if (Schema::hasTable('product_user')) {
            $productIds = Product::query()->whereIn('tenant_id', $tenantIds)->pluck('id');
            if ($productIds->isNotEmpty()) {
                foreach ($productIds as $productId) {
                    $product = Product::query()->find($productId);
                    $product?->users()->detach();
                }
            }
        }

        Order::query()->whereIn('id', $orderIds)->delete();
        $this->resetWallets($alpha, $beta, $gamma);
    }

    /**
     * @param  list<int>  $tenantIds
     */
    private function purgeDemoExtras(array $tenantIds): void
    {
        if (Schema::hasTable('seller_activity_logs')) {
            SellerActivityLog::query()->whereIn('tenant_id', $tenantIds)->delete();
        }
        if (Schema::hasTable('withdrawals')) {
            Withdrawal::query()->whereIn('tenant_id', $tenantIds)->where('notes', 'like', 'Seed local%')->delete();
        }
        if (Schema::hasTable('wallet_transactions')) {
            WalletTransaction::query()
                ->whereIn('tenant_id', $tenantIds)
                ->where('type', WalletTransaction::TYPE_WITHDRAWAL_REQUEST)
                ->whereNull('order_id')
                ->delete();
        }

        User::query()
            ->where('role', User::ROLE_TEAM)
            ->whereIn('email', ['equipe.alpha@local.test', 'equipe.beta@local.test'])
            ->delete();

        if (Schema::hasTable('team_roles')) {
            TeamRole::query()
                ->whereIn('tenant_id', $tenantIds)
                ->whereIn('name', ['Suporte', 'Financeiro'])
                ->delete();
        }
    }

    private function resetWallets(User $alpha, User $beta, User $gamma): void
    {
        if (! Schema::hasTable('tenant_wallets')) {
            return;
        }

        foreach ([$alpha->id, $beta->id, $gamma->id] as $tenantId) {
            TenantWallet::query()->where('tenant_id', $tenantId)->update([
                'available_balance' => 0,
                'pending_balance' => 0,
                'available_pix' => 0,
                'available_card' => 0,
                'available_boleto' => 0,
                'pending_pix' => 0,
                'pending_card' => 0,
                'pending_boleto' => 0,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function createPaidOrder(User $customer, User $seller, Product $product, array $opts): Order
    {
        $order = $this->createOrder($customer, $seller, $product, array_merge($opts, [
            'status' => 'completed',
        ]));
        $this->creditWallet($order, $seller, $opts['payment_method'] ?? 'pix', (float) $opts['amount']);
        if (! empty($opts['grant_access'])) {
            $order->grantPurchasedProductAccessToBuyer();
        }

        return $order->fresh();
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function createOrder(User $customer, User $seller, Product $product, array $opts): Order
    {
        $createdAt = Carbon::now()->subDays((int) ($opts['days_ago'] ?? 0))->subMinutes(random_int(5, 180));
        $amount = (float) $opts['amount'];

        $order = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => $opts['status'],
            'amount' => $amount,
            'email' => $customer->email,
            'cpf' => $customer->document,
            'phone' => $customer->phone,
            'gateway' => self::GATEWAY,
            'gateway_id' => 'local_'.Str::lower(Str::random(12)),
            'payment_method' => $opts['payment_method'] ?? 'pix',
            'approved_manually' => false,
            'metadata' => [
                'seed' => 'local_platform_tx',
                'checkout_payment_method' => $opts['payment_method'] ?? 'pix',
            ],
        ]);
        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        if (Schema::hasTable('order_items')) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'amount' => $amount,
                'position' => 0,
            ]);
        }

        return $order->fresh();
    }

    private function creditWallet(Order $order, User $seller, string $paymentMethod, float $gross): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            return;
        }

        $bucket = match ($paymentMethod) {
            'card' => 'card',
            'boleto' => 'boleto',
            default => 'pix',
        };
        $fee = round($gross * self::FEE_RATE, 2);
        $net = round($gross - $fee, 2);
        $availCol = 'available_'.$bucket;

        $wallet = TenantWallet::query()->firstOrCreate(
            ['tenant_id' => $seller->id],
            [
                'currency' => 'BRL',
                'available_balance' => 0,
                'pending_balance' => 0,
                'available_pix' => 0,
                'available_card' => 0,
                'available_boleto' => 0,
                'pending_pix' => 0,
                'pending_card' => 0,
                'pending_boleto' => 0,
            ]
        );
        $wallet->{$availCol} = round((float) $wallet->{$availCol} + $net, 2);
        $wallet->available_balance = round(
            (float) $wallet->available_pix + (float) $wallet->available_card + (float) $wallet->available_boleto,
            2
        );
        $wallet->save();

        WalletTransaction::query()->create([
            'tenant_id' => $seller->id,
            'order_id' => $order->id,
            'bucket' => $bucket,
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => $gross,
            'amount_fee' => $fee,
            'amount_net' => $net,
            'meta' => ['seed' => 'local_platform_tx'],
        ]);
    }

    /**
     * @return User Membro da equipe da Marina (Alpha)
     */
    private function seedTeamAndWithdrawals(User $alpha, User $beta, User $gamma): User
    {
        $role = TeamRole::query()->create([
            'tenant_id' => $alpha->id,
            'name' => 'Suporte',
            'permissions' => [
                'dashboard.view' => true,
                'vendas.view' => true,
                'equipe.manage' => false,
            ],
        ]);

        $member = User::query()->updateOrCreate(
            ['email' => 'equipe.alpha@local.test'],
            [
                'name' => 'Juliana Equipe',
                'password' => self::PASSWORD,
                'role' => User::ROLE_TEAM,
                'tenant_id' => $alpha->id,
                'team_role_id' => $role->id,
                'account_status' => 'approved',
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'equipe.beta@local.test'],
            [
                'name' => 'Pedro Equipe',
                'password' => self::PASSWORD,
                'role' => User::ROLE_TEAM,
                'tenant_id' => $beta->id,
                'account_status' => 'approved',
                'email_verified_at' => now(),
            ]
        );

        $this->createDemoWithdrawal($alpha, 120.00, 'pending', 'Seed local — saque PIX pendente');
        $this->createDemoWithdrawal($beta, 250.00, 'paid', 'Seed local — saque PIX já pago');
        $this->createDemoWithdrawal($gamma, 50.00, 'pending', 'Seed local — saque templates');

        return $member->fresh();
    }

    private function createDemoWithdrawal(User $seller, float $amount, string $status, string $notes): void
    {
        if (! Schema::hasTable('withdrawals')) {
            return;
        }

        $fee = round($amount * self::FEE_RATE, 2);
        $net = round($amount - $fee, 2);
        $createdAt = Carbon::now()->subDays(random_int(1, 8))->subHours(random_int(1, 12));

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => $amount,
            'fee_amount' => $fee,
            'net_amount' => $net,
            'bucket' => 'pix',
            'status' => $status,
            'notes' => $notes,
            'currency' => 'BRL',
        ]);
        $withdrawal->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $wallet = TenantWallet::query()->where('tenant_id', $seller->id)->first();
        if ($wallet && $status === 'pending') {
            $wallet->available_pix = round(max(0, (float) $wallet->available_pix - $amount), 2);
            $wallet->available_balance = round(
                (float) $wallet->available_pix + (float) $wallet->available_card + (float) $wallet->available_boleto,
                2
            );
            $wallet->save();
        }

        if (Schema::hasTable('wallet_transactions')) {
            $payload = [
                'tenant_id' => $seller->id,
                'order_id' => null,
                'bucket' => 'pix',
                'type' => WalletTransaction::TYPE_WITHDRAWAL_REQUEST,
                'amount_gross' => $amount,
                'amount_fee' => $fee,
                'amount_net' => $net,
                'meta' => ['seed' => 'local_platform_tx', 'status' => $status],
            ];
            if (Schema::hasColumn('wallet_transactions', 'withdrawal_id')) {
                $payload['withdrawal_id'] = $withdrawal->id;
            }
            WalletTransaction::query()->create($payload);
        }
    }

    private function seedActivityLogs(
        User $alpha,
        User $beta,
        User $gamma,
        User $equipeAlpha,
        Order $paidMentoria,
        Order $pendingRefundOrder,
    ): void {
        if (! Schema::hasTable('seller_activity_logs')) {
            return;
        }

        $this->writeLog($alpha, SellerActivityLogService::PAYOUT_SETTINGS_UPDATED, now()->subDays(18), [
            'pix_key_type' => 'email',
            'pix_key_masked' => '****local.test',
            'label' => 'PIX Marina Costa',
        ]);
        $this->writeLog($alpha, SellerActivityLogService::PAYOUT_SETTINGS_UPDATED, now()->subDays(9), [
            'pix_key_type' => 'cpf',
            'pix_key_masked' => '*******4705',
            'label' => 'PIX CPF Marina',
        ]);
        $this->writeLog($alpha, SellerActivityLogService::WITHDRAWAL_REQUESTED, now()->subDays(6), [
            'amount' => 120,
            'fee_amount' => 6,
            'net_amount' => 114,
            'bucket' => 'pix',
        ]);
        $this->writeLog($alpha, SellerActivityLogService::TEAM_ROLE_CREATED, now()->subDays(14), [
            'name' => 'Suporte',
        ]);
        $this->writeLog($alpha, SellerActivityLogService::TEAM_MEMBER_CREATED, now()->subDays(13), [
            'email' => 'equipe.alpha@local.test',
            'team_role_id' => $equipeAlpha->team_role_id,
        ]);
        $this->writeLog($equipeAlpha, SellerActivityLogService::REFUND_COMPLETED, now()->subDays(5), [
            'order_id' => $paidMentoria->id,
            'amount' => 197,
            'reason' => 'Cliente pediu cancelamento no WhatsApp',
            'gateway_status' => 'skipped',
        ], 'panel', $paidMentoria->id, Order::class);
        $this->writeLog($alpha, SellerActivityLogService::API_KEY_CREATED, now()->subDays(11), [
            'name' => 'Checkout próprio',
            'public_key_masked' => 'pk_live_****dem1',
            'scopes' => ['pix.create', 'pix.read'],
        ]);
        $this->writeLog($alpha, SellerActivityLogService::API_KEY_ROTATED, now()->subDays(3), [
            'name' => 'Checkout próprio',
            'public_key_masked' => 'pk_live_****rot2',
        ]);
        $this->writeLog($alpha, SellerActivityLogService::API_WEBHOOK_SECRET_ROTATED, now()->subDays(2), [
            'webhook_url' => 'https://hooks.local.test/alpha',
        ]);

        $this->writeLog($beta, SellerActivityLogService::PAYOUT_SETTINGS_UPDATED, now()->subDays(16), [
            'pix_key_type' => 'email',
            'pix_key_masked' => '****local.test',
            'label' => 'PIX Rafael Souza',
        ]);
        $this->writeLog($beta, SellerActivityLogService::WITHDRAWAL_REQUESTED, now()->subDays(4), [
            'amount' => 250,
            'bucket' => 'pix',
        ]);
        $this->writeLog($beta, SellerActivityLogService::WITHDRAWAL_REFERRAL_REQUESTED, now()->subDays(7), [
            'amount' => 80,
            'pix_key_type' => 'email',
            'pix_key_masked' => '****local.test',
        ]);
        $this->writeLog($beta, SellerActivityLogService::REFUND_REQUEST_REJECTED, now()->subDays(1), [
            'order_id' => $pendingRefundOrder->id,
            'reason' => 'Conteúdo foi acessado normalmente.',
        ], 'panel', $pendingRefundOrder->id, Order::class);
        $this->writeLog($beta, SellerActivityLogService::API_KEY_CREATED, now()->subDays(10), [
            'name' => 'Integração Hotmart',
            'public_key_masked' => 'pk_live_****beta',
        ]);
        $this->writeLog($beta, SellerActivityLogService::API_SECRET_REVEALED, now()->subHours(20), [
            'public_key_masked' => 'pk_live_****beta',
        ]);
        $this->writeLog($beta, SellerActivityLogService::TEAM_ROLE_CREATED, now()->subDays(12), [
            'name' => 'Financeiro',
        ]);
        $this->writeLog($beta, SellerActivityLogService::TEAM_MEMBER_CREATED, now()->subDays(12)->addHours(2), [
            'email' => 'equipe.beta@local.test',
        ]);

        $this->writeLog($gamma, SellerActivityLogService::PAYOUT_SETTINGS_UPDATED, now()->subDays(8), [
            'pix_key_type' => 'email',
            'pix_key_masked' => '****local.test',
            'label' => 'PIX Camila Ferreira',
        ]);
        $this->writeLog($gamma, SellerActivityLogService::WITHDRAWAL_REQUESTED, now()->subDays(2), [
            'amount' => 50,
            'bucket' => 'pix',
        ], 'api');
        $this->writeLog($gamma, SellerActivityLogService::API_KEY_CREATED, now()->subDays(15), [
            'name' => 'API PIX loja',
            'public_key_masked' => 'pk_live_****gamm',
        ]);
        $this->writeLog($gamma, SellerActivityLogService::API_KEY_SECRET_REVEALED, now()->subDays(1), [
            'name' => 'API PIX loja',
        ]);
        $this->writeLog($gamma, SellerActivityLogService::API_WEBHOOK_UPDATED, now()->subHours(6), [
            'webhook_url' => 'https://hooks.local.test/gamma',
            'secret_changed' => false,
        ], 'api');
        $this->writeLog($gamma, SellerActivityLogService::REFUND_REQUEST_APPROVED, now()->subDays(3), [
            'amount' => 67,
            'reason' => 'Cliente desistiu do pack',
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function writeLog(
        User $actor,
        string $action,
        Carbon $at,
        array $metadata = [],
        string $source = 'panel',
        mixed $targetId = null,
        ?string $targetType = null,
    ): void {
        $catalog = SellerActivityLogService::ACTIONS[$action] ?? null;
        if ($catalog === null) {
            return;
        }

        $log = SellerActivityLog::query()->create([
            'tenant_id' => (int) ($actor->tenant_id ?: $actor->id),
            'actor_user_id' => $actor->id,
            'action' => $action,
            'action_group' => $catalog['group'],
            'source' => $source,
            'target_type' => $targetType,
            'target_id' => $targetId !== null ? (string) $targetId : null,
            'summary' => $this->demoSummary($action, $catalog['label'], $metadata),
            'metadata' => array_merge($metadata, ['seed' => 'local_platform_tx']),
            'ip' => '187.10.'.random_int(1, 250).'.'.random_int(1, 250),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) SeedLocal/1.0',
        ]);
        $log->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function demoSummary(string $action, string $label, array $metadata): string
    {
        if (isset($metadata['amount'])) {
            return $label.' de R$ '.number_format((float) $metadata['amount'], 2, ',', '.');
        }
        if (! empty($metadata['order_id'])) {
            return $label.' do pedido #'.$metadata['order_id'];
        }
        if (! empty($metadata['email'])) {
            return $label.': '.$metadata['email'];
        }
        if (! empty($metadata['name']) && str_starts_with($action, 'team.')) {
            return $label.': '.$metadata['name'];
        }
        if (! empty($metadata['name']) && str_starts_with($action, 'api.')) {
            return $label.': '.$metadata['name'];
        }

        return $label;
    }
}
