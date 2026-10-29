<?php

namespace Tests\Feature;

use App\Mail\AccessGrantedMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\AccessEmailSendResult;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccessEmailSmtpPriorityTest extends TestCase
{
    public function test_uses_platform_smtp_from_and_seller_email_only_in_body(): void
    {
        Mail::fake();

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'email' => 'seller-gmail@gmail.com',
            'name' => 'Seller Loja',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'contato@asgardpay.com.br', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('email_provider', 'smtp', null);
        Setting::set('mail_from_address', 'contato@asgardpay.com.br', null);
        Setting::set('mail_from_name', 'Asgard Pay', null);

        $buyer = User::factory()->create(['tenant_id' => $seller->id, 'email' => 'buyer-smtp@test.com']);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-smtp',
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer-smtp@test.com',
        ]);

        $result = app(\App\Services\AccessEmailService::class)->sendForOrder($order, true);

        $this->assertTrue($result->success);
        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($seller) {
            $fromAddress = $mail->from[0]['address'] ?? $mail->from[0]->address ?? null;
            $fromName = $mail->from[0]['name'] ?? $mail->from[0]->name ?? null;

            $this->assertSame('contato@asgardpay.com.br', $fromAddress);
            $this->assertNotSame($seller->email, $fromAddress);
            $this->assertSame('Asgard Pay', $fromName);
            $this->assertEmpty($mail->replyTo);
            $this->assertStringContainsString($seller->email, $mail->htmlBody);
            $this->assertStringContainsString('data-seller-support="1"', $mail->htmlBody);
            $this->assertStringContainsString('Seller Loja', $mail->htmlBody);

            return true;
        });
    }

    public function test_uses_checkout_support_email_in_body_when_set(): void
    {
        Mail::fake();

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'email' => 'seller-login@test.com',
            'name' => 'Seller Nome',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'contato@asgardpay.com.br', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('email_provider', 'smtp', null);
        Setting::set('mail_from_address', 'plataforma@getfy.test', null);
        Setting::set('mail_from_name', 'Plataforma Global', null);

        $buyer = User::factory()->create(['tenant_id' => $seller->id, 'email' => 'buyer-support-from@test.com']);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-sup',
            'checkout_config' => [
                'footer' => ['support_email' => 'suporte-seller@loja.test'],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer-support-from@test.com',
        ]);

        $result = app(\App\Services\AccessEmailService::class)->sendForOrder($order, true);

        $this->assertTrue($result->success);
        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) {
            $fromAddress = $mail->from[0]['address'] ?? $mail->from[0]->address ?? null;
            $fromName = $mail->from[0]['name'] ?? $mail->from[0]->name ?? null;

            // mail_from_address divergente do SMTP username é ignorado (evita 553).
            $this->assertSame('contato@asgardpay.com.br', $fromAddress);
            $this->assertSame('Plataforma Global', $fromName);
            $this->assertEmpty($mail->replyTo);
            $this->assertStringContainsString('suporte-seller@loja.test', $mail->htmlBody);
            $this->assertStringNotContainsString('seller-login@test.com', $mail->htmlBody);

            return true;
        });
    }

    public function test_uses_product_support_email_over_seller_and_footer(): void
    {
        Mail::fake();

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'email' => 'seller-login@test.com',
            'name' => 'Seller Nome',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'contato@asgardpay.com.br', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('email_provider', 'smtp', null);
        Setting::set('mail_from_address', 'plataforma@getfy.test', null);
        Setting::set('mail_from_name', 'Plataforma Global', null);

        $buyer = User::factory()->create(['tenant_id' => $seller->id, 'email' => 'buyer-product-support@test.com']);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-sup-geral',
            'support_email' => 'suporte-produto@loja.test',
            'checkout_config' => [
                'footer' => ['support_email' => 'suporte-footer@loja.test'],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer-product-support@test.com',
        ]);

        $result = app(\App\Services\AccessEmailService::class)->sendForOrder($order, true);

        $this->assertTrue($result->success);
        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) {
            $this->assertStringContainsString('suporte-produto@loja.test', $mail->htmlBody);
            $this->assertStringContainsString('Qualquer dúvida, fale com', $mail->htmlBody);
            $this->assertStringNotContainsString('suporte-footer@loja.test', $mail->htmlBody);
            $this->assertStringNotContainsString('seller-login@test.com', $mail->htmlBody);

            return true;
        });
    }

    public function test_ignores_default_noreply_from_when_smtp_username_is_mailbox(): void
    {
        Mail::fake();

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'email' => 'seller-noreply@test.com',
            'name' => 'Seller',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'contato@asgardpay.com.br', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('email_provider', 'smtp', null);
        Setting::set('mail_from_address', 'noreply@getfy.com', null);
        Setting::set('mail_from_name', 'Getfy', null);

        $buyer = User::factory()->create(['tenant_id' => $seller->id, 'email' => 'buyer-noreply@test.com']);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-noreply',
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer-noreply@test.com',
        ]);

        $result = app(\App\Services\AccessEmailService::class)->sendForOrder($order, true);

        $this->assertTrue($result->success);
        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) {
            $fromAddress = $mail->from[0]['address'] ?? $mail->from[0]->address ?? null;
            $this->assertSame('contato@asgardpay.com.br', $fromAddress);
            $this->assertNotSame('noreply@getfy.com', $fromAddress);

            return true;
        });
    }

    public function test_returns_smtp_not_configured_when_no_provider(): void
    {
        Mail::fake();

        foreach ([
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption',
            'email_provider', 'mail_from_address', 'mail_from_name',
            'hostinger_smtp_username', 'hostinger_mail_from_address',
            'sendgrid_api_key', 'sendgrid_mail_from_address',
            'resend_api_key', 'resend_mail_from_address',
        ] as $key) {
            Setting::set($key, '', null);
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_LINK,
            'checkout_config' => ['deliverable_link' => 'https://example.com/file'],
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@test.com',
        ]);

        $result = app(\App\Services\AccessEmailService::class)->sendForOrder($order, true);

        $this->assertFalse($result->success);
        $this->assertSame(AccessEmailSendResult::REASON_SMTP_NOT_CONFIGURED, $result->reason);
        Mail::assertNothingSent();
    }
}
