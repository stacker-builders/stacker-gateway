<?php

namespace App\Http\Controllers;

use App\Models\MemberCertificateIssued;
use App\Models\MemberComment;
use App\Models\MemberCommunityPage;
use App\Models\MemberCommunityPost;
use App\Models\MemberCommunityPostComment;
use App\Models\MemberCommunityPostLike;
use App\Models\MemberInternalProduct;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\BrandingSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\GamificationService;
use App\Services\MemberAreaResolver;
use App\Services\MemberCommentService;
use App\Services\MemberModuleAccessService;
use App\Services\MemberProgressService;
use App\Services\MemberStudentActivityLogService;
use App\Services\StorageService;
use App\Support\MemberAreaAdminPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemberAreaAppController extends Controller
{
    public function __construct(
        protected MemberProgressService $progressService,
        protected MemberAreaResolver $resolver,
        protected GamificationService $gamificationService,
        protected MemberModuleAccessService $moduleAccess,
        protected MemberStudentActivityLogService $studentActivity,
    ) {}

    public function show(Request $request, string $slug): Response
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        $this->studentActivity->recordVisitOncePerSession($user, $product, $request);
        $now = now();
        $config = $this->memberAreaConfigForApp($product);
        $sections = $product->memberSections()->with(['modules.lessons', 'modules.relatedProduct'])->orderBy('position')->get();
        $progressPercent = $this->progressService->completionPercent($product, $user);
        $continueWatching = $this->getContinueWatching($product, $user);
        $internalProducts = $product->memberInternalProducts()->with('relatedProduct')->orderBy('position')->get();
        $baseUrl = $this->baseUrlForRequest($product, $request);
        $userProductIds = $user->products()->pluck('products.id')->flip()->all();
        $push = $this->pushProps($product);

        return Inertia::render('MemberAreaApp/Show', [
            'product' => $this->productToArray($product),
            'config' => $config,
            'sections' => $sections->map(fn (MemberSection $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'cover_mode' => $s->cover_mode ?? 'vertical',
                'section_type' => $s->section_type ?? 'courses',
                'modules' => $s->modules->map(fn ($m) => $this->mapModuleForMemberArea($m, $s, $product, $user, $userProductIds, $baseUrl, $now))->values()->all(),
            ])->values()->all(),
            'progress_percent' => $progressPercent,
            'continue_watching' => $continueWatching,
            'internal_products' => $internalProducts->map(fn (MemberInternalProduct $ip) => [
                'id' => $ip->related_product_id,
                'name' => $ip->relatedProduct?->name,
                'image_url' => $ip->relatedProduct?->image ? (new StorageService($product->tenant_id))->url($ip->relatedProduct->image) : null,
                'checkout_slug' => $ip->relatedProduct?->checkout_slug,
                'checkout_url' => $ip->relatedProduct?->checkout_slug ? url('/c/'.$ip->relatedProduct->checkout_slug) : null,
                'has_access' => $user->products()->where('products.id', $ip->related_product_id)->exists(),
            ])->values()->all(),
            'community_enabled' => (bool) ($config['community_enabled'] ?? false),
            'base_url' => $baseUrl,
            'slug' => $slug,
            'push_enabled' => $push['push_enabled'],
            'vapid_public' => $push['vapid_public'],
        ] + $this->gamificationProps($product, $user));
    }

    public function modulos(Request $request, string $slug): Response
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        $this->studentActivity->recordVisitOncePerSession($user, $product, $request);
        $now = now();
        $sections = $product->memberSections()->with('modules.lessons')->orderBy('position')->get();

        return Inertia::render('MemberAreaApp/Modulos', [
            'product' => $this->productToArray($product),
            'config' => $this->memberAreaConfigForApp($product),
            'sections' => $sections->map(fn (MemberSection $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'cover_mode' => $s->cover_mode ?? 'vertical',
                'modules' => $s->modules->map(fn ($m) => [
                    'id' => $m->id,
                    'title' => $m->title,
                    'thumbnail' => $this->moduleThumbnailUrl($product, $m->thumbnail),
                    'show_title_on_cover' => $m->show_title_on_cover ?? true,
                    ...$this->moduleAccess->moduleLockPayload($m, $product, $user, $now),
                    'lessons' => $m->lessons->map(fn (MemberLesson $l) => [
                        'id' => $l->id,
                        'title' => $l->title,
                        'type' => $l->type,
                        'duration_seconds' => $l->duration_seconds,
                        'is_completed' => $this->isLessonCompleted($user->id, $l->id),
                        ...$this->moduleAccess->lessonLockPayload($l, $m, $product, $user, $now),
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            ...$this->pushProps($product),
        ] + $this->gamificationProps($product, $user));
    }

    public function moduleContent(Request $request, string $slug, MemberModule $module): Response|RedirectResponse
    {
        $product = $this->getProduct($request);
        if ($module->product_id !== $product->id) {
            abort(404);
        }
        $user = $request->user();
        $this->studentActivity->recordVisitOncePerSession($user, $product, $request);
        $now = now();
        $moduleLock = $this->moduleAccess->moduleLockPayload($module, $product, $user, $now);
        if (($moduleLock['is_locked'] ?? false) === true && ($moduleLock['lock_reason'] ?? null) !== 'expired') {
            return $this->redirectToMemberAreaHome($request, $slug)
                ->with('error', $moduleLock['lock_message'] ?? 'Módulo ainda não liberado.');
        }
        $moduleExpired = ($moduleLock['lock_reason'] ?? null) === 'expired';
        $module->load(['section', 'lessons' => fn ($q) => $q->orderBy('position')]);
        $lessons = $moduleExpired ? [] : $module->lessons->map(fn (MemberLesson $l) => [
            'id' => $l->id,
            'title' => $l->title,
            'type' => $l->type,
            'position' => $l->position,
            'duration_seconds' => $l->duration_seconds,
            'is_completed' => $this->isLessonCompleted($user->id, $l->id),
            ...$this->moduleAccess->lessonLockPayload($l, $module, $product, $user, $now),
        ])->values()->all();

        $lessonId = $request->query('aula');
        $currentLesson = $moduleExpired ? null : ($lessonId
            ? $module->lessons->firstWhere('id', (int) $lessonId)
            : $module->lessons->first());
        if ($currentLesson) {
            $lock = $this->moduleAccess->lessonLockPayload($currentLesson, $module, $product, $user, $now);
            if (($lock['is_locked'] ?? false) === true) {
                $firstUnlocked = $module->lessons->first(function (MemberLesson $l) use ($module, $product, $user, $now) {
                    return ($this->moduleAccess->lessonLockPayload($l, $module, $product, $user, $now)['is_locked'] ?? false) !== true;
                });
                if ($firstUnlocked) {
                    return $this->redirectToModuleContent($request, $slug, $module->id, $firstUnlocked->id)
                        ->with('error', $lock['lock_message'] ?? 'Aula ainda não liberada.');
                }
                $request->session()->flash('error', $lock['lock_message'] ?? 'Aulas ainda não liberadas.');
                $currentLesson = null;
            }
        }

        $currentLessonData = null;
        if ($currentLesson) {
            $this->progressService->ensureLessonStarted($currentLesson, $user);
            $this->studentActivity->recordLessonViewedOncePerSession($user, $product, $currentLesson, $request);
            $currentLesson->load('module.section');
            $studentFiles = $this->studentFacingLessonFiles($request, $slug, $currentLesson);
            $currentLessonData = [
                'id' => $currentLesson->id,
                'title' => $currentLesson->title,
                'type' => $currentLesson->type,
                'content_url' => $this->studentFacingLessonContentUrl($request, $slug, $currentLesson, $studentFiles),
                'content_files' => $studentFiles,
                'link_title' => $currentLesson->link_title,
                'content_text' => \App\Support\HtmlSanitizer::sanitize($currentLesson->content_text),
                'duration_seconds' => $currentLesson->duration_seconds,
                'is_completed' => $this->isLessonCompleted($user->id, $currentLesson->id),
                'module' => $currentLesson->module ? ['id' => $currentLesson->module->id, 'title' => $currentLesson->module->title] : null,
                'section' => $currentLesson->module && $currentLesson->module->section ? ['id' => $currentLesson->module->section->id, 'title' => $currentLesson->module->section->title] : null,
                'watermark_enabled' => (bool) ($currentLesson->watermark_enabled ?? false),
            ];
            if ($currentLessonData['watermark_enabled']) {
                $currentLessonData['student'] = $this->getStudentWatermarkData($user, $product);
            }
        }

        $progressPercent = $this->progressService->completionPercent($product, $user);

        $sections = $product->memberSections()->with('modules')->orderBy('position')->get();
        $sectionsPayload = $sections->map(fn (MemberSection $s) => [
            'id' => $s->id,
            'title' => $s->title,
            'cover_mode' => $s->cover_mode ?? 'vertical',
            'modules' => $s->modules->map(fn ($m) => [
                'id' => $m->id,
                'title' => $m->title,
                'thumbnail' => $this->moduleThumbnailUrl($product, $m->thumbnail),
                'show_title_on_cover' => $m->show_title_on_cover ?? true,
                ...$this->moduleAccess->moduleLockPayload($m, $product, $user, $now),
            ])->values()->all(),
        ])->values()->all();

        $config = $this->memberAreaConfigForApp($product);
        $commentsEnabled = (bool) ($config['comments_enabled'] ?? false);
        $commentsRequireApproval = (bool) ($config['comments_require_approval'] ?? true);
        $lessonComments = [];
        if ($commentsEnabled && $currentLesson) {
            $lessonComments = MemberComment::forProduct($product->id)
                ->where('member_lesson_id', $currentLesson->id)
                ->status(MemberComment::STATUS_APPROVED)
                ->with('user:id,name,avatar')
                ->latest()
                ->get()
                ->map(fn (MemberComment $c) => [
                    'id' => $c->id,
                    'content' => $c->content,
                    'user' => $c->user ? [
                        'id' => $c->user->id,
                        'name' => $c->user->name,
                        'avatar_url' => $c->user->avatar ? (new StorageService($product->tenant_id))->url($c->user->avatar) : null,
                    ] : null,
                    'created_at' => $c->created_at->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return Inertia::render('MemberAreaApp/ModuleContent', [
            'product' => $this->productToArray($product),
            'config' => $this->memberAreaConfigForApp($product),
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            'module' => [
                'id' => $module->id,
                'title' => $module->title,
                'section' => $module->section ? ['id' => $module->section->id, 'title' => $module->section->title] : null,
                ...$moduleLock,
            ],
            'lessons' => $lessons,
            'current_lesson' => $currentLessonData,
            'progress_percent' => $progressPercent,
            'sections' => $sectionsPayload,
            'comments_enabled' => $commentsEnabled,
            'comments_require_approval' => $commentsRequireApproval,
            'lesson_comments' => $lessonComments,
            ...$this->pushProps($product),
        ]);
    }

    public function lesson(Request $request, string $slug, MemberLesson $lesson): Response|RedirectResponse
    {
        $product = $this->getProduct($request);
        if ($lesson->product_id !== $product->id) {
            abort(404);
        }
        $user = $request->user();
        $this->studentActivity->recordVisitOncePerSession($user, $product, $request);
        $now = now();
        $lesson->load('module');
        if ($lesson->module && $lesson->module->product_id === $product->id) {
            $moduleLock = $this->moduleAccess->moduleLockPayload($lesson->module, $product, $user, $now);
            if (($moduleLock['is_locked'] ?? false) === true) {
                return $this->redirectToMemberAreaHome($request, $slug)
                    ->with('error', $moduleLock['lock_message'] ?? 'Módulo ainda não liberado.');
            }
        }
        $lessonLock = $this->moduleAccess->lessonLockPayload($lesson, $lesson->module, $product, $user, $now);
        if (($lessonLock['is_locked'] ?? false) === true) {
            if ($lesson->module) {
                return $this->redirectToModuleContent($request, $slug, $lesson->module->id)
                    ->with('error', $lessonLock['lock_message'] ?? 'Aula ainda não liberada.');
            }

            return $this->redirectToMemberAreaRoute($request, 'member-area-app.modulos', ['slug' => $slug])
                ->with('error', $lessonLock['lock_message'] ?? 'Aula ainda não liberada.');
        }
        $this->progressService->ensureLessonStarted($lesson, $user);
        $this->studentActivity->recordLessonViewedOncePerSession($user, $product, $lesson, $request);
        $lesson->load('module.section');

        $studentFiles = $this->studentFacingLessonFiles($request, $slug, $lesson);
        $lessonPayload = [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'type' => $lesson->type,
            'content_url' => $this->studentFacingLessonContentUrl($request, $slug, $lesson, $studentFiles),
            'content_files' => $studentFiles,
            'link_title' => $lesson->link_title,
            'content_text' => \App\Support\HtmlSanitizer::sanitize($lesson->content_text),
            'duration_seconds' => $lesson->duration_seconds,
            'is_completed' => $this->isLessonCompleted($user->id, $lesson->id),
            'module' => $lesson->module ? ['id' => $lesson->module->id, 'title' => $lesson->module->title] : null,
            'section' => $lesson->module && $lesson->module->section ? ['id' => $lesson->module->section->id, 'title' => $lesson->module->section->title] : null,
            'watermark_enabled' => (bool) ($lesson->watermark_enabled ?? false),
        ];
        if ($lessonPayload['watermark_enabled']) {
            $lessonPayload['student'] = $this->getStudentWatermarkData($user, $product);
        }
        $config = $this->memberAreaConfigForApp($product);
        $commentsEnabled = (bool) ($config['comments_enabled'] ?? false);
        $commentsRequireApproval = (bool) ($config['comments_require_approval'] ?? true);
        $lessonComments = [];
        if ($commentsEnabled) {
            $lessonComments = MemberComment::forProduct($product->id)
                ->where('member_lesson_id', $lesson->id)
                ->status(MemberComment::STATUS_APPROVED)
                ->with('user:id,name,avatar')
                ->latest()
                ->get()
                ->map(fn (MemberComment $c) => [
                    'id' => $c->id,
                    'content' => $c->content,
                    'user' => $c->user ? [
                        'id' => $c->user->id,
                        'name' => $c->user->name,
                        'avatar_url' => $c->user->avatar ? (new StorageService($product->tenant_id))->url($c->user->avatar) : null,
                    ] : null,
                    'created_at' => $c->created_at->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return Inertia::render('MemberAreaApp/Lesson', [
            'product' => $this->productToArray($product),
            'config' => $this->memberAreaConfigForApp($product),
            'lesson' => $lessonPayload,
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            'comments_enabled' => $commentsEnabled,
            'comments_require_approval' => $commentsRequireApproval,
            'lesson_comments' => $lessonComments,
            ...$this->pushProps($product),
        ] + $this->gamificationProps($product, $user));
    }

    public function completeLesson(Request $request, string $slug, MemberLesson $lesson): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Não autenticado.'], 401);
        }
        $product = $this->getProduct($request);
        if ($lesson->product_id !== $product->id) {
            abort(404);
        }
        $this->assertNotAdminPreviewMutation($request, $product);
        $now = now();
        $lesson->loadMissing('module');
        $lessonLock = $this->moduleAccess->lessonLockPayload($lesson, $lesson->module, $product, $user, $now);
        if (($lessonLock['is_locked'] ?? false) === true) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['success' => false, 'message' => $lessonLock['lock_message'] ?? 'Conteúdo indisponível.'], 403);
            }
            if ($lesson->module) {
                return $this->redirectToModuleContent($request, $slug, $lesson->module->id)
                    ->with('error', $lessonLock['lock_message'] ?? 'Conteúdo indisponível.');
            }

            return $this->redirectToMemberAreaHome($request, $slug)
                ->with('error', $lessonLock['lock_message'] ?? 'Conteúdo indisponível.');
        }
        $alreadyCompleted = $this->isLessonCompleted($user->id, $lesson->id);
        $this->progressService->markLessonCompleted($lesson->id, $user);
        if (! $alreadyCompleted) {
            $this->studentActivity->recordLessonCompleted($user, $product, $lesson, $request);
        }

        $newlyUnlocked = $this->gamificationService->checkAndUnlock($product, $user);
        if ($newlyUnlocked !== []) {
            $request->session()->flash('newly_unlocked_achievements', $newlyUnlocked);
        }

        if ($request->header('X-Inertia')) {
            return $this->redirectToLessonContext($request, $slug, $lesson);
        }
        $percent = $this->progressService->completionPercent($product, $user);

        return response()->json(['success' => true, 'progress_percent' => $percent, 'newly_unlocked_achievements' => $newlyUnlocked]);
    }

    public function downloadLessonMaterial(Request $request, string $slug, MemberLesson $lesson, int $index): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }
        $product = $this->getProduct($request);
        if ($lesson->product_id !== $product->id) {
            abort(404);
        }
        $now = now();
        $lesson->loadMissing('module');
        $lessonLock = $this->moduleAccess->lessonLockPayload($lesson, $lesson->module, $product, $user, $now);
        if (($lessonLock['is_locked'] ?? false) === true) {
            abort(403, $lessonLock['lock_message'] ?? 'Conteúdo indisponível.');
        }

        $files = $this->normalizeLessonContentFiles($lesson);
        $file = $files[$index] ?? null;
        if ($file === null) {
            abort(404, 'Material não encontrado.');
        }

        $this->studentActivity->recordMaterialDownload(
            $user,
            $product,
            $lesson,
            $request,
            $index,
            (string) ($file['name'] ?? 'Material')
        );

        $url = trim((string) ($file['url'] ?? ''));
        if ($url === '') {
            abort(404, 'Material não encontrado.');
        }
        if (str_starts_with($url, '/')) {
            return redirect()->to($url);
        }
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return redirect()->away($url);
        }

        abort(404, 'Material não encontrado.');
    }

    /**
     * @return list<array{url: string, name: string}>
     */
    private function normalizeLessonContentFiles(MemberLesson $lesson): array
    {
        $list = is_array($lesson->content_files) ? $lesson->content_files : [];
        $out = [];
        foreach ($list as $item) {
            if (is_string($item)) {
                $url = trim($item);
                if ($url !== '') {
                    $out[] = ['url' => $url, 'name' => 'Material'];
                }
                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            $out[] = ['url' => $url, 'name' => $name !== '' ? $name : 'Material'];
        }
        if ($out === [] && is_string($lesson->content_url) && trim($lesson->content_url) !== '') {
            $out[] = ['url' => trim($lesson->content_url), 'name' => 'Material'];
        }

        return $out;
    }

    /**
     * @return list<array{url: string, name: string}>
     */
    private function studentFacingLessonFiles(Request $request, string $slug, MemberLesson $lesson): array
    {
        $files = $this->normalizeLessonContentFiles($lesson);
        foreach ($files as $i => $file) {
            $files[$i]['url'] = $this->lessonMaterialDownloadPath($request, $slug, (int) $lesson->id, $i);
        }

        return $files;
    }

    /**
     * @param  list<array{url: string, name: string}>  $studentFiles
     */
    private function studentFacingLessonContentUrl(Request $request, string $slug, MemberLesson $lesson, array $studentFiles): ?string
    {
        if (($lesson->type ?? '') === MemberLesson::TYPE_PDF) {
            return $studentFiles[0]['url'] ?? ($lesson->content_url ?: null);
        }

        return $lesson->content_url ?: null;
    }

    private function lessonMaterialDownloadPath(Request $request, string $slug, int $lessonId, int $index): string
    {
        $suffix = '/aula/'.$lessonId.'/material/'.$index;
        if ($this->isHostMemberAreaRequest($request)) {
            return $suffix;
        }

        return '/m/'.$slug.$suffix;
    }

    public function storeLessonComment(Request $request, string $slug, MemberLesson $lesson): JsonResponse|RedirectResponse
    {
        $product = $this->getProduct($request);
        $this->assertNotAdminPreviewMutation($request, $product);
        if ($lesson->product_id !== $product->id) {
            abort(404);
        }
        $lesson->loadMissing('module');
        $lessonLock = $this->moduleAccess->lessonLockPayload($lesson, $lesson->module, $product, $request->user(), now());
        if (($lessonLock['is_locked'] ?? false) === true) {
            abort(403, $lessonLock['lock_message'] ?? 'Conteúdo indisponível.');
        }
        $config = $this->memberAreaConfigForApp($product);
        if (empty($config['comments_enabled'])) {
            abort(403, 'Comentários desativados para este produto.');
        }
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);
        $requireApproval = ! empty($config['comments_require_approval']);
        $initialStatus = $requireApproval ? MemberComment::STATUS_PENDING : MemberComment::STATUS_APPROVED;
        $commentService = app(MemberCommentService::class);
        $commentService->create(
            $product,
            $request->user(),
            $validated['content'],
            $lesson->id,
            null,
            $initialStatus
        );
        $message = $requireApproval ? 'Comentário enviado e aguardando aprovação.' : 'Comentário publicado.';
        if (! $requireApproval) {
            $newlyUnlocked = $this->gamificationService->checkAndUnlock($product, $request->user());
            if ($newlyUnlocked !== []) {
                $request->session()->flash('newly_unlocked_achievements', $newlyUnlocked);
            }
        }
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return $this->redirectToLessonContext($request, $slug, $lesson)->with('success', $message);
    }

    public function loja(Request $request, string $slug): Response
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        $internalProducts = $product->memberInternalProducts()->with('relatedProduct')->orderBy('position')->get();
        $userProductIds = $user->products()->pluck('products.id')->flip()->all();

        $items = $internalProducts->map(fn (MemberInternalProduct $ip) => [
            'id' => $ip->related_product_id,
            'name' => $ip->relatedProduct?->name,
            'description' => $ip->relatedProduct?->description,
            'image_url' => $ip->relatedProduct?->image ? (new StorageService($product->tenant_id))->url($ip->relatedProduct->image) : null,
            'checkout_slug' => $ip->relatedProduct?->checkout_slug,
            'checkout_url' => $ip->relatedProduct?->checkout_slug ? url('/c/'.$ip->relatedProduct->checkout_slug) : null,
            'price' => $ip->relatedProduct?->price,
            'has_access' => isset($userProductIds[$ip->related_product_id]),
        ])->values()->all();

        return Inertia::render('MemberAreaApp/Loja', [
            'product' => $this->productToArray($product),
            'config' => $this->memberAreaConfigForApp($product),
            'items' => $items,
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            ...$this->pushProps($product),
        ] + $this->gamificationProps($product, $user));
    }

    public function comunidade(Request $request, string $slug): Response|\Illuminate\Http\RedirectResponse
    {
        $product = $this->getProduct($request);
        if ($redirect = $this->redirectIfCommunityDisabled($request, $product, $slug)) {
            return $redirect;
        }
        $user = $request->user();
        $pages = $product->memberCommunityPages()->orderBy('position')->get();
        $defaultPage = $pages->firstWhere('is_default', true);
        if ($defaultPage) {
            $routeName = (string) $request->route()?->getName();
            $pageRouteName = str_ends_with($routeName, '.host')
                ? 'member-area-app.comunidade.page.host'
                : 'member-area-app.comunidade.page';
            $params = ['pageSlug' => $defaultPage->slug];
            if (! str_ends_with($routeName, '.host')) {
                $params['slug'] = $slug;
            }

            return redirect()->route($pageRouteName, $params);
        }

        return Inertia::render('MemberAreaApp/Comunidade', [
            'product' => $this->productToArray($product),
            'config' => $this->memberAreaConfigForApp($product),
            'pages' => $pages->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'icon' => $p->icon,
                'slug' => $p->slug,
                'banner_url' => $p->banner ? (new StorageService($product->tenant_id))->url($p->banner) : null,
            ])->values()->all(),
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            ...$this->pushProps($product),
        ] + $this->gamificationProps($product, $user));
    }

    public function comunidadePage(Request $request, string $slug, string $pageSlug): Response|\Illuminate\Http\RedirectResponse
    {
        $product = $this->getProduct($request);
        if ($redirect = $this->redirectIfCommunityDisabled($request, $product, $slug)) {
            return $redirect;
        }
        $user = $request->user();
        $config = $this->memberAreaConfigForApp($product);
        $pages = $product->memberCommunityPages()->orderBy('position')->get();
        $page = $pages->firstWhere('slug', $pageSlug);
        if (! $page) {
            abort(404);
        }
        $storage = new StorageService($product->tenant_id);
        $postsQuery = $page->posts()->with(['user:id,name,email,avatar', 'likes', 'comments.user:id,name,avatar'])->latest();
        $posts = $postsQuery->paginate(20);
        $canDeleteAny = $this->isCommunityInstructor($user, $product);
        $canPost = $page->is_public_posting || $canDeleteAny;
        $usersCanDeleteOwn = (bool) ($config['community_users_can_delete_own_posts'] ?? true);
        $posts->getCollection()->transform(function (MemberCommunityPost $post) use ($user, $storage) {
            $comments = $post->comments->map(fn (MemberCommunityPostComment $c) => [
                'id' => $c->id,
                'content' => $c->content,
                'created_at' => $c->created_at->format('d/m/Y H:i'),
                'user' => $c->user ? [
                    'id' => $c->user->id,
                    'name' => $c->user->name,
                    'avatar_url' => $c->user->avatar ? $storage->url($c->user->avatar) : null,
                ] : null,
            ])->values()->all();

            return array_merge($post->toArray(), [
                'user' => $post->user ? [
                    'id' => $post->user->id,
                    'name' => $post->user->name,
                    'avatar_url' => $post->user->avatar ? $storage->url($post->user->avatar) : null,
                ] : null,
                'likes_count' => $post->likes->count(),
                'user_has_liked' => $post->likes->contains('user_id', $user->id),
                'comments' => $comments,
            ]);
        });

        return Inertia::render('MemberAreaApp/ComunidadePage', [
            'product' => $this->productToArray($product),
            'config' => $config,
            'auth_user_id' => $user->id,
            'can_delete_any_post' => $canDeleteAny,
            'can_post' => $canPost,
            'community_users_can_delete_own_posts' => $usersCanDeleteOwn,
            'pages' => $pages->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'icon' => $p->icon,
                'slug' => $p->slug,
                'banner_url' => $p->banner ? (new StorageService($product->tenant_id))->url($p->banner) : null,
            ])->values()->all(),
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'icon' => $page->icon,
                'slug' => $page->slug,
                'banner_url' => $page->banner ? (new StorageService($product->tenant_id))->url($page->banner) : null,
                'is_public_posting' => $page->is_public_posting,
            ],
            'posts' => $posts,
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            ...$this->pushProps($product),
        ] + $this->gamificationProps($product, $user));
    }

    public function storeCommunityPost(Request $request, string $slug, string $pageSlug): RedirectResponse
    {
        $product = $this->getProduct($request);
        $this->assertCommunityEnabled($product);
        $this->assertNotAdminPreviewMutation($request, $product);
        $page = MemberCommunityPage::where('product_id', $product->id)->where('slug', $pageSlug)->firstOrFail();
        $user = $request->user();
        if (! $page->is_public_posting && ! $this->isCommunityInstructor($user, $product)) {
            abort(403, 'Apenas o instrutor pode postar nesta página.');
        }
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'image' => ['nullable', 'file', 'image', 'max:5120'],
        ]);
        $imagePath = null;
        if ($request->hasFile('image')) {
            $storage = new StorageService($product->tenant_id);
            $imagePath = $storage->putFile('member-area-posts/'.$product->id, $request->file('image'));
        }
        MemberCommunityPost::create([
            'member_community_page_id' => $page->id,
            'user_id' => $user->id,
            'content' => $validated['content'],
            'image' => $imagePath,
        ]);

        return back()->with('success', 'Post publicado.');
    }

    public function destroyCommunityPost(Request $request, string $slug, string $pageSlug, MemberCommunityPost $post): RedirectResponse
    {
        $product = $this->getProduct($request);
        $this->assertCommunityEnabled($product);
        $this->assertNotAdminPreviewMutation($request, $product);
        $page = MemberCommunityPage::where('product_id', $product->id)->where('slug', $pageSlug)->firstOrFail();
        if ($post->member_community_page_id !== $page->id) {
            abort(404);
        }
        $user = $request->user();
        $config = $this->memberAreaConfigForApp($product);
        $canDeleteAny = $this->isCommunityInstructor($user, $product);
        $usersCanDeleteOwn = (bool) ($config['community_users_can_delete_own_posts'] ?? true);
        $isAuthor = $post->user_id === $user->id;
        if (! $canDeleteAny && ! ($isAuthor && $usersCanDeleteOwn)) {
            abort(403, 'Você não pode excluir esta postagem.');
        }
        $post->delete();

        return back()->with('success', 'Postagem excluída.');
    }

    public function likeCommunityPost(Request $request, string $slug, string $pageSlug, MemberCommunityPost $post): JsonResponse
    {
        $product = $this->getProduct($request);
        $this->assertCommunityEnabled($product);
        $this->assertNotAdminPreviewMutation($request, $product);
        $page = MemberCommunityPage::where('product_id', $product->id)->where('slug', $pageSlug)->firstOrFail();
        if ($post->member_community_page_id !== $page->id) {
            abort(404);
        }
        MemberCommunityPostLike::firstOrCreate(
            ['member_community_post_id' => $post->id, 'user_id' => $request->user()->id]
        );

        return response()->json([
            'likes_count' => $post->likes()->count(),
            'user_has_liked' => true,
        ]);
    }

    public function unlikeCommunityPost(Request $request, string $slug, string $pageSlug, MemberCommunityPost $post): JsonResponse
    {
        $product = $this->getProduct($request);
        $this->assertCommunityEnabled($product);
        $this->assertNotAdminPreviewMutation($request, $product);
        $page = MemberCommunityPage::where('product_id', $product->id)->where('slug', $pageSlug)->firstOrFail();
        if ($post->member_community_page_id !== $page->id) {
            abort(404);
        }
        MemberCommunityPostLike::where('member_community_post_id', $post->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'likes_count' => $post->likes()->count(),
            'user_has_liked' => false,
        ]);
    }

    public function storeCommunityPostComment(Request $request, string $slug, string $pageSlug, MemberCommunityPost $post): JsonResponse|RedirectResponse
    {
        $product = $this->getProduct($request);
        $this->assertCommunityEnabled($product);
        $this->assertNotAdminPreviewMutation($request, $product);
        $page = MemberCommunityPage::where('product_id', $product->id)->where('slug', $pageSlug)->firstOrFail();
        if ($post->member_community_page_id !== $page->id) {
            abort(404);
        }
        $validated = $request->validate(['content' => ['required', 'string', 'max:2000']]);
        $comment = MemberCommunityPostComment::create([
            'member_community_post_id' => $post->id,
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
        ]);
        $comment->load('user:id,name,avatar');
        if ($request->expectsJson()) {
            return response()->json([
                'comment' => [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'created_at' => $comment->created_at->format('d/m/Y H:i'),
                    'user' => $comment->user ? [
                        'id' => $comment->user->id,
                        'name' => $comment->user->name,
                        'avatar_url' => $comment->user->avatar ? (new StorageService($product->tenant_id))->url($comment->user->avatar) : null,
                    ] : null,
                ],
            ]);
        }

        return back()->with('success', 'Comentário adicionado.');
    }

    public function certificado(Request $request, string $slug): Response|RedirectResponse
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        $config = $this->memberAreaConfigForApp($product);
        $certConfig = $config['certificate'] ?? [];

        if (empty($certConfig['enabled'])) {
            return $this->redirectToMemberAreaHome($request, $slug)
                ->with('error', 'O certificado não está habilitado para este curso.');
        }

        $eligibility = $this->progressService->certificateEligibility($product, $user);
        $progressPercent = $eligibility['progress_percent'];
        $requiredPercent = $eligibility['required_percent'];
        $issued = MemberCertificateIssued::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($eligibility['eligible'] && ! $issued) {
            $issued = $this->progressService->issueCertificate($product, $user);
        }

        $newlyUnlocked = [];
        if ($issued !== null) {
            $newlyUnlocked = $this->gamificationService->checkAndUnlock($product, $user);
        }

        $certificateAvailable = $issued !== null;
        $certTitle = ! empty($certConfig['title']) ? $certConfig['title'] : $product->name;
        $globalBranding = BrandingSetting::query()->whereNull('tenant_id')->first();
        $globalBrandingData = is_array($globalBranding?->data) ? $globalBranding->data : [];
        $globalPlatformName = trim((string) ($globalBrandingData['app_name'] ?? ''));
        $customPlatformName = trim((string) ($certConfig['platform_name'] ?? ''));
        $platformName = $customPlatformName !== ''
            ? $customPlatformName
            : ($globalPlatformName !== '' ? $globalPlatformName : (string) config('app.name'));

        $fontScale = (int) ($certConfig['font_scale'] ?? 100);
        $fontScale = max(75, min(150, $fontScale > 0 ? $fontScale : 100));

        $certificatePayload = [
            'title' => $certTitle,
            'issued_at' => $issued ? $issued->issued_at->format('d/m/Y') : null,
            'issued_at_full' => $issued ? $issued->issued_at->format('d/m/Y H:i') : null,
            'completion_percent' => $issued ? $issued->completion_percent : $progressPercent,
            'signature_text' => $certConfig['signature_text'] ?? '',
            'duration_text' => $certConfig['duration_text'] ?? '',
            'duration_enabled' => array_key_exists('duration_enabled', $certConfig)
                ? (bool) $certConfig['duration_enabled']
                : true,
            'font_family' => $certConfig['font_family'] ?? 'sans-serif',
            'font_scale' => $fontScale,
            'body_template' => trim((string) ($certConfig['body_template'] ?? '')),
            'platform_name' => $platformName,
            'header_text' => trim((string) ($certConfig['header_text'] ?? '')) !== '' ? $certConfig['header_text'] : 'Certificado de conclusão',
            'recipient_intro_text' => trim((string) ($certConfig['recipient_intro_text'] ?? '')) !== '' ? $certConfig['recipient_intro_text'] : 'Certificamos que',
            'completion_text' => trim((string) ($certConfig['completion_text'] ?? '')) !== '' ? $certConfig['completion_text'] : 'completou com sucesso o curso em',
            'issued_on_text' => trim((string) ($certConfig['issued_on_text'] ?? '')) !== '' ? $certConfig['issued_on_text'] : 'em',
            'instructor_label_text' => trim((string) ($certConfig['instructor_label_text'] ?? '')) !== '' ? $certConfig['instructor_label_text'] : 'Assinatura do Instrutor',
            'platform_label_text' => trim((string) ($certConfig['platform_label_text'] ?? '')) !== '' ? $certConfig['platform_label_text'] : 'Plataforma de Cursos',
            'duration_label_text' => trim((string) ($certConfig['duration_label_text'] ?? '')) !== '' ? $certConfig['duration_label_text'] : 'Duração',
            'primary_color' => $certConfig['primary_color'] ?? null,
            'background_image_url' => $certConfig['background_image_url'] ?? null,
            'background_overlay_enabled' => (bool) ($certConfig['background_overlay_enabled'] ?? false),
            'background_overlay_color' => $certConfig['background_overlay_color'] ?? '#000000',
            'background_overlay_opacity' => isset($certConfig['background_overlay_opacity']) ? (float) $certConfig['background_overlay_opacity'] : 50,
            'text_color' => $certConfig['text_color'] ?? null,
            'title_color' => $certConfig['title_color'] ?? null,
            'signature_font_family' => $certConfig['signature_font_family'] ?? 'Dancing Script',
            'print_format' => $certConfig['print_format'] ?? 'A4',
            'layout' => is_array($certConfig['layout'] ?? null) ? $certConfig['layout'] : [],
        ];

        return Inertia::render('MemberAreaApp/Certificado', [
            'product' => $this->productToArray($product),
            'config' => $this->memberAreaConfigForApp($product),
            'recipient_name' => $user->name,
            'certificate_available' => $certificateAvailable,
            'progress_percent' => $progressPercent,
            'completion_required_percent' => $requiredPercent,
            'certificate_release' => [
                'mode' => $eligibility['release_mode'],
                'required_percent' => $eligibility['required_percent'],
                'percent_met' => $eligibility['percent_met'],
                'days_after_access' => $eligibility['days_after_access'],
                'days_elapsed' => $eligibility['days_elapsed'],
                'days_remaining' => $eligibility['days_remaining'],
                'days_met' => $eligibility['days_met'],
                'unlocks_at' => $eligibility['unlocks_at'],
            ],
            'certificate' => $certificatePayload,
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            'newly_unlocked_achievements' => $newlyUnlocked,
            ...$this->pushProps($product),
        ] + $this->gamificationProps($product, $user));
    }

    public function pushSubscribe(Request $request, string $slug): JsonResponse
    {
        $product = $this->getProduct($request);
        $this->assertNotAdminPreviewMutation($request, $product);
        $config = $this->memberAreaConfigForApp($product);
        $pwa = $config['pwa'] ?? [];
        if (! ((bool) ($pwa['push_enabled'] ?? false))) {
            return response()->json(['message' => 'Notificações push não estão habilitadas para esta área.'], 403);
        }
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys' => ['required', 'array'],
            'keys.auth' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
        ]);
        $keys = $validated['keys'];
        $keys['auth'] = $this->normalizeBase64KeyForPush((string) ($keys['auth'] ?? ''));
        $keys['p256dh'] = $this->normalizeBase64KeyForPush((string) ($keys['p256dh'] ?? ''));
        $subscription = \App\Models\MemberPushSubscription::updateOrCreate(
            [
                'endpoint' => $validated['endpoint'],
            ],
            [
                'user_id' => $request->user()->id,
                'product_id' => $product->id,
                'keys' => $keys,
                'user_agent' => $request->userAgent(),
            ]
        );

        return response()->json([
            'success' => true,
            'subscribed' => true,
            'subscription_id' => $subscription->id,
            'updated_at' => $subscription->updated_at?->toISOString(),
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

    private function getProduct(Request $request): Product
    {
        $product = $request->route('product') ?? $request->attributes->get('member_area_product');
        if (! $product instanceof Product) {
            abort(404);
        }

        return $product;
    }

    /** @return array{push_enabled: bool, vapid_public: string|null} */
    private function pushProps(Product $product): array
    {
        $config = $this->memberAreaConfigForApp($product);
        $pwa = $config['pwa'] ?? [];
        $pushEnabled = (bool) ($pwa['push_enabled'] ?? false);

        return [
            'push_enabled' => $pushEnabled,
            'vapid_public' => $pushEnabled ? ($pwa['vapid_public'] ?? null) : null,
        ];
    }

    /** @return array{gamification_achievements: array} */
    private function gamificationProps(Product $product, User $user): array
    {
        $config = $this->memberAreaConfigForApp($product);
        $gamification = $config['gamification'] ?? [];
        if (empty($gamification['enabled'])) {
            return ['gamification_achievements' => []];
        }

        return [
            'gamification_achievements' => $this->gamificationService->getAchievementsForUser($product, $user),
        ];
    }

    private function productToArray(Product $product): array
    {
        $config = $this->memberAreaConfigForApp($product);
        $logos = $config['logos'] ?? [];

        return [
            'id' => $product->id,
            'name' => $product->name,
            'checkout_slug' => $product->checkout_slug,
            'logo_light' => $logos['logo_light'] ?? '',
            'logo_dark' => $logos['logo_dark'] ?? '',
        ];
    }

    /**
     * Retorna lista de "continuar assistindo": um item por seção (o último progresso de cada seção).
     */
    private function getContinueWatching(Product $product, $user): array
    {
        $progresses = \App\Models\MemberLessonProgress::forProduct($product->id)
            ->forUser($user->id)
            ->whereNull('completed_at')
            ->with('lesson.module')
            ->latest('updated_at')
            ->get();

        $bySection = [];
        foreach ($progresses as $p) {
            if (! $p->lesson) {
                continue;
            }
            $sectionId = $p->lesson->module?->member_section_id;
            if ($sectionId === null) {
                continue;
            }
            if (isset($bySection[$sectionId])) {
                continue;
            }
            $module = $p->lesson->module;
            if ($module) {
                $lock = $this->moduleAccess->lessonLockPayload($p->lesson, $module, $product, $user, now());
                if (($lock['is_locked'] ?? false) === true) {
                    continue;
                }
            }
            $bySection[$sectionId] = $p;
        }

        $items = [];
        foreach ($bySection as $p) {
            $lesson = $p->lesson;
            $module = $lesson->module;
            $moduleThumbnail = null;
            if ($module && $module->thumbnail) {
                $moduleThumbnail = $this->moduleThumbnailUrl($product, $module->thumbnail);
            }
            $items[] = [
                'lesson_id' => $lesson->id,
                'module_id' => $module?->id,
                'title' => $lesson->title,
                'module_title' => $module?->title,
                'module_thumbnail' => $moduleThumbnail,
            ];
        }

        return $items;
    }

    private function mapModuleForMemberArea(MemberModule $m, MemberSection $s, Product $product, $user, array $userProductIds, string $baseUrl, $now): array
    {
        $sectionType = $s->section_type ?? 'courses';

        if ($sectionType === 'courses') {
            return [
                'id' => $m->id,
                'title' => $m->title,
                'thumbnail' => $this->moduleThumbnailUrl($product, $m->thumbnail),
                'show_title_on_cover' => $m->show_title_on_cover ?? true,
                ...$this->moduleAccess->moduleLockPayload($m, $product, $user, $now),
                'lessons' => $m->lessons->map(fn (MemberLesson $l) => [
                    'id' => $l->id,
                    'title' => $l->title,
                    'type' => $l->type,
                    'duration_seconds' => $l->duration_seconds,
                    'is_completed' => $this->progressService->completedLessonsCount($product, $user) > 0
                        ? $this->isLessonCompleted($user->id, $l->id)
                        : false,
                    ...$this->moduleAccess->lessonLockPayload($l, $m, $product, $user, $now),
                ])->values()->all(),
            ];
        }

        if ($sectionType === 'products') {
            $related = $m->relatedProduct;
            $hasAccess = $m->related_product_id ? isset($userProductIds[$m->related_product_id]) : false;

            return [
                'id' => $m->id,
                'title' => $m->title,
                'thumbnail' => $this->moduleThumbnailUrl($product, $m->thumbnail),
                'show_title_on_cover' => $m->show_title_on_cover ?? true,
                'related_product_id' => $m->related_product_id,
                'access_type' => $m->access_type,
                'related_product' => $related ? [
                    'id' => $related->id,
                    'name' => $related->name,
                    'image_url' => $related->image ? (new StorageService($product->tenant_id))->url($related->image) : null,
                    'checkout_slug' => $related->checkout_slug,
                    'checkout_url' => url('/c/'.$related->checkout_slug),
                    'member_area_slug' => $related->checkout_slug,
                ] : null,
                'has_access' => $hasAccess,
            ];
        }

        // external_links
        return [
            'id' => $m->id,
            'title' => $m->title,
            'thumbnail' => $this->moduleThumbnailUrl($product, $m->thumbnail),
            'show_title_on_cover' => $m->show_title_on_cover ?? true,
            'external_url' => $m->external_url,
        ];
    }

    private function isCommunityInstructor(User $user, Product $product): bool
    {
        return ($user->canAccessPanel() && $user->tenant_id === $product->tenant_id)
            || $user->canAccessPlatformPanel();
    }

    private function isHostMemberAreaRequest(Request $request): bool
    {
        return in_array($request->attributes->get('member_area_access_type'), ['subdomain', 'custom'], true);
    }

    private function memberAreaRouteName(Request $request, string $baseName): string
    {
        if ($this->isHostMemberAreaRequest($request) && ! str_ends_with($baseName, '.host')) {
            return $baseName.'.host';
        }

        return $baseName;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function redirectToMemberAreaRoute(Request $request, string $baseName, array $params = []): RedirectResponse
    {
        return redirect()->route($this->memberAreaRouteName($request, $baseName), $params);
    }

    private function redirectToModuleContent(Request $request, string $slug, int|string $moduleId, int|string|null $lessonId = null): RedirectResponse
    {
        $params = [
            'slug' => $slug,
            'module' => $moduleId,
        ];
        if ($lessonId !== null && $lessonId !== '') {
            $params['aula'] = $lessonId;
        }

        return $this->redirectToMemberAreaRoute($request, 'member-area-app.module', $params);
    }

    private function redirectToLessonContext(Request $request, string $slug, MemberLesson $lesson): RedirectResponse
    {
        $lesson->loadMissing('module');
        if ($lesson->module && (int) $lesson->module->product_id === (int) $lesson->product_id) {
            return $this->redirectToModuleContent($request, $slug, $lesson->module->id, $lesson->id);
        }

        return $this->redirectToMemberAreaRoute($request, 'member-area-app.lesson', [
            'slug' => $slug,
            'lesson' => $lesson->id,
        ]);
    }

    private function redirectToMemberAreaHome(Request $request, string $slug): RedirectResponse
    {
        if ($this->isHostMemberAreaRequest($request)) {
            return redirect('/');
        }

        return redirect()->route('member-area-app.show', $slug);
    }

    private function redirectIfCommunityDisabled(Request $request, Product $product, string $slug): ?RedirectResponse
    {
        $config = $this->memberAreaConfigForApp($product);
        if (! empty($config['community_enabled'])) {
            return null;
        }

        return $this->redirectToMemberAreaHome($request, $slug)
            ->with('error', 'A comunidade não está habilitada para este curso.');
    }

    private function assertCommunityEnabled(Product $product): void
    {
        $config = $this->memberAreaConfigForApp($product);
        if (empty($config['community_enabled'])) {
            abort(404);
        }
    }

    private function assertNotAdminPreviewMutation(Request $request, Product $product): void
    {
        if (MemberAreaAdminPreview::isActive($request, $product)) {
            abort(403, 'Modo de visualização administrativa: esta ação não está disponível.');
        }
    }

    private function isLessonCompleted(int $userId, int $lessonId): bool
    {
        return \App\Models\MemberLessonProgress::where('user_id', $userId)
            ->where('member_lesson_id', $lessonId)
            ->whereNotNull('completed_at')
            ->exists();
    }

    /**
     * Dados do aluno para marca d'água (nome, email, cpf se houver no pedido).
     */
    private function getStudentWatermarkData(User $user, Product $product): array
    {
        $cpf = Order::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->where('status', 'completed')
            ->latest()
            ->value('cpf');
        if (is_string($cpf) && trim($cpf) !== '') {
            return ['name' => $user->name ?? '', 'email' => $user->email ?? '', 'cpf' => trim($cpf)];
        }

        return ['name' => $user->name ?? '', 'email' => $user->email ?? '', 'cpf' => null];
    }

    public function manifest(Request $request, ?string $slug = null): \Illuminate\Http\JsonResponse
    {
        $product = $request->route('product') ?? $request->attributes->get('member_area_product');
        if (! $product instanceof Product || $product->type !== Product::TYPE_AREA_MEMBROS) {
            abort(404);
        }
        $slug = $slug ?? $request->route('slug') ?? $request->attributes->get('member_area_slug');
        $baseUrl = rtrim($this->baseUrlForRequest($product, $request), '/');
        $config = $this->memberAreaConfigForApp($product);
        $pwa = $config['pwa'] ?? [];
        $logos = $config['logos'] ?? [];
        $name = $pwa['name'] ?: $product->name;
        $shortName = $pwa['short_name'] ?: $name;
        $themeColor = $pwa['theme_color'] ?? '#0ea5e9';

        $icons = [];
        $faviconUrl = $logos['favicon'] ?? $pwa['favicon'] ?? null;
        if ($faviconUrl) {
            $iconUrl = str_starts_with($faviconUrl, 'http') ? $faviconUrl : (str_starts_with($faviconUrl, '/') ? $request->getSchemeAndHttpHost().$faviconUrl : $request->getSchemeAndHttpHost().'/'.ltrim($faviconUrl, '/'));
            $icons[] = ['src' => $iconUrl, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'];
            $icons[] = ['src' => $iconUrl, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'];
        }
        if (isset($pwa['icons']) && is_array($pwa['icons'])) {
            foreach ($pwa['icons'] as $icon) {
                $src = $icon['src'] ?? null;
                if ($src && ! str_starts_with($src, 'http')) {
                    $src = $request->getSchemeAndHttpHost().(str_starts_with($src, '/') ? $src : '/'.ltrim($src, '/'));
                }
                if ($src) {
                    $icons[] = [
                        'src' => $src,
                        'sizes' => $icon['sizes'] ?? '192x192',
                        'type' => $icon['type'] ?? 'image/png',
                        'purpose' => $icon['purpose'] ?? 'any maskable',
                    ];
                }
            }
        }
        if (empty($icons)) {
            $icons[] = [
                'src' => $request->getSchemeAndHttpHost().'/images/gateways/pix.svg',
                'sizes' => '192x192',
                'type' => 'image/svg+xml',
                'purpose' => 'any maskable',
            ];
        }

        // `id` único para o Android tratar como app separado do painel (mesmo origin); evita "já está instalado"
        $manifestId = $slug ? '/m/'.$slug : ($baseUrl ? parse_url($baseUrl, PHP_URL_PATH) : '/m/member-area');

        $manifest = [
            'id' => $manifestId,
            'name' => $name,
            'short_name' => $shortName,
            'start_url' => $baseUrl,
            'scope' => $baseUrl.'/',
            'display' => 'standalone',
            'background_color' => $config['theme']['background'] ?? '#18181b',
            'theme_color' => $themeColor,
            'icons' => $icons,
        ];

        return response()->json($manifest)->header('Content-Type', 'application/manifest+json');
    }

    private function baseUrlForRequest(Product $product, Request $request): string
    {
        $accessType = $request->attributes->get('member_area_access_type');
        if (in_array($accessType, ['subdomain', 'custom'], true)) {
            return rtrim($request->getSchemeAndHttpHost(), '/');
        }

        return $this->resolver->baseUrlForProduct($product);
    }

    /**
     * @return array<string, mixed>
     */
    private function memberAreaConfigForApp(Product $product): array
    {
        $resolved = (new StorageService($product->tenant_id))->resolveMediaUrlsInConfig($product->member_area_config ?? []);

        return is_array($resolved) ? $resolved : [];
    }

    private function moduleThumbnailUrl(Product $product, ?string $thumbnail): ?string
    {
        if ($thumbnail === null || trim($thumbnail) === '') {
            return null;
        }

        $url = (new StorageService($product->tenant_id))->resolvePublicUrl($thumbnail);

        return $url !== '' ? $url : null;
    }
}
