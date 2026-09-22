<?php

namespace Tests\Feature;

use App\Models\BrandingSetting;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberCertificateConfigValidationTest extends TestCase
{
    public function test_member_area_certificate_payload_uses_custom_platform_name_when_set(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $student = User::factory()->create(['role' => User::ROLE_ALUNO]);

        BrandingSetting::query()->updateOrCreate(
            ['tenant_id' => null],
            ['data' => ['app_name' => 'Plataforma Global Teste']]
        );

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'member_area_config' => array_replace_recursive(Product::defaultMemberAreaConfig(), [
                'certificate' => [
                    'enabled' => true,
                    'platform_name' => 'Nome customizado do produto',
                ],
            ]),
        ]);
        $product->users()->syncWithoutDetaching([$student->id]);

        $this->actingAs($student)
            ->get(route('member-area-app.certificado', ['slug' => $product->checkout_slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('certificate.platform_name', 'Nome customizado do produto')
                ->where('certificate.header_text', 'Certificado de conclusão')
            );
    }

    public function test_member_area_certificate_payload_falls_back_to_global_platform_name(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $student = User::factory()->create(['role' => User::ROLE_ALUNO]);

        BrandingSetting::query()->updateOrCreate(
            ['tenant_id' => null],
            ['data' => ['app_name' => 'Plataforma Global Teste']]
        );

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'member_area_config' => array_replace_recursive(Product::defaultMemberAreaConfig(), [
                'certificate' => [
                    'enabled' => true,
                    'platform_name' => '',
                ],
            ]),
        ]);
        $product->users()->syncWithoutDetaching([$student->id]);

        $this->actingAs($student)
            ->get(route('member-area-app.certificado', ['slug' => $product->checkout_slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('certificate.platform_name', 'Plataforma Global Teste')
            );
    }

    public function test_member_builder_requires_duration_when_duration_enabled(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'name' => 'Curso Teste Certificado',
        ]);

        $payload = [
            'member_area_config' => array_replace_recursive(Product::defaultMemberAreaConfig(), [
                'certificate' => [
                    'enabled' => true,
                    'title' => '',
                    'signature_text' => '',
                    'duration_enabled' => true,
                    'duration_text' => '',
                ],
            ]),
        ];

        $this->actingAs($seller)
            ->postJson(route('member-builder.config.update.post', ['produto' => $product->id]), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['member_area_config.certificate.duration_text']);
    }

    public function test_member_builder_allows_empty_duration_when_duration_disabled(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'name' => 'Curso Sem Carga Horária',
        ]);

        $payload = [
            'member_area_config' => array_replace_recursive(Product::defaultMemberAreaConfig(), [
                'certificate' => [
                    'enabled' => true,
                    'title' => '',
                    'signature_text' => '',
                    'duration_enabled' => false,
                    'duration_text' => '',
                ],
            ]),
        ];

        $this->actingAs($seller)
            ->postJson(route('member-builder.config.update.post', ['produto' => $product->id]), $payload)
            ->assertOk();

        $product->refresh();
        $cert = $product->member_area_config['certificate'] ?? [];

        $this->assertFalse((bool) ($cert['duration_enabled'] ?? true));
        $this->assertSame('', $cert['duration_text'] ?? null);
    }

    public function test_member_builder_saves_certificate_when_enabled_with_auto_defaults(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'name' => 'Curso Salvar Certificado',
        ]);

        $payload = [
            'member_area_config' => array_replace_recursive(Product::defaultMemberAreaConfig(), [
                'certificate' => [
                    'enabled' => true,
                    'title' => '',
                    'signature_text' => '',
                    'duration_text' => '40 horas',
                ],
            ]),
        ];

        $this->actingAs($seller)
            ->postJson(route('member-builder.config.update.post', ['produto' => $product->id]), $payload)
            ->assertOk();

        $product->refresh();
        $cert = $product->member_area_config['certificate'] ?? [];

        $this->assertTrue((bool) ($cert['enabled'] ?? false));
        $this->assertSame('Curso Salvar Certificado', $cert['title']);
        $this->assertSame('Instrutor', $cert['signature_text']);
        $this->assertSame('Certificado de conclusão', $cert['header_text']);
    }

    public function test_member_builder_persists_certificate_layout_positions(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'name' => 'Curso Layout Certificado',
        ]);

        $payload = [
            'member_area_config' => array_replace_recursive(Product::defaultMemberAreaConfig(), [
                'certificate' => [
                    'enabled' => true,
                    'title' => 'Layout Test',
                    'signature_text' => 'Diretor',
                    'duration_text' => '20 horas',
                    'layout' => [
                        'background_only' => true,
                        'custom_positions' => true,
                        'fields' => [
                            'header' => ['visible' => false, 'x' => 10, 'y' => 20, 'w' => 50, 'align' => 'left'],
                            'title' => ['visible' => true, 'x' => 55, 'y' => 30, 'w' => 60, 'align' => 'center'],
                            'body' => ['visible' => true, 'x' => 50, 'y' => 55, 'w' => 80, 'align' => 'center'],
                            'date' => ['visible' => true, 'x' => 20, 'y' => 72, 'w' => 35, 'align' => 'left'],
                            'duration' => ['visible' => false, 'x' => 80, 'y' => 72, 'w' => 30, 'align' => 'right'],
                            'signature' => ['visible' => true, 'x' => 15, 'y' => 88, 'w' => 30, 'align' => 'left'],
                            'platform' => ['visible' => true, 'x' => 85, 'y' => 88, 'w' => 30, 'align' => 'right'],
                        ],
                    ],
                ],
            ]),
        ];

        $this->actingAs($seller)
            ->postJson(route('member-builder.config.update.post', ['produto' => $product->id]), $payload)
            ->assertOk();

        $product->refresh();
        $layout = $product->member_area_config['certificate']['layout'] ?? [];

        $this->assertTrue((bool) ($layout['background_only'] ?? false));
        $this->assertTrue((bool) ($layout['custom_positions'] ?? false));
        $this->assertFalse((bool) ($layout['fields']['header']['visible'] ?? true));
        $this->assertSame(10.0, (float) ($layout['fields']['header']['x'] ?? 0));
        $this->assertSame(20.0, (float) ($layout['fields']['header']['y'] ?? 0));
        $this->assertSame('left', $layout['fields']['header']['align'] ?? null);
        $this->assertSame(55.0, (float) ($layout['fields']['title']['x'] ?? 0));
        $this->assertSame(20.0, (float) ($layout['fields']['date']['x'] ?? 0));
        $this->assertFalse((bool) ($layout['fields']['duration']['visible'] ?? true));
    }
}

