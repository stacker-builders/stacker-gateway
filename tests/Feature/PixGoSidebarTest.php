<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PixGoAccess;
use Tests\TestCase;

class PixGoSidebarTest extends TestCase
{
    public function test_inertia_shares_pixgo_props_when_enabled(): void
    {
        PixGoAccess::setEnabled(true);
        PixGoAccess::setSidebarLabel('CajuGO');

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
        ])->save();

        $this->actingAs($seller)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pixgo_enabled_effective', true)
                ->where('pixgo_sidebar_label', 'CajuGO'));
    }

    public function test_inertia_hides_pixgo_when_disabled(): void
    {
        PixGoAccess::setEnabled(false);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($seller)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pixgo_enabled_effective', false));
    }
}
