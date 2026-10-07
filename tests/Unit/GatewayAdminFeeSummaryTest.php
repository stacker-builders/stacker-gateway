<?php

namespace Tests\Unit;

use App\Support\GatewayAdminFeeSummary;
use PHPUnit\Framework\TestCase;

class GatewayAdminFeeSummaryTest extends TestCase
{
    public function test_format_shows_fixed_and_percent_on_both_sides(): void
    {
        $this->assertSame(
            'taxa: in = 0,50 + 1% / out = 0,50 + 2%',
            GatewayAdminFeeSummary::format(0.5, 1, 0.5, 2)
        );
    }

    public function test_format_omits_missing_percent_or_fixed(): void
    {
        $this->assertSame(
            'taxa: in = 0,50 / out = 2%',
            GatewayAdminFeeSummary::format(0.5, 0, 0, 2)
        );
    }

    public function test_format_omits_side_without_any_fee(): void
    {
        $this->assertSame('taxa: in = 1,5%', GatewayAdminFeeSummary::format(0, 1.5, 0, 0));
        $this->assertSame('taxa: out = 1,25', GatewayAdminFeeSummary::format(0, 0, 1.25, 0));
    }

    public function test_format_hides_line_when_nothing_is_configured(): void
    {
        $this->assertNull(GatewayAdminFeeSummary::format(0, 0, 0, 0));
    }

    public function test_label_from_credentials_uses_slug_keys(): void
    {
        $label = GatewayAdminFeeSummary::labelFromCredentials('efi', [
            'efi_admin_fee_pix_brl' => '0,50',
            'efi_admin_fee_pix_percent' => '1',
            'efi_admin_fee_payout_brl' => '0,50',
            'efi_admin_fee_payout_percent' => '2',
        ]);

        $this->assertSame('taxa: in = 0,50 + 1% / out = 0,50 + 2%', $label);
    }
}
