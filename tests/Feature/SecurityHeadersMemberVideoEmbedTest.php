<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersMemberVideoEmbedTest extends TestCase
{
    public function test_production_csp_allows_member_area_video_embeds(): void
    {
        config(['app.env' => 'production']);

        $response = $this->get('/');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertNotSame('', $csp);

        $this->assertStringContainsString('https://www.youtube.com', $csp);

        $this->assertStringContainsString('https://player.vimeo.com', $csp);
        $this->assertStringContainsString('https://vimeo.com', $csp);
        $this->assertStringContainsString('https://*.vimeo.com', $csp);
        $this->assertStringContainsString('https://*.vimeocdn.com', $csp);

        $this->assertStringContainsString('https://fast.wistia.net', $csp);
        $this->assertStringContainsString('https://*.wistia.com', $csp);

        $this->assertStringContainsString('https://www.loom.com', $csp);
        $this->assertStringContainsString('https://*.loom.com', $csp);

        $this->assertStringContainsString('https://player.mediadelivery.net', $csp);
        $this->assertStringContainsString('https://iframe.mediadelivery.net', $csp);
        $this->assertStringContainsString('https://*.mediadelivery.net', $csp);
    }
}
