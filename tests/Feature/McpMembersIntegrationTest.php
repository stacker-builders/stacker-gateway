<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberComment;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\TenantWallet;
use App\Models\User;
use App\Services\SellerIntegrationVisibility;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class McpMembersIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
            ThrottleRequests::class,
        ]);
        Cache::forget('setting.global.'.SellerIntegrationVisibility::settingKey(SellerIntegrationVisibility::MCP));
    }

    public function test_member_structure_and_lesson_crud_stay_inside_tenant(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeSeller();
        $token = $this->enableMcp($seller);
        $product = $this->memberProduct($seller, 'Curso MCP');
        $foreign = $this->memberProduct($other, 'Curso alheio');

        $foreignGet = $this->callTool($token, 'members.get_structure', [
            'product_id' => (string) $foreign->id,
        ]);
        $this->assertTrue($foreignGet->json('result.isError'));
        $this->assertSame('not_found', $this->decodeTool($foreignGet)['code']);

        $section = $this->decodeTool($this->callTool($token, 'members.create_section', [
            'product_id' => (string) $product->id,
            'title' => 'Módulos',
        ]));
        $this->assertSame('Módulos', $section['section']['title']);

        $module = $this->decodeTool($this->callTool($token, 'members.create_module', [
            'product_id' => (string) $product->id,
            'section_id' => $section['section']['id'],
            'title' => 'Módulo 1',
            'release_after_days' => 7,
        ]));
        $this->assertSame('Módulo 1', $module['module']['title']);
        $this->assertSame(7, $module['module']['release_after_days']);

        $lesson = $this->decodeTool($this->callTool($token, 'members.create_lesson', [
            'product_id' => (string) $product->id,
            'module_id' => $module['module']['id'],
            'title' => 'Introdução',
            'type' => 'video',
            'content_url' => 'https://vimeo.com/123456',
        ]));
        $this->assertSame('Introdução', $lesson['lesson']['title']);
        $this->assertSame('https://vimeo.com/123456', $lesson['lesson']['content_url']);

        $structure = $this->decodeTool($this->callTool($token, 'members.get_structure', [
            'product_id' => (string) $product->id,
        ]));
        $this->assertSame('Curso MCP', $structure['product']['name']);
        $this->assertSame('Introdução', $structure['sections'][0]['modules'][0]['lessons'][0]['title']);
        $this->assertArrayNotHasKey('default_password', $structure);
        $this->assertArrayNotHasKey('vapid_private', json_decode((string) json_encode($structure), true));

        $this->decodeTool($this->callTool($token, 'members.update_config', [
            'product_id' => (string) $product->id,
            'theme' => ['primary' => '#22c55e'],
            'hero_title' => 'Bem-vindo',
        ]));
        $fresh = $product->fresh();
        $this->assertSame('#22c55e', $fresh?->member_area_config['theme']['primary'] ?? null);
        $this->assertSame('Bem-vindo', $fresh?->member_area_config['hero']['title'] ?? null);
    }

    public function test_delete_lesson_and_revoke_student_need_apply_plan(): void
    {
        $seller = $this->makeSeller();
        $token = $this->enableMcp($seller);
        $product = $this->memberProduct($seller, 'Curso gated');
        $section = MemberSection::query()->create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'section_type' => 'courses',
        ]);
        $module = MemberModule::query()->create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo',
            'position' => 1,
        ]);
        $lesson = MemberLesson::query()->create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula a remover',
            'position' => 1,
            'type' => 'text',
            'content_text' => 'Olá',
        ]);

        $delete = $this->callTool($token, 'members.delete_lesson', [
            'product_id' => (string) $product->id,
            'lesson_id' => $lesson->id,
        ]);
        $this->assertTrue($delete->json('result.isError'));
        $payload = $this->decodeTool($delete);
        $this->assertSame('needs_confirmation', $payload['code']);
        $this->assertDatabaseHas('member_lessons', ['id' => $lesson->id]);

        $applied = $this->decodeTool($this->callTool($token, 'members.apply_plan', [
            'preview_id' => $payload['preview_id'],
        ]));
        $this->assertTrue($applied['ok']);
        $this->assertDatabaseMissing('member_lessons', ['id' => $lesson->id]);

        $grant = $this->decodeTool($this->callTool($token, 'students.grant', [
            'product_id' => (string) $product->id,
            'name' => 'Maria Silva',
            'email' => 'maria.mcp@example.com',
            'password' => 'senha123',
        ]));
        $this->assertSame('maria.mcp@example.com', $grant['student']['email']);
        $this->assertArrayNotHasKey('password', $grant['student']);
        $this->assertTrue($product->users()->where('users.email', 'maria.mcp@example.com')->exists());

        $studentId = $grant['student']['id'];
        $revoke = $this->callTool($token, 'students.revoke', [
            'product_id' => (string) $product->id,
            'student_id' => $studentId,
        ]);
        $this->assertTrue($revoke->json('result.isError'));
        $this->assertSame('needs_confirmation', $this->decodeTool($revoke)['code']);
        $this->assertTrue($product->users()->where('users.id', $studentId)->exists());

        $this->decodeTool($this->callTool($token, 'members.apply_plan', [
            'preview_id' => $this->decodeTool($revoke)['preview_id'],
        ]));
        $this->assertFalse($product->users()->where('users.id', $studentId)->exists());
    }

    public function test_students_list_and_turma_and_comments_and_balance(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeSeller();
        $token = $this->enableMcp($seller);
        $product = $this->memberProduct($seller, 'Curso alunos');
        $foreignProduct = $this->memberProduct($other, 'Curso outro');

        $ownStudent = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'name' => 'Aluno próprio',
            'email' => 'aluno.proprio@example.com',
            'tenant_id' => null,
        ]);
        $product->users()->attach($ownStudent->id);
        $foreignStudent = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'name' => 'Aluno alheio',
            'email' => 'aluno.alheio@example.com',
            'tenant_id' => null,
        ]);
        $foreignProduct->users()->attach($foreignStudent->id);

        $list = $this->decodeTool($this->callTool($token, 'students.list', [
            'product_id' => (string) $product->id,
        ]));
        $emails = collect($list['students'])->pluck('email')->all();
        $this->assertContains('aluno.proprio@example.com', $emails);
        $this->assertNotContains('aluno.alheio@example.com', $emails);
        $this->assertArrayNotHasKey('cpf', $list['students'][0]);
        $this->assertArrayNotHasKey('phone', $list['students'][0]);

        $turma = $this->decodeTool($this->callTool($token, 'members.create_turma', [
            'product_id' => (string) $product->id,
            'name' => 'Turma noite',
        ]));
        $this->decodeTool($this->callTool($token, 'members.attach_turma_student', [
            'product_id' => (string) $product->id,
            'turma_id' => $turma['turma']['id'],
            'student_id' => $ownStudent->id,
        ]));
        $listedTurmas = $this->decodeTool($this->callTool($token, 'members.list_turmas', [
            'product_id' => (string) $product->id,
        ]));
        $this->assertSame('Turma noite', $listedTurmas['turmas'][0]['name']);
        $this->assertSame($ownStudent->id, $listedTurmas['turmas'][0]['students'][0]['id']);

        $lesson = MemberLesson::query()->create([
            'member_module_id' => MemberModule::query()->create([
                'member_section_id' => MemberSection::query()->create([
                    'product_id' => $product->id,
                    'title' => 'S',
                    'position' => 1,
                    'section_type' => 'courses',
                ])->id,
                'product_id' => $product->id,
                'title' => 'M',
                'position' => 1,
            ])->id,
            'product_id' => $product->id,
            'title' => 'Aula comentário',
            'position' => 1,
            'type' => 'text',
            'content_text' => 'x',
        ]);
        $comment = MemberComment::query()->create([
            'product_id' => $product->id,
            'user_id' => $ownStudent->id,
            'member_lesson_id' => $lesson->id,
            'content' => 'Dúvida',
            'status' => MemberComment::STATUS_PENDING,
        ]);
        $this->decodeTool($this->callTool($token, 'members.moderate_comment', [
            'product_id' => (string) $product->id,
            'comment_id' => $comment->id,
            'status' => 'approved',
        ]));
        $this->assertSame(MemberComment::STATUS_APPROVED, $comment->fresh()?->status);

        if (Schema::hasTable('tenant_wallets')) {
            TenantWallet::query()->updateOrCreate(
                ['tenant_id' => $seller->id],
                [
                    'currency' => 'BRL',
                    'available_pix' => 150.5,
                    'available_card' => 20,
                    'available_boleto' => 0,
                    'pending_pix' => 10,
                    'pending_card' => 0,
                    'pending_boleto' => 0,
                ]
            );
        }
        $balance = $this->decodeTool($this->callTool($token, 'finance.get_balance'));
        $this->assertTrue($balance['read_only']);
        $this->assertEquals(170.5, $balance['available_total']);
        $this->assertArrayNotHasKey('admin_withdrawal_blocked', $balance);
        $this->assertArrayNotHasKey('payout_pix_key', $balance);

        $unknown = $this->callTool($token, 'finance.withdraw', ['amount' => 10]);
        $this->assertTrue($unknown->json('result.isError'));
        $this->assertSame('unknown_tool', $this->decodeTool($unknown)['code']);
    }

    private function makeSeller(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $seller->fresh();
    }

    private function memberProduct(User $seller, string $name): Product
    {
        return $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => $name,
            'type' => Product::TYPE_AREA_MEMBROS,
            'approval_status' => Product::APPROVAL_APPROVED,
            'is_active' => true,
        ]);
    }

    private function enableMcp(User $seller): string
    {
        SellerIntegrationVisibility::setTenantMode(
            SellerIntegrationVisibility::MCP,
            (int) $seller->id,
            SellerIntegrationVisibility::MODE_ENABLED
        );

        $token = $this->actingAs($seller)
            ->postJson(route('integrations.mcp.store'))
            ->assertCreated()
            ->json('token');

        $this->assertIsString($token);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function callTool(string $token, string $name, array $arguments = [])
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeTool($response): array
    {
        $decoded = json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
