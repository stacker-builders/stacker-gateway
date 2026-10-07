<?php

namespace Tests\Unit;

use App\Models\BrandingSetting;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiLabelService;
use Tests\TestCase;

class UazapiLabelServiceTest extends TestCase
{
    public function test_resolved_labels_use_global_branding_app_name(): void
    {
        BrandingSetting::query()->updateOrCreate(
            ['tenant_id' => null],
            ['data' => ['app_name' => 'AsgardPay']]
        );

        $service = new UazapiLabelService(app(UazapiClient::class));
        $labels = $service->resolvedLabelDefinitions();

        $this->assertSame('AsgardPay', $service->platformName());
        $this->assertSame('AsgardPay · abandonou', $labels['abandoned']['name']);
        $this->assertSame('AsgardPay · quente', $labels['hot']['name']);
        $this->assertSame('AsgardPay · pagou', $labels['paid']['name']);
    }

    public function test_platform_name_falls_back_to_getfy_config(): void
    {
        BrandingSetting::query()->whereNull('tenant_id')->delete();
        config(['getfy.app_name' => 'Bersek', 'app.name' => 'Laravel']);

        $service = new UazapiLabelService(app(UazapiClient::class));

        $this->assertSame('Bersek', $service->platformName());
    }
}
