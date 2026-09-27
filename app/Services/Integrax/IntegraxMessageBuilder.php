<?php

namespace App\Services\Integrax;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\DeliverableAccessLinkService;
use App\Support\PublicAppUrl;
use Illuminate\Support\Facades\URL;

class IntegraxMessageBuilder
{
    /**
     * @return array<string, string>
     */
    public function fromCheckoutSession(CheckoutSession $session): array
    {
        $session->loadMissing('product:id,name,checkout_slug');

        $name = trim((string) ($session->name ?? ''));
        if ($name === '' && is_string($session->email) && $session->email !== '') {
            $name = explode('@', $session->email)[0] ?? 'Cliente';
        }
        if ($name === '') {
            $name = 'Cliente';
        }

        $slug = $session->checkout_slug ?? $session->product?->checkout_slug ?? '';

        return [
            'nome' => $name,
            'produto' => (string) ($session->product?->name ?? 'Produto'),
            'valor' => '',
            'link' => $this->checkoutPublicUrl(is_string($slug) ? $slug : ''),
            'link_acesso' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function fromOrder(Order $order): array
    {
        $order->loadMissing(['user', 'product']);

        $email = $order->email ?? $order->user?->email ?? '';
        $name = trim((string) ($order->user?->name ?? ''));
        if ($name === '' && is_string($email) && $email !== '') {
            $name = explode('@', $email)[0] ?? 'Cliente';
        }
        if ($name === '') {
            $name = 'Cliente';
        }

        $product = $order->product;
        $slug = $order->getCheckoutSlug();

        return [
            'nome' => $name,
            'produto' => (string) ($product?->name ?? 'Produto'),
            'valor' => 'R$ '.number_format((float) $order->amount, 2, ',', '.'),
            'link' => $this->checkoutPublicUrl(is_string($slug) ? $slug : ''),
            'link_acesso' => $this->resolveAccessLink($product, $order->user),
        ];
    }

    public function shouldSendAccessGranted(Order $order): bool
    {
        $order->loadMissing('product');
        $product = $order->product;
        if (! $product) {
            return false;
        }

        if ($product->type === Product::TYPE_LINK_PAGAMENTO) {
            return false;
        }

        return in_array($product->type, [
            Product::TYPE_AREA_MEMBROS,
            Product::TYPE_AREA_MEMBROS_EXTERNA,
            Product::TYPE_LINK,
            Product::TYPE_APLICATIVO,
        ], true);
    }

    private function checkoutPublicUrl(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        // Cron/queue rodam com APP_URL interno (localhost). Força a origem pública
        // antes de gerar o checkout enviado no WhatsApp/SMS.
        PublicAppUrl::forceRoot();

        return URL::route('checkout.show', ['slug' => $slug]);
    }

    private function resolveAccessLink(?Product $product, ?User $user): string
    {
        $login = rtrim(PublicAppUrl::base(), '/').'/login';

        if (! $product) {
            return $login;
        }

        if ($product->type === Product::TYPE_LINK && $user) {
            $tracked = app(DeliverableAccessLinkService::class)->trackedUrl($user, $product);

            return $tracked ?: $login;
        }

        // Área de membros: login da plataforma (aluno vê todos os produtos).
        return $login;
    }
}
