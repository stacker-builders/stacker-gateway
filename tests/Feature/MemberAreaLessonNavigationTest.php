<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\MemberAreaDomain;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemberAreaLessonNavigationTest extends TestCase
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

    public function test_inertia_complete_stays_on_lesson_instead_of_previous_purchases_page(): void
    {
        [, $product, $student, $lesson] = $this->enrolledStudent();

        $expected = route('member-area-app.module', [
            'slug' => $product->checkout_slug,
            'module' => $lesson->member_module_id,
            'aula' => $lesson->id,
        ]);

        $this->actingAs($student)
            ->from('/painel-cliente')
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->post(route('member-area-app.lesson.complete', [
                'slug' => $product->checkout_slug,
                'lesson' => $lesson->id,
            ]))
            ->assertRedirect($expected);
    }

    public function test_json_complete_does_not_redirect(): void
    {
        [, $product, $student, $lesson] = $this->enrolledStudent();

        $this->actingAs($student)
            ->from('/painel-cliente')
            ->postJson(route('member-area-app.lesson.complete', [
                'slug' => $product->checkout_slug,
                'lesson' => $lesson->id,
            ]))
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_locked_lesson_on_path_redirects_to_path_module_not_host_route(): void
    {
        [, $product, $student, $unlocked] = $this->enrolledStudent();
        $module = $unlocked->module;
        $locked = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula bloqueada',
            'position' => 2,
            'type' => MemberLesson::TYPE_VIDEO,
            'content_url' => 'https://www.youtube.com/watch?v=dQw4w9wgGcQ',
            'release_after_days' => 30,
        ]);

        $response = $this->actingAs($student)->get(route('member-area-app.module', [
            'slug' => $product->checkout_slug,
            'module' => $module->id,
            'aula' => $locked->id,
        ]));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringContainsString('/m/'.$product->checkout_slug.'/modulo/'.$module->id, $location);
        $this->assertStringContainsString('aula='.$unlocked->id, $location);
        $parsed = parse_url($location);
        $this->assertNotSame('/modulo/'.$module->id, $parsed['path'] ?? '');
    }

    public function test_locked_module_redirects_to_member_area_home_not_customer_panel(): void
    {
        [, $product, $student, $lesson] = $this->enrolledStudent();
        $lesson->module->update(['release_after_days' => 30]);

        $this->actingAs($student)
            ->from('/painel-cliente')
            ->get(route('member-area-app.module', [
                'slug' => $product->checkout_slug,
                'module' => $lesson->member_module_id,
            ]))
            ->assertRedirect(route('member-area-app.show', $product->checkout_slug));
    }

    public function test_custom_domain_module_content_does_not_type_error_with_product_route_param(): void
    {
        [, $product, $student, $lesson] = $this->enrolledStudent();

        MemberAreaDomain::create([
            'product_id' => $product->id,
            'type' => MemberAreaDomain::TYPE_CUSTOM,
            'value' => 'area-curso.test',
        ]);

        $this->actingAs($student)
            ->get('http://area-curso.test/modulo/'.$lesson->member_module_id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MemberAreaApp/ModuleContent')
                ->where('module.id', $lesson->member_module_id)
            );
    }

    /**
     * @return array{0: User, 1: Product, 2: User, 3: MemberLesson}
     */
    private function enrolledStudent(): array
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
            'checkout_slug' => 'mnav'.bin2hex(random_bytes(4)),
            'slug' => 'mnav'.bin2hex(random_bytes(4)),
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
            'title' => 'Aula 1',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => 'Conteúdo',
        ]);

        $student = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
        ]);
        DB::table('product_user')->insert([
            'product_id' => $product->id,
            'user_id' => $student->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        return [$owner->fresh(), $product, $student, $lesson->fresh(['module'])];
    }
}
