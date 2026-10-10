<?php

namespace Tests\Unit;

use App\Models\BrandingSetting;
use App\Support\PanelPwaIconUrls;
use Tests\TestCase;

class PanelPwaIconUrlsTest extends TestCase
{
    public function test_notification_icon_uses_admin_branding_when_config_is_empty(): void
    {
        config([
            'getfy.pwa_icon_192' => null,
            'getfy.pwa_icon_512' => null,
        ]);

        BrandingSetting::query()->create([
            'tenant_id' => null,
            'data' => [
                'pwa_icon_192' => 'https://cdn.example.com/platform-192.png',
                'pwa_icon_512' => 'https://cdn.example.com/platform-512.png',
            ],
        ]);

        $this->assertSame(
            'https://cdn.example.com/platform-192.png',
            PanelPwaIconUrls::primaryNotificationIconUrl()
        );
    }
}
