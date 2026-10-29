<?php

namespace App\Services\Mcp;

use App\Models\MemberComment;
use App\Models\MemberCommunityPage;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\MemberTurma;
use App\Models\Product;
use App\Models\User;
use App\Services\AccessEmailService;
use App\Services\MemberAccessGrantService;
use App\Services\MemberCommentService;
use App\Services\MemberProgressService;
use App\Services\MemberStudentAccountService;
use App\Services\SellerActivityLogService;
use App\Support\DemoMode;
use App\Support\HtmlSanitizer;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class McpMembersService
{
    public const WRITE_TOOLS = [
        'members.create_section',
        'members.update_section',
        'members.delete_section',
        'members.create_module',
        'members.update_module',
        'members.delete_module',
        'members.create_lesson',
        'members.update_lesson',
        'members.delete_lesson',
        'members.reorder',
        'members.create_turma',
        'members.update_turma',
        'members.delete_turma',
        'members.attach_turma_student',
        'members.detach_turma_student',
        'members.moderate_comment',
        'members.update_config',
        'members.create_community_page',
        'members.update_community_page',
        'members.apply_plan',
        'students.grant',
        'students.revoke',
    ];

    public function __construct(
        private readonly McpPreviewStore $previews,
        private readonly MemberAccessGrantService $grants,
        private readonly MemberStudentAccountService $accounts,
        private readonly MemberProgressService $progress,
        private readonly MemberCommentService $comments,
        private readonly AccessEmailService $accessEmail,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        $productId = ['type' => 'string', 'description' => 'ID do produto (área de membros) deste tenant.'];
        $title = ['type' => 'string', 'description' => 'Título.'];

        return [
            $this->tool('members.get_structure', 'Lê a estrutura da área de membros: seções, módulos, aulas, turmas, páginas da comunidade e resumo de aparência. Sem senha de login, VAPID ou domínio.', [
                'type' => 'object',
                'properties' => ['product_id' => $productId],
                'required' => ['product_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.create_section', 'Cria uma seção na área de membros (cursos, produtos relacionados ou links).', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'title' => $title,
                    'cover_mode' => ['type' => 'string', 'enum' => ['vertical', 'horizontal']],
                    'section_type' => ['type' => 'string', 'enum' => ['courses', 'products', 'external_links']],
                ],
                'required' => ['product_id', 'title'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.update_section', 'Atualiza título, capa ou tipo de uma seção.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'section_id' => ['type' => 'integer'],
                    'title' => $title,
                    'cover_mode' => ['type' => 'string', 'enum' => ['vertical', 'horizontal']],
                    'section_type' => ['type' => 'string', 'enum' => ['courses', 'products', 'external_links']],
                ],
                'required' => ['product_id', 'section_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.delete_section', 'Remove uma seção e o conteúdo em cascata. Exige members.apply_plan.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'section_id' => ['type' => 'integer'],
                ],
                'required' => ['product_id', 'section_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.create_module', 'Cria um módulo (curso com drip, produto relacionado ou link externo).', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'section_id' => ['type' => 'integer'],
                    'title' => $title,
                    'show_title_on_cover' => ['type' => 'boolean'],
                    'release_after_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650],
                    'release_at_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'expire_after_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650],
                    'expire_at_date' => ['type' => 'string'],
                    'renewal_price' => ['type' => 'number'],
                    'related_product_id' => $productId,
                    'access_type' => ['type' => 'string', 'enum' => ['paid', 'free']],
                    'external_url' => ['type' => 'string'],
                ],
                'required' => ['product_id', 'section_id', 'title'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.update_module', 'Atualiza módulo: título, drip, validade, produto relacionado ou URL.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'module_id' => ['type' => 'integer'],
                    'title' => $title,
                    'show_title_on_cover' => ['type' => 'boolean'],
                    'release_after_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650],
                    'release_at_date' => ['type' => 'string'],
                    'expire_after_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650],
                    'expire_at_date' => ['type' => 'string'],
                    'renewal_price' => ['type' => 'number'],
                    'related_product_id' => $productId,
                    'access_type' => ['type' => 'string', 'enum' => ['paid', 'free']],
                    'external_url' => ['type' => 'string'],
                ],
                'required' => ['product_id', 'module_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.delete_module', 'Remove um módulo e as aulas. Exige members.apply_plan.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'module_id' => ['type' => 'integer'],
                ],
                'required' => ['product_id', 'module_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.create_lesson', 'Cria aula (vídeo, link, PDF por URL ou texto). HTML da aula é sanitizado. Sem upload de arquivo.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'module_id' => ['type' => 'integer'],
                    'title' => $title,
                    'type' => ['type' => 'string', 'enum' => ['video', 'link', 'pdf', 'text']],
                    'content_url' => ['type' => 'string', 'description' => 'URL http(s) do vídeo, link ou PDF.'],
                    'link_title' => ['type' => 'string'],
                    'content_text' => ['type' => 'string', 'description' => 'HTML ou texto da aula (tipo text).'],
                    'duration_seconds' => ['type' => 'integer', 'minimum' => 0],
                    'is_free' => ['type' => 'boolean'],
                    'release_after_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650],
                    'release_at_date' => ['type' => 'string'],
                ],
                'required' => ['product_id', 'module_id', 'title', 'type'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.update_lesson', 'Atualiza aula: título, URL, texto, drip ou tipo.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'lesson_id' => ['type' => 'integer'],
                    'title' => $title,
                    'type' => ['type' => 'string', 'enum' => ['video', 'link', 'pdf', 'text']],
                    'content_url' => ['type' => 'string'],
                    'link_title' => ['type' => 'string'],
                    'content_text' => ['type' => 'string'],
                    'duration_seconds' => ['type' => 'integer', 'minimum' => 0],
                    'is_free' => ['type' => 'boolean'],
                    'release_after_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650],
                    'release_at_date' => ['type' => 'string'],
                ],
                'required' => ['product_id', 'lesson_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.delete_lesson', 'Remove uma aula. Exige members.apply_plan.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'lesson_id' => ['type' => 'integer'],
                ],
                'required' => ['product_id', 'lesson_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.reorder', 'Reordena seções do produto, módulos de uma seção ou aulas de um módulo. ordered_ids deve listar exatamente os IDs atuais.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'scope' => ['type' => 'string', 'enum' => ['sections', 'modules', 'lessons']],
                    'ordered_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'section_id' => ['type' => 'integer'],
                    'module_id' => ['type' => 'integer'],
                ],
                'required' => ['product_id', 'scope', 'ordered_ids'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.list_turmas', 'Lista turmas e alunos (id, nome, e-mail) deste produto.', [
                'type' => 'object',
                'properties' => ['product_id' => $productId],
                'required' => ['product_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.create_turma', 'Cria turma neste produto.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'start_date' => ['type' => 'string'],
                    'end_date' => ['type' => 'string'],
                ],
                'required' => ['product_id', 'name'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.update_turma', 'Atualiza nome, descrição ou datas da turma.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'turma_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'start_date' => ['type' => 'string'],
                    'end_date' => ['type' => 'string'],
                ],
                'required' => ['product_id', 'turma_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.delete_turma', 'Remove a turma (não revoga acesso ao produto). Exige members.apply_plan.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'turma_id' => ['type' => 'integer'],
                ],
                'required' => ['product_id', 'turma_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.attach_turma_student', 'Coloca um aluno que já tem acesso ao produto em uma turma.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'turma_id' => ['type' => 'integer'],
                    'student_id' => ['type' => 'integer'],
                ],
                'required' => ['product_id', 'turma_id', 'student_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.detach_turma_student', 'Remove o aluno da turma (o acesso ao produto permanece).', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'turma_id' => ['type' => 'integer'],
                    'student_id' => ['type' => 'integer'],
                ],
                'required' => ['product_id', 'turma_id', 'student_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.list_comments', 'Lista comentários da área de membros (pendentes por padrão). Sem IP.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'rejected', 'all']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ],
                'required' => ['product_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.moderate_comment', 'Aprova ou rejeita um comentário.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'comment_id' => ['type' => 'integer'],
                    'status' => ['type' => 'string', 'enum' => ['approved', 'rejected']],
                ],
                'required' => ['product_id', 'comment_id', 'status'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.update_config', 'Atualiza aparência (cores, hero), comentários, certificado e oferta na aula. Não altera domínio, senha padrão, PWA/VAPID nem pixels.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'theme' => [
                        'type' => 'object',
                        'properties' => [
                            'primary' => ['type' => 'string'],
                            'background' => ['type' => 'string'],
                            'sidebar_bg' => ['type' => 'string'],
                            'text' => ['type' => 'string'],
                            'accent' => ['type' => 'string'],
                        ],
                    ],
                    'hero_title' => ['type' => 'string'],
                    'hero_subtitle' => ['type' => 'string'],
                    'comments_enabled' => ['type' => 'boolean'],
                    'comments_require_approval' => ['type' => 'boolean'],
                    'certificate' => [
                        'type' => 'object',
                        'properties' => [
                            'enabled' => ['type' => 'boolean'],
                            'title' => ['type' => 'string'],
                            'release_mode' => ['type' => 'string', 'enum' => ['completion_percent', 'days_after_access', 'both']],
                            'completion_percent' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                            'days_after_access' => ['type' => 'integer', 'minimum' => 0],
                            'signature_text' => ['type' => 'string'],
                            'header_text' => ['type' => 'string'],
                            'recipient_intro_text' => ['type' => 'string'],
                            'completion_text' => ['type' => 'string'],
                        ],
                    ],
                    'lesson_offer' => [
                        'type' => 'object',
                        'properties' => [
                            'enabled' => ['type' => 'boolean'],
                            'product_id' => $productId,
                            'button_text' => ['type' => 'string'],
                        ],
                    ],
                ],
                'required' => ['product_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.create_community_page', 'Cria página da comunidade (não cria posts).', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'title' => $title,
                    'icon' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'is_public_posting' => ['type' => 'boolean'],
                    'is_default' => ['type' => 'boolean'],
                ],
                'required' => ['product_id', 'title'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.update_community_page', 'Atualiza página da comunidade.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'page_id' => ['type' => 'integer'],
                    'title' => $title,
                    'icon' => ['type' => 'string'],
                    'is_public_posting' => ['type' => 'boolean'],
                    'is_default' => ['type' => 'boolean'],
                ],
                'required' => ['product_id', 'page_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('members.apply_plan', 'Aplica um preview_id de exclusão, revogação ou e-mail de acesso. Use idempotency_key para reexecutar com segurança.', [
                'type' => 'object',
                'properties' => [
                    'preview_id' => ['type' => 'string'],
                    'idempotency_key' => ['type' => 'string', 'maxLength' => 128],
                ],
                'required' => ['preview_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('students.list', 'Lista alunos deste tenant (nome, e-mail, produtos e progresso). Sem IP, CPF ou telefone.', [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'product_id' => $productId,
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                    'offset' => ['type' => 'integer', 'minimum' => 0],
                ],
                'additionalProperties' => false,
            ]),
            $this->tool('students.grant', 'Concede acesso de 1 aluno a 1 produto. Conta nova precisa de senha ou e-mail de acesso (e-mail exige apply_plan). Turma opcional.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'password' => ['type' => 'string', 'description' => 'Obrigatória para aluno novo se send_access_email for falso. Nunca devolvida.'],
                    'turma_id' => ['type' => 'integer'],
                    'send_access_email' => ['type' => 'boolean', 'description' => 'Padrão false. true exige members.apply_plan.'],
                ],
                'required' => ['product_id', 'name', 'email'],
                'additionalProperties' => false,
            ]),
            $this->tool('students.revoke', 'Revoga o acesso de 1 aluno a 1 produto. Exige members.apply_plan. Não remove a conta.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'student_id' => ['type' => 'integer'],
                    'email' => ['type' => 'string'],
                ],
                'required' => ['product_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('students.get_progress', 'Progresso do aluno no produto: aulas feitas, percentual e se o certificado já foi emitido. Sem IP.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => $productId,
                    'student_id' => ['type' => 'integer'],
                    'email' => ['type' => 'string'],
                ],
                'required' => ['product_id'],
                'additionalProperties' => false,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments, int $tenantId): array
    {
        if (in_array($name, self::WRITE_TOOLS, true) && DemoMode::isEnabled()) {
            throw new McpCatalogException('Escrita MCP bloqueada no modo demonstração.', 'demo');
        }

        return match ($name) {
            'members.get_structure' => $this->getStructure($tenantId, $arguments),
            'members.list_turmas' => $this->listTurmas($tenantId, $arguments),
            'members.list_comments' => $this->listComments($tenantId, $arguments),
            'members.apply_plan' => $this->applyPlan($tenantId, $arguments),
            'students.list' => $this->listStudents($tenantId, $arguments),
            'students.get_progress' => $this->getProgress($tenantId, $arguments),
            'members.create_section' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'create_section'])),
            'members.update_section' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'update_section'])),
            'members.delete_section' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'delete_section'])),
            'members.create_module' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'create_module'])),
            'members.update_module' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'update_module'])),
            'members.delete_module' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'delete_module'])),
            'members.create_lesson' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'create_lesson'])),
            'members.update_lesson' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'update_lesson'])),
            'members.delete_lesson' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'delete_lesson'])),
            'members.reorder' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'reorder'])),
            'members.create_turma' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'create_turma'])),
            'members.update_turma' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'update_turma'])),
            'members.delete_turma' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'delete_turma'])),
            'members.attach_turma_student' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'attach_turma_student'])),
            'members.detach_turma_student' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'detach_turma_student'])),
            'members.moderate_comment' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'moderate_comment'])),
            'members.update_config' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'update_config'])),
            'members.create_community_page' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'create_community_page'])),
            'members.update_community_page' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'update_community_page'])),
            'students.grant' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'grant_student'])),
            'students.revoke' => $this->dispatchWrite($tenantId, array_merge($arguments, ['op' => 'revoke_student'])),
            default => throw new McpCatalogException('Ferramenta indisponível neste servidor.', 'unknown_tool'),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function applyPlan(int $tenantId, array $arguments): array
    {
        $previewId = trim((string) ($arguments['preview_id'] ?? ''));
        $idempotencyKey = trim((string) ($arguments['idempotency_key'] ?? ''));

        if ($idempotencyKey !== '') {
            $cached = $this->previews->getIdempotency($tenantId, $idempotencyKey);
            if ($cached !== null) {
                return array_merge($cached, ['idempotent_replay' => true]);
            }
        }

        $preview = $this->previews->get($tenantId, $previewId);
        if ($preview === null) {
            throw new McpCatalogException('Preview inválido, expirado ou de outra conta.', 'preview_expired');
        }

        if (! empty($preview['consumed_at'])) {
            $previous = is_array($preview['result'] ?? null) ? $preview['result'] : ['ok' => true];

            return array_merge($previous, [
                'already_applied' => true,
                'applied_at' => $preview['consumed_at'],
            ]);
        }

        $operations = is_array($preview['operations'] ?? null) ? $preview['operations'] : [];
        $results = [];
        DB::transaction(function () use ($tenantId, $operations, &$results): void {
            foreach ($operations as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $results[] = $this->execute($tenantId, $item);
            }
        });

        $payload = [
            'ok' => true,
            'preview_id' => $previewId,
            'results' => $results,
        ];
        $this->previews->markConsumed($tenantId, $previewId, $payload);
        if ($idempotencyKey !== '') {
            $this->previews->putIdempotency($tenantId, $idempotencyKey, $payload);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function getStructure(int $tenantId, array $arguments): array
    {
        $product = $this->memberProduct($tenantId, (string) ($arguments['product_id'] ?? ''));
        $sections = MemberSection::query()
            ->where('product_id', $product->id)
            ->orderBy('position')
            ->with(['modules.lessons'])
            ->get();

        $config = $product->member_area_config;
        $theme = is_array($config['theme'] ?? null) ? $config['theme'] : [];
        $hero = is_array($config['hero'] ?? null) ? $config['hero'] : [];
        $certificate = is_array($config['certificate'] ?? null) ? $config['certificate'] : [];
        $offer = is_array($config['lesson_offer'] ?? null) ? $config['lesson_offer'] : [];

        return [
            'product' => [
                'id' => (string) $product->id,
                'name' => $product->name,
                'type' => $product->type,
            ],
            'appearance' => [
                'theme' => [
                    'primary' => $theme['primary'] ?? null,
                    'background' => $theme['background'] ?? null,
                    'sidebar_bg' => $theme['sidebar_bg'] ?? null,
                    'text' => $theme['text'] ?? null,
                    'accent' => $theme['accent'] ?? null,
                ],
                'hero_title' => $hero['title'] ?? '',
                'hero_subtitle' => $hero['subtitle'] ?? '',
                'comments_enabled' => (bool) ($config['comments_enabled'] ?? false),
                'comments_require_approval' => (bool) ($config['comments_require_approval'] ?? true),
                'certificate_enabled' => (bool) ($certificate['enabled'] ?? false),
                'lesson_offer_enabled' => (bool) ($offer['enabled'] ?? false),
            ],
            'pending_comments' => MemberComment::query()
                ->where('product_id', $product->id)
                ->where('status', MemberComment::STATUS_PENDING)
                ->count(),
            'community_pages' => MemberCommunityPage::query()
                ->where('product_id', $product->id)
                ->orderBy('position')
                ->get()
                ->map(fn (MemberCommunityPage $page) => $this->communityPageSummary($page))
                ->values()
                ->all(),
            'turmas' => MemberTurma::query()
                ->where('product_id', $product->id)
                ->orderBy('position')
                ->get()
                ->map(fn (MemberTurma $turma) => [
                    'id' => $turma->id,
                    'name' => $turma->name,
                    'students_count' => $turma->users()->count(),
                ])
                ->values()
                ->all(),
            'sections' => $sections->map(function (MemberSection $section) {
                return [
                    'id' => $section->id,
                    'title' => $section->title,
                    'position' => $section->position,
                    'cover_mode' => $section->cover_mode,
                    'section_type' => $section->section_type ?? 'courses',
                    'modules' => $section->modules->map(function (MemberModule $module) {
                        return $this->moduleSummary($module, withLessons: true);
                    })->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function listTurmas(int $tenantId, array $arguments): array
    {
        $product = $this->memberProduct($tenantId, (string) ($arguments['product_id'] ?? ''));
        $turmas = MemberTurma::query()
            ->where('product_id', $product->id)
            ->orderBy('position')
            ->with(['users:id,name,email'])
            ->get();

        return [
            'product_id' => (string) $product->id,
            'turmas' => $turmas->map(fn (MemberTurma $turma) => $this->turmaSummary($turma, withUsers: true))->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function listComments(int $tenantId, array $arguments): array
    {
        $product = $this->memberProduct($tenantId, (string) ($arguments['product_id'] ?? ''));
        $status = (string) ($arguments['status'] ?? MemberComment::STATUS_PENDING);
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));

        $query = MemberComment::query()
            ->where('product_id', $product->id)
            ->with(['user:id,name,email', 'lesson:id,title'])
            ->latest();
        if ($status !== 'all') {
            if (! in_array($status, [MemberComment::STATUS_PENDING, MemberComment::STATUS_APPROVED, MemberComment::STATUS_REJECTED], true)) {
                throw new McpCatalogException('Status de comentário inválido.', 'validation');
            }
            $query->where('status', $status);
        }

        $items = $query->limit($limit)->get()->map(function (MemberComment $comment) {
            return [
                'id' => $comment->id,
                'status' => $comment->status,
                'content' => $comment->content,
                'lesson_id' => $comment->member_lesson_id,
                'lesson_title' => $comment->lesson?->title,
                'student' => [
                    'id' => $comment->user?->id,
                    'name' => $comment->user?->name,
                    'email' => $comment->user?->email,
                ],
                'created_at' => $comment->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'product_id' => (string) $product->id,
            'status' => $status,
            'comments' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function listStudents(int $tenantId, array $arguments): array
    {
        $queryText = isset($arguments['query']) ? HtmlSanitizer::plainText((string) $arguments['query'], 80) : '';
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));
        $offset = max(0, (int) ($arguments['offset'] ?? 0));
        $productId = trim((string) ($arguments['product_id'] ?? ''));
        $product = $productId !== '' ? $this->tenantProduct($tenantId, $productId) : null;

        $builder = User::query()
            ->whereIn('role', User::buyerRoleValues())
            ->whereHas('products', function ($q) use ($tenantId, $product) {
                $q->forTenant($tenantId);
                if ($product !== null) {
                    $q->where('products.id', $product->id);
                }
            })
            ->with(['products' => fn ($q) => $q->forTenant($tenantId)->select('products.id', 'products.name', 'products.type')])
            ->orderBy('name');

        if ($queryText !== '') {
            $like = '%'.$queryText.'%';
            $builder->where(function ($q) use ($like) {
                $q->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like);
            });
        }

        $total = (clone $builder)->count();
        $students = $builder->offset($offset)->limit($limit)->get()->map(function (User $user) use ($product) {
            $row = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'products' => $user->products->map(fn (Product $p) => [
                    'id' => (string) $p->id,
                    'name' => $p->name,
                    'type' => $p->type,
                ])->values()->all(),
            ];
            if ($product !== null && $product->type === Product::TYPE_AREA_MEMBROS) {
                $row['progress'] = [
                    'completed' => $this->progress->completedLessonsCount($product, $user),
                    'total' => $this->progress->totalLessonsCount($product),
                    'percent' => $this->progress->completionPercent($product, $user),
                ];
            }

            return $row;
        })->values()->all();

        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'students' => $students,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function getProgress(int $tenantId, array $arguments): array
    {
        $product = $this->memberProduct($tenantId, (string) ($arguments['product_id'] ?? ''));
        $student = $this->studentOfProduct($tenantId, $product, $arguments);

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
            ],
            'product_id' => (string) $product->id,
            'completed' => $this->progress->completedLessonsCount($product, $student),
            'total' => $this->progress->totalLessonsCount($product),
            'percent' => $this->progress->completionPercent($product, $student),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function dispatchWrite(int $tenantId, array $raw): array
    {
        $inspected = $this->inspect($tenantId, $raw);
        if ($inspected['status'] === 'invalid') {
            throw new McpCatalogException((string) $inspected['error'], 'validation', [
                'op' => $inspected['op'],
                'gates' => $inspected['gates'],
            ]);
        }

        if ($inspected['status'] === 'needs_confirmation') {
            $stored = $this->previews->put($tenantId, [$inspected], $inspected['gates']);
            throw new McpCatalogException(
                'Esta alteração exige confirmação. Mostre o plano ao vendedor e chame members.apply_plan com o preview_id.',
                'needs_confirmation',
                [
                    'preview_id' => $stored['preview_id'],
                    'expires_at' => $stored['expires_at'],
                    'summary' => $inspected['summary'],
                    'gates' => $inspected['gates'],
                    'operation' => $this->publicInspected([$inspected])[0],
                ]
            );
        }

        return $this->execute($tenantId, $inspected);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspect(int $tenantId, array $raw): array
    {
        $op = trim((string) ($raw['op'] ?? ''));

        try {
            return match ($op) {
                'create_section' => $this->inspectCreateSection($tenantId, $raw),
                'update_section' => $this->inspectUpdateSection($tenantId, $raw),
                'delete_section' => $this->inspectDelete($tenantId, $raw, 'delete_section', 'section_id', 'Remover seção'),
                'create_module' => $this->inspectCreateModule($tenantId, $raw),
                'update_module' => $this->inspectUpdateModule($tenantId, $raw),
                'delete_module' => $this->inspectDelete($tenantId, $raw, 'delete_module', 'module_id', 'Remover módulo'),
                'create_lesson' => $this->inspectCreateLesson($tenantId, $raw),
                'update_lesson' => $this->inspectUpdateLesson($tenantId, $raw),
                'delete_lesson' => $this->inspectDelete($tenantId, $raw, 'delete_lesson', 'lesson_id', 'Remover aula'),
                'reorder' => $this->inspectReorder($tenantId, $raw),
                'create_turma' => $this->inspectCreateTurma($tenantId, $raw),
                'update_turma' => $this->inspectUpdateTurma($tenantId, $raw),
                'delete_turma' => $this->inspectDelete($tenantId, $raw, 'delete_turma', 'turma_id', 'Remover turma'),
                'attach_turma_student' => $this->inspectTurmaStudent($tenantId, $raw, 'attach_turma_student'),
                'detach_turma_student' => $this->inspectTurmaStudent($tenantId, $raw, 'detach_turma_student'),
                'moderate_comment' => $this->inspectModerateComment($tenantId, $raw),
                'update_config' => $this->inspectUpdateConfig($tenantId, $raw),
                'create_community_page' => $this->inspectCreateCommunityPage($tenantId, $raw),
                'update_community_page' => $this->inspectUpdateCommunityPage($tenantId, $raw),
                'grant_student' => $this->inspectGrantStudent($tenantId, $raw),
                'revoke_student' => $this->inspectRevokeStudent($tenantId, $raw),
                default => $this->inspected($op !== '' ? $op : 'unknown', 'invalid', 'Operação não suportada.'),
            };
        } catch (McpCatalogException $e) {
            return $this->inspected($op !== '' ? $op : 'unknown', 'invalid', $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function execute(int $tenantId, array $item): array
    {
        $op = (string) ($item['op'] ?? '');
        $data = is_array($item['normalized'] ?? null) ? $item['normalized'] : [];

        return match ($op) {
            'create_section' => $this->executeCreateSection($tenantId, $data),
            'update_section' => $this->executeUpdateSection($tenantId, $data),
            'delete_section' => $this->executeDeleteSection($tenantId, $data),
            'create_module' => $this->executeCreateModule($tenantId, $data),
            'update_module' => $this->executeUpdateModule($tenantId, $data),
            'delete_module' => $this->executeDeleteModule($tenantId, $data),
            'create_lesson' => $this->executeCreateLesson($tenantId, $data),
            'update_lesson' => $this->executeUpdateLesson($tenantId, $data),
            'delete_lesson' => $this->executeDeleteLesson($tenantId, $data),
            'reorder' => $this->executeReorder($tenantId, $data),
            'create_turma' => $this->executeCreateTurma($tenantId, $data),
            'update_turma' => $this->executeUpdateTurma($tenantId, $data),
            'delete_turma' => $this->executeDeleteTurma($tenantId, $data),
            'attach_turma_student' => $this->executeAttachTurma($tenantId, $data),
            'detach_turma_student' => $this->executeDetachTurma($tenantId, $data),
            'moderate_comment' => $this->executeModerateComment($tenantId, $data),
            'update_config' => $this->executeUpdateConfig($tenantId, $data),
            'create_community_page' => $this->executeCreateCommunityPage($tenantId, $data),
            'update_community_page' => $this->executeUpdateCommunityPage($tenantId, $data),
            'grant_student' => $this->executeGrantStudent($tenantId, $data),
            'revoke_student' => $this->executeRevokeStudent($tenantId, $data),
            default => throw new McpCatalogException('Operação não suportada no apply.', 'validation'),
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectCreateSection(int $tenantId, array $raw): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $title = HtmlSanitizer::plainText((string) ($raw['title'] ?? ''), 255);
        if ($title === '') {
            throw new McpCatalogException('Título da seção é obrigatório.', 'validation');
        }
        $cover = (string) ($raw['cover_mode'] ?? 'vertical');
        if (! in_array($cover, ['vertical', 'horizontal'], true)) {
            $cover = 'vertical';
        }
        $type = (string) ($raw['section_type'] ?? 'courses');
        if (! in_array($type, ['courses', 'products', 'external_links'], true)) {
            $type = 'courses';
        }

        return $this->ok('create_section', 'Criar seção "'.$title.'"', [
            'product_id' => (string) $product->id,
            'title' => $title,
            'cover_mode' => $cover,
            'section_type' => $type,
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectUpdateSection(int $tenantId, array $raw): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $section = $this->sectionOfProduct($product, (int) ($raw['section_id'] ?? 0));
        $data = ['product_id' => (string) $product->id, 'section_id' => $section->id];
        if (array_key_exists('title', $raw)) {
            $title = HtmlSanitizer::plainText((string) $raw['title'], 255);
            if ($title === '') {
                throw new McpCatalogException('Título da seção é obrigatório.', 'validation');
            }
            $data['title'] = $title;
        }
        if (array_key_exists('cover_mode', $raw) && in_array($raw['cover_mode'], ['vertical', 'horizontal'], true)) {
            $data['cover_mode'] = $raw['cover_mode'];
        }
        if (array_key_exists('section_type', $raw) && in_array($raw['section_type'], ['courses', 'products', 'external_links'], true)) {
            $data['section_type'] = $raw['section_type'];
        }

        return $this->ok('update_section', 'Atualizar seção "'.$section->title.'"', $data);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectCreateModule(int $tenantId, array $raw): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $section = $this->sectionOfProduct($product, (int) ($raw['section_id'] ?? 0));
        $title = HtmlSanitizer::plainText((string) ($raw['title'] ?? ''), 255);
        if ($title === '') {
            throw new McpCatalogException('Título do módulo é obrigatório.', 'validation');
        }
        $data = array_merge([
            'product_id' => (string) $product->id,
            'section_id' => $section->id,
            'title' => $title,
            'show_title_on_cover' => array_key_exists('show_title_on_cover', $raw) ? (bool) $raw['show_title_on_cover'] : true,
            'section_type' => $section->section_type ?? 'courses',
        ], $this->scheduleFromRaw($raw));

        $sectionType = $data['section_type'];
        if ($sectionType === 'products') {
            $relatedId = trim((string) ($raw['related_product_id'] ?? ''));
            $related = $this->tenantProduct($tenantId, $relatedId);
            if ((string) $related->id === (string) $product->id) {
                throw new McpCatalogException('Não é possível referenciar o próprio produto.', 'validation');
            }
            $data['related_product_id'] = (string) $related->id;
            $access = (string) ($raw['access_type'] ?? 'paid');
            $data['access_type'] = in_array($access, ['paid', 'free'], true) ? $access : 'paid';
        } elseif ($sectionType === 'external_links') {
            $url = SafeUrl::normalizeHttpUrl((string) ($raw['external_url'] ?? ''));
            if ($url === null) {
                throw new McpCatalogException('URL externa http(s) é obrigatória.', 'validation');
            }
            $data['external_url'] = $url;
        }

        return $this->ok('create_module', 'Criar módulo "'.$title.'"', $data);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectUpdateModule(int $tenantId, array $raw): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $module = $this->moduleOfProduct($product, (int) ($raw['module_id'] ?? 0));
        $module->loadMissing('section');
        $data = array_merge([
            'product_id' => (string) $product->id,
            'module_id' => $module->id,
        ], $this->scheduleFromRaw($raw, creating: false));
        if (array_key_exists('title', $raw)) {
            $title = HtmlSanitizer::plainText((string) $raw['title'], 255);
            if ($title === '') {
                throw new McpCatalogException('Título do módulo é obrigatório.', 'validation');
            }
            $data['title'] = $title;
        }
        if (array_key_exists('show_title_on_cover', $raw)) {
            $data['show_title_on_cover'] = (bool) $raw['show_title_on_cover'];
        }
        $sectionType = $module->section?->section_type ?? 'courses';
        if ($sectionType === 'products' && array_key_exists('related_product_id', $raw)) {
            $related = $this->tenantProduct($tenantId, (string) $raw['related_product_id']);
            if ((string) $related->id === (string) $product->id) {
                throw new McpCatalogException('Não é possível referenciar o próprio produto.', 'validation');
            }
            $data['related_product_id'] = (string) $related->id;
        }
        if (array_key_exists('access_type', $raw) && in_array($raw['access_type'], ['paid', 'free'], true)) {
            $data['access_type'] = $raw['access_type'];
        }
        if (array_key_exists('external_url', $raw)) {
            $url = SafeUrl::normalizeHttpUrl((string) $raw['external_url']);
            if ($url === null) {
                throw new McpCatalogException('URL externa inválida.', 'validation');
            }
            $data['external_url'] = $url;
        }

        return $this->ok('update_module', 'Atualizar módulo "'.$module->title.'"', $data);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectCreateLesson(int $tenantId, array $raw): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $module = $this->moduleOfProduct($product, (int) ($raw['module_id'] ?? 0));
        $title = HtmlSanitizer::plainText((string) ($raw['title'] ?? ''), 255);
        if ($title === '') {
            throw new McpCatalogException('Título da aula é obrigatório.', 'validation');
        }
        $type = (string) ($raw['type'] ?? '');
        if (! in_array($type, ['video', 'link', 'pdf', 'text'], true)) {
            throw new McpCatalogException('Tipo de aula inválido.', 'validation');
        }
        $data = array_merge([
            'product_id' => (string) $product->id,
            'module_id' => $module->id,
            'title' => $title,
            'type' => $type,
            'is_free' => (bool) ($raw['is_free'] ?? false),
            'duration_seconds' => isset($raw['duration_seconds']) ? max(0, (int) $raw['duration_seconds']) : null,
            'link_title' => isset($raw['link_title']) ? HtmlSanitizer::plainText((string) $raw['link_title'], 255) : null,
        ], $this->lessonReleaseFromRaw($raw));
        $data['content_url'] = $this->contentUrlForType($type, $raw['content_url'] ?? null, required: $type !== 'text');
        $data['content_text'] = $type === 'text'
            ? HtmlSanitizer::sanitizeLessonHtml((string) ($raw['content_text'] ?? ''))
            : null;
        if ($type === 'text' && ($data['content_text'] === null || $data['content_text'] === '')) {
            throw new McpCatalogException('Aula de texto precisa de content_text.', 'validation');
        }

        return $this->ok('create_lesson', 'Criar aula "'.$title.'"', $data);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectUpdateLesson(int $tenantId, array $raw): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $lesson = $this->lessonOfProduct($product, (int) ($raw['lesson_id'] ?? 0));
        $type = (string) ($raw['type'] ?? $lesson->type);
        if (! in_array($type, ['video', 'link', 'pdf', 'text'], true)) {
            throw new McpCatalogException('Tipo de aula inválido.', 'validation');
        }
        $data = array_merge([
            'product_id' => (string) $product->id,
            'lesson_id' => $lesson->id,
            'type' => $type,
        ], $this->lessonReleaseFromRaw($raw, creating: false));
        if (array_key_exists('title', $raw)) {
            $title = HtmlSanitizer::plainText((string) $raw['title'], 255);
            if ($title === '') {
                throw new McpCatalogException('Título da aula é obrigatório.', 'validation');
            }
            $data['title'] = $title;
        }
        if (array_key_exists('is_free', $raw)) {
            $data['is_free'] = (bool) $raw['is_free'];
        }
        if (array_key_exists('duration_seconds', $raw)) {
            $data['duration_seconds'] = max(0, (int) $raw['duration_seconds']);
        }
        if (array_key_exists('link_title', $raw)) {
            $data['link_title'] = HtmlSanitizer::plainText((string) $raw['link_title'], 255);
        }
        if (array_key_exists('content_url', $raw) || array_key_exists('type', $raw)) {
            $required = $type !== 'text' && array_key_exists('content_url', $raw);
            $data['content_url'] = $this->contentUrlForType($type, $raw['content_url'] ?? $lesson->content_url, required: $required && $type !== 'text');
        }
        if (array_key_exists('content_text', $raw)) {
            $data['content_text'] = HtmlSanitizer::sanitizeLessonHtml((string) $raw['content_text']);
        }

        return $this->ok('update_lesson', 'Atualizar aula "'.$lesson->title.'"', $data);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectReorder(int $tenantId, array $raw): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $scope = (string) ($raw['scope'] ?? '');
        if (! in_array($scope, ['sections', 'modules', 'lessons'], true)) {
            throw new McpCatalogException('scope deve ser sections, modules ou lessons.', 'validation');
        }
        $ids = array_values(array_map('intval', is_array($raw['ordered_ids'] ?? null) ? $raw['ordered_ids'] : []));
        if ($ids === [] || count($ids) !== count(array_unique($ids))) {
            throw new McpCatalogException('ordered_ids inválido.', 'validation');
        }
        $data = [
            'product_id' => (string) $product->id,
            'scope' => $scope,
            'ordered_ids' => $ids,
        ];
        if ($scope === 'modules') {
            $section = $this->sectionOfProduct($product, (int) ($raw['section_id'] ?? 0));
            $data['section_id'] = $section->id;
            $this->assertSameIds($ids, MemberModule::query()->where('product_id', $product->id)->where('member_section_id', $section->id)->pluck('id')->all());
        } elseif ($scope === 'lessons') {
            $module = $this->moduleOfProduct($product, (int) ($raw['module_id'] ?? 0));
            $data['module_id'] = $module->id;
            $this->assertSameIds($ids, MemberLesson::query()->where('product_id', $product->id)->where('member_module_id', $module->id)->pluck('id')->all());
        } else {
            $this->assertSameIds($ids, MemberSection::query()->where('product_id', $product->id)->pluck('id')->all());
        }

        return $this->ok('reorder', 'Reordenar '.$scope, $data);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectCreateTurma(int $tenantId, array $raw): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $name = HtmlSanitizer::plainText((string) ($raw['name'] ?? ''), 255);
        if ($name === '') {
            throw new McpCatalogException('Nome da turma é obrigatório.', 'validation');
        }

        return $this->ok('create_turma', 'Criar turma "'.$name.'"', [
            'product_id' => (string) $product->id,
            'name' => $name,
            'description' => isset($raw['description']) ? HtmlSanitizer::plainTextMultiline((string) $raw['description'], 2000) : null,
            'start_date' => $this->optionalDate($raw['start_date'] ?? null),
            'end_date' => $this->optionalDate($raw['end_date'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectUpdateTurma(int $tenantId, array $raw): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $turma = $this->turmaOfProduct($product, (int) ($raw['turma_id'] ?? 0));
        $data = ['product_id' => (string) $product->id, 'turma_id' => $turma->id];
        if (array_key_exists('name', $raw)) {
            $name = HtmlSanitizer::plainText((string) $raw['name'], 255);
            if ($name === '') {
                throw new McpCatalogException('Nome da turma é obrigatório.', 'validation');
            }
            $data['name'] = $name;
        }
        if (array_key_exists('description', $raw)) {
            $data['description'] = HtmlSanitizer::plainTextMultiline((string) $raw['description'], 2000);
        }
        if (array_key_exists('start_date', $raw)) {
            $data['start_date'] = $this->optionalDate($raw['start_date']);
        }
        if (array_key_exists('end_date', $raw)) {
            $data['end_date'] = $this->optionalDate($raw['end_date']);
        }

        return $this->ok('update_turma', 'Atualizar turma "'.$turma->name.'"', $data);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectTurmaStudent(int $tenantId, array $raw, string $op): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $turma = $this->turmaOfProduct($product, (int) ($raw['turma_id'] ?? 0));
        $student = $this->studentOfProduct($tenantId, $product, $raw);

        return $this->ok($op, ($op === 'attach_turma_student' ? 'Adicionar' : 'Remover').' aluno na turma "'.$turma->name.'"', [
            'product_id' => (string) $product->id,
            'turma_id' => $turma->id,
            'student_id' => $student->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectModerateComment(int $tenantId, array $raw): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $comment = MemberComment::query()->where('product_id', $product->id)->whereKey((int) ($raw['comment_id'] ?? 0))->first();
        if ($comment === null) {
            throw new McpCatalogException('Comentário não encontrado neste produto.', 'not_found');
        }
        $status = (string) ($raw['status'] ?? '');
        if (! in_array($status, ['approved', 'rejected'], true)) {
            throw new McpCatalogException('status deve ser approved ou rejected.', 'validation');
        }

        return $this->ok('moderate_comment', 'Moderar comentário #'.$comment->id, [
            'product_id' => (string) $product->id,
            'comment_id' => $comment->id,
            'status' => $status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectUpdateConfig(int $tenantId, array $raw): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $data = ['product_id' => (string) $product->id];
        if (isset($raw['theme']) && is_array($raw['theme'])) {
            $theme = [];
            foreach (['primary', 'background', 'sidebar_bg', 'text', 'accent'] as $key) {
                if (! array_key_exists($key, $raw['theme'])) {
                    continue;
                }
                $hex = $this->hexColor((string) $raw['theme'][$key]);
                if ($hex === null) {
                    throw new McpCatalogException('Cor inválida em theme.'.$key.'. Use #RGB ou #RRGGBB.', 'validation');
                }
                $theme[$key] = $hex;
            }
            if ($theme !== []) {
                $data['theme'] = $theme;
            }
        }
        if (array_key_exists('hero_title', $raw)) {
            $data['hero_title'] = HtmlSanitizer::plainText((string) $raw['hero_title'], 255);
        }
        if (array_key_exists('hero_subtitle', $raw)) {
            $data['hero_subtitle'] = HtmlSanitizer::plainText((string) $raw['hero_subtitle'], 255);
        }
        if (array_key_exists('comments_enabled', $raw)) {
            $data['comments_enabled'] = (bool) $raw['comments_enabled'];
        }
        if (array_key_exists('comments_require_approval', $raw)) {
            $data['comments_require_approval'] = (bool) $raw['comments_require_approval'];
        }
        if (isset($raw['certificate']) && is_array($raw['certificate'])) {
            $data['certificate'] = $this->certificatePatch($raw['certificate']);
        }
        if (isset($raw['lesson_offer']) && is_array($raw['lesson_offer'])) {
            $data['lesson_offer'] = $this->lessonOfferPatch($tenantId, $product, $raw['lesson_offer']);
        }
        unset($data['product_id']);
        if ($data === []) {
            throw new McpCatalogException('Informe ao menos um campo de configuração para atualizar.', 'validation');
        }
        $data['product_id'] = (string) $product->id;

        return $this->ok('update_config', 'Atualizar configuração da área de membros', $data);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectCreateCommunityPage(int $tenantId, array $raw): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $title = HtmlSanitizer::plainText((string) ($raw['title'] ?? ''), 255);
        if ($title === '') {
            throw new McpCatalogException('Título da página é obrigatório.', 'validation');
        }

        return $this->ok('create_community_page', 'Criar página "'.$title.'"', [
            'product_id' => (string) $product->id,
            'title' => $title,
            'icon' => isset($raw['icon']) ? HtmlSanitizer::plainText((string) $raw['icon'], 50) : null,
            'slug' => isset($raw['slug']) ? HtmlSanitizer::plainText((string) $raw['slug'], 255) : null,
            'is_public_posting' => array_key_exists('is_public_posting', $raw) ? (bool) $raw['is_public_posting'] : true,
            'is_default' => (bool) ($raw['is_default'] ?? false),
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectUpdateCommunityPage(int $tenantId, array $raw): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $page = MemberCommunityPage::query()->where('product_id', $product->id)->whereKey((int) ($raw['page_id'] ?? 0))->first();
        if ($page === null) {
            throw new McpCatalogException('Página da comunidade não encontrada.', 'not_found');
        }
        $data = ['product_id' => (string) $product->id, 'page_id' => $page->id];
        if (array_key_exists('title', $raw)) {
            $title = HtmlSanitizer::plainText((string) $raw['title'], 255);
            if ($title === '') {
                throw new McpCatalogException('Título da página é obrigatório.', 'validation');
            }
            $data['title'] = $title;
        }
        if (array_key_exists('icon', $raw)) {
            $data['icon'] = HtmlSanitizer::plainText((string) $raw['icon'], 50);
        }
        if (array_key_exists('is_public_posting', $raw)) {
            $data['is_public_posting'] = (bool) $raw['is_public_posting'];
        }
        if (array_key_exists('is_default', $raw)) {
            $data['is_default'] = (bool) $raw['is_default'];
        }

        return $this->ok('update_community_page', 'Atualizar página "'.$page->title.'"', $data);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectGrantStudent(int $tenantId, array $raw): array
    {
        $product = $this->grantableProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $email = mb_strtolower(trim((string) ($raw['email'] ?? '')));
        $name = HtmlSanitizer::plainText((string) ($raw['name'] ?? ''), 255);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new McpCatalogException('E-mail do aluno é obrigatório e precisa ser válido.', 'validation');
        }
        if ($name === '') {
            throw new McpCatalogException('Nome do aluno é obrigatório.', 'validation');
        }
        $sendEmail = (bool) ($raw['send_access_email'] ?? false);
        $password = trim((string) ($raw['password'] ?? ''));
        $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing !== null && ! $existing->isCliente()) {
            throw new McpCatalogException('Este e-mail já pertence a outra conta da plataforma e não pode ser cadastrado como aluno.', 'conflict');
        }
        $creating = $existing === null;
        if ($creating && strlen($password) < 6) {
            $password = Str::password(16);
            $sendEmail = true;
        }
        $turmaId = isset($raw['turma_id']) ? (int) $raw['turma_id'] : null;
        if ($turmaId) {
            $this->turmaOfProduct($product, $turmaId);
        }
        $gates = [];
        if ($sendEmail) {
            $gates[] = ['code' => 'send_access_email', 'message' => 'Enviar e-mail de acesso para '.$email.'.'];
        }
        if ($creating && ($raw['password'] ?? '') === '') {
            $gates[] = ['code' => 'new_student', 'message' => 'Criar conta nova e enviar acesso (senha não é devolvida ao chat).'];
        }

        return $this->inspected(
            'grant_student',
            $gates === [] ? 'ok' : 'needs_confirmation',
            null,
            $gates,
            'Conceder acesso de '.$email.' a "'.$product->name.'"',
            [
                'product_id' => (string) $product->id,
                'name' => $name,
                'email' => $email,
                'password' => $password !== '' ? $password : null,
                'turma_id' => $turmaId,
                'send_access_email' => $sendEmail,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectRevokeStudent(int $tenantId, array $raw): array
    {
        $product = $this->grantableProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $student = $this->studentOfProduct($tenantId, $product, $raw);

        return $this->inspected(
            'revoke_student',
            'needs_confirmation',
            null,
            [['code' => 'revoke_access', 'message' => 'Revogar acesso de '.$student->email.' ao produto "'.$product->name.'".']],
            'Revogar acesso de '.$student->email,
            [
                'product_id' => (string) $product->id,
                'student_id' => $student->id,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function inspectDelete(int $tenantId, array $raw, string $op, string $idKey, string $label): array
    {
        $product = $this->memberProduct($tenantId, (string) ($raw['product_id'] ?? ''));
        $id = (int) ($raw[$idKey] ?? 0);
        $name = match ($op) {
            'delete_section' => $this->sectionOfProduct($product, $id)->title,
            'delete_module' => $this->moduleOfProduct($product, $id)->title,
            'delete_lesson' => $this->lessonOfProduct($product, $id)->title,
            'delete_turma' => $this->turmaOfProduct($product, $id)->name,
            default => (string) $id,
        };

        return $this->inspected(
            $op,
            'needs_confirmation',
            null,
            [['code' => 'destructive', 'message' => $label.' "'.$name.'" (pode apagar conteúdo em cascata).']],
            $label.' "'.$name.'"',
            ['product_id' => (string) $product->id, $idKey => $id]
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeCreateSection(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $max = (int) (MemberSection::query()->where('product_id', $product->id)->max('position') ?? 0);
        $section = MemberSection::query()->create([
            'product_id' => $product->id,
            'title' => $data['title'],
            'position' => $max + 1,
            'cover_mode' => $data['cover_mode'],
            'section_type' => $data['section_type'],
        ]);
        $this->log(SellerActivityLogService::PRODUCT_UPDATED, $tenantId, $product, ['name' => $product->name, 'mcp' => 'create_section']);

        return ['op' => 'create_section', 'section' => ['id' => $section->id, 'title' => $section->title, 'section_type' => $section->section_type]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeUpdateSection(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $section = $this->sectionOfProduct($product, (int) $data['section_id']);
        $attrs = [];
        foreach (['title', 'cover_mode', 'section_type'] as $key) {
            if (array_key_exists($key, $data)) {
                $attrs[$key] = $data[$key];
            }
        }
        if ($attrs !== []) {
            $section->update($attrs);
        }
        $this->log(SellerActivityLogService::PRODUCT_UPDATED, $tenantId, $product, ['name' => $product->name, 'mcp' => 'update_section']);

        return ['op' => 'update_section', 'section' => ['id' => $section->id, 'title' => $section->title]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeDeleteSection(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $section = $this->sectionOfProduct($product, (int) $data['section_id']);
        $id = $section->id;
        $section->delete();
        $this->log(SellerActivityLogService::PRODUCT_UPDATED, $tenantId, $product, ['name' => $product->name, 'mcp' => 'delete_section']);

        return ['op' => 'delete_section', 'section_id' => $id];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeCreateModule(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $section = $this->sectionOfProduct($product, (int) $data['section_id']);
        $max = (int) (MemberModule::query()->where('member_section_id', $section->id)->max('position') ?? 0);
        $attrs = [
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => $data['title'],
            'position' => $max + 1,
            'show_title_on_cover' => $data['show_title_on_cover'] ?? true,
            'release_after_days' => $data['release_after_days'] ?? null,
            'release_at_date' => $data['release_at_date'] ?? null,
            'expire_after_days' => $data['expire_after_days'] ?? null,
            'expire_at_date' => $data['expire_at_date'] ?? null,
            'renewal_price' => $data['renewal_price'] ?? null,
            'related_product_id' => $data['related_product_id'] ?? null,
            'access_type' => $data['access_type'] ?? null,
            'external_url' => $data['external_url'] ?? null,
        ];
        $module = MemberModule::query()->create($attrs);
        $this->log(SellerActivityLogService::PRODUCT_UPDATED, $tenantId, $product, ['name' => $product->name, 'mcp' => 'create_module']);

        return ['op' => 'create_module', 'module' => $this->moduleSummary($module)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeUpdateModule(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $module = $this->moduleOfProduct($product, (int) $data['module_id']);
        $attrs = [];
        foreach (['title', 'show_title_on_cover', 'release_after_days', 'release_at_date', 'expire_after_days', 'expire_at_date', 'renewal_price', 'related_product_id', 'access_type', 'external_url'] as $key) {
            if (array_key_exists($key, $data)) {
                $attrs[$key] = $data[$key];
            }
        }
        if ($attrs !== []) {
            $module->update($attrs);
        }
        $this->log(SellerActivityLogService::PRODUCT_UPDATED, $tenantId, $product, ['name' => $product->name, 'mcp' => 'update_module']);

        return ['op' => 'update_module', 'module' => $this->moduleSummary($module->fresh() ?? $module)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeDeleteModule(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $module = $this->moduleOfProduct($product, (int) $data['module_id']);
        $id = $module->id;
        $module->delete();
        $this->log(SellerActivityLogService::PRODUCT_UPDATED, $tenantId, $product, ['name' => $product->name, 'mcp' => 'delete_module']);

        return ['op' => 'delete_module', 'module_id' => $id];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeCreateLesson(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $module = $this->moduleOfProduct($product, (int) $data['module_id']);
        $max = (int) (MemberLesson::query()->where('member_module_id', $module->id)->max('position') ?? 0);
        $lesson = MemberLesson::query()->create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => $data['title'],
            'position' => $max + 1,
            'type' => $data['type'],
            'content_url' => $data['content_url'],
            'link_title' => $data['link_title'] ?: null,
            'content_text' => $data['content_text'],
            'duration_seconds' => $data['duration_seconds'],
            'is_free' => (bool) $data['is_free'],
            'release_after_days' => $data['release_after_days'] ?? null,
            'release_at_date' => $data['release_at_date'] ?? null,
        ]);
        $this->log(SellerActivityLogService::PRODUCT_UPDATED, $tenantId, $product, ['name' => $product->name, 'mcp' => 'create_lesson']);

        return ['op' => 'create_lesson', 'lesson' => $this->lessonSummary($lesson)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeUpdateLesson(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $lesson = $this->lessonOfProduct($product, (int) $data['lesson_id']);
        $attrs = [];
        foreach (['title', 'type', 'content_url', 'link_title', 'content_text', 'duration_seconds', 'is_free', 'release_after_days', 'release_at_date'] as $key) {
            if (array_key_exists($key, $data)) {
                $attrs[$key] = $data[$key];
            }
        }
        if ($attrs !== []) {
            $lesson->update($attrs);
        }
        $this->log(SellerActivityLogService::PRODUCT_UPDATED, $tenantId, $product, ['name' => $product->name, 'mcp' => 'update_lesson']);

        return ['op' => 'update_lesson', 'lesson' => $this->lessonSummary($lesson->fresh() ?? $lesson)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeDeleteLesson(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $lesson = $this->lessonOfProduct($product, (int) $data['lesson_id']);
        $id = $lesson->id;
        $lesson->delete();
        $this->log(SellerActivityLogService::PRODUCT_UPDATED, $tenantId, $product, ['name' => $product->name, 'mcp' => 'delete_lesson']);

        return ['op' => 'delete_lesson', 'lesson_id' => $id];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeReorder(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $ids = $data['ordered_ids'];
        if ($data['scope'] === 'sections') {
            foreach ($ids as $index => $id) {
                MemberSection::query()->where('product_id', $product->id)->whereKey($id)->update(['position' => $index + 1]);
            }
        } elseif ($data['scope'] === 'modules') {
            foreach ($ids as $index => $id) {
                MemberModule::query()
                    ->where('product_id', $product->id)
                    ->where('member_section_id', (int) $data['section_id'])
                    ->whereKey($id)
                    ->update(['position' => $index + 1]);
            }
        } else {
            foreach ($ids as $index => $id) {
                MemberLesson::query()
                    ->where('product_id', $product->id)
                    ->where('member_module_id', (int) $data['module_id'])
                    ->whereKey($id)
                    ->update(['position' => $index + 1]);
            }
        }

        return ['op' => 'reorder', 'scope' => $data['scope'], 'ordered_ids' => $ids];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeCreateTurma(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $max = (int) (MemberTurma::query()->where('product_id', $product->id)->max('position') ?? 0);
        $turma = MemberTurma::query()->create([
            'product_id' => $product->id,
            'name' => $data['name'],
            'description' => $data['description'] ?: null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'position' => $max + 1,
        ]);

        return ['op' => 'create_turma', 'turma' => $this->turmaSummary($turma)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeUpdateTurma(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $turma = $this->turmaOfProduct($product, (int) $data['turma_id']);
        $attrs = [];
        foreach (['name', 'description', 'start_date', 'end_date'] as $key) {
            if (array_key_exists($key, $data)) {
                $attrs[$key] = $data[$key];
            }
        }
        if ($attrs !== []) {
            $turma->update($attrs);
        }

        return ['op' => 'update_turma', 'turma' => $this->turmaSummary($turma->fresh() ?? $turma)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeDeleteTurma(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $turma = $this->turmaOfProduct($product, (int) $data['turma_id']);
        $id = $turma->id;
        $turma->delete();

        return ['op' => 'delete_turma', 'turma_id' => $id];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeAttachTurma(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $turma = $this->turmaOfProduct($product, (int) $data['turma_id']);
        $turma->users()->syncWithoutDetaching([(int) $data['student_id']]);

        return ['op' => 'attach_turma_student', 'turma_id' => $turma->id, 'student_id' => (int) $data['student_id']];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeDetachTurma(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $turma = $this->turmaOfProduct($product, (int) $data['turma_id']);
        $turma->users()->detach((int) $data['student_id']);

        return ['op' => 'detach_turma_student', 'turma_id' => $turma->id, 'student_id' => (int) $data['student_id']];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeModerateComment(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $comment = MemberComment::query()->where('product_id', $product->id)->whereKey((int) $data['comment_id'])->first();
        if ($comment === null) {
            throw new McpCatalogException('Comentário não encontrado neste produto.', 'not_found');
        }
        $reviewer = User::query()->whereKey($tenantId)->first();
        if ($reviewer === null) {
            throw new McpCatalogException('Conta do infoprodutor não encontrada.', 'validation');
        }
        if ($data['status'] === 'approved') {
            $this->comments->approve($comment, $reviewer);
        } else {
            $this->comments->reject($comment, $reviewer);
        }

        return ['op' => 'moderate_comment', 'comment_id' => $comment->id, 'status' => $data['status']];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeUpdateConfig(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $config = $product->member_area_config;
        if (isset($data['theme']) && is_array($data['theme'])) {
            $config['theme'] = array_merge(is_array($config['theme'] ?? null) ? $config['theme'] : [], $data['theme']);
        }
        if (array_key_exists('hero_title', $data) || array_key_exists('hero_subtitle', $data)) {
            $config['hero'] = is_array($config['hero'] ?? null) ? $config['hero'] : [];
            if (array_key_exists('hero_title', $data)) {
                $config['hero']['title'] = $data['hero_title'];
            }
            if (array_key_exists('hero_subtitle', $data)) {
                $config['hero']['subtitle'] = $data['hero_subtitle'];
            }
        }
        if (array_key_exists('comments_enabled', $data)) {
            $config['comments_enabled'] = $data['comments_enabled'];
        }
        if (array_key_exists('comments_require_approval', $data)) {
            $config['comments_require_approval'] = $data['comments_require_approval'];
        }
        if (isset($data['certificate']) && is_array($data['certificate'])) {
            $config['certificate'] = array_merge(is_array($config['certificate'] ?? null) ? $config['certificate'] : [], $data['certificate']);
        }
        if (isset($data['lesson_offer']) && is_array($data['lesson_offer'])) {
            $config['lesson_offer'] = array_merge(is_array($config['lesson_offer'] ?? null) ? $config['lesson_offer'] : [], $data['lesson_offer']);
        }
        $product->update(['member_area_config' => $config]);
        $this->log(SellerActivityLogService::PRODUCT_UPDATED, $tenantId, $product, ['name' => $product->name, 'mcp' => 'update_config']);

        return ['op' => 'update_config', 'product_id' => (string) $product->id];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeCreateCommunityPage(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        if ($data['is_default']) {
            MemberCommunityPage::query()->where('product_id', $product->id)->update(['is_default' => false]);
        }
        $max = (int) (MemberCommunityPage::query()->where('product_id', $product->id)->max('position') ?? 0);
        $page = MemberCommunityPage::query()->create([
            'product_id' => $product->id,
            'title' => $data['title'],
            'icon' => $data['icon'] ?: null,
            'slug' => $data['slug'] ?: null,
            'position' => $max + 1,
            'is_public_posting' => $data['is_public_posting'],
            'is_default' => $data['is_default'],
        ]);

        return ['op' => 'create_community_page', 'page' => $this->communityPageSummary($page)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeUpdateCommunityPage(int $tenantId, array $data): array
    {
        $product = $this->memberProduct($tenantId, (string) $data['product_id']);
        $page = MemberCommunityPage::query()->where('product_id', $product->id)->whereKey((int) $data['page_id'])->first();
        if ($page === null) {
            throw new McpCatalogException('Página da comunidade não encontrada.', 'not_found');
        }
        if (! empty($data['is_default'])) {
            MemberCommunityPage::query()->where('product_id', $product->id)->where('id', '!=', $page->id)->update(['is_default' => false]);
        }
        $attrs = [];
        foreach (['title', 'icon', 'is_public_posting', 'is_default'] as $key) {
            if (array_key_exists($key, $data)) {
                $attrs[$key] = $data[$key];
            }
        }
        if ($attrs !== []) {
            $page->update($attrs);
        }

        return ['op' => 'update_community_page', 'page' => $this->communityPageSummary($page->fresh() ?? $page)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeGrantStudent(int $tenantId, array $data): array
    {
        $product = $this->grantableProduct($tenantId, (string) $data['product_id']);
        try {
            $resolved = $this->accounts->resolveOrCreateCliente(
                (string) $data['email'],
                (string) $data['name'],
                isset($data['password']) && is_string($data['password']) ? $data['password'] : null
            );
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();
            throw new McpCatalogException(is_string($first) ? $first : 'Não foi possível cadastrar o aluno.', 'validation');
        }
        $user = $resolved['user'];
        $this->grants->grant($user, $product);
        if (! empty($data['turma_id'])) {
            $turma = $this->turmaOfProduct($product, (int) $data['turma_id']);
            $turma->users()->syncWithoutDetaching([$user->id]);
        }
        $emailSent = false;
        if (! empty($data['send_access_email'])) {
            $emailSent = $this->accessEmail->sendForUserProduct($user, $product)->success;
        }
        $this->log(
            $resolved['created'] ? SellerActivityLogService::STUDENT_CREATED : SellerActivityLogService::STUDENT_PRODUCT_ADDED,
            $tenantId,
            $user,
            ['email' => $user->email, 'product_id' => (string) $product->id]
        );

        return [
            'op' => 'grant_student',
            'created' => $resolved['created'],
            'access_email_sent' => $emailSent,
            'student' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'product_id' => (string) $product->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function executeRevokeStudent(int $tenantId, array $data): array
    {
        $product = $this->grantableProduct($tenantId, (string) $data['product_id']);
        $student = $this->studentOfProduct($tenantId, $product, ['student_id' => $data['student_id']]);
        $this->grants->revoke($student, $product);
        $this->log(SellerActivityLogService::STUDENT_PRODUCT_REMOVED, $tenantId, $student, [
            'email' => $student->email,
            'product_id' => (string) $product->id,
        ]);

        return [
            'op' => 'revoke_student',
            'student_id' => $student->id,
            'product_id' => (string) $product->id,
        ];
    }

    private function memberProduct(int $tenantId, string $productId): Product
    {
        $product = $this->tenantProduct($tenantId, $productId);
        if ($product->type !== Product::TYPE_AREA_MEMBROS) {
            throw new McpCatalogException('Este produto não é uma área de membros interna.', 'validation');
        }

        return $product;
    }

    private function grantableProduct(int $tenantId, string $productId): Product
    {
        $product = $this->tenantProduct($tenantId, $productId);
        if (! in_array($product->type, [Product::TYPE_AREA_MEMBROS, Product::TYPE_AREA_MEMBROS_EXTERNA], true)) {
            throw new McpCatalogException('Só é possível conceder acesso a produto de área de membros.', 'validation');
        }

        return $product;
    }

    private function tenantProduct(int $tenantId, string $productId): Product
    {
        $productId = trim($productId);
        if ($productId === '') {
            throw new McpCatalogException('product_id é obrigatório.', 'validation');
        }
        $product = Product::query()->forTenant($tenantId)->whereKey($productId)->first();
        if ($product === null) {
            throw new McpCatalogException('Produto não encontrado neste catálogo.', 'not_found');
        }

        return $product;
    }

    private function sectionOfProduct(Product $product, int $sectionId): MemberSection
    {
        $section = MemberSection::query()->where('product_id', $product->id)->whereKey($sectionId)->first();
        if ($section === null) {
            throw new McpCatalogException('Seção não encontrada neste produto.', 'not_found');
        }

        return $section;
    }

    private function moduleOfProduct(Product $product, int $moduleId): MemberModule
    {
        $module = MemberModule::query()->where('product_id', $product->id)->whereKey($moduleId)->first();
        if ($module === null) {
            throw new McpCatalogException('Módulo não encontrado neste produto.', 'not_found');
        }

        return $module;
    }

    private function lessonOfProduct(Product $product, int $lessonId): MemberLesson
    {
        $lesson = MemberLesson::query()->where('product_id', $product->id)->whereKey($lessonId)->first();
        if ($lesson === null) {
            throw new McpCatalogException('Aula não encontrada neste produto.', 'not_found');
        }

        return $lesson;
    }

    private function turmaOfProduct(Product $product, int $turmaId): MemberTurma
    {
        $turma = MemberTurma::query()->where('product_id', $product->id)->whereKey($turmaId)->first();
        if ($turma === null) {
            throw new McpCatalogException('Turma não encontrada neste produto.', 'not_found');
        }

        return $turma;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function studentOfProduct(int $tenantId, Product $product, array $raw): User
    {
        $id = (int) ($raw['student_id'] ?? 0);
        $email = mb_strtolower(trim((string) ($raw['email'] ?? '')));
        $builder = User::query()->whereIn('role', User::buyerRoleValues())
            ->whereHas('products', fn ($q) => $q->forTenant($tenantId)->where('products.id', $product->id));
        if ($id > 0) {
            $user = $builder->whereKey($id)->first();
        } elseif ($email !== '') {
            $user = $builder->whereRaw('LOWER(email) = ?', [$email])->first();
        } else {
            throw new McpCatalogException('Informe student_id ou email.', 'validation');
        }
        if ($user === null) {
            throw new McpCatalogException('Aluno não encontrado neste produto.', 'not_found');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function scheduleFromRaw(array $raw, bool $creating = true): array
    {
        $out = [];
        $hasRelease = array_key_exists('release_at_date', $raw) || array_key_exists('release_after_days', $raw);
        if ($creating || $hasRelease) {
            $date = $this->optionalDate($raw['release_at_date'] ?? null);
            $days = isset($raw['release_after_days']) ? (int) $raw['release_after_days'] : null;
            if ($date !== null) {
                $out['release_at_date'] = $date;
                $out['release_after_days'] = null;
            } elseif ($days !== null && $days > 0) {
                $out['release_after_days'] = $days;
                $out['release_at_date'] = null;
            } else {
                $out['release_after_days'] = null;
                $out['release_at_date'] = null;
            }
        }
        $hasExpire = array_key_exists('expire_at_date', $raw) || array_key_exists('expire_after_days', $raw);
        if ($creating || $hasExpire) {
            $date = $this->optionalDate($raw['expire_at_date'] ?? null);
            $days = isset($raw['expire_after_days']) ? (int) $raw['expire_after_days'] : null;
            if ($date !== null) {
                $out['expire_at_date'] = $date;
                $out['expire_after_days'] = null;
            } elseif ($days !== null && $days > 0) {
                $out['expire_after_days'] = $days;
                $out['expire_at_date'] = null;
            } else {
                $out['expire_after_days'] = null;
                $out['expire_at_date'] = null;
            }
        }
        if ($creating || array_key_exists('renewal_price', $raw)) {
            $price = $raw['renewal_price'] ?? null;
            $out['renewal_price'] = ($price !== null && $price !== '' && (float) $price > 0)
                ? round((float) $price, 2)
                : null;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function lessonReleaseFromRaw(array $raw, bool $creating = true): array
    {
        $has = array_key_exists('release_at_date', $raw) || array_key_exists('release_after_days', $raw);
        if (! $creating && ! $has) {
            return [];
        }
        $date = $this->optionalDate($raw['release_at_date'] ?? null);
        $days = isset($raw['release_after_days']) ? (int) $raw['release_after_days'] : null;
        if ($date !== null) {
            return ['release_at_date' => $date, 'release_after_days' => null];
        }
        if ($days !== null && $days > 0) {
            return ['release_after_days' => $days, 'release_at_date' => null];
        }

        return ['release_after_days' => null, 'release_at_date' => null];
    }

    private function contentUrlForType(string $type, mixed $raw, bool $required): ?string
    {
        $url = is_string($raw) ? trim($raw) : '';
        if ($url === '') {
            if ($required) {
                throw new McpCatalogException('content_url http(s) é obrigatória para aula do tipo '.$type.'.', 'validation');
            }

            return null;
        }
        $safe = SafeUrl::normalizeHttpUrl($url);
        if ($safe === null) {
            throw new McpCatalogException('content_url precisa ser http(s).', 'validation');
        }

        return $safe;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function certificatePatch(array $raw): array
    {
        $out = [];
        if (array_key_exists('enabled', $raw)) {
            $out['enabled'] = (bool) $raw['enabled'];
        }
        foreach (['title', 'signature_text', 'header_text', 'recipient_intro_text', 'completion_text'] as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = HtmlSanitizer::plainText((string) $raw[$key], 255);
            }
        }
        if (array_key_exists('release_mode', $raw)) {
            $mode = (string) $raw['release_mode'];
            if (! in_array($mode, ['completion_percent', 'days_after_access', 'both'], true)) {
                throw new McpCatalogException('release_mode de certificado inválido.', 'validation');
            }
            $out['release_mode'] = $mode;
        }
        if (array_key_exists('completion_percent', $raw)) {
            $out['completion_percent'] = max(1, min(100, (int) $raw['completion_percent']));
        }
        if (array_key_exists('days_after_access', $raw)) {
            $out['days_after_access'] = max(0, (int) $raw['days_after_access']);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function lessonOfferPatch(int $tenantId, Product $product, array $raw): array
    {
        $out = [];
        if (array_key_exists('enabled', $raw)) {
            $out['enabled'] = (bool) $raw['enabled'];
        }
        if (array_key_exists('button_text', $raw)) {
            $out['button_text'] = HtmlSanitizer::plainText((string) $raw['button_text'], 80) ?: 'Comprar';
        }
        if (array_key_exists('product_id', $raw) && $raw['product_id'] !== null && $raw['product_id'] !== '') {
            $target = $this->tenantProduct($tenantId, (string) $raw['product_id']);
            if ((string) $target->id === (string) $product->id) {
                throw new McpCatalogException('A oferta da aula precisa ser outro produto.', 'validation');
            }
            $out['product_id'] = (string) $target->id;
        }
        if (($out['enabled'] ?? false) === true && empty($out['product_id']) && empty($product->member_area_config['lesson_offer']['product_id'])) {
            throw new McpCatalogException('Oferta na aula ativa precisa de product_id.', 'validation');
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleSummary(MemberModule $module, bool $withLessons = false): array
    {
        $row = [
            'id' => $module->id,
            'title' => $module->title,
            'position' => $module->position,
            'show_title_on_cover' => (bool) $module->show_title_on_cover,
            'release_after_days' => $module->release_after_days,
            'release_at_date' => $module->release_at_date?->format('Y-m-d'),
            'expire_after_days' => $module->expire_after_days,
            'expire_at_date' => $module->expire_at_date?->format('Y-m-d'),
            'renewal_price' => $module->renewal_price !== null ? (float) $module->renewal_price : null,
            'related_product_id' => $module->related_product_id ? (string) $module->related_product_id : null,
            'access_type' => $module->access_type,
            'external_url' => $module->external_url,
        ];
        if ($withLessons) {
            $row['lessons'] = $module->lessons->map(fn (MemberLesson $lesson) => $this->lessonSummary($lesson))->values()->all();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function lessonSummary(MemberLesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'position' => $lesson->position,
            'type' => $lesson->type,
            'content_url' => $lesson->content_url,
            'link_title' => $lesson->link_title,
            'content_text' => $lesson->content_text,
            'duration_seconds' => $lesson->duration_seconds,
            'is_free' => (bool) $lesson->is_free,
            'release_after_days' => $lesson->release_after_days,
            'release_at_date' => $lesson->release_at_date?->format('Y-m-d'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function turmaSummary(MemberTurma $turma, bool $withUsers = false): array
    {
        $row = [
            'id' => $turma->id,
            'name' => $turma->name,
            'description' => $turma->description,
            'start_date' => $turma->start_date?->format('Y-m-d'),
            'end_date' => $turma->end_date?->format('Y-m-d'),
        ];
        if ($withUsers) {
            $row['students'] = $turma->users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values()->all();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function communityPageSummary(MemberCommunityPage $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'icon' => $page->icon,
            'is_public_posting' => (bool) $page->is_public_posting,
            'is_default' => (bool) $page->is_default,
        ];
    }

    /**
     * @param  list<int|string>  $ordered
     * @param  list<int|string>  $existing
     */
    private function assertSameIds(array $ordered, array $existing): void
    {
        $a = array_map('intval', $existing);
        $b = array_map('intval', $ordered);
        sort($a);
        sort($b);
        if ($a !== $b) {
            throw new McpCatalogException('A lista não corresponde aos itens deste contexto.', 'validation');
        }
    }

    private function optionalDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = trim((string) $raw);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new McpCatalogException('Data deve estar no formato YYYY-MM-DD.', 'validation');
        }

        return $value;
    }

    private function hexColor(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) !== 1) {
            return null;
        }

        return strtolower($value);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function ok(string $op, string $summary, array $normalized): array
    {
        return $this->inspected($op, 'ok', null, [], $summary, $normalized);
    }

    /**
     * @param  list<array<string, mixed>>  $gates
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function inspected(
        string $op,
        string $status,
        ?string $error = null,
        array $gates = [],
        string $summary = '',
        array $normalized = [],
    ): array {
        return [
            'op' => $op,
            'status' => $status,
            'gates' => $gates,
            'error' => $error,
            'summary' => $summary !== '' ? $summary : ($error ?: $op),
            'normalized' => $normalized,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function publicInspected(array $items): array
    {
        return array_map(static function (array $item): array {
            return [
                'op' => $item['op'] ?? '',
                'status' => $item['status'] ?? '',
                'summary' => $item['summary'] ?? '',
                'error' => $item['error'] ?? null,
                'gates' => $item['gates'] ?? [],
            ];
        }, $items);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function log(string $action, int $tenantId, mixed $target, array $metadata): void
    {
        $targetType = is_object($target) ? $target::class : null;
        $targetId = is_object($target) ? ($target->id ?? null) : null;
        SellerActivityLogService::record(
            actor: null,
            action: $action,
            targetType: $targetType,
            targetId: $targetId,
            metadata: $metadata,
            tenantId: $tenantId,
            source: 'mcp',
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $schema): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $schema,
        ];
    }
}
