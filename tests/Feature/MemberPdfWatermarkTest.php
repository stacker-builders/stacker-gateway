<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\MemberStudentActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use FPDF;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemberPdfWatermarkTest extends TestCase
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

    public function test_watermarked_pdf_download_embeds_buyer_identity(): void
    {
        Storage::fake('public');
        [$product, $student, $lesson] = $this->enrolledPdfLesson();
        $student->forceFill(['name' => 'João Souza', 'email' => 'joao.conta@example.com'])->save();

        Order::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => $student->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 97,
            'email' => 'joao.pedido@example.com',
            'cpf' => '12345678900',
            'public_reference' => 'A1B2C3D4E5',
        ]);

        $path = 'member-area/'.$product->id.'/apostila.pdf';
        Storage::disk('public')->put($path, $this->samplePdf());
        $lesson->update([
            'watermark_enabled' => true,
            'content_files' => [
                ['url' => '/storage/'.$path, 'name' => 'Apostila.pdf'],
            ],
        ]);

        $response = $this->actingAs($student->fresh())->get(route('member-area-app.lesson.material', [
            'slug' => $product->checkout_slug,
            'lesson' => $lesson->id,
            'index' => 0,
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            'attachment; filename="Apostila.pdf"',
            (string) $response->headers->get('content-disposition')
        );
        $body = $response->getContent();
        $this->assertSame((string) strlen($body), (string) $response->headers->get('content-length'));
        $this->assertStringStartsWith('%PDF', $body);
        $this->assertStringContainsString('%%EOF', $body);
        $plain = $this->pdfPlainText($body);
        $this->assertStringContainsString($this->latin('João Souza'), $plain);
        $this->assertStringContainsString('123.456.789-00', $plain);
        $this->assertStringContainsString('joao.pedido@example.com', $plain);
        $this->assertStringContainsString('A1B2C3D4E5', $plain);
        $this->assertStringContainsString('comprador identificado', $plain);
        $this->assertGreaterThanOrEqual(2, substr_count($plain, $this->latin('João Souza')));

        $this->assertDatabaseHas('member_student_activity_logs', [
            'user_id' => $student->id,
            'event' => MemberStudentActivityLog::EVENT_MATERIAL_DOWNLOADED,
            'subject' => 'Apostila.pdf',
            'file_index' => 0,
        ]);
    }

    public function test_watermark_without_cpf_uses_email_and_does_not_send_original_when_pdf_is_invalid(): void
    {
        Storage::fake('public');
        [$product, $student, $lesson] = $this->enrolledPdfLesson();
        $student->forceFill(['name' => 'Maria Silva'])->save();

        $path = 'member-area/'.$product->id.'/quebrado.pdf';
        Storage::disk('public')->put($path, "%PDF-1.4\nisto nao e um pdf");
        $lesson->update([
            'watermark_enabled' => true,
            'content_files' => [
                ['url' => '/storage/'.$path, 'name' => 'Quebrado.pdf'],
            ],
        ]);

        $broken = $this->actingAs($student)->get(route('member-area-app.lesson.material', [
            'slug' => $product->checkout_slug,
            'lesson' => $lesson->id,
            'index' => 0,
        ]));
        $broken->assertStatus(422);
        $this->assertStringContainsString('application/json', (string) $broken->headers->get('content-type'));
        $this->assertStringContainsString('O arquivo original não foi enviado', (string) $broken->json('message'));
        $this->assertStringNotContainsString('%PDF', $broken->getContent());
        $this->assertDatabaseMissing('member_student_activity_logs', [
            'user_id' => $student->id,
            'event' => MemberStudentActivityLog::EVENT_MATERIAL_DOWNLOADED,
        ]);

        Storage::disk('public')->put($path, $this->samplePdf());
        $ok = $this->actingAs($student->fresh())->get(route('member-area-app.lesson.material', [
            'slug' => $product->checkout_slug,
            'lesson' => $lesson->id,
            'index' => 0,
        ]));
        $ok->assertOk();
        $body = $ok->getContent();
        $plain = $this->pdfPlainText($body);
        $this->assertStringContainsString($this->latin('Maria Silva'), $plain);
        $this->assertStringContainsString($this->latin('não informado'), $plain);
        $this->assertStringContainsString($student->email, $plain);
        $this->assertStringContainsString($this->latin('Nº do cliente: ').$student->id, $plain);
    }

    public function test_non_pdf_material_keeps_redirect_when_protection_is_enabled(): void
    {
        [$product, $student, $lesson] = $this->enrolledPdfLesson();
        $lesson->update([
            'watermark_enabled' => true,
            'content_files' => [
                ['url' => 'https://cdn.example.com/planilha.xlsx', 'name' => 'Planilha.xlsx'],
            ],
        ]);

        $this->actingAs($student)
            ->get(route('member-area-app.lesson.material', [
                'slug' => $product->checkout_slug,
                'lesson' => $lesson->id,
                'index' => 0,
            ]))
            ->assertRedirect('https://cdn.example.com/planilha.xlsx');
    }

    public function test_external_pdf_is_stamped_and_private_address_is_refused(): void
    {
        [$product, $student, $lesson] = $this->enrolledPdfLesson();
        $student->forceFill(['name' => 'Ana Costa'])->save();
        $lesson->update([
            'watermark_enabled' => true,
            'content_files' => [
                ['url' => 'https://example.com/aula.pdf', 'name' => 'Aula.pdf'],
            ],
        ]);

        Http::fake([
            'https://example.com/aula.pdf' => Http::response($this->samplePdf(), 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $response = $this->actingAs($student->fresh())->get(route('member-area-app.lesson.material', [
            'slug' => $product->checkout_slug,
            'lesson' => $lesson->id,
            'index' => 0,
        ]));
        $response->assertOk();
        $this->assertStringContainsString($this->latin('Ana Costa'), $this->pdfPlainText($response->getContent()));

        $lesson->update([
            'content_files' => [
                ['url' => 'http://127.0.0.1/secreto.pdf', 'name' => 'Secreto.pdf'],
            ],
        ]);
        Http::fake();

        $blocked = $this->actingAs($student->fresh())->get(route('member-area-app.lesson.material', [
            'slug' => $product->checkout_slug,
            'lesson' => $lesson->id,
            'index' => 0,
        ]));
        $blocked->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_own_domain_storage_url_is_stamped_without_http(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://app.asgardpay.com.br']);

        [$product, $student, $lesson] = $this->enrolledPdfLesson();
        $student->forceFill(['name' => 'Ana Costa'])->save();

        $path = 'member-area/'.$product->id.'/apostila.pdf';
        Storage::disk('public')->put($path, $this->samplePdf());
        $url = 'https://app.asgardpay.com.br/storage/'.$path;

        $lesson->update([
            'watermark_enabled' => true,
            'content_files' => [
                ['url' => $url, 'name' => 'Apostila.pdf'],
            ],
        ]);

        Http::fake();

        $response = $this->actingAs($student->fresh())->get(route('member-area-app.lesson.material', [
            'slug' => $product->checkout_slug,
            'lesson' => $lesson->id,
            'index' => 0,
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString($this->latin('Ana Costa'), $this->pdfPlainText($response->getContent()));
        Http::assertNothingSent();
    }

    private function samplePdf(): string
    {
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 14);
        $pdf->Cell(0, 10, 'Pagina 1');
        $pdf->AddPage();
        $pdf->Cell(0, 10, 'Pagina 2');

        return $pdf->Output('S');
    }

    private function latin(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);

        return $converted !== false ? $converted : $text;
    }

    private function pdfPlainText(string $binary): string
    {
        $text = $binary;
        $offset = 0;
        $pattern = '/\/Length\s+(\d+)\s*>>\s*stream\r?\n/';
        while (preg_match($pattern, $binary, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $length = (int) $match[1][0];
            $start = $match[0][1] + strlen($match[0][0]);
            $stream = substr($binary, $start, $length);
            $decoded = @gzuncompress($stream);
            if (is_string($decoded)) {
                $text .= "\n".$decoded;
            }
            $offset = $start + max(1, $length);
        }

        return $text;
    }

    /**
     * @return array{0: Product, 1: User, 2: MemberLesson}
     */
    private function enrolledPdfLesson(): array
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
            'checkout_slug' => 'pdfwm'.substr(uniqid('', true), -8),
            'slug' => 'pw'.substr(uniqid('', true), -8),
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
        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Apostila',
            'position' => 1,
            'type' => MemberLesson::TYPE_PDF,
            'content_text' => null,
            'watermark_enabled' => false,
        ]);

        $student = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'password' => Hash::make('password'),
        ]);
        DB::table('product_user')->insert([
            'product_id' => $product->id,
            'user_id' => $student->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        return [$product, $student, $lesson];
    }
}
