<?php

namespace Tests\Unit;

use App\Services\Payout\GatewayPayoutEconomics;
use Tests\TestCase;

class GatewayPayoutEconomicsTest extends TestCase
{
    public function test_transfer_amount_without_percent_keeps_legacy_fixed_only(): void
    {
        $this->assertSame(18.0, GatewayPayoutEconomics::transferAmountBrlForApi(16.0, 2.0));
        $this->assertSame(16.0, GatewayPayoutEconomics::transferAmountBrlForApi(16.0, 0.0, 0.0));
    }

    public function test_transfer_amount_grosses_up_percent_plus_fixed(): void
    {
        // (16 + 2) / (1 - 0.01) = 18 / 0.99 ≈ 18.18
        $this->assertSame(18.18, GatewayPayoutEconomics::transferAmountBrlForApi(16.0, 2.0, 1.0));
    }

    public function test_transfer_amount_percent_only(): void
    {
        // 100 / (1 - 0.02) ≈ 102.04
        $this->assertSame(102.04, GatewayPayoutEconomics::transferAmountBrlForApi(100.0, 0.0, 2.0));
    }

    public function test_payout_fee_cost_is_api_minus_net(): void
    {
        $this->assertSame(2.0, GatewayPayoutEconomics::payoutFeeCostBrl(16.0, 2.0, 0.0));
        $this->assertSame(2.18, GatewayPayoutEconomics::payoutFeeCostBrl(16.0, 2.0, 1.0));
    }

    public function test_pix_in_fee_cost_percent_plus_fixed(): void
    {
        // 100 * 1.5% + 0.50 = 2.00
        $this->assertSame(2.0, GatewayPayoutEconomics::pixInFeeCostBrl(100.0, 0.5, 1.5));
        $this->assertSame(0.5, GatewayPayoutEconomics::pixInFeeCostBrl(100.0, 0.5, 0.0));
        $this->assertSame(0.0, GatewayPayoutEconomics::pixInFeeCostBrl(100.0, 0.0, 0.0));
    }

    public function test_from_credentials_array_reads_percent_keys(): void
    {
        $e = GatewayPayoutEconomics::fromCredentialsArray('woovi', [
            'woovi_payout_min_brl' => '10',
            'woovi_admin_fee_pix_brl' => '0.3',
            'woovi_admin_fee_pix_percent' => '0,99',
            'woovi_admin_fee_payout_brl' => '1.2',
            'woovi_admin_fee_payout_percent' => '1.5',
        ]);

        $this->assertSame(10.0, $e['required_min_net']);
        $this->assertSame(0.3, $e['admin_fee_pix_brl']);
        $this->assertSame(0.99, $e['admin_fee_pix_percent']);
        $this->assertSame(1.2, $e['admin_fee_payout_brl']);
        $this->assertSame(1.5, $e['admin_fee_payout_percent']);
    }
}
