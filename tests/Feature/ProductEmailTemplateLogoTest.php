<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductEmailTemplateLogoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
        Storage::fake('public');
    }

    public function test_seller_can_upload_and_remove_email_logo(): void
    {
        $seller = $this->infoprodutor();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'checkout_config' => [
                'email_template' => Product::defaultEmailTemplate(),
            ],
        ]);

        $file = UploadedFile::fake()->image('marca.png', 400, 120);

        $this->actingAs($seller)
            ->postJson(route('produtos.email-template-logo', $product), ['logo' => $file])
            ->assertOk()
            ->assertJsonStructure(['logo_url']);

        $product->refresh();
        $logoUrl = $product->checkout_config['email_template']['logo_url'] ?? '';
        $this->assertNotSame('', $logoUrl);

        $this->actingAs($seller)
            ->deleteJson(route('produtos.email-template-logo.destroy', $product))
            ->assertOk()
            ->assertJson(['logo_url' => '']);

        $product->refresh();
        $this->assertSame('', $product->checkout_config['email_template']['logo_url'] ?? 'x');
    }

    private function infoprodutor(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $attrs = ['tenant_id' => $seller->id, 'account_status' => 'approved'];
        if (Schema::hasColumn('users', 'kyc_status')) {
            $attrs['kyc_status'] = User::KYC_APPROVED;
        }
        $seller->forceFill($attrs)->save();

        return $seller->fresh();
    }
}
