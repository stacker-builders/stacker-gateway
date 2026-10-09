<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use App\Services\DeliverableAccessLinkService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemberAreaProductCardAccessTest extends TestCase
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

    public function test_home_product_cards_expose_member_area_access_url_not_hardcoded_path_only(): void
    {
        [$main, $related, $student] = $this->memberAreaWithRelatedProduct(Product::TYPE_AREA_MEMBROS);

        $this->actingAs($student)
            ->get(route('member-area-app.show', $main->checkout_slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MemberAreaApp/Show')
                ->has('sections.0.modules', 1)
                ->where('sections.0.modules.0.has_access', true)
                ->where('sections.0.modules.0.related_product.access_url', fn ($url) => is_string($url) && str_contains($url, '/m/'.$related->checkout_slug))
                ->missing('sections.0.modules.0.related_product.member_area_slug')
            );
    }

    public function test_home_product_cards_use_tracked_link_for_link_products(): void
    {
        [$main, $related, $student] = $this->memberAreaWithRelatedProduct(Product::TYPE_LINK, [
            'checkout_config' => [
                'deliverable_link' => 'https://conteudo.externo.test/curso',
            ],
        ]);

        $tracked = app(DeliverableAccessLinkService::class)->trackedUrl($student, $related);
        $this->assertIsString($tracked);

        $this->actingAs($student)
            ->get(route('member-area-app.show', $main->checkout_slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MemberAreaApp/Show')
                ->where('sections.0.modules.0.has_access', true)
                ->where('sections.0.modules.0.related_product.access_url', $tracked)
            );
    }

    public function test_module_content_accepts_string_product_id_mismatch_safe_cast(): void
    {
        [$main, , $student, $module] = $this->memberAreaWithCourseModule();

        $this->actingAs($student)
            ->get(route('member-area-app.module', [
                'slug' => $main->checkout_slug,
                'module' => $module->id,
            ]))
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $relatedOverrides
     * @return array{0: Product, 1: Product, 2: User}
     */
    private function memberAreaWithRelatedProduct(string $relatedType, array $relatedOverrides = []): array
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $owner->forceFill(['tenant_id' => $owner->id])->save();

        $main = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'main'.bin2hex(random_bytes(3)),
            'slug' => 'main'.bin2hex(random_bytes(3)),
        ]);

        $related = $this->createTestProduct(array_merge([
            'type' => $relatedType,
            'tenant_id' => $owner->id,
            'name' => 'Produto relacionado',
            'checkout_slug' => 'rel'.bin2hex(random_bytes(3)),
            'slug' => 'rel'.bin2hex(random_bytes(3)),
        ], $relatedOverrides));

        $section = MemberSection::create([
            'product_id' => $main->id,
            'title' => 'Outros produtos',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'products',
        ]);
        MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $main->id,
            'title' => 'Card produto',
            'position' => 1,
            'related_product_id' => $related->id,
            'access_type' => 'paid',
        ]);

        $student = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        DB::table('product_user')->insert([
            [
                'product_id' => $main->id,
                'user_id' => $student->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $related->id,
                'user_id' => $student->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return [$main, $related, $student];
    }

    /**
     * @return array{0: Product, 1: User, 2: User, 3: MemberModule}
     */
    private function memberAreaWithCourseModule(): array
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $owner->forceFill(['tenant_id' => $owner->id])->save();

        $main = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'crs'.bin2hex(random_bytes(3)),
            'slug' => 'crs'.bin2hex(random_bytes(3)),
        ]);

        $section = MemberSection::create([
            'product_id' => $main->id,
            'title' => 'Cursos',
            'position' => 1,
            'section_type' => 'courses',
        ]);
        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => (string) $main->id,
            'title' => 'Módulo',
            'position' => 1,
        ]);

        $student = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        DB::table('product_user')->insert([
            'product_id' => $main->id,
            'user_id' => $student->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$main, $owner, $student, $module];
    }
}
