<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberModuleAccess;
use App\Models\MemberSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\MemberModuleAccessService;
use App\Services\PaymentService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class MemberModuleAccessValidityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function seller(): User
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $owner->forceFill(['tenant_id' => $owner->id])->save();

        return $owner->fresh();
    }

    private function memberProduct(User $owner): Product
    {
        return $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'mmval'.substr(uniqid('', true), -8),
            'slug' => 'mv-'.substr(uniqid('', true), -8),
        ]);
    }

    private function courseModule(Product $product, array $attrs = []): array
    {
        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);
        $module = MemberModule::create(array_merge([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo X',
            'position' => 1,
        ], $attrs));
        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 1',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => 'Conteúdo',
        ]);

        return [$section, $module->fresh(), $lesson];
    }

    private function enroll(Product $product, User $student, $createdAt = null): void
    {
        DB::table('product_user')->insert([
            'product_id' => $product->id,
            'user_id' => $student->id,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);
    }

    public function test_builder_saves_expire_and_renewal_price(): void
    {
        $owner = $this->seller();
        $product = $this->memberProduct($owner);
        [, $module] = $this->courseModule($product);

        $resp = $this->actingAs($owner)->putJson(route('member-builder.modules.update', [
            'produto' => $product->id,
            'module' => $module->id,
        ]), [
            'title' => 'Módulo X',
            'expire_after_days' => 365,
            'expire_at_date' => null,
            'renewal_price' => 47.9,
        ]);

        $resp->assertOk();
        $module = $module->fresh();
        $this->assertSame(365, $module->expire_after_days);
        $this->assertNull($module->expire_at_date);
        $this->assertEquals(47.9, (float) $module->renewal_price);
    }

    public function test_old_and_new_students_share_the_same_validity_rule(): void
    {
        $owner = $this->seller();
        $product = $this->memberProduct($owner);
        [, $module] = $this->courseModule($product, ['expire_after_days' => 365]);
        $service = app(MemberModuleAccessService::class);

        $old = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $new = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $this->enroll($product, $old, now()->subDays(400));
        $this->enroll($product, $new, now()->subDays(10));

        $oldLock = $service->moduleLockPayload($module, $product, $old, now());
        $newLock = $service->moduleLockPayload($module, $product, $new, now());

        $this->assertTrue($oldLock['is_locked']);
        $this->assertSame('expired', $oldLock['lock_reason']);
        $this->assertFalse($newLock['is_locked']);
        $this->assertNull($newLock['lock_reason']);
    }

    public function test_lesson_inherits_expired_module(): void
    {
        $owner = $this->seller();
        $product = $this->memberProduct($owner);
        [, $module, $lesson] = $this->courseModule($product, ['expire_after_days' => 30]);
        $student = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $this->enroll($product, $student, now()->subDays(40));

        $lock = app(MemberModuleAccessService::class)->lessonLockPayload($lesson, $module, $product, $student, now());
        $this->assertTrue($lock['is_locked']);
        $this->assertSame('expired', $lock['lock_reason']);
    }

    public function test_module_without_validity_stays_open(): void
    {
        $owner = $this->seller();
        $product = $this->memberProduct($owner);
        [, $module] = $this->courseModule($product);
        $student = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $this->enroll($product, $student, now()->subYears(3));

        $lock = app(MemberModuleAccessService::class)->moduleLockPayload($module, $product, $student, now());
        $this->assertFalse($lock['is_locked']);
    }

    public function test_platform_admin_unlocks_future_drip_and_expired_modules(): void
    {
        $owner = $this->seller();
        $product = $this->memberProduct($owner);
        [, $module, $lesson] = $this->courseModule($product, [
            'release_at_date' => now()->addMonth()->toDateString(),
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        $student = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $this->enroll($product, $student, now()->subDays(10));

        $service = app(MemberModuleAccessService::class);
        $adminModule = $service->moduleLockPayload($module, $product, $admin, now());
        $adminLesson = $service->lessonLockPayload($lesson, $module, $product, $admin, now());
        $studentModule = $service->moduleLockPayload($module, $product, $student, now());

        $this->assertFalse($adminModule['is_locked']);
        $this->assertFalse($adminLesson['is_locked']);
        $this->assertTrue($studentModule['is_locked']);
        $this->assertSame('drip', $studentModule['lock_reason']);
    }

    public function test_seller_can_inspect_expired_module(): void
    {
        $owner = $this->seller();
        $product = $this->memberProduct($owner);
        [, $module] = $this->courseModule($product, [
            'expire_after_days' => 1,
        ]);

        $lock = app(MemberModuleAccessService::class)->moduleLockPayload($module, $product, $owner, now());
        $this->assertFalse($lock['is_locked']);
        $this->assertFalse($lock['can_renew']);
    }

    public function test_can_renew_only_with_days_and_price(): void
    {
        $owner = $this->seller();
        $product = $this->memberProduct($owner);
        $student = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $this->enroll($product, $student, now()->subDays(400));
        $service = app(MemberModuleAccessService::class);

        [, $daysModule] = $this->courseModule($product, [
            'expire_after_days' => 365,
            'renewal_price' => 19.9,
        ]);
        $daysLock = $service->moduleLockPayload($daysModule, $product, $student, now());
        $this->assertTrue($daysLock['can_renew']);
        $this->assertEquals(19.9, $daysLock['renewal_amount']);

        $dateModule = MemberModule::create([
            'member_section_id' => $daysModule->member_section_id,
            'product_id' => $product->id,
            'title' => 'Data',
            'position' => 2,
            'expire_at_date' => now()->subDay()->toDateString(),
            'renewal_price' => 19.9,
        ]);
        $dateLock = $service->moduleLockPayload($dateModule, $product, $student, now());
        $this->assertTrue($dateLock['is_locked']);
        $this->assertFalse($dateLock['can_renew']);
    }

    public function test_renewal_restarts_days_from_payment_without_affecting_other_modules(): void
    {
        $owner = $this->seller();
        $product = $this->memberProduct($owner);
        [, $moduleA] = $this->courseModule($product, [
            'title' => 'A',
            'expire_after_days' => 30,
        ]);
        $moduleB = MemberModule::create([
            'member_section_id' => $moduleA->member_section_id,
            'product_id' => $product->id,
            'title' => 'B',
            'position' => 2,
            'expire_after_days' => 30,
        ]);
        $student = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $this->enroll($product, $student, now()->subDays(40));

        $service = app(MemberModuleAccessService::class);
        $this->assertTrue($service->moduleLockPayload($moduleA, $product, $student, now())['is_locked']);
        $this->assertTrue($service->moduleLockPayload($moduleB, $product, $student, now())['is_locked']);

        $order = Order::create([
            'tenant_id' => $owner->id,
            'user_id' => $student->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 19.9,
            'email' => $student->email,
            'payment_method' => 'pix',
            'metadata' => [
                MemberModuleAccessService::META_RENEWAL => true,
                MemberModuleAccessService::META_MODULE_ID => $moduleA->id,
            ],
        ]);
        event(new OrderCompleted($order));

        $service = app(MemberModuleAccessService::class);
        $lockA = $service->moduleLockPayload($moduleA->fresh(), $product, $student, now());
        $lockB = $service->moduleLockPayload($moduleB->fresh(), $product, $student, now());
        $this->assertFalse($lockA['is_locked']);
        $this->assertTrue($lockB['is_locked']);
        $this->assertTrue(MemberModuleAccess::query()->where('order_id', $order->id)->exists());
    }

    public function test_refunding_renewal_relocks_module_but_keeps_member_area(): void
    {
        $owner = $this->seller();
        $product = $this->memberProduct($owner);
        [, $module] = $this->courseModule($product, ['expire_after_days' => 10]);
        $student = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $this->enroll($product, $student, now()->subDays(20));

        $original = Order::create([
            'tenant_id' => $owner->id,
            'user_id' => $student->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 97,
            'email' => $student->email,
        ]);
        $renewal = Order::create([
            'tenant_id' => $owner->id,
            'user_id' => $student->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 19.9,
            'email' => $student->email,
            'metadata' => [
                MemberModuleAccessService::META_RENEWAL => true,
                MemberModuleAccessService::META_MODULE_ID => $module->id,
            ],
        ]);
        app(MemberModuleAccessService::class)->grantFromOrder($renewal);
        $this->assertFalse(app(MemberModuleAccessService::class)->moduleLockPayload($module, $product, $student, now())['is_locked']);

        $renewal->revokePurchasedProductAccessFromBuyer();

        $this->assertTrue($product->users()->where('user_id', $student->id)->exists());
        $this->assertTrue(app(MemberModuleAccessService::class)->moduleLockPayload($module->fresh(), $product, $student, now())['is_locked']);
        $this->assertTrue($original->exists);
    }

    public function test_member_area_hides_expired_module_and_blocks_complete_lesson(): void
    {
        $owner = $this->seller();
        $product = $this->memberProduct($owner);
        [, $module, $lesson] = $this->courseModule($product, ['expire_after_days' => 7]);
        $student = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $this->enroll($product, $student, now()->subDays(20));

        $show = $this->actingAs($student)->get(route('member-area-app.show', ['slug' => $product->checkout_slug]));
        $show->assertOk();
        $show->assertInertia(fn ($page) => $page
            ->where('sections.0.modules.0.is_locked', true)
            ->where('sections.0.modules.0.lock_reason', 'expired')
        );

        $complete = $this->actingAs($student)->postJson(route('member-area-app.lesson.complete', [
            'slug' => $product->checkout_slug,
            'lesson' => $lesson->id,
        ]));
        $complete->assertForbidden();
    }

    public function test_renew_pix_creates_order_for_expired_module(): void
    {
        $owner = $this->seller();
        $product = $this->memberProduct($owner);
        [, $module] = $this->courseModule($product, [
            'expire_after_days' => 30,
            'renewal_price' => 25.5,
        ]);
        $student = User::factory()->create(['role' => User::ROLE_CLIENTE, 'document' => '52998224725']);
        $this->enroll($product, $student, now()->subDays(40));

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createPixPayment')
            ->once()
            ->andReturn([
                'transaction_id' => 'tx-mod-1',
                'gateway' => 'fake',
                'qrcode' => 'qr-data',
                'copy_paste' => '00020126580014br.gov.bcb.pix',
            ]);
        $this->instance(PaymentService::class, $mock);

        $resp = $this->actingAs($student)->postJson(route('member-area-app.module.renew-pix', [
            'slug' => $product->checkout_slug,
            'module' => $module->id,
        ]));

        $resp->assertOk();
        $resp->assertJsonPath('copy_paste', '00020126580014br.gov.bcb.pix');
        $order = Order::query()->find($resp->json('order_id'));
        $this->assertNotNull($order);
        $this->assertTrue(MemberModuleAccessService::isRenewalOrder($order));
        $this->assertSame($module->id, MemberModuleAccessService::renewalModuleId($order));
        $this->assertEquals(25.5, (float) $order->amount);
    }
}
