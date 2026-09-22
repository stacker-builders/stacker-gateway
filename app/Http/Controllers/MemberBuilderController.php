<?php

namespace App\Http\Controllers;

use App\Models\MemberAreaDomain;
use App\Models\MemberComment;
use App\Models\MemberCommunityPage;
use App\Models\MemberCommunityPost;
use App\Models\MemberInternalProduct;
use App\Models\MemberLesson;
use App\Models\MemberLessonProgress;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\MemberTurma;
use App\Models\Product;
use App\Models\User;
use App\Models\BrandingSetting;
use App\Models\MemberNotification;
use App\Models\MemberPushSubscription;
use App\Http\Middleware\ApplyBrandingConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use App\Services\MemberAreaResolver;
use App\Services\MemberAccessGrantService;
use App\Services\MemberStudentAccountService;
use App\Services\MemberCommentService;
use App\Services\StorageService;
use App\Services\GamificationService;
use App\Services\MemberProgressService;
use App\Services\TeamAccessService;
use App\Support\MemberAreaPwaIconUrls;
use App\Support\MemberLessonMaterialUpload;
use App\Support\PublicAppUrl;
use App\Support\UploadLimits;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class MemberBuilderController extends Controller
{
    private function normalizeLessonContentFiles(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $out = [];
        foreach ($input as $item) {
            if (is_string($item)) {
                $url = trim($item);
                if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                    $out[] = ['url' => $url, 'name' => 'Material'];
                }
                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $url = isset($item['url']) ? trim((string) $item['url']) : '';
            $name = isset($item['name']) ? trim((string) $item['name']) : '';
            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $out[] = [
                'url' => $url,
                'name' => $name !== '' ? mb_substr($name, 0, 255) : 'Material',
            ];
        }

        return array_slice($out, 0, 30);
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleAccessScheduleRules(): array
    {
        return [
            'expire_after_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'expire_at_date' => ['nullable', 'date_format:Y-m-d'],
            'renewal_price' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeModuleAccessSchedule(array $validated, bool $forceExpire): array
    {
        if ($forceExpire || array_key_exists('expire_at_date', $validated) || array_key_exists('expire_after_days', $validated)) {
            $date = $validated['expire_at_date'] ?? null;
            $days = $validated['expire_after_days'] ?? null;
            if (! empty($date)) {
                $validated['expire_at_date'] = $date;
                $validated['expire_after_days'] = null;
            } elseif (! empty($days)) {
                $validated['expire_after_days'] = (int) $days;
                $validated['expire_at_date'] = null;
            } else {
                $validated['expire_after_days'] = null;
                $validated['expire_at_date'] = null;
            }
        }
        if (array_key_exists('renewal_price', $validated)) {
            $price = $validated['renewal_price'];
            $validated['renewal_price'] = ($price !== null && $price !== '' && (float) $price > 0)
                ? round((float) $price, 2)
                : null;
        }

        return $validated;
    }

    public function __construct(
        protected MemberCommentService $commentService,
        protected GamificationService $gamificationService,
        protected MemberProgressService $memberProgressService
    ) {}

    public function index(Request $request, Product $produto): View|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($produto->type !== Product::TYPE_AREA_MEMBROS) {
            return redirect()->route('produtos.edit', $produto)->with('error', 'Member Builder só está disponível para produtos do tipo Área de membros.');
        }

        $produto->refresh();
        $produto->load([
            'memberAreaDomain',
            'memberSections.modules.lessons',
            'memberSections.modules.relatedProduct',
            'memberInternalProducts.relatedProduct',
            'memberTurmas.users:id,name,email',
            'memberCommunityPages',
        ]);

        // Garantir que exista ao menos a "Turma padrão"
        if ($produto->memberTurmas->isEmpty()) {
            MemberTurma::create([
                'product_id' => $produto->id,
                'name' => 'Turma padrão',
                'position' => 1,
            ]);
            $produto->load('memberTurmas.users:id,name,email');
        }

        $memberAreaUrl = app(MemberAreaResolver::class)->baseUrlForProduct($produto);

        $appUrl = rtrim(PublicAppUrl::base(), '/');
        $appHost = parse_url($appUrl, PHP_URL_HOST) ?: request()->getHost();
        $dnsTargetHost = $appHost;
        $dnsTargetIp = env('MEMBER_AREA_IP');
        if (empty($dnsTargetIp) && ! empty($dnsTargetHost)) {
            if (filter_var($dnsTargetHost, FILTER_VALIDATE_IP)) {
                $dnsTargetIp = $dnsTargetHost;
            } else {
                $resolved = gethostbyname($dnsTargetHost);
                $resolvedIp = ($resolved !== $dnsTargetHost) ? $resolved : null;
                $dnsTargetIp = $resolvedIp && ! $this->isCloudflareIp($resolvedIp) ? $resolvedIp : null;
            }
        }

        $memberStorage = new StorageService($produto->tenant_id);
        $memberAreaConfigForFront = $memberStorage->resolveMediaUrlsInConfig($produto->member_area_config) ?? [];
        if (isset($memberAreaConfigForFront['pwa'])) {
            unset($memberAreaConfigForFront['pwa']['vapid_private']);
        }

        $productUsers = $produto->users()->select('users.id', 'users.name', 'users.email')->orderBy('users.name')->get()->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ])->values()->all();

        $totalLessons = $this->memberProgressService->totalLessonsCount($produto);
        $completedByUserId = MemberLessonProgress::query()
            ->where('product_id', $produto->id)
            ->whereNotNull('completed_at')
            ->selectRaw('user_id, COUNT(*) as cnt')
            ->groupBy('user_id')
            ->get()
            ->pluck('cnt', 'user_id')
            ->map(fn ($n) => (int) $n)
            ->all();

        $studentProgress = [];
        foreach ($productUsers as $u) {
            $completed = (int) ($completedByUserId[$u['id']] ?? 0);
            $percent = $totalLessons === 0
                ? 100
                : (int) min(100, round(($completed / $totalLessons) * 100));
            $studentProgress[] = [
                'id' => $u['id'],
                'name' => $u['name'],
                'email' => $u['email'],
                'completed_count' => $completed,
                'total_lessons' => $totalLessons,
                'percent' => $percent,
            ];
        }

        $produtoPayload = [
            'id' => $produto->id,
            'name' => $produto->name,
            'checkout_slug' => $produto->checkout_slug,
            'type' => $produto->type,
            'member_area_config' => $memberAreaConfigForFront,
            'member_area_url' => $memberAreaUrl,
            'member_area_domain' => $produto->memberAreaDomain ? [
                'type' => $this->memberAreaDomainTypeForFront($produto->memberAreaDomain),
                'value' => $this->memberAreaDomainValueForFront($produto->memberAreaDomain, $produto),
            ] : null,
            'sections' => $produto->memberSections->map(fn (MemberSection $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'position' => $s->position,
                'cover_mode' => $s->cover_mode ?? 'vertical',
                'section_type' => $s->section_type ?? 'courses',
                'modules' => $s->modules->map(function (MemberModule $m) use ($memberStorage) {
                    $base = [
                        'id' => $m->id,
                        'title' => $m->title,
                        'position' => $m->position,
                        'thumbnail' => $m->thumbnail ? $memberStorage->resolvePublicUrl($m->thumbnail) : null,
                        'show_title_on_cover' => $m->show_title_on_cover ?? true,
                        'release_after_days' => $m->release_after_days,
                        'release_at_date' => $m->release_at_date?->format('Y-m-d'),
                        'expire_after_days' => $m->expire_after_days,
                        'expire_at_date' => $m->expire_at_date?->format('Y-m-d'),
                        'renewal_price' => $m->renewal_price !== null ? (float) $m->renewal_price : null,
                        'lessons' => $m->lessons->map(fn (MemberLesson $l) => [
                            'id' => $l->id,
                            'title' => $l->title,
                            'position' => $l->position,
                            'type' => $l->type,
                            'content_url' => $l->content_url,
                            'link_title' => $l->link_title,
                            'content_files' => $l->content_files,
                            'release_after_days' => $l->release_after_days,
                            'release_at_date' => $l->release_at_date?->format('Y-m-d'),
                            'content_text' => \App\Support\HtmlSanitizer::sanitize($l->content_text),
                            'duration_seconds' => $l->duration_seconds,
                            'is_free' => $l->is_free,
                            'watermark_enabled' => (bool) ($l->watermark_enabled ?? false),
                        ])->values()->all(),
                    ];
                    $extra = array_filter([
                        'related_product_id' => $m->related_product_id,
                        'access_type' => $m->access_type,
                        'external_url' => $m->external_url,
                        'related_product' => $m->relatedProduct ? [
                            'id' => $m->relatedProduct->id,
                            'name' => $m->relatedProduct->name,
                            'image_url' => $m->relatedProduct->image ? app(StorageService::class)->url($m->relatedProduct->image) : null,
                        ] : null,
                    ]);
                    return array_merge($base, $extra);
                })->values()->all(),
            ])->values()->all(),
            'internal_products' => $produto->memberInternalProducts->map(fn (MemberInternalProduct $ip) => [
                'id' => $ip->id,
                'related_product_id' => $ip->related_product_id,
                'position' => $ip->position,
                'related_product' => $ip->relatedProduct ? [
                    'id' => $ip->relatedProduct->id,
                    'name' => $ip->relatedProduct->name,
                    'image_url' => $ip->relatedProduct->image ? app(StorageService::class)->url($ip->relatedProduct->image) : null,
                ] : null,
            ])->values()->all(),
            'turmas' => $produto->memberTurmas->map(fn (MemberTurma $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'start_date' => $t->start_date?->format('Y-m-d'),
                'end_date' => $t->end_date?->format('Y-m-d'),
                'position' => $t->position,
                'users_count' => $t->users()->count(),
                'users' => $t->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values()->all(),
            ])->values()->all(),
            'product_users' => $productUsers,
            'total_lessons' => $totalLessons,
            'student_progress' => $studentProgress,
            'community_pages' => $produto->memberCommunityPages->map(fn (MemberCommunityPage $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'icon' => $p->icon,
                'slug' => $p->slug,
                'banner' => $p->banner,
                'banner_url' => $p->banner ? app(StorageService::class)->url($p->banner) : null,
                'position' => $p->position,
                'is_public_posting' => $p->is_public_posting,
                'is_default' => (bool) ($p->is_default ?? false),
            ])->values()->all(),
            'push_subscribers_count' => MemberPushSubscription::where('product_id', $produto->id)->count(),
            'comments' => MemberComment::forProduct($produto->id)
                ->with(['user:id,name,email', 'lesson:id,title', 'reviewer:id,name'])
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (MemberComment $c) => [
                    'id' => $c->id,
                    'content' => $c->content,
                    'status' => $c->status,
                    'created_at' => $c->created_at->format('d/m/Y H:i'),
                    'user' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name, 'email' => $c->user->email] : null,
                    'lesson' => $c->lesson ? ['id' => $c->lesson->id, 'title' => $c->lesson->title] : null,
                    'reviewer' => $c->reviewer ? ['id' => $c->reviewer->id, 'name' => $c->reviewer->name] : null,
                ])
                ->values()
                ->all(),
        ];

        $tenantProductsQuery = Product::forTenant($produto->tenant_id)
            ->where('id', '!=', $produto->id)
            ->orderBy('name');
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $tenantProductsQuery->whereIn('id', $allowed ?: ['__none__']);
        }
        $tenant_products = $tenantProductsQuery->get(['id', 'name', 'image'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'image_url' => $p->image ? app(StorageService::class)->url($p->image) : null,
            ])->values()->all();

        return view('member-builder', [
            'produto' => $produtoPayload,
            'tenant_products' => $tenant_products,
            'app_url' => rtrim(PublicAppUrl::base(), '/'),
            'dns_target_host' => $dnsTargetHost,
            'dns_target_ip' => $dnsTargetIp,
            'upload_limits' => UploadLimits::memberBuilderForFrontend(),
            'platform_app_name' => $this->platformAppNameForBuilder($request),
        ]);
    }

    public function updateConfig(Request $request, Product $produto): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($produto->type !== Product::TYPE_AREA_MEMBROS) {
            abort(403);
        }

        $validated = $request->validate([
            'member_area_config' => ['required', 'array'],
            'member_area_config.login.password_mode' => ['nullable', 'string', 'in:auto,default'],
            'member_area_config.login.default_password' => ['nullable', 'string', 'max:255'],
            'member_area_config.login.login_without_password' => ['nullable', 'boolean'],
            'domain_type' => ['nullable', 'string', 'in:path,custom'],
            'domain_value' => ['nullable', 'string', 'max:255'],
        ]);

        $domainType = $request->input('domain_type'); // ler direto para não perder quando enviado pelo front
        $domainValue = $request->input('domain_value');
        if ($domainType !== null && $domainType !== '') {
            $domainType = (string) $domainType;
        } else {
            $domainType = $validated['domain_type'] ?? null;
            $domainValue = $validated['domain_value'] ?? null;
        }

        if ($domainType === 'path' && $domainValue !== null) {
            $pathSegment = trim((string) $domainValue) !== '' ? trim($domainValue) : $produto->checkout_slug;
            $pathSegment = strtolower($pathSegment);
            if (! preg_match('/^[a-z0-9]{6,16}$/', $pathSegment)) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'O segmento do path deve ter entre 6 e 16 caracteres (apenas letras minúsculas e números).',
                    ], 422);
                }
                return back()->with('error', 'O segmento do path deve ter entre 6 e 16 caracteres (apenas letras minúsculas e números).');
            }
            $conflictProduct = Product::where('checkout_slug', $pathSegment)
                ->where('type', Product::TYPE_AREA_MEMBROS)
                ->where('id', '!=', $produto->id)
                ->exists();
            $conflictDomain = MemberAreaDomain::where('type', MemberAreaDomain::TYPE_PATH)
                ->where('value', $pathSegment)
                ->where('product_id', '!=', $produto->id)
                ->exists();
            if ($conflictProduct || $conflictDomain) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Este segmento de URL já está em uso por outra área de membros.',
                    ], 422);
                }
                return back()->with('error', 'Este segmento de URL já está em uso por outra área de membros.');
            }
        }

        // Usar input() para garantir o payload completo (validated() em alguns contextos pode devolver só chaves validadas)
        $incoming = $request->input('member_area_config', []);
        if (! is_array($incoming)) {
            $incoming = [];
        }
        // Mesclar config atual com a enviada (preserva vapid_private que não vem do front)
        $config = array_replace_recursive($produto->member_area_config ?? [], $incoming);
        // sidebar.items: substituir por completo (array_replace_recursive mantém índices antigos ao remover itens)
        if (isset($incoming['sidebar']['items']) && is_array($incoming['sidebar']['items'])) {
            $config['sidebar'] = $config['sidebar'] ?? [];
            $config['sidebar']['items'] = $this->normalizeSidebarMenuItems($incoming['sidebar']['items']);
        }
        // gamification.achievements: substituir por completo
        if (isset($incoming['gamification']['achievements']) && is_array($incoming['gamification']['achievements'])) {
            $config['gamification'] = $config['gamification'] ?? ['enabled' => false, 'achievements' => []];
            $config['gamification']['achievements'] = array_values($incoming['gamification']['achievements']);
        }

        $this->normalizeCertificateConfig($config, $produto);
        $this->validateCertificateConfig($config);

        $pwa = $config['pwa'] ?? [];
        $vapidWarning = null;
        if (! empty($pwa['push_enabled'])) {
            if (empty($pwa['vapid_public'] ?? null) || empty($pwa['vapid_private'] ?? null)) {
                try {
                    $keys = VAPID::createVapidKeys();
                    $config['pwa']['vapid_public'] = $keys['publicKey'];
                    $config['pwa']['vapid_private'] = $keys['privateKey'];
                } catch (\Throwable $e) {
                    // Fallback: no Windows/XAMPP o openssl_pkey_new pode falhar por falta de openssl.cnf; gerar via CLI
                    $keys = $this->createVapidKeysViaOpensslCli();
                    if ($keys !== null) {
                        $config['pwa']['vapid_public'] = $keys['publicKey'];
                        $config['pwa']['vapid_private'] = $keys['privateKey'];
                    } else {
                        $existing = $produto->member_area_config['pwa'] ?? [];
                        $config['pwa']['vapid_public'] = $config['pwa']['vapid_public'] ?? $existing['vapid_public'] ?? null;
                        $config['pwa']['vapid_private'] = $existing['vapid_private'] ?? null;
                        $vapidWarning = 'Configuração salva, mas não foi possível gerar chaves para notificações push. Verifique se a extensão OpenSSL do PHP está habilitada e suporta chaves EC (P-256), ou se o binário openssl está no PATH.';
                    }
                }
            } else {
                $config['pwa']['vapid_private'] = $produto->member_area_config['pwa']['vapid_private'] ?? $config['pwa']['vapid_private'];
            }
        }
        $produto->update(['member_area_config' => $config]);

        \Illuminate\Support\Facades\Log::info('MemberBuilder updateConfig', [
            'product_id' => $produto->id,
            'updated' => true,
        ]);

        if ($domainType !== null) {
            $value = $domainType === 'path'
                ? (trim((string) ($domainValue ?? '')) !== '' ? strtolower(trim($domainValue)) : $produto->checkout_slug)
                : $domainValue;
            if ($domainType === 'path' && ($value === null || $value === '')) {
                $value = $produto->checkout_slug;
            }
            if ($domainType === 'custom' && $value !== null && $value !== '') {
                $value = MemberAreaDomain::normalizeCustomHost($value);
                if ($value === null || ! filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => 'Informe um domínio ou subdomínio válido (apenas o host, sem https:// e sem /path).',
                        ], 422);
                    }
                    return back()->with('error', 'Informe um domínio ou subdomínio válido (apenas o host, sem https:// e sem /path).');
                }
                $conflictCustom = MemberAreaDomain::where('type', MemberAreaDomain::TYPE_CUSTOM)
                    ->where('value', $value)
                    ->where('product_id', '!=', $produto->id)
                    ->exists();
                if ($conflictCustom) {
                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => 'Este domínio já está vinculado a outra área de membros.',
                        ], 422);
                    }
                    return back()->with('error', 'Este domínio já está vinculado a outra área de membros.');
                }
            }
            MemberAreaDomain::updateOrCreate(
                ['product_id' => $produto->id],
                ['type' => $domainType, 'value' => $value]
            );
        }

        if ($request->expectsJson()) {
            $json = ['message' => 'Configuração salva.'];
            if ($vapidWarning !== null) {
                $json['warning'] = $vapidWarning;
            }
            return response()->json($json);
        }

        return back()->with('success', 'Configuração salva.');
    }

    public function uploadImage(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);
        $maxKb = UploadLimits::memberBuilderImageMaxKb();
        $maxMb = UploadLimits::memberBuilderImageMaxMb();
        UploadLimits::assertUploadedFileIsValid($request->file('file'), $maxMb);
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:'.$maxKb],
        ], [
            'file.required' => 'Nenhum arquivo enviado.',
            'file.image' => 'O arquivo deve ser uma imagem (JPG, PNG, GIF ou WebP).',
            'file.max' => "A imagem deve ter no máximo {$maxMb} MB.",
        ]);
        $storage = app(StorageService::class);
        $path = $storage->putFile('member-area/' . $produto->id, $request->file('file'));
        return response()->json(['url' => $storage->url($path), 'path' => $path]);
    }

    public function uploadPdf(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);
        $maxKb = UploadLimits::memberBuilderPdfMaxKb();
        $maxMb = UploadLimits::memberBuilderPdfMaxMb();
        UploadLimits::assertUploadedFileIsValid($request->file('file'), $maxMb);
        $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb],
        ], [
            'file.required' => 'Nenhum arquivo enviado.',
            'file.max' => "O arquivo deve ter no máximo {$maxMb} MB.",
        ]);
        $file = $request->file('file');
        $resolved = MemberLessonMaterialUpload::assertValid($file);
        $safeName = MemberLessonMaterialUpload::safeStoredName(
            (string) $file->getClientOriginalName(),
            $resolved['extension']
        );
        $storage = app(StorageService::class);
        $path = $storage->putFileAs(
            'member-area/' . $produto->id,
            $file,
            $safeName,
            MemberLessonMaterialUpload::storageUploadOptions($safeName, $resolved['mime'])
        );
        return response()->json(['url' => $storage->url($path), 'path' => $path]);
    }

    public function uploadBadge(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);
        if ($produto->type !== Product::TYPE_AREA_MEMBROS) {
            abort(403);
        }
        $maxKb = UploadLimits::memberBuilderBadgeMaxKb();
        $maxMb = UploadLimits::memberBuilderBadgeMaxMb();
        UploadLimits::assertUploadedFileIsValid($request->file('file'), $maxMb);
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:'.$maxKb],
        ], [
            'file.required' => 'Nenhum arquivo enviado.',
            'file.image' => 'O arquivo deve ser uma imagem (JPG, PNG, GIF ou WebP).',
            'file.max' => "A imagem da badge deve ter no máximo {$maxMb} MB.",
        ]);
        $storage = app(StorageService::class);
        $path = $storage->putFile('member-area-gamification/' . $produto->id . '/badges', $request->file('file'));
        return response()->json(['url' => $storage->url($path), 'path' => $path]);
    }

    // Sections
    public function storeSection(Request $request, Product $produto): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'cover_mode' => ['nullable', 'string', 'in:vertical,horizontal'],
            'section_type' => ['nullable', 'string', 'in:courses,products,external_links'],
        ]);
        $max = MemberSection::where('product_id', $produto->id)->max('position') ?? 0;
        MemberSection::create([
            'product_id' => $produto->id,
            'title' => $validated['title'],
            'position' => $max + 1,
            'cover_mode' => $validated['cover_mode'] ?? 'vertical',
            'section_type' => $validated['section_type'] ?? 'courses',
        ]);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Seção criada.']);
        }
        return back()->with('success', 'Seção criada.');
    }

    public function updateSection(Request $request, Product $produto, MemberSection $section): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($section->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'cover_mode' => ['sometimes', 'string', 'in:vertical,horizontal'],
            'section_type' => ['sometimes', 'string', 'in:courses,products,external_links'],
        ]);
        $section->update($validated);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Seção atualizada.']);
        }
        return back()->with('success', 'Seção atualizada.');
    }

    public function destroySection(Request $request, Product $produto, MemberSection $section): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($section->product_id !== $produto->id) {
            abort(404);
        }
        $section->delete();
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Seção removida.']);
        }
        return back()->with('success', 'Seção removida.');
    }

    /**
     * Reordena seções do produto, módulos dentro de uma seção ou aulas dentro de um módulo (transação única).
     *
     * JSON: { "scope": "sections"|"modules"|"lessons", "ordered_ids": int[], "section_id"?: int, "module_id"?: int }
     * `ordered_ids` deve conter exatamente os IDs esperados neste contexto, na nova ordem (posições 1…n).
     */
    public function reorder(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);
        if ($produto->type !== Product::TYPE_AREA_MEMBROS) {
            abort(403);
        }

        $validated = $request->validate([
            'scope' => ['required', 'string', Rule::in(['sections', 'modules', 'lessons'])],
            'ordered_ids' => ['present', 'array'],
            'ordered_ids.*' => ['integer'],
        ]);

        if ($validated['scope'] === 'modules') {
            $validated = array_merge($validated, $request->validate([
                'section_id' => ['required', 'integer'],
            ]));
        } elseif ($validated['scope'] === 'lessons') {
            $validated = array_merge($validated, $request->validate([
                'module_id' => ['required', 'integer'],
            ]));
        }

        $orderedIds = array_values(array_map(static fn ($id) => (int) $id, $validated['ordered_ids']));

        DB::transaction(function () use ($produto, $validated, $orderedIds): void {
            match ($validated['scope']) {
                'sections' => $this->applyMemberSectionReorder($produto, $orderedIds),
                'modules' => $this->applyMemberModuleReorder($produto, (int) $validated['section_id'], $orderedIds),
                'lessons' => $this->applyMemberLessonReorder($produto, (int) $validated['module_id'], $orderedIds),
            };
        });

        return response()->json(['message' => 'Ordem atualizada.']);
    }

    /** @param  array<int>  $orderedIds */
    private function applyMemberSectionReorder(Product $produto, array $orderedIds): void
    {
        $existing = MemberSection::query()
            ->where('product_id', $produto->id)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
        $this->assertSameMemberReorderIdSet($orderedIds, $existing);

        foreach ($orderedIds as $index => $id) {
            MemberSection::query()->where('product_id', $produto->id)->whereKey($id)->update(['position' => $index + 1]);
        }
    }

    /** @param  array<int>  $orderedIds */
    private function applyMemberModuleReorder(Product $produto, int $sectionId, array $orderedIds): void
    {
        $section = MemberSection::query()
            ->where('product_id', $produto->id)
            ->whereKey($sectionId)
            ->firstOrFail();

        $existing = MemberModule::query()
            ->where('product_id', $produto->id)
            ->where('member_section_id', $section->id)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
        $this->assertSameMemberReorderIdSet($orderedIds, $existing);

        foreach ($orderedIds as $index => $id) {
            MemberModule::query()
                ->where('product_id', $produto->id)
                ->where('member_section_id', $section->id)
                ->whereKey($id)
                ->update(['position' => $index + 1]);
        }
    }

    /** @param  array<int>  $orderedIds */
    private function applyMemberLessonReorder(Product $produto, int $moduleId, array $orderedIds): void
    {
        $module = MemberModule::query()
            ->where('product_id', $produto->id)
            ->whereKey($moduleId)
            ->firstOrFail();

        $existing = MemberLesson::query()
            ->where('product_id', $produto->id)
            ->where('member_module_id', $module->id)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
        $this->assertSameMemberReorderIdSet($orderedIds, $existing);

        foreach ($orderedIds as $index => $id) {
            MemberLesson::query()
                ->where('product_id', $produto->id)
                ->where('member_module_id', $module->id)
                ->whereKey($id)
                ->update(['position' => $index + 1]);
        }
    }

    /**
     * @param  array<int>  $orderedIds
     * @param  array<int>  $existingIds
     */
    private function assertSameMemberReorderIdSet(array $orderedIds, array $existingIds): void
    {
        if (count($orderedIds) !== count(array_unique($orderedIds))) {
            throw ValidationException::withMessages([
                'ordered_ids' => ['IDs duplicados não são permitidos.'],
            ]);
        }
        $a = $existingIds;
        $b = $orderedIds;
        sort($a);
        sort($b);
        if ($a !== $b) {
            throw ValidationException::withMessages([
                'ordered_ids' => ['A lista não corresponde aos itens deste contexto.'],
            ]);
        }
    }

    // Modules
    public function storeModule(Request $request, Product $produto, MemberSection $section): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($section->product_id !== $produto->id) {
            abort(404);
        }
        $sectionType = $section->section_type ?? 'courses';

        if ($sectionType === 'courses') {
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'show_title_on_cover' => ['nullable', 'boolean'],
                'release_after_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'release_at_date' => ['nullable', 'date_format:Y-m-d'],
                ...$this->moduleAccessScheduleRules(),
            ]);
            if (! empty($validated['release_at_date'] ?? null)) {
                $validated['release_after_days'] = null;
            } elseif (empty($validated['release_after_days'] ?? null)) {
                $validated['release_after_days'] = null;
                $validated['release_at_date'] = null;
            } else {
                $validated['release_at_date'] = null;
            }
            $validated = $this->normalizeModuleAccessSchedule($validated, true);
            $max = MemberModule::where('member_section_id', $section->id)->max('position') ?? 0;
            $module = MemberModule::create([
                'member_section_id' => $section->id,
                'product_id' => $produto->id,
                'title' => $validated['title'],
                'position' => $max + 1,
                'show_title_on_cover' => $validated['show_title_on_cover'] ?? true,
                'release_after_days' => $validated['release_after_days'] ?? null,
                'release_at_date' => $validated['release_at_date'] ?? null,
                'expire_after_days' => $validated['expire_after_days'] ?? null,
                'expire_at_date' => $validated['expire_at_date'] ?? null,
                'renewal_price' => $validated['renewal_price'] ?? null,
            ]);
        } elseif ($sectionType === 'products') {
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'related_product_id' => ['required', 'exists:products,id'],
                'access_type' => ['required', 'string', 'in:paid,free'],
                'thumbnail' => ['nullable', 'string', 'max:500'],
                'show_title_on_cover' => ['nullable', 'boolean'],
            ]);
            $related = Product::find($validated['related_product_id']);
            if ($related->tenant_id !== $produto->tenant_id) {
                abort(403);
            }
            if ((int) $validated['related_product_id'] === $produto->id) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Não é possível referenciar o próprio produto.'], 422);
                }
                return back()->with('error', 'Não é possível referenciar o próprio produto.');
            }
            $max = MemberModule::where('member_section_id', $section->id)->max('position') ?? 0;
            $thumbPath = array_key_exists('thumbnail', $validated)
                ? (new StorageService($produto->tenant_id))->toStoragePath($validated['thumbnail'])
                : null;
            $module = MemberModule::create([
                'member_section_id' => $section->id,
                'product_id' => $produto->id,
                'title' => $validated['title'],
                'position' => $max + 1,
                'related_product_id' => $validated['related_product_id'],
                'access_type' => $validated['access_type'],
                'thumbnail' => $thumbPath,
                'show_title_on_cover' => $validated['show_title_on_cover'] ?? true,
            ]);
        } else {
            // external_links
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'external_url' => ['required', 'url', 'max:500'],
                'thumbnail' => ['nullable', 'string', 'max:500'],
                'show_title_on_cover' => ['nullable', 'boolean'],
            ]);
            $max = MemberModule::where('member_section_id', $section->id)->max('position') ?? 0;
            $thumbPath = array_key_exists('thumbnail', $validated)
                ? (new StorageService($produto->tenant_id))->toStoragePath($validated['thumbnail'])
                : null;
            $module = MemberModule::create([
                'member_section_id' => $section->id,
                'product_id' => $produto->id,
                'title' => $validated['title'],
                'position' => $max + 1,
                'external_url' => $validated['external_url'],
                'thumbnail' => $thumbPath,
                'show_title_on_cover' => $validated['show_title_on_cover'] ?? true,
            ]);
        }

        if ($request->expectsJson()) {
            $thumbStorage = new StorageService($produto->tenant_id);
            $payload = [
                'id' => $module->id,
                'title' => $module->title,
                'position' => $module->position,
                'thumbnail' => $module->thumbnail ? $thumbStorage->resolvePublicUrl($module->thumbnail) : null,
                'show_title_on_cover' => $module->show_title_on_cover ?? true,
                'release_after_days' => $module->release_after_days,
                'release_at_date' => $module->release_at_date?->format('Y-m-d'),
                'expire_after_days' => $module->expire_after_days,
                'expire_at_date' => $module->expire_at_date?->format('Y-m-d'),
                'renewal_price' => $module->renewal_price !== null ? (float) $module->renewal_price : null,
                'lessons' => $module->relationLoaded('lessons') ? $module->lessons->map(fn (MemberLesson $l) => [
                    'id' => $l->id,
                    'title' => $l->title,
                    'position' => $l->position,
                    'type' => $l->type,
                    'content_url' => $l->content_url,
                    'content_text' => \App\Support\HtmlSanitizer::sanitize($l->content_text),
                    'duration_seconds' => $l->duration_seconds,
                    'is_free' => $l->is_free,
                    'watermark_enabled' => (bool) ($l->watermark_enabled ?? false),
                ])->values()->all() : [],
            ];
            if ($module->related_product_id) {
                $module->load('relatedProduct');
                $payload['related_product_id'] = $module->related_product_id;
                $payload['access_type'] = $module->access_type;
                $payload['related_product'] = $module->relatedProduct ? [
                    'id' => $module->relatedProduct->id,
                    'name' => $module->relatedProduct->name,
                    'image_url' => $module->relatedProduct->image ? app(StorageService::class)->url($module->relatedProduct->image) : null,
                ] : null;
            }
            if ($module->external_url) {
                $payload['external_url'] = $module->external_url;
            }
            return response()->json(['message' => 'Módulo criado.', 'module' => $payload]);
        }
        return back()->with('success', 'Módulo criado.');
    }

    public function updateModule(Request $request, Product $produto, MemberModule $module): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($module->product_id !== $produto->id) {
            abort(404);
        }
        $section = $module->section;
        $sectionType = $section->section_type ?? 'courses';

        if ($sectionType === 'courses') {
            $validated = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'position' => ['sometimes', 'integer', 'min:0'],
                'thumbnail' => ['nullable', 'string', 'max:500'],
                'show_title_on_cover' => ['sometimes', 'boolean'],
                'release_after_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'release_at_date' => ['nullable', 'date_format:Y-m-d'],
                ...$this->moduleAccessScheduleRules(),
            ]);
            if (array_key_exists('release_at_date', $validated) || array_key_exists('release_after_days', $validated)) {
                $date = $validated['release_at_date'] ?? null;
                $days = $validated['release_after_days'] ?? null;
                if (! empty($date)) {
                    $validated['release_at_date'] = $date;
                    $validated['release_after_days'] = null;
                } elseif (! empty($days)) {
                    $validated['release_after_days'] = (int) $days;
                    $validated['release_at_date'] = null;
                } else {
                    $validated['release_after_days'] = null;
                    $validated['release_at_date'] = null;
                }
            }
            $validated = $this->normalizeModuleAccessSchedule($validated, false);
        } elseif ($sectionType === 'products') {
            $validated = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'position' => ['sometimes', 'integer', 'min:0'],
                'related_product_id' => ['sometimes', 'exists:products,id'],
                'access_type' => ['sometimes', 'string', 'in:paid,free'],
                'thumbnail' => ['nullable', 'string', 'max:500'],
                'show_title_on_cover' => ['sometimes', 'boolean'],
            ]);
            if (isset($validated['related_product_id'])) {
                $related = Product::find($validated['related_product_id']);
                if ($related->tenant_id !== $produto->tenant_id) {
                    abort(403);
                }
                if ((int) $validated['related_product_id'] === $produto->id) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Não é possível referenciar o próprio produto.'], 422);
                    }
                    return back()->with('error', 'Não é possível referenciar o próprio produto.');
                }
            }
        } else {
            $validated = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'position' => ['sometimes', 'integer', 'min:0'],
                'external_url' => ['sometimes', 'url', 'max:500'],
                'thumbnail' => ['nullable', 'string', 'max:500'],
                'show_title_on_cover' => ['sometimes', 'boolean'],
            ]);
        }
        if (array_key_exists('thumbnail', $validated)) {
            $validated['thumbnail'] = (new StorageService($produto->tenant_id))->toStoragePath($validated['thumbnail']);
        }
        $module->update($validated);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Módulo atualizado.']);
        }
        return back()->with('success', 'Módulo atualizado.');
    }

    public function destroyModule(Request $request, Product $produto, MemberModule $module): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($module->product_id !== $produto->id) {
            abort(404);
        }
        $module->delete();
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Módulo removido.']);
        }
        return back()->with('success', 'Módulo removido.');
    }

    // Lessons
    public function storeLesson(Request $request, Product $produto, MemberModule $module): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($module->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:video,link,pdf,text'],
            'content_url' => ['nullable', 'string', 'max:2000'],
            'link_title' => ['nullable', 'string', 'max:255'],
            'content_files' => ['nullable', 'array', 'max:30'],
            'content_files.*.url' => ['nullable', 'string', 'url', 'max:2000'],
            'content_files.*.name' => ['nullable', 'string', 'max:255'],
            'release_after_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'release_at_date' => ['nullable', 'date_format:Y-m-d'],
            'content_text' => ['nullable', 'string'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'is_free' => ['boolean'],
            'watermark_enabled' => ['boolean'],
        ]);
        if (! empty($validated['release_at_date'] ?? null)) {
            $validated['release_after_days'] = null;
        } elseif (empty($validated['release_after_days'] ?? null)) {
            $validated['release_after_days'] = null;
            $validated['release_at_date'] = null;
        } else {
            $validated['release_at_date'] = null;
        }
        $contentFiles = $this->normalizeLessonContentFiles($request->input('content_files'));
        if (($validated['type'] ?? null) === 'pdf' && empty($validated['content_url']) && count($contentFiles) > 0) {
            $validated['content_url'] = $contentFiles[0]['url'];
        }
        $max = MemberLesson::where('member_module_id', $module->id)->max('position') ?? 0;
        MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $produto->id,
            'title' => $validated['title'],
            'position' => $max + 1,
            'type' => $validated['type'],
            'content_url' => $validated['content_url'] ?? null,
            'link_title' => $validated['link_title'] ?? null,
            'content_files' => $validated['type'] === 'pdf' ? ($contentFiles !== [] ? $contentFiles : null) : null,
            'release_after_days' => $validated['release_after_days'] ?? null,
            'release_at_date' => $validated['release_at_date'] ?? null,
            'content_text' => isset($validated['content_text'])
                ? \App\Support\HtmlSanitizer::sanitize($validated['content_text'])
                : null,
            'duration_seconds' => $validated['duration_seconds'] ?? null,
            'is_free' => $request->boolean('is_free', false),
            'watermark_enabled' => $request->boolean('watermark_enabled', false),
        ]);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Aula criada.']);
        }
        return back()->with('success', 'Aula criada.');
    }

    public function updateLesson(Request $request, Product $produto, MemberLesson $lesson): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($lesson->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'type' => ['sometimes', 'string', 'in:video,link,pdf,text'],
            'content_url' => ['nullable', 'string', 'max:2000'],
            'link_title' => ['nullable', 'string', 'max:255'],
            'content_files' => ['nullable', 'array', 'max:30'],
            'content_files.*.url' => ['nullable', 'string', 'url', 'max:2000'],
            'content_files.*.name' => ['nullable', 'string', 'max:255'],
            'release_after_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'release_at_date' => ['nullable', 'date_format:Y-m-d'],
            'content_text' => ['nullable', 'string'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'is_free' => ['boolean'],
            'watermark_enabled' => ['boolean'],
        ]);
        if (isset($validated['is_free'])) {
            $validated['is_free'] = $request->boolean('is_free');
        }
        if (array_key_exists('watermark_enabled', $validated)) {
            $validated['watermark_enabled'] = $request->boolean('watermark_enabled');
        }
        if (array_key_exists('release_at_date', $validated) || array_key_exists('release_after_days', $validated)) {
            $date = $validated['release_at_date'] ?? null;
            $days = $validated['release_after_days'] ?? null;
            if (! empty($date)) {
                $validated['release_at_date'] = $date;
                $validated['release_after_days'] = null;
            } elseif (! empty($days)) {
                $validated['release_after_days'] = (int) $days;
                $validated['release_at_date'] = null;
            } else {
                $validated['release_after_days'] = null;
                $validated['release_at_date'] = null;
            }
        }
        $type = $validated['type'] ?? $lesson->type;
        $contentFiles = $this->normalizeLessonContentFiles($request->input('content_files'));
        if ($type === 'pdf') {
            if (count($contentFiles) > 0) {
                $validated['content_files'] = $contentFiles;
                if (empty($validated['content_url'])) {
                    $validated['content_url'] = $contentFiles[0]['url'];
                }
            } elseif (array_key_exists('content_files', $validated)) {
                $validated['content_files'] = null;
            }
        } else {
            if (array_key_exists('content_files', $validated)) {
                $validated['content_files'] = null;
            }
        }
        if (array_key_exists('content_text', $validated)) {
            $validated['content_text'] = \App\Support\HtmlSanitizer::sanitize($validated['content_text']);
        }
        $lesson->update($validated);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Aula atualizada.']);
        }
        return back()->with('success', 'Aula atualizada.');
    }

    public function destroyLesson(Request $request, Product $produto, MemberLesson $lesson): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($lesson->product_id !== $produto->id) {
            abort(404);
        }
        $lesson->delete();
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Aula removida.']);
        }
        return back()->with('success', 'Aula removida.');
    }

    // Internal products
    public function storeInternalProduct(Request $request, Product $produto): RedirectResponse
    {
        $this->authorizeProduct($produto);
        $validated = $request->validate(['related_product_id' => ['required', 'exists:products,id']]);
        if ((int) $validated['related_product_id'] === $produto->id) {
            return back()->with('error', 'Não é possível adicionar o próprio produto.');
        }
        $related = Product::find($validated['related_product_id']);
        if ($related->tenant_id !== $produto->tenant_id) {
            abort(403);
        }
        $max = MemberInternalProduct::where('product_id', $produto->id)->max('position') ?? 0;
        MemberInternalProduct::firstOrCreate(
            ['product_id' => $produto->id, 'related_product_id' => $validated['related_product_id']],
            ['position' => $max + 1]
        );
        return back()->with('success', 'Produto adicionado à loja interna.');
    }

    public function destroyInternalProduct(Product $produto, MemberInternalProduct $internalProduct): RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($internalProduct->product_id !== $produto->id) {
            abort(404);
        }
        $internalProduct->delete();
        return back()->with('success', 'Produto removido da loja interna.');
    }

    // Turmas
    public function storeTurma(Request $request, Product $produto): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
        $max = MemberTurma::where('product_id', $produto->id)->max('position') ?? 0;
        $turma = MemberTurma::create([
            'product_id' => $produto->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'position' => $max + 1,
        ]);
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Turma criada.',
                'turma' => [
                    'id' => $turma->id,
                    'name' => $turma->name,
                    'description' => $turma->description,
                    'start_date' => $turma->start_date?->format('Y-m-d'),
                    'end_date' => $turma->end_date?->format('Y-m-d'),
                    'users' => [],
                ],
            ]);
        }

        return back()->with('success', 'Turma criada.');
    }

    public function updateTurma(Request $request, Product $produto, MemberTurma $turma): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($turma->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);
        $turma->update($validated);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Turma atualizada.', 'turma' => [
                'id' => $turma->id,
                'name' => $turma->name,
            ]]);
        }

        return back()->with('success', 'Turma atualizada.');
    }

    public function destroyTurma(Request $request, Product $produto, MemberTurma $turma): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($turma->product_id !== $produto->id) {
            abort(404);
        }
        $turma->delete();
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Turma removida.']);
        }

        return back()->with('success', 'Turma removida.');
    }

    public function attachTurmaUser(Request $request, Product $produto, MemberTurma $turma): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($turma->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate(['user_id' => ['required', 'exists:users,id']]);
        $turma->users()->syncWithoutDetaching([$validated['user_id']]);
        if ($request->expectsJson()) {
            $user = User::query()->select('id', 'name', 'email')->find($validated['user_id']);

            return response()->json([
                'message' => 'Aluno adicionado à turma.',
                'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
            ]);
        }

        return back()->with('success', 'Aluno adicionado à turma.');
    }

    public function detachTurmaUser(Request $request, Product $produto, MemberTurma $turma, int $user): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($turma->product_id !== $produto->id) {
            abort(404);
        }
        $turma->users()->detach($user);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Aluno removido da turma.']);
        }

        return back()->with('success', 'Aluno removido da turma.');
    }

    /**
     * Criar novo aluno ou reutilizar cliente existente, dar acesso ao produto e opcionalmente adicionar à turma.
     */
    public function storeNewAluno(
        Request $request,
        Product $produto,
        MemberAccessGrantService $memberAccessGrant,
        MemberStudentAccountService $memberStudentAccount
    ): JsonResponse|RedirectResponse {
        $this->authorizeProduct($produto);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
            'turma_id' => ['nullable', 'integer', 'exists:member_turmas,id'],
        ]);
        $turmaId = $validated['turma_id'] ?? null;
        if ($turmaId) {
            $turma = MemberTurma::find($turmaId);
            if (! $turma || $turma->product_id !== $produto->id) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Turma inválida.'], 422);
                }
                return back()->with('error', 'Turma inválida.');
            }
        }

        $resolved = $memberStudentAccount->resolveOrCreateCliente(
            $validated['email'],
            $validated['name'],
            $validated['password'] ?? null
        );
        $user = $resolved['user'];
        $memberAccessGrant->grant($user, $produto);
        if ($turmaId) {
            MemberTurma::find($turmaId)->users()->syncWithoutDetaching([$user->id]);
        }

        $message = $resolved['created']
            ? 'Aluno criado e adicionado.'
            : 'Usuário já existente. Acesso ao produto concedido.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'created' => $resolved['created'],
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            ]);
        }
        return back()->with('success', $message);
    }

    // Comments
    public function commentsIndex(Request $request, Product $produto): Response
    {
        $this->authorizeProduct($produto);
        $status = $request->query('status', 'pending');
        $comments = MemberComment::forProduct($produto->id)
            ->when($status !== 'all', fn ($q) => $q->status($status))
            ->with(['user:id,name,email', 'lesson:id,title', 'reviewer:id,name'])
            ->latest()
            ->paginate(20);
        return Inertia::render('Produtos/MemberBuilder/Comments', [
            'produto' => ['id' => $produto->id, 'name' => $produto->name],
            'comments' => $comments,
            'status_filter' => $status,
        ]);
    }

    public function updateComment(Request $request, Product $produto, MemberComment $comment): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($comment->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate(['status' => ['required', 'string', 'in:approved,rejected']]);
        if ($validated['status'] === 'approved') {
            $this->commentService->approve($comment, $request->user());
            $comment->user && $this->gamificationService->checkAndUnlock($produto, $comment->user);
        } else {
            $this->commentService->reject($comment, $request->user());
        }
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Comentário atualizado.']);
        }
        return back()->with('success', 'Comentário atualizado.');
    }

    // Community pages
    public function storeCommunityPage(Request $request, Product $produto): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);

        if ($response = $this->ensureCommunityPagesTableReady($request)) {
            return $response;
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'slug' => ['nullable', 'string', 'max:255'],
            'banner' => ['nullable', 'string', 'max:500'],
            'is_public_posting' => ['boolean'],
            'is_default' => ['boolean'],
        ]);

        try {
            if ($request->boolean('is_default')) {
                MemberCommunityPage::where('product_id', $produto->id)->update(['is_default' => false]);
            }
            $max = MemberCommunityPage::where('product_id', $produto->id)->max('position') ?? 0;

            MemberCommunityPage::create([
                'product_id' => $produto->id,
                'title' => $validated['title'],
                'icon' => $validated['icon'] ?? null,
                'slug' => $validated['slug'] ?? null,
                'banner' => $validated['banner'] ?? null,
                'position' => $max + 1,
                'is_public_posting' => $request->boolean('is_public_posting', true),
                'is_default' => $request->boolean('is_default', false),
            ]);
        } catch (QueryException $e) {
            return $this->communityPageSaveErrorResponse($request, $this->communityPageDatabaseErrorMessage($e));
        } catch (\Throwable $e) {
            Log::error('member_builder.community_page_store_failed', [
                'product_id' => $produto->id,
                'message' => $e->getMessage(),
            ]);

            return $this->communityPageSaveErrorResponse($request, 'Não foi possível criar a página da comunidade. Tente novamente.');
        }

        return $this->communityPageSuccessResponse(
            $request,
            $produto,
            'Página da comunidade criada.',
            'Página da comunidade criada.'
        );
    }

    public function updateCommunityPage(Request $request, Product $produto, MemberCommunityPage $page): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ((string) $page->product_id !== (string) $produto->id) {
            abort(404);
        }

        if ($response = $this->ensureCommunityPagesTableReady($request)) {
            return $response;
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'slug' => ['nullable', 'string', 'max:255'],
            'banner' => ['nullable', 'string', 'max:500'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'is_public_posting' => ['boolean'],
            'is_default' => ['boolean'],
        ]);

        try {
            if ($request->boolean('is_default')) {
                MemberCommunityPage::where('product_id', $produto->id)->where('id', '!=', $page->id)->update(['is_default' => false]);
            }

            $page->update($validated);
        } catch (QueryException $e) {
            return $this->communityPageSaveErrorResponse($request, $this->communityPageDatabaseErrorMessage($e));
        } catch (\Throwable $e) {
            Log::error('member_builder.community_page_update_failed', [
                'product_id' => $produto->id,
                'page_id' => $page->id,
                'message' => $e->getMessage(),
            ]);

            return $this->communityPageSaveErrorResponse($request, 'Não foi possível atualizar a página da comunidade. Tente novamente.');
        }

        return $this->communityPageSuccessResponse(
            $request,
            $produto,
            'Página atualizada.',
            'Página atualizada.'
        );
    }

    public function destroyCommunityPage(Request $request, Product $produto, MemberCommunityPage $page): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ((string) $page->product_id !== (string) $produto->id) {
            abort(404);
        }

        if ($response = $this->ensureCommunityPagesTableReady($request)) {
            return $response;
        }

        try {
            $page->delete();
        } catch (\Throwable $e) {
            Log::error('member_builder.community_page_destroy_failed', [
                'product_id' => $produto->id,
                'page_id' => $page->id,
                'message' => $e->getMessage(),
            ]);

            return $this->communityPageSaveErrorResponse($request, 'Não foi possível remover a página da comunidade. Tente novamente.');
        }

        return $this->communityPageSuccessResponse(
            $request,
            $produto,
            'Página removida.',
            'Página removida.'
        );
    }

    /**
     * @return array<int, array{id: int, title: string, icon: string|null, slug: string|null, banner: string|null, banner_url: string|null, position: int, is_public_posting: bool}>
     */
    private function buildCommunityPagesPayload(Product $produto): array
    {
        $produto->load('memberCommunityPages');
        $storage = new StorageService($produto->tenant_id);

        return $produto->memberCommunityPages->map(fn (MemberCommunityPage $p) => [
            'id' => $p->id,
            'title' => $p->title,
            'icon' => $p->icon,
            'slug' => $p->slug,
            'banner' => $p->banner,
            'banner_url' => $p->banner ? $storage->url($p->banner) : null,
            'position' => $p->position,
            'is_public_posting' => $p->is_public_posting,
            'is_default' => (bool) ($p->is_default ?? false),
        ])->values()->all();
    }

    private function ensureCommunityPagesTableReady(Request $request): JsonResponse|RedirectResponse|null
    {
        if (Schema::hasTable('member_community_pages')) {
            return null;
        }

        return $this->communityPageSaveErrorResponse(
            $request,
            'A comunidade ainda não está disponível neste ambiente. Execute as migrações do banco (php artisan migrate) e tente novamente.'
        );
    }

    private function communityPageDatabaseErrorMessage(QueryException $e): string
    {
        $message = strtolower($e->getMessage());
        $previous = $e->getPrevious();
        if ($previous instanceof \Throwable) {
            $message .= ' '.strtolower($previous->getMessage());
        }

        if (str_contains($message, 'base table or view not found')
            || str_contains($message, 'doesn\'t exist')
            || str_contains($message, '42s02')) {
            return 'A tabela da comunidade não existe neste banco. Execute php artisan migrate e tente novamente.';
        }

        if (str_contains($message, 'unknown column')) {
            return 'O banco de dados está desatualizado para a comunidade. Execute php artisan migrate e tente novamente.';
        }

        if (str_contains($message, 'data truncated') && str_contains($message, 'product_id')) {
            return 'O banco precisa de ajuste nas tabelas da área de membros. Execute php artisan migrate (migration fix_member_tables_product_id_uuid) e tente novamente.';
        }

        if (str_contains($message, 'duplicate') || str_contains($message, '1062')) {
            return 'Não foi possível salvar a página. Verifique se já existe uma página com o mesmo identificador.';
        }

        return 'Não foi possível salvar a página da comunidade. Verifique se o banco está atualizado (php artisan migrate) e tente novamente.';
    }

    private function communityPageSuccessResponse(
        Request $request,
        Product $produto,
        string $jsonMessage,
        string $flashMessage
    ): JsonResponse|RedirectResponse {
        try {
            $communityPages = $this->buildCommunityPagesPayload($produto);
        } catch (\Throwable $e) {
            Log::error('member_builder.community_pages_payload_failed', [
                'product_id' => $produto->id,
                'message' => $e->getMessage(),
            ]);

            return $this->communityPageSaveErrorResponse(
                $request,
                'A página foi salva, mas não foi possível atualizar a lista. Recarregue a página.'
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $jsonMessage,
                'community_pages' => $communityPages,
            ]);
        }

        return back()->with('success', $flashMessage);
    }

    private function communityPageSaveErrorResponse(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->with('error', $message);
    }

    public function sendPushNotification(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);
        if ($produto->type !== Product::TYPE_AREA_MEMBROS) {
            abort(403);
        }
        $config = $produto->member_area_config;
        $pwa = $config['pwa'] ?? [];
        if (! ((bool) ($pwa['push_enabled'] ?? false))) {
            return response()->json(['message' => 'Notificações push não estão habilitadas para esta área.'], 403);
        }
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:200'],
        ]);
        $vapidPublic = $produto->member_area_config['pwa']['vapid_public'] ?? null;
        $vapidPrivate = $produto->member_area_config['pwa']['vapid_private'] ?? null;
        if (! $vapidPublic || ! $vapidPrivate) {
            return response()->json(['message' => 'Gere as chaves VAPID: ative as notificações push e salve a configuração.'], 400);
        }
        $subscriptions = MemberPushSubscription::where('product_id', $produto->id)->get();
        $sent = 0;
        $subject = 'mailto:' . (config('mail.from.address') ?: 'noreply@' . parse_url(config('app.url'), PHP_URL_HOST));
        $auth = [
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ],
        ];
        $icon = MemberAreaPwaIconUrls::notificationIconUrl($request, $produto);
        $payload = json_encode([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'icon' => $icon,
            'badge' => $icon,
        ]);
        $userIdsSent = [];
        try {
            $webPush = new WebPush($auth);
            foreach ($subscriptions as $sub) {
                $keys = $sub->keys ?? [];
                $authKey = trim((string) ($keys['auth'] ?? ''));
                $p256dh = trim((string) ($keys['p256dh'] ?? ''));
                if (! $sub->endpoint || ! $authKey || ! $p256dh) {
                    continue;
                }
                $subscription = Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys' => [
                        'auth' => $this->normalizeBase64KeyForPush($authKey),
                        'p256dh' => $this->normalizeBase64KeyForPush($p256dh),
                    ],
                ]);
                $report = $webPush->sendOneNotification($subscription, $payload);
                if ($report->isSuccess()) {
                    $sent++;
                    if ($sub->user_id) {
                        $userIdsSent[$sub->user_id] = true;
                    }
                }
            }
            foreach (array_keys($userIdsSent) as $userId) {
                MemberNotification::create([
                    'product_id' => $produto->id,
                    'user_id' => $userId,
                    'type' => 'push',
                    'title' => $validated['title'],
                    'body' => $validated['body'],
                ]);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Erro ao enviar: ' . $e->getMessage()], 500);
        }
        return response()->json([
            'success' => true,
            'sent' => $sent,
            'message' => $sent === 0
                ? 'Nenhum destinatário inscrito ou falha no envio.'
                : "Notificação enviada para {$sent} destinatário(s).",
        ]);
    }

    private function normalizeBase64KeyForPush(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return $key;
        }
        if (str_contains($key, '+') || str_contains($key, '/')) {
            return strtr($key, ['+' => '-', '/' => '_']);
        }
        return $key;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function normalizeCertificateConfig(array &$config, Product $produto): void
    {
        $certificate = $config['certificate'] ?? [];
        if (! is_array($certificate)) {
            $certificate = [];
        }

        $textKeys = [
            'title',
            'signature_text',
            'duration_text',
            'platform_name',
            'header_text',
            'recipient_intro_text',
            'completion_text',
            'issued_on_text',
            'instructor_label_text',
            'platform_label_text',
            'duration_label_text',
            'body_template',
        ];

        foreach ($textKeys as $key) {
            $certificate[$key] = trim((string) Arr::get($certificate, $key, ''));
        }

        $fontScale = (int) Arr::get($certificate, 'font_scale', 100);
        $certificate['font_scale'] = max(75, min(150, $fontScale > 0 ? $fontScale : 100));
        $certificate['duration_enabled'] = array_key_exists('duration_enabled', $certificate)
            ? (bool) $certificate['duration_enabled']
            : true;
        $certificate['layout'] = $this->normalizeCertificateLayout(
            is_array($certificate['layout'] ?? null) ? $certificate['layout'] : []
        );

        if ((bool) ($certificate['enabled'] ?? false)) {
            $defaults = Product::defaultMemberAreaConfig()['certificate'] ?? [];
            if (trim((string) ($certificate['title'] ?? '')) === '') {
                $certificate['title'] = trim((string) $produto->name) !== '' ? trim((string) $produto->name) : 'Certificado';
            }
            if (trim((string) ($certificate['signature_text'] ?? '')) === '') {
                $certificate['signature_text'] = 'Instrutor';
            }
            foreach ([
                'header_text',
                'recipient_intro_text',
                'completion_text',
                'issued_on_text',
                'instructor_label_text',
                'platform_label_text',
                'duration_label_text',
            ] as $key) {
                if (trim((string) ($certificate[$key] ?? '')) === '') {
                    $certificate[$key] = trim((string) ($defaults[$key] ?? ''));
                }
            }
        }

        $config['certificate'] = $certificate;
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array{background_only: bool, custom_positions: bool, fields: array<string, array{visible: bool, x: float, y: float, w: float, align: string}>}
     */
    private function normalizeCertificateLayout(array $layout): array
    {
        $defaults = Product::defaultMemberAreaConfig()['certificate']['layout'] ?? [];
        $defaultFields = is_array($defaults['fields'] ?? null) ? $defaults['fields'] : [];
        $fieldIds = ['header', 'title', 'body', 'date', 'duration', 'signature', 'platform'];
        $aligns = ['left', 'center', 'right'];
        $incomingFields = is_array($layout['fields'] ?? null) ? $layout['fields'] : [];
        $fields = [];

        foreach ($fieldIds as $id) {
            $def = is_array($defaultFields[$id] ?? null) ? $defaultFields[$id] : [
                'visible' => true,
                'x' => 50,
                'y' => 50,
                'w' => 70,
                'align' => 'center',
            ];
            $src = is_array($incomingFields[$id] ?? null) ? $incomingFields[$id] : [];
            $align = (string) ($src['align'] ?? $def['align'] ?? 'center');
            if (! in_array($align, $aligns, true)) {
                $align = 'center';
            }
            $fields[$id] = [
                'visible' => array_key_exists('visible', $src) ? (bool) $src['visible'] : (bool) ($def['visible'] ?? true),
                'x' => max(0, min(100, (float) ($src['x'] ?? $def['x'] ?? 50))),
                'y' => max(0, min(100, (float) ($src['y'] ?? $def['y'] ?? 50))),
                'w' => max(10, min(100, (float) ($src['w'] ?? $def['w'] ?? 70))),
                'align' => $align,
            ];
        }

        return [
            'background_only' => (bool) ($layout['background_only'] ?? $defaults['background_only'] ?? false),
            'custom_positions' => (bool) ($layout['custom_positions'] ?? $defaults['custom_positions'] ?? false),
            'fields' => $fields,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateCertificateConfig(array $config): void
    {
        $certificate = $config['certificate'] ?? [];
        if (! is_array($certificate)) {
            return;
        }
        if (! ((bool) ($certificate['enabled'] ?? false))) {
            return;
        }

        $required = [
            'title' => 'Nome do certificado',
            'signature_text' => 'Texto da assinatura',
        ];

        if ((bool) ($certificate['duration_enabled'] ?? true)) {
            $required['duration_text'] = 'Duração do curso';
        }

        $errors = [];
        foreach ($required as $key => $label) {
            $value = trim((string) ($certificate[$key] ?? ''));
            if ($value === '') {
                $errors["member_area_config.certificate.{$key}"] = "{$label} é obrigatório quando o certificado estiver habilitado.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function platformAppNameForBuilder(Request $request): string
    {
        $global = BrandingSetting::query()->whereNull('tenant_id')->first();
        $globalData = is_array($global?->data) ? $global->data : [];
        $tenantData = [];
        $user = $request->user();
        if ($user !== null && $user->tenant_id !== null) {
            $tenant = BrandingSetting::query()->where('tenant_id', $user->tenant_id)->first();
            $tenantData = is_array($tenant?->data) ? $tenant->data : [];
        }
        $merged = ApplyBrandingConfig::mergeLayers($globalData, $tenantData);
        $name = trim((string) ($merged['app_name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        return trim((string) config('getfy.app_name', config('app.name'))) ?: 'Getfy';
    }

    private function authorizeProduct(Product $produto): void
    {
        $tenantId = auth()->user()->tenant_id;
        if ($produto->tenant_id !== $tenantId) {
            abort(403);
        }

        if (auth()->user()->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            if (! in_array($produto->id, $allowed, true)) {
                abort(403);
            }
        }
    }

    private function isCloudflareIp(string $ip): bool
    {
        $cidrs = [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
        ];

        foreach ($cidrs as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if (! is_string($subnet) || ! is_string($bits)) {
            return false;
        }
        if (! filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $bitsInt = (int) $bits;
        if ($bitsInt < 0 || $bitsInt > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bitsInt === 0 ? 0 : (-1 << (32 - $bitsInt));

        return (($ipLong & $mask) === ($subnetLong & $mask));
    }

    /** Para o front: exibe só "path" ou "custom". Subdomínio vira custom com valor = host completo. */
    private function memberAreaDomainTypeForFront(MemberAreaDomain $domain): string
    {
        return $domain->type === MemberAreaDomain::TYPE_SUBDOMAIN ? 'custom' : $domain->type;
    }

    /** Para o front: no caso subdomain, valor = slug + "." + subdomain_base (host completo). */
    private function memberAreaDomainValueForFront(MemberAreaDomain $domain, Product $product): string
    {
        if ($domain->type === MemberAreaDomain::TYPE_SUBDOMAIN && config('members.subdomain_enabled')) {
            $base = config('members.subdomain_base', '');
            $slug = $domain->value ?: $product->checkout_slug;
            return $base !== '' ? $slug . '.' . $base : (string) $domain->value;
        }
        return (string) ($domain->value ?? '');
    }

    /**
     * Gera chaves VAPID via binário openssl (CLI). Útil quando openssl_pkey_new falha no PHP (ex.: Windows sem openssl.cnf).
     *
     * @return array{publicKey: string, privateKey: string}|null
     */
    private function createVapidKeysViaOpensslCli(): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'vapid_');
        if ($tmp === false) {
            return null;
        }
        $pemFile = $tmp . '.pem';
        if (! @rename($tmp, $pemFile)) {
            @unlink($tmp);
            return null;
        }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $cmd = 'openssl ecparam -name prime256v1 -genkey -noout -out ' . escapeshellarg($pemFile) . ' 2>' . $null;
        exec($cmd, $out, $code);
        if ($code !== 0 || ! is_readable($pemFile)) {
            // No Windows, tentar openssl do XAMPP se não estiver no PATH
            if (PHP_OS_FAMILY === 'Windows' && is_file('C:\\xampp\\apache\\bin\\openssl.exe')) {
                $cmd = 'C:\\xampp\\apache\\bin\\openssl.exe ecparam -name prime256v1 -genkey -noout -out ' . escapeshellarg($pemFile) . ' 2>' . $null;
                exec($cmd, $out2, $code);
            }
            if ($code !== 0 || ! is_readable($pemFile)) {
                @unlink($pemFile);
                return null;
            }
        }
        $pem = file_get_contents($pemFile);
        @unlink($pemFile);
        if ($pem === false || $pem === '') {
            return null;
        }
        try {
            $jwkArray = \Jose\Component\KeyManagement\KeyConverter\ECKey::createFromPEM($pem)->toArray();
            $jwk = new \Jose\Component\Core\JWK($jwkArray);
            $binaryPublicKey = hex2bin(\Minishlink\WebPush\Utils::serializePublicKeyFromJWK($jwk));
            if (! $binaryPublicKey || strlen($binaryPublicKey) !== 65) {
                return null;
            }
            $d = \Base64Url\Base64Url::decode($jwk->get('d'));
            $binaryPrivateKey = str_pad($d, 32, "\0", STR_PAD_LEFT);
            if (strlen($binaryPrivateKey) !== 32) {
                return null;
            }
            return [
                'publicKey' => \Base64Url\Base64Url::encode($binaryPublicKey),
                'privateKey' => \Base64Url\Base64Url::encode($binaryPrivateKey),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSidebarMenuItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $link = trim((string) ($item['link'] ?? ''));
            if ($link !== '' && preg_match('#^https?://#i', $link)) {
                $parsed = parse_url($link);
                $path = $parsed['path'] ?? '/';
                $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
                $fragment = isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '';
                $link = $path.$query.$fragment;
            }
            if ($link === '') {
                $link = '/';
            } elseif (! str_starts_with($link, '/')) {
                $link = '/'.$link;
            }
            if (preg_match('#^/m/[a-zA-Z0-9-]+#', $link)) {
                $link = preg_replace('#^/m/[a-zA-Z0-9-]+#', '', $link) ?: '/';
                if ($link !== '/' && ! str_starts_with($link, '/')) {
                    $link = '/'.$link;
                }
            }
            $item['link'] = $link;
            $normalized[] = $item;
        }

        return array_values($normalized);
    }
}
