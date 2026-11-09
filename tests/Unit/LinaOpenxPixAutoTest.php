<?php

namespace Tests\Unit;

use App\Events\SubscriptionCancelled;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\LinaOpenx\LinaOpenxClient;
use App\Services\LinaOpenx\LinaOpenxPixAutoLifecycleService;
use App\Services\LinaOpenx\LinaOpenxPixAutoRenewalService;
use App\Services\LinaOpenx\LinaOpenxPixAutoService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class LinaOpenxPixAutoTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function sampleCredentials(): array
    {
        return [
            'client_id' => 'cid',
            'client_secret' => 'csecret',
            'sandbox' => false,
            'creditor_name' => 'Recebedor Teste',
            'creditor_cpf_cnpj' => '11111111111111',
            'creditor_ispb' => '12345678',
            'creditor_issuer' => '1774',
            'creditor_number' => '1234567890',
            'creditor_account_type' => 'CACC',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake([
            'iam.linaob.com.br/*' => Http::response([
                'access_token' => 'tok',
                'expires_in' => 900,
            ], 200),
        ]);
    }

    public function test_interval_from_plan(): void
    {
        $this->assertSame('SEMANAL', LinaOpenxPixAutoService::intervalFromPlan(SubscriptionPlan::INTERVAL_WEEKLY));
        $this->assertSame('TRIMESTRAL', LinaOpenxPixAutoService::intervalFromPlan(SubscriptionPlan::INTERVAL_QUARTERLY));
        $this->assertSame('SEMESTRAL', LinaOpenxPixAutoService::intervalFromPlan(SubscriptionPlan::INTERVAL_SEMI_ANNUAL));
        $this->assertSame('ANUAL', LinaOpenxPixAutoService::intervalFromPlan(SubscriptionPlan::INTERVAL_ANNUAL));
        $this->assertSame('MENSAL', LinaOpenxPixAutoService::intervalFromPlan(null));
    }

    public function test_authorised_without_payment_is_pending(): void
    {
        $client = new LinaOpenxClient;
        $this->assertSame('pending', $client->normalizeAutomaticStatuses('AUTHORISED', null));
        $this->assertSame('paid', $client->normalizeAutomaticStatuses('AUTHORISED', 'ACSC'));
        $this->assertSame('rejected', $client->normalizeAutomaticStatuses('AUTHORISED', 'RJCT'));
        $this->assertSame('cancelled', $client->normalizeAutomaticStatuses('REVOKED', null));
    }

    public function test_list_registered_participants_parses_nested_servers(): void
    {
        Http::fake([
            'iam.linaob.com.br/*' => Http::response(['access_token' => 'tok', 'expires_in' => 900], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/open-integration/participants/registered' => Http::response([
                'data' => [[
                    'organisationId' => 'org-1',
                    'organisationName' => 'Banco Exemplo',
                    'authorisationServers' => [[
                        'authorisationServerId' => 'as-1',
                        'customerFriendlyName' => 'Banco Exemplo App',
                    ]],
                ]],
            ], 200),
        ]);

        $list = (new LinaOpenxClient)->listRegisteredParticipants($this->sampleCredentials());
        $this->assertCount(1, $list);
        $this->assertSame('org-1', $list[0]['organisation_id']);
        $this->assertSame('as-1', $list[0]['authorisation_server_id']);
        $this->assertSame('Banco Exemplo App', $list[0]['name']);
    }

    public function test_list_registered_participants_parses_documented_pascal_case(): void
    {
        Http::fake([
            'iam.linaob.com.br/*' => Http::response(['access_token' => 'tok', 'expires_in' => 900], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/open-integration/participants/registered' => Http::response([
                'data' => [[
                    'OrganisationId' => '12345',
                    'Status' => 'active',
                    'OrganisationName' => 'ACME Corp',
                    'AuthorisationServers' => [[
                        'AuthorisationServerId' => 'server123',
                        'CustomerFriendlyName' => 'ACME Bank',
                    ]],
                ], [
                    'OrganisationId' => '999',
                    'Status' => 'inactive',
                    'OrganisationName' => 'Banco Inativo',
                    'AuthorisationServers' => [[
                        'AuthorisationServerId' => 'server-off',
                        'CustomerFriendlyName' => 'Banco Inativo',
                    ]],
                ]],
                'message' => 'OK',
                'type' => 'success',
                'statusCode' => 200,
            ], 200),
        ]);

        $list = (new LinaOpenxClient)->listRegisteredParticipants($this->sampleCredentials());
        $this->assertCount(1, $list);
        $this->assertSame('12345', $list[0]['organisation_id']);
        $this->assertSame('server123', $list[0]['authorisation_server_id']);
        $this->assertSame('ACME Bank', $list[0]['name']);
    }

    public function test_create_recurring_consent_posts_collection_body(): void
    {
        Http::fake([
            'iam.linaob.com.br/*' => Http::response(['access_token' => 'tok', 'expires_in' => 900], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/automatic-payments/recurring-consents' => Http::response([
                'data' => [
                    'consentId' => 'urn:banco:consent:abc',
                    'redirectUrl' => 'https://portal.linaopenx.com.br/auto/abc',
                    'paymentRequestId' => 'pr-auto-1',
                ],
            ], 201),
        ]);

        $result = (new LinaOpenxClient)->createRecurringConsent($this->sampleCredentials(), [
            'organisationId' => 'org-1',
            'authorisationServerId' => 'as-1',
            'recurringConsent' => ['loggedUser' => ['document' => ['identification' => '76109277673', 'rel' => 'CPF']]],
        ]);

        $this->assertSame('urn:banco:consent:abc', $result['consent_id']);
        $this->assertSame('pr-auto-1', $result['payment_request_id']);
        $this->assertStringContainsString('portal.linaopenx.com.br', $result['redirect_url']);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/automatic-payments/recurring-consents')) {
                return false;
            }
            $data = $request->data();

            return ($data['organisationId'] ?? null) === 'org-1'
                && ($data['authorisationServerId'] ?? null) === 'as-1'
                && isset($data['recurringConsent']);
        });
    }

    public function test_start_consent_requires_plugin(): void
    {
        if (! Schema::hasTable('orders')) {
            $this->markTestSkipped('orders');
        }
        $product = $this->createTestProduct(['billing_type' => Product::BILLING_SUBSCRIPTION]);
        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 10,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'p-'.Str::lower(Str::random(8)),
            'position' => 1,
        ]);
        $order = Order::query()->create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'a@b.com',
            'cpf' => '76109277673',
            'payment_method' => 'pix_auto',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plugin');
        app(LinaOpenxPixAutoService::class)->startConsentForOrder($order, [
            'name' => 'Cliente',
            'email' => 'a@b.com',
            'cpf' => '76109277673',
            'lina_organisation_id' => 'org-1',
            'lina_authorisation_server_id' => 'as-1',
        ]);
    }

    public function test_handle_return_posts_first_payment_when_authorised_without_payments(): void
    {
        if (! Schema::hasTable('orders')) {
            $this->markTestSkipped('orders');
        }
        $this->seedCredential();
        $ctx = $this->makePixAutoContext();
        $order = $ctx['order'];
        $order->update([
            'status' => 'pending',
            'gateway' => 'linaopenx',
            'gateway_id' => 'pr-auto-1',
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'linaopenx_pix_auto_consent_id' => 'urn:banco:consent:abc',
                'lina_payment_request_id' => 'pr-auto-1',
            ],
        ]);

        Http::fake([
            'iam.linaob.com.br/*' => Http::response(['access_token' => 'tok', 'expires_in' => 900], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/automatic-payments/request/*' => Http::response([
                'data' => [
                    'consentId' => 'urn:banco:consent:abc',
                    'consentStatus' => 'AUTHORISED',
                    'payments' => [],
                ],
            ], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/automatic-payments/recurring-payments' => Http::response([
                'data' => ['recurringPaymentId' => 'pay-1'],
            ], 201),
            'embedded-payment-manager.linaob.com.br/api/v1/automatic-payments/recurring-payments/*' => Http::response([
                'status' => 'PDNG',
            ], 200),
        ]);

        $result = app(LinaOpenxPixAutoService::class)->handleReturn($order);
        $this->assertSame('pending', $result['status']);
        $this->assertFalse($result['paid']);
        $this->assertSame('pay-1', $order->fresh()->metadata['linaopenx_pix_auto_recurring_payment_id'] ?? null);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH) ?? '', '/recurring-payments'));
    }

    public function test_handle_return_completes_when_payment_acsc(): void
    {
        if (! Schema::hasTable('orders')) {
            $this->markTestSkipped('orders');
        }
        $this->seedCredential();
        $ctx = $this->makePixAutoContext();
        $order = $ctx['order'];
        $order->update([
            'status' => 'pending',
            'gateway' => 'linaopenx',
            'gateway_id' => 'pr-auto-1',
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'linaopenx_pix_auto_consent_id' => 'urn:banco:consent:abc',
                'lina_payment_request_id' => 'pr-auto-1',
            ],
        ]);

        Http::fake([
            'iam.linaob.com.br/*' => Http::response(['access_token' => 'tok', 'expires_in' => 900], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/automatic-payments/request/*' => Http::response([
                'consentStatus' => 'AUTHORISED',
                'consentId' => 'urn:banco:consent:abc',
                'payments' => [[
                    'recurringPaymentId' => 'pay-paid',
                    'status' => 'ACSC',
                ]],
            ], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/automatic-payments/recurring-payments/*' => Http::response([
                'status' => 'ACSC',
            ], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/payments/requests/*' => Http::response([
                'status' => 'CONSUMIDO',
                'payments' => [['status' => 'ACSC']],
            ], 200),
        ]);

        $result = app(LinaOpenxPixAutoService::class)->handleReturn($order);
        $this->assertTrue($result['paid']);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertArrayNotHasKey('linaopenx_pix_auto_first_payment_posted', $order->fresh()->metadata ?? []);
    }

    public function test_ensure_next_payment_posts_recurring_payment(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            $this->markTestSkipped('tables');
        }
        $this->seedCredential();
        $ctx = $this->makePixAutoContext();

        Http::fake([
            'iam.linaob.com.br/*' => Http::response(['access_token' => 'tok', 'expires_in' => 900], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/automatic-payments/recurring-payments' => Http::response([
                'data' => ['recurringPaymentId' => 'pay-renew'],
            ], 201),
        ]);

        $created = app(LinaOpenxPixAutoRenewalService::class)->ensureNextPayment($ctx['subscription'], $ctx['order']);
        $this->assertNotNull($created);
        $this->assertTrue((bool) $created->is_renewal);
        $this->assertSame('pay-renew', $created->gateway_id);
        $this->assertSame('urn:banco:consent:abc', $created->metadata['linaopenx_pix_auto_consent_id'] ?? null);
        Http::assertSent(function ($r) {
            if ($r->method() !== 'POST' || ! str_ends_with(parse_url($r->url(), PHP_URL_PATH) ?? '', '/recurring-payments')) {
                return false;
            }
            $data = $r->data();

            return ($data['recurringConsentId'] ?? null) === 'urn:banco:consent:abc'
                && ($data['localInstrument'] ?? null) === 'AUTO'
                && ($data['authorisationFlow'] ?? null) === 'HYBRID_FLOW';
        });
    }

    public function test_cancel_remote_patches_consent_revoked(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            $this->markTestSkipped('tables');
        }
        $this->seedCredential();
        $ctx = $this->makePixAutoContext();
        $pending = Order::query()->create([
            'tenant_id' => 1,
            'user_id' => $ctx['subscription']->user_id,
            'product_id' => $ctx['subscription']->product_id,
            'subscription_plan_id' => $ctx['subscription']->subscription_plan_id,
            'status' => 'pending',
            'gateway' => 'linaopenx',
            'gateway_id' => 'pay-pending',
            'payment_method' => 'pix_auto',
            'amount' => 35,
            'email' => 'a@b.com',
            'is_renewal' => true,
            'period_start' => now()->addMonth()->startOfDay(),
            'period_end' => now()->addMonths(2)->startOfDay(),
            'metadata' => [
                'linaopenx_pix_auto_consent_id' => 'urn:banco:consent:abc',
                'linaopenx_pix_auto_recurring_payment_id' => 'pay-pending',
            ],
        ]);

        Http::fake([
            'iam.linaob.com.br/*' => Http::response(['access_token' => 'tok', 'expires_in' => 900], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/automatic-payments/recurring-consents/*' => Http::response(['status' => 'REVOKED'], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/automatic-payments/recurring-payments/*' => Http::response(['ok' => true], 200),
        ]);

        app(LinaOpenxPixAutoLifecycleService::class)->cancelRemote($ctx['subscription']);

        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/recurring-consents/')
            && ($r['status'] ?? null) === 'REVOKED');
        $this->assertSame('cancelled', $pending->fresh()->status);
    }

    public function test_subscription_cancelled_event_revokes_lina_consent(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            $this->markTestSkipped('tables');
        }
        $this->seedCredential();
        $ctx = $this->makePixAutoContext();

        Http::fake([
            'iam.linaob.com.br/*' => Http::response(['access_token' => 'tok', 'expires_in' => 900], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/automatic-payments/recurring-consents/*' => Http::response(['status' => 'REVOKED'], 200),
        ]);

        Event::dispatch(new SubscriptionCancelled($ctx['subscription']));
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/recurring-consents/'));
    }

    public function test_retry_rejected_payment_posts_retry(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            $this->markTestSkipped('tables');
        }
        $this->seedCredential();
        $ctx = $this->makePixAutoContext();
        $pending = Order::query()->create([
            'tenant_id' => 1,
            'user_id' => $ctx['subscription']->user_id,
            'product_id' => $ctx['subscription']->product_id,
            'subscription_plan_id' => $ctx['subscription']->subscription_plan_id,
            'status' => 'pending',
            'gateway' => 'linaopenx',
            'gateway_id' => 'pay-fail',
            'payment_method' => 'pix_auto',
            'amount' => 35,
            'email' => 'a@b.com',
            'is_renewal' => true,
            'metadata' => [
                'linaopenx_pix_auto_consent_id' => 'urn:banco:consent:abc',
                'linaopenx_pix_auto_recurring_payment_id' => 'pay-fail',
            ],
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/oauth') || str_contains($request->url(), '/token')) {
                return Http::response(['access_token' => 'tok', 'expires_in' => 900], 200);
            }
            if (str_contains($request->url(), '/retry')) {
                return Http::response(['data' => ['recurringPaymentId' => 'pay-retry']], 201);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/recurring-payments/')) {
                return Http::response(['status' => 'RJCT'], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $ok = app(LinaOpenxPixAutoLifecycleService::class)->retryFailedPayment($pending);
        $this->assertTrue($ok);
        $this->assertSame(1, (int) ($pending->fresh()->metadata['linaopenx_pix_auto_retry_count'] ?? 0));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/retry'));
    }

    /**
     * @return array{order: Order, subscription: Subscription}
     */
    private function makePixAutoContext(): array
    {
        $buyer = User::factory()->create(['tenant_id' => 1, 'name' => 'Fulano', 'document' => '76109277673']);
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
        ]);
        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 35,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'p-'.Str::lower(Str::random(8)),
            'position' => 1,
        ]);
        $order = Order::query()->create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'completed',
            'gateway' => 'linaopenx',
            'gateway_id' => 'pr-auto-1',
            'payment_method' => 'pix_auto',
            'amount' => 35,
            'email' => $buyer->email,
            'cpf' => '76109277673',
            'period_start' => now()->startOfDay(),
            'period_end' => now()->addMonth()->startOfDay(),
            'is_renewal' => false,
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'linaopenx_pix_auto_consent_id' => 'urn:banco:consent:abc',
            ],
        ]);
        $subscription = Subscription::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->startOfDay(),
            'current_period_end' => now()->addMonth()->startOfDay(),
            'gateway_subscription_id' => 'urn:banco:consent:abc',
        ]);

        return ['order' => $order, 'subscription' => $subscription];
    }

    private function seedCredential(): void
    {
        $cred = new GatewayCredential;
        $cred->tenant_id = 1;
        $cred->gateway_slug = 'linaopenx';
        $cred->is_connected = true;
        $cred->is_enabled = true;
        $cred->setEncryptedCredentials($this->sampleCredentials());
        $cred->save();
    }
}
