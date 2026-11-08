<?php

namespace Tests\Feature;

use App\Mail\AccessGrantedMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\AccessEmailService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccessEmailTelegramTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('email_provider', 'smtp', null);
    }

    public function test_telegram_access_email_includes_provisional_credentials_and_grant_link(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'comprador-telegram@test.com',
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_TELEGRAM,
            'name' => 'Grupo VIP Telegram',
            'checkout_slug' => 'tg-email-'.substr(uniqid(), -5),
        ]);

        $bot = TelegramBot::query()->create([
            'tenant_id' => 1,
            'telegram_bot_id' => 880001,
            'username' => 'email_tg_bot',
            'first_name' => 'EmailBot',
            'token_encrypted' => '880001:AAHemailTelegramTokenValue',
            'webhook_path_secret' => TelegramBot::makePathSecret(),
            'webhook_secret_token' => TelegramBot::makeWebhookSecretToken(),
            'status' => TelegramBot::STATUS_CONNECTED,
            'connected_at' => now(),
        ]);

        $plain = 'TgTempPass99';
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 97,
            'email' => $user->email,
            'metadata' => ['access_password_temp' => encrypt($plain)],
            'is_renewal' => false,
        ]);

        $grant = TelegramAccessGrant::query()->create([
            'tenant_id' => 1,
            'order_id' => $order->id,
            'latest_order_id' => $order->id,
            'product_id' => (string) $product->id,
            'user_id' => $user->id,
            'telegram_bot_id' => $bot->id,
            'status' => TelegramAccessGrant::STATUS_PENDING_IDENTITY,
            'delivery_status' => TelegramAccessGrant::DELIVERY_PENDING,
            'paid_at' => now()->utc()->format('Y-m-d H:i:s'),
            'access_starts_at' => now()->utc()->format('Y-m-d H:i:s'),
            'access_ends_at' => now()->addDays(30)->utc()->format('Y-m-d H:i:s'),
            'is_lifetime' => false,
        ]);

        $ok = app(AccessEmailService::class)->sendForOrder($order->fresh(['product', 'user']), true);
        $this->assertTrue($ok->success, $ok->message ?? 'send failed');

        $expectedUrl = route('painel-cliente.telegram.show', ['grant' => $grant->id]);

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($plain, $user, $expectedUrl) {
            $this->assertStringContainsString('Seu acesso Telegram', $mail->subjectLine);
            $this->assertStringContainsString('Abrir acesso Telegram', $mail->htmlBody);
            $this->assertStringContainsString($expectedUrl, $mail->htmlBody);
            $this->assertStringContainsString('Dados provisórios para Minhas compras', $mail->htmlBody);
            $this->assertStringContainsString($user->email, $mail->htmlBody);
            $this->assertStringContainsString($plain, $mail->htmlBody);
            $this->assertStringContainsString('/painel-cliente', $mail->htmlBody);
            $this->assertStringNotContainsString('área de cursos', $mail->htmlBody);

            return true;
        });

        $accessLink = app(AccessEmailService::class)->getAccessLinkForOrder($order->fresh(['product', 'user']));
        $this->assertSame($expectedUrl, $accessLink);
    }
}
