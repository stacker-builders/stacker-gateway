<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use App\Services\MemberLessonPdfDrmValidator;
use FPDF;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemberBuilderPdfDrmValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_store_lesson_rejects_incompatible_pdf_when_drm_enabled(): void
    {
        [$owner, $product, $module] = $this->sellerWithModule();
        $url = 'https://example.com/quebrado.pdf';
        Http::fake([
            $url => Http::response("%PDF-1.4\nisto nao e um pdf", 200, ['Content-Type' => 'application/pdf']),
        ]);

        $response = $this->actingAs($owner)->postJson(
            route('member-builder.lessons.store', [$product, $module]),
            [
                'title' => 'Apostila',
                'type' => MemberLesson::TYPE_PDF,
                'content_url' => $url,
                'content_files' => [
                    ['url' => $url, 'name' => 'Quebrado.pdf'],
                ],
                'watermark_enabled' => true,
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['watermark_enabled']);
        $this->assertSame(
            MemberLessonPdfDrmValidator::PRODUCER_REJECT_MESSAGE,
            $response->json('errors.watermark_enabled.0')
        );
        $this->assertSame(
            MemberLessonPdfDrmValidator::PRODUCER_REJECT_MESSAGE,
            $response->json('message')
        );
        $this->assertSame(0, MemberLesson::where('product_id', $product->id)->count());
    }

    public function test_store_lesson_accepts_compatible_pdf_when_drm_enabled(): void
    {
        [$owner, $product, $module] = $this->sellerWithModule();
        $url = 'https://example.com/apostila.pdf';
        Http::fake([
            $url => Http::response($this->samplePdf(), 200, ['Content-Type' => 'application/pdf']),
        ]);

        $response = $this->actingAs($owner)->postJson(
            route('member-builder.lessons.store', [$product, $module]),
            [
                'title' => 'Apostila',
                'type' => MemberLesson::TYPE_PDF,
                'content_url' => $url,
                'content_files' => [
                    ['url' => $url, 'name' => 'Apostila.pdf'],
                ],
                'watermark_enabled' => true,
            ]
        );

        $response->assertOk();
        $this->assertDatabaseHas('member_lessons', [
            'product_id' => $product->id,
            'title' => 'Apostila',
            'watermark_enabled' => 1,
        ]);
    }

    public function test_store_lesson_accepts_local_storage_upload_url_when_drm_enabled(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://app.asgardpay.com.br']);
        [$owner, $product, $module] = $this->sellerWithModule();

        $path = 'member-area/'.$product->id.'/apostila.pdf';
        Storage::disk('public')->put($path, $this->samplePdf());
        $url = 'https://app.asgardpay.com.br/storage/'.$path;

        Http::fake();

        $response = $this->actingAs($owner)->postJson(
            route('member-builder.lessons.store', [$product, $module]),
            [
                'title' => 'Apostila local',
                'type' => MemberLesson::TYPE_PDF,
                'content_url' => $url,
                'content_files' => [
                    ['url' => $url, 'name' => 'Apostila.pdf'],
                ],
                'watermark_enabled' => true,
            ]
        );

        $response->assertOk();
        $this->assertDatabaseHas('member_lessons', [
            'product_id' => $product->id,
            'title' => 'Apostila local',
            'watermark_enabled' => 1,
        ]);
        Http::assertNothingSent();
    }

    public function test_store_lesson_reports_read_failure_distinct_from_incompatible_pdf(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://app.asgardpay.com.br']);
        [$owner, $product, $module] = $this->sellerWithModule();

        $url = 'https://app.asgardpay.com.br/storage/member-area/'.$product->id.'/ausente.pdf';
        Http::fake();

        $response = $this->actingAs($owner)->postJson(
            route('member-builder.lessons.store', [$product, $module]),
            [
                'title' => 'Apostila ausente',
                'type' => MemberLesson::TYPE_PDF,
                'content_url' => $url,
                'content_files' => [
                    ['url' => $url, 'name' => 'Ausente.pdf'],
                ],
                'watermark_enabled' => true,
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['watermark_enabled']);
        $this->assertSame(
            MemberLessonPdfDrmValidator::PRODUCER_READ_MESSAGE,
            $response->json('errors.watermark_enabled.0')
        );
        Http::assertNothingSent();
    }

    public function test_update_lesson_rejects_enabling_drm_on_incompatible_pdf(): void
    {
        [$owner, $product, $module] = $this->sellerWithModule();
        $url = 'https://example.com/quebrado.pdf';
        Http::fake([
            $url => Http::response("%PDF-1.4\nisto nao e um pdf", 200, ['Content-Type' => 'application/pdf']),
        ]);

        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Apostila',
            'position' => 1,
            'type' => MemberLesson::TYPE_PDF,
            'content_url' => $url,
            'content_files' => [
                ['url' => $url, 'name' => 'Quebrado.pdf'],
            ],
            'watermark_enabled' => false,
        ]);

        $response = $this->actingAs($owner)->putJson(
            route('member-builder.lessons.update', [$product, $lesson]),
            [
                'title' => 'Apostila',
                'type' => MemberLesson::TYPE_PDF,
                'content_url' => $url,
                'content_files' => [
                    ['url' => $url, 'name' => 'Quebrado.pdf'],
                ],
                'watermark_enabled' => true,
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['watermark_enabled']);
        $this->assertFalse((bool) $lesson->fresh()->watermark_enabled);
    }

    private function samplePdf(): string
    {
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 14);
        $pdf->Cell(0, 10, 'Pagina 1');

        return $pdf->Output('S');
    }

    /**
     * @return array{0: User, 1: Product, 2: MemberModule}
     */
    private function sellerWithModule(): array
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $owner->forceFill(['tenant_id' => $owner->id])->save();

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'mbdrm'.substr(uniqid('', true), -8),
            'slug' => 'md-'.substr(uniqid('', true), -8),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);
        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo',
            'position' => 1,
        ]);

        return [$owner->fresh(), $product, $module];
    }
}
