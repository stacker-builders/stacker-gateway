<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\PaymentService;
use App\Support\SaleOrigin;
use Tests\TestCase;

class BspayApiPixExclusionTest extends TestCase
{
    public function test_bspay_is_excluded_from_api_pix_and_pixgo_but_allowed_on_checkout(): void
    {
        $service = app(PaymentService::class);

        $apiOrder = new Order(['metadata' => ['source' => 'api']]);
        $checkoutOrder = new Order(['metadata' => []]);
        $pixGoOrder = new Order(['metadata' => ['source' => 'pixgo']]);
        $pixGoWithCheckoutOrigin = new Order([
            'sale_origin' => SaleOrigin::CHECKOUT_PUBLIC,
            'metadata' => ['source' => 'pixgo'],
        ]);

        $this->assertFalse($service->isPixAcquirerAllowedForOrder('bspay', $apiOrder));
        $this->assertFalse($service->isPixAcquirerAllowedForOrder('bspay', $pixGoOrder));
        $this->assertFalse($service->isPixAcquirerAllowedForOrder('bspay', $pixGoWithCheckoutOrigin));
        $this->assertTrue($service->isPixAcquirerAllowedForOrder('cajupay', $apiOrder));
        $this->assertTrue($service->isPixAcquirerAllowedForOrder('woovi', $apiOrder));
        $this->assertTrue($service->isPixAcquirerAllowedForOrder('cajupay', $pixGoOrder));
        $this->assertTrue($service->isPixAcquirerAllowedForOrder('bspay', $checkoutOrder));
    }
}
