<?php

namespace Tests\Unit\Asaas;

use App\Models\SubscriptionPlan;
use App\Services\Asaas\AsaasPixAutoService;
use Carbon\Carbon;
use Tests\TestCase;

class AsaasPixAutoServiceTest extends TestCase
{
    public function test_frequency_matches_plan_intervals(): void
    {
        $this->assertSame('WEEKLY', AsaasPixAutoService::frequencyFromInterval(SubscriptionPlan::INTERVAL_WEEKLY));
        $this->assertSame('MONTHLY', AsaasPixAutoService::frequencyFromInterval(SubscriptionPlan::INTERVAL_MONTHLY));
        $this->assertSame('QUARTERLY', AsaasPixAutoService::frequencyFromInterval(SubscriptionPlan::INTERVAL_QUARTERLY));
        $this->assertSame('SEMIANNUALLY', AsaasPixAutoService::frequencyFromInterval(SubscriptionPlan::INTERVAL_SEMI_ANNUAL));
        $this->assertSame('ANNUALLY', AsaasPixAutoService::frequencyFromInterval(SubscriptionPlan::INTERVAL_ANNUAL));
    }

    public function test_instruction_window_counts_business_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23')); // segunda
        $service = app(AsaasPixAutoService::class);

        $this->assertFalse($service->withinInstructionWindow(Carbon::parse('2026-11-24')));
        $this->assertTrue($service->withinInstructionWindow(Carbon::parse('2026-11-25')));
        $this->assertTrue($service->withinInstructionWindow(Carbon::parse('2026-12-04')));
        $this->assertFalse($service->withinInstructionWindow(Carbon::parse('2026-12-08')));

        Carbon::setTestNow();
    }
}
