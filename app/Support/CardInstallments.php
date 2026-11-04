<?php

namespace App\Support;

/**
 * Parcelamento no cartão (Pagar.me, Efí, Asaas, Cielo, CajuPay, Mercado Pago).
 * O teto geral é 12x. O Mercado Pago aceita até 18x; o Brick só mostra o que o cartão permite.
 * Mínimo de R$ 5,00 por parcela. O comprador vê o total dividido em Nx sem juros.
 */
final class CardInstallments
{
    public const MIN_AMOUNT_BRL = 5.0;

    public const MAX_ALLOWED = 12;

    public const MAX_MERCADOPAGO = 18;

    /** @var list<string> */
    public const GATEWAY_SLUGS = ['pagarme', 'efi', 'asaas', 'cielo', 'cajupay', 'mercadopago'];

    public static function gatewaySupports(?string $slug): bool
    {
        if ($slug === null || $slug === '') {
            return false;
        }

        return in_array($slug, self::GATEWAY_SLUGS, true);
    }

    public static function ceilingForGateway(?string $slug): int
    {
        return $slug === 'mercadopago' ? self::MAX_MERCADOPAGO : self::MAX_ALLOWED;
    }

    public static function normalizeMax(int $configuredMax, ?int $ceiling = null): int
    {
        $ceiling = $ceiling ?? self::MAX_ALLOWED;

        return max(1, min($ceiling, $configuredMax));
    }

    /**
     * Máximo efetivo dado o valor cobrado e o teto configurado pelo seller.
     */
    public static function maxAllowedForAmount(float $amountBrl, int $configuredMax = self::MAX_ALLOWED, ?int $ceiling = null): int
    {
        $ceiling = $ceiling ?? self::MAX_ALLOWED;
        $configuredMax = self::normalizeMax($configuredMax, $ceiling);
        if ($amountBrl < self::MIN_AMOUNT_BRL) {
            return 1;
        }
        $byAmount = (int) floor($amountBrl / self::MIN_AMOUNT_BRL);

        return max(1, min($configuredMax, $byAmount, $ceiling));
    }

    /**
     * Quantidade enviada ao adquirente. Assinatura e config desligada sempre 1x.
     */
    public static function clamp(
        ?int $requested,
        bool $enabled,
        int $configuredMax,
        float $amountBrl,
        bool $isSubscription = false,
        ?string $gatewaySlug = null
    ): int {
        if ($isSubscription || ! $enabled) {
            return 1;
        }
        $max = self::maxAllowedForAmount($amountBrl, $configuredMax, self::ceilingForGateway($gatewaySlug));
        $n = max(1, (int) $requested);

        return min($max, $n);
    }

    /**
     * Garante que o JSON do payment_token (Pagar.me) use o mesmo N já clampado.
     *
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    public static function applyToCardPayload(array $card, int $installments): array
    {
        $card['installments'] = $installments;
        $tokenRaw = $card['payment_token'] ?? '';
        if (is_string($tokenRaw) && $tokenRaw !== '') {
            $decoded = json_decode($tokenRaw, true);
            if (is_array($decoded)) {
                $decoded['installments'] = $installments;
                $encoded = json_encode($decoded);
                if (is_string($encoded)) {
                    $card['payment_token'] = $encoded;
                }
            }
        }

        return $card;
    }
}
