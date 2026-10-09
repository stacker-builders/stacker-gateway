<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\MemberStudentActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\DeliverableAccessLinkService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class DeliverableAccessRedirectTest extends TestCase
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

    public function test_tracked_link_hides_destination_then_redirects_and_logs_ip(): void
    {
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_LINK,
            'checkout_slug' => 'curso-link',
            'checkout_config' => [
                'deliverable_link' => 'https://conteudo.externo.test/meu-curso',
            ],
        ]);
        Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $buyer->email,
        ]);

        $url = app(DeliverableAccessLinkService::class)->trackedUrl($buyer, $product);
        $this->assertIsString($url);
        $this->assertDoesNotMatchRegularExpression('#conteudo\.externo\.test#', $url);
        $this->assertMatchesRegularExpression('#/a/[a-z0-9]{12}$#', $url);
        $ref = substr($url, -12);

        $this->get(route('deliverable.access', ['ref' => $ref]))
            ->assertOk()
            ->assertSee('Abrindo o conteúdo')
            ->assertDontSee('conteudo.externo.test', false);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
            ->get(route('deliverable.access.go', ['ref' => $ref]))
            ->assertRedirect('https://conteudo.externo.test/meu-curso');

        $this->assertDatabaseHas('member_student_activity_logs', [
            'user_id' => $buyer->id,
            'product_id' => (string) $product->id,
            'event' => MemberStudentActivityLog::EVENT_EXTERNAL_LINK_CLICKED,
            'ip' => '203.0.113.44',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->get(route('deliverable.access.go', ['ref' => $ref]))
            ->assertRedirect('https://conteudo.externo.test/meu-curso');

        $this->assertSame(2, MemberStudentActivityLog::query()
            ->where('user_id', $buyer->id)
            ->where('event', MemberStudentActivityLog::EVENT_EXTERNAL_LINK_CLICKED)
            ->count());
        $this->assertCount(2, MemberStudentActivityLog::query()
            ->where('user_id', $buyer->id)
            ->where('event', MemberStudentActivityLog::EVENT_EXTERNAL_LINK_CLICKED)
            ->pluck('ip')
            ->unique()
            ->values());
    }

    public function test_unknown_ref_is_not_found(): void
    {
        $this->get(route('deliverable.access', ['ref' => 'abcdefghjkmn']))->assertNotFound();
    }

    public function test_go_still_redirects_when_click_logging_throws(): void
    {
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_LINK,
            'checkout_slug' => 'curso-link-throw',
            'checkout_config' => [
                'deliverable_link' => 'https://conteudo.externo.test/com-erro-log',
            ],
        ]);
        Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $buyer->email,
        ]);

        $url = app(DeliverableAccessLinkService::class)->trackedUrl($buyer, $product);
        $this->assertIsString($url);
        $ref = substr($url, -12);

        $this->mock(DeliverableAccessLinkService::class, function ($mock) use ($buyer, $product) {
            $mock->shouldReceive('resolveRef')
                ->once()
                ->andReturn([
                    'user' => $buyer,
                    'product' => $product,
                    'destination' => 'https://conteudo.externo.test/com-erro-log',
                ]);
            $mock->shouldReceive('recordClick')
                ->once()
                ->andThrow(new \RuntimeException('falha ao gravar clique'));
        });

        $this->get(route('deliverable.access.go', ['ref' => $ref]))
            ->assertRedirect('https://conteudo.externo.test/com-erro-log');
    }
}
