<?php

namespace Tests\Unit;

use App\Support\CardInstallments;
use Tests\TestCase;

class CardInstallmentsTest extends TestCase
{
    public function test_gateway_supports_pagarme_efi_asaas_cielo_cajupay_mercadopago(): void
    {
        $this->assertTrue(CardInstallments::gatewaySupports('pagarme'));
        $this->assertTrue(CardInstallments::gatewaySupports('efi'));
        $this->assertTrue(CardInstallments::gatewaySupports('asaas'));
        $this->assertTrue(CardInstallments::gatewaySupports('cielo'));
        $this->assertTrue(CardInstallments::gatewaySupports('cajupay'));
        $this->assertTrue(CardInstallments::gatewaySupports('mercadopago'));
        $this->assertFalse(CardInstallments::gatewaySupports('stripe'));
        $this->assertFalse(CardInstallments::gatewaySupports(null));
    }

    public function test_max_allowed_respects_minimum_per_installment(): void
    {
        $this->assertSame(1, CardInstallments::maxAllowedForAmount(4.99, 12));
        $this->assertSame(1, CardInstallments::maxAllowedForAmount(8, 12));
        $this->assertSame(4, CardInstallments::maxAllowedForAmount(20, 12));
        $this->assertSame(3, CardInstallments::maxAllowedForAmount(120, 3));
        $this->assertSame(12, CardInstallments::maxAllowedForAmount(1000, 12));
        $this->assertSame(12, CardInstallments::maxAllowedForAmount(1000, 18));
        $this->assertSame(18, CardInstallments::maxAllowedForAmount(1000, 18, CardInstallments::MAX_MERCADOPAGO));
    }

    public function test_clamp_forces_one_when_disabled_or_subscription(): void
    {
        $this->assertSame(1, CardInstallments::clamp(6, false, 12, 120));
        $this->assertSame(1, CardInstallments::clamp(6, true, 12, 120, true));
        $this->assertSame(3, CardInstallments::clamp(3, true, 12, 120));
        $this->assertSame(4, CardInstallments::clamp(12, true, 12, 20));
        $this->assertSame(18, CardInstallments::clamp(18, true, 18, 1000, false, 'mercadopago'));
        $this->assertSame(12, CardInstallments::clamp(18, true, 18, 1000, false, 'pagarme'));
        $this->assertSame(12, CardInstallments::clamp(18, true, 18, 1000));
    }

    public function test_apply_to_card_payload_rewrites_token_json(): void
    {
        $card = CardInstallments::applyToCardPayload([
            'payment_token' => json_encode(['card_token' => 'tok_1', 'installments' => 12]),
            'installments' => 12,
        ], 3);

        $this->assertSame(3, $card['installments']);
        $decoded = json_decode((string) $card['payment_token'], true);
        $this->assertSame(3, $decoded['installments']);
        $this->assertSame('tok_1', $decoded['card_token']);
    }
}
