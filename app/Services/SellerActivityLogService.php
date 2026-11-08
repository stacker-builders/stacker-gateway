<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SellerActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SellerActivityLogService
{
    public const GROUP_PAYOUT = 'payout';

    public const GROUP_WITHDRAWAL = 'withdrawal';

    public const GROUP_REFUND = 'refund';

    public const GROUP_TEAM = 'team';

    public const GROUP_API = 'api';

    public const GROUP_AUTH = 'auth';

    public const GROUP_KYC = 'kyc';

    public const GROUP_PRODUCT = 'product';

    public const GROUP_COMMERCE = 'commerce';

    public const GROUP_PARTNER = 'partner';

    public const GROUP_INTEGRATION = 'integration';

    public const GROUP_DISPUTE = 'dispute';

    public const GROUP_SUBSCRIPTION = 'subscription';

    public const PAYOUT_SETTINGS_UPDATED = 'payout.settings.updated';

    public const WITHDRAWAL_REQUESTED = 'withdrawal.requested';

    public const WITHDRAWAL_REFERRAL_REQUESTED = 'withdrawal.referral_requested';

    public const REFUND_COMPLETED = 'refund.completed';

    public const REFUND_FAILED = 'refund.failed';

    public const REFUND_REQUEST_APPROVED = 'refund.request.approved';

    public const REFUND_REQUEST_REJECTED = 'refund.request.rejected';

    public const TEAM_ROLE_CREATED = 'team.role.created';

    public const TEAM_ROLE_UPDATED = 'team.role.updated';

    public const TEAM_ROLE_DELETED = 'team.role.deleted';

    public const TEAM_MEMBER_CREATED = 'team.member.created';

    public const TEAM_MEMBER_UPDATED = 'team.member.updated';

    public const TEAM_MEMBER_DELETED = 'team.member.deleted';

    public const API_KEY_CREATED = 'api.key.created';

    public const API_KEY_UPDATED = 'api.key.updated';

    public const API_KEY_ROTATED = 'api.key.rotated';

    public const API_KEY_SECRET_REVEALED = 'api.key.secret.revealed';

    public const API_KEY_DELETED = 'api.key.deleted';

    public const API_CREDENTIALS_ROTATED = 'api.credentials.rotated';

    public const API_SECRET_REVEALED = 'api.secret.revealed';

    public const API_WEBHOOK_UPDATED = 'api.webhook.updated';

    public const API_WEBHOOK_CLEARED = 'api.webhook.cleared';

    public const API_WEBHOOK_SECRET_ROTATED = 'api.webhook.secret.rotated';

    public const API_WEBHOOK_DELIVERY_RETRIED = 'api.webhook.delivery.retried';

    public const API_PIX_CANCELLED = 'api.pix.cancelled';

    public const AUTH_LOGIN = 'auth.login';

    public const AUTH_LOGOUT = 'auth.logout';

    public const AUTH_TOTP_ENABLED = 'auth.totp.enabled';

    public const AUTH_TOTP_DISABLED = 'auth.totp.disabled';

    public const PROFILE_UPDATED = 'profile.updated';

    public const PROFILE_USERNAME_UPDATED = 'profile.username.updated';

    public const PROFILE_PASSWORD_UPDATED = 'profile.password.updated';

    public const PROFILE_WHATSAPP_UPDATED = 'profile.whatsapp.updated';

    public const KYC_DOCUMENT_UPLOADED = 'kyc.document.uploaded';

    public const KYC_SUBMITTED = 'kyc.submitted';

    public const PJ_CONVERSION_STARTED = 'kyc.pj_conversion.started';

    public const PJ_CONVERSION_SUBMITTED = 'kyc.pj_conversion.submitted';

    public const PJ_CONVERSION_CANCELLED = 'kyc.pj_conversion.cancelled';

    public const PRODUCT_CREATED = 'product.created';

    public const PRODUCT_UPDATED = 'product.updated';

    public const PRODUCT_DELETED = 'product.deleted';

    public const PRODUCT_DUPLICATED = 'product.duplicated';

    public const PRODUCT_RESUBMITTED = 'product.resubmitted';

    public const PRODUCT_OFFER_CREATED = 'product.offer.created';

    public const PRODUCT_OFFER_UPDATED = 'product.offer.updated';

    public const PRODUCT_OFFER_DELETED = 'product.offer.deleted';

    public const PRODUCT_ORDER_BUMP_CREATED = 'product.order_bump.created';

    public const PRODUCT_ORDER_BUMP_UPDATED = 'product.order_bump.updated';

    public const PRODUCT_ORDER_BUMP_DELETED = 'product.order_bump.deleted';

    public const PRODUCT_PLAN_CREATED = 'product.plan.created';

    public const PRODUCT_PLAN_UPDATED = 'product.plan.updated';

    public const PRODUCT_PLAN_DELETED = 'product.plan.deleted';

    public const PRODUCT_CHECKOUT_UPDATED = 'product.checkout.updated';

    public const PRODUCT_CHECKOUT_SLUG_GENERATED = 'product.checkout.slug.generated';

    public const PRODUCT_CHECKOUT_SLUG_REMOVED = 'product.checkout.slug.removed';

    public const PRODUCT_UPSELL_UPDATED = 'product.upsell.updated';

    public const PRODUCT_DOWNSELL_UPDATED = 'product.downsell.updated';

    public const PRODUCT_MEMBER_AREA_UPDATED = 'product.member_area.updated';

    public const COUPON_CREATED = 'coupon.created';

    public const COUPON_UPDATED = 'coupon.updated';

    public const COUPON_DELETED = 'coupon.deleted';

    public const STUDENT_CREATED = 'student.created';

    public const STUDENT_UPDATED = 'student.updated';

    public const STUDENT_DELETED = 'student.deleted';

    public const STUDENT_IMPORTED = 'student.imported';

    public const STUDENT_PRODUCT_REMOVED = 'student.product.removed';

    public const STUDENT_PRODUCT_ADDED = 'student.product.added';

    public const SHIPPING_STORE_CREATED = 'shipping.store.created';

    public const SHIPPING_STORE_UPDATED = 'shipping.store.updated';

    public const SHIPPING_STORE_DELETED = 'shipping.store.deleted';

    public const SHIPPING_RULE_CREATED = 'shipping.rule.created';

    public const SHIPPING_RULE_UPDATED = 'shipping.rule.updated';

    public const SHIPPING_RULE_DELETED = 'shipping.rule.deleted';

    public const AFFILIATE_SETTINGS_UPDATED = 'affiliate.settings.updated';

    public const AFFILIATE_APPROVED = 'affiliate.enrollment.approved';

    public const AFFILIATE_REJECTED = 'affiliate.enrollment.rejected';

    public const AFFILIATE_REVOKED = 'affiliate.enrollment.revoked';

    public const AFFILIATE_ENROLLED = 'affiliate.showcase.enrolled';

    public const COPRODUCTION_INVITED = 'coproduction.invited';

    public const COPRODUCTION_REMOVED = 'coproduction.removed';

    public const COPRODUCTION_ACCEPTED = 'coproduction.invite.accepted';

    public const INTEGRATION_PLUGIN_ENABLED = 'integration.plugin.enabled';

    public const INTEGRATION_PLUGIN_DISABLED = 'integration.plugin.disabled';

    public const INTEGRATION_PLUGIN_UNINSTALLED = 'integration.plugin.uninstalled';

    public const INTEGRATION_UTMIFY_CREATED = 'integration.utmify.created';

    public const INTEGRATION_UTMIFY_UPDATED = 'integration.utmify.updated';

    public const INTEGRATION_UTMIFY_DELETED = 'integration.utmify.deleted';

    public const INTEGRATION_SPEDY_CREATED = 'integration.spedy.created';

    public const INTEGRATION_SPEDY_UPDATED = 'integration.spedy.updated';

    public const INTEGRATION_SPEDY_DELETED = 'integration.spedy.deleted';

    public const INTEGRATION_CADEMI_CREATED = 'integration.cademi.created';

    public const INTEGRATION_CADEMI_UPDATED = 'integration.cademi.updated';

    public const INTEGRATION_CADEMI_DELETED = 'integration.cademi.deleted';

    public const INTEGRATION_WEBHOOK_CREATED = 'integration.webhook.created';

    public const INTEGRATION_WEBHOOK_UPDATED = 'integration.webhook.updated';

    public const INTEGRATION_WEBHOOK_DELETED = 'integration.webhook.deleted';

    public const INTEGRATION_UAZAPI_CONNECTED = 'integration.uazapi.connected';

    public const INTEGRATION_UAZAPI_DISCONNECTED = 'integration.uazapi.disconnected';

    public const INTEGRATION_UAZAPI_UPDATED = 'integration.uazapi.updated';

    public const INTEGRATION_UAZAPI_CAMPAIGN = 'integration.uazapi.campaign';

    public const INTEGRATION_EVOLUTION_CONNECTED = 'integration.evolution.connected';

    public const INTEGRATION_EVOLUTION_DISCONNECTED = 'integration.evolution.disconnected';

    public const INTEGRATION_EVOLUTION_UPDATED = 'integration.evolution.updated';

    public const INTEGRATION_MCP_ACTIVATED = 'integration.mcp.activated';

    public const INTEGRATION_MCP_ROTATED = 'integration.mcp.rotated';

    public const INTEGRATION_MCP_REVOKED = 'integration.mcp.revoked';

    public const INTEGRATION_TELEGRAM_BOT_CONNECTED = 'integration.telegram.bot.connected';

    public const INTEGRATION_TELEGRAM_BOT_DISCONNECTED = 'integration.telegram.bot.disconnected';

    public const INTEGRATION_TELEGRAM_COMMUNITY_LINKED = 'integration.telegram.community.linked';

    public const INTEGRATION_TELEGRAM_COMMUNITY_UNLINKED = 'integration.telegram.community.unlinked';

    public const DISPUTE_DEFENSE_SUBMITTED = 'dispute.defense.submitted';

    public const DISPUTE_DOSSIER_GENERATED = 'dispute.dossier.generated';

    public const SUBSCRIPTION_CANCELLED = 'subscription.cancelled';

    /**
     * @var array<string, array{group: string, label: string}>
     */
    public const ACTIONS = [
        self::PAYOUT_SETTINGS_UPDATED => ['group' => self::GROUP_PAYOUT, 'label' => 'Alterou dados de saque'],
        self::WITHDRAWAL_REQUESTED => ['group' => self::GROUP_WITHDRAWAL, 'label' => 'Solicitou saque'],
        self::WITHDRAWAL_REFERRAL_REQUESTED => ['group' => self::GROUP_WITHDRAWAL, 'label' => 'Solicitou saque de indicação'],
        self::REFUND_COMPLETED => ['group' => self::GROUP_REFUND, 'label' => 'Efetivou reembolso'],
        self::REFUND_FAILED => ['group' => self::GROUP_REFUND, 'label' => 'Falha ao reembolsar'],
        self::REFUND_REQUEST_APPROVED => ['group' => self::GROUP_REFUND, 'label' => 'Aprovou solicitação de reembolso'],
        self::REFUND_REQUEST_REJECTED => ['group' => self::GROUP_REFUND, 'label' => 'Recusou solicitação de reembolso'],
        self::TEAM_ROLE_CREATED => ['group' => self::GROUP_TEAM, 'label' => 'Criou cargo'],
        self::TEAM_ROLE_UPDATED => ['group' => self::GROUP_TEAM, 'label' => 'Atualizou cargo'],
        self::TEAM_ROLE_DELETED => ['group' => self::GROUP_TEAM, 'label' => 'Removeu cargo'],
        self::TEAM_MEMBER_CREATED => ['group' => self::GROUP_TEAM, 'label' => 'Adicionou membro da equipe'],
        self::TEAM_MEMBER_UPDATED => ['group' => self::GROUP_TEAM, 'label' => 'Atualizou membro da equipe'],
        self::TEAM_MEMBER_DELETED => ['group' => self::GROUP_TEAM, 'label' => 'Removeu membro da equipe'],
        self::API_KEY_CREATED => ['group' => self::GROUP_API, 'label' => 'Criou chave API'],
        self::API_KEY_UPDATED => ['group' => self::GROUP_API, 'label' => 'Atualizou chave API'],
        self::API_KEY_ROTATED => ['group' => self::GROUP_API, 'label' => 'Rotacionou chave API'],
        self::API_KEY_SECRET_REVEALED => ['group' => self::GROUP_API, 'label' => 'Revelou secret da chave API'],
        self::API_KEY_DELETED => ['group' => self::GROUP_API, 'label' => 'Excluiu chave API'],
        self::API_CREDENTIALS_ROTATED => ['group' => self::GROUP_API, 'label' => 'Rotacionou credenciais da API'],
        self::API_SECRET_REVEALED => ['group' => self::GROUP_API, 'label' => 'Revelou secret da API'],
        self::API_WEBHOOK_UPDATED => ['group' => self::GROUP_API, 'label' => 'Atualizou webhook da API'],
        self::API_WEBHOOK_CLEARED => ['group' => self::GROUP_API, 'label' => 'Removeu webhook da API'],
        self::API_WEBHOOK_SECRET_ROTATED => ['group' => self::GROUP_API, 'label' => 'Rotacionou secret do webhook'],
        self::API_WEBHOOK_DELIVERY_RETRIED => ['group' => self::GROUP_API, 'label' => 'Reenviou entrega de webhook da API'],
        self::API_PIX_CANCELLED => ['group' => self::GROUP_API, 'label' => 'Cancelou cobrança PIX via API'],
        self::AUTH_LOGIN => ['group' => self::GROUP_AUTH, 'label' => 'Entrou no painel'],
        self::AUTH_LOGOUT => ['group' => self::GROUP_AUTH, 'label' => 'Saiu do painel'],
        self::AUTH_TOTP_ENABLED => ['group' => self::GROUP_AUTH, 'label' => 'Ativou autenticação em dois fatores'],
        self::AUTH_TOTP_DISABLED => ['group' => self::GROUP_AUTH, 'label' => 'Desativou autenticação em dois fatores'],
        self::PROFILE_UPDATED => ['group' => self::GROUP_AUTH, 'label' => 'Atualizou o perfil'],
        self::PROFILE_USERNAME_UPDATED => ['group' => self::GROUP_AUTH, 'label' => 'Alterou o nome de usuário'],
        self::PROFILE_PASSWORD_UPDATED => ['group' => self::GROUP_AUTH, 'label' => 'Alterou a senha'],
        self::PROFILE_WHATSAPP_UPDATED => ['group' => self::GROUP_AUTH, 'label' => 'Atualizou o WhatsApp'],
        self::KYC_DOCUMENT_UPLOADED => ['group' => self::GROUP_KYC, 'label' => 'Enviou documento de KYC'],
        self::KYC_SUBMITTED => ['group' => self::GROUP_KYC, 'label' => 'Enviou KYC para análise'],
        self::PJ_CONVERSION_STARTED => ['group' => self::GROUP_KYC, 'label' => 'Iniciou migração de CPF para CNPJ'],
        self::PJ_CONVERSION_SUBMITTED => ['group' => self::GROUP_KYC, 'label' => 'Enviou migração para CNPJ para análise'],
        self::PJ_CONVERSION_CANCELLED => ['group' => self::GROUP_KYC, 'label' => 'Cancelou migração para CNPJ'],
        self::PRODUCT_CREATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Criou produto'],
        self::PRODUCT_UPDATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Atualizou produto'],
        self::PRODUCT_DELETED => ['group' => self::GROUP_PRODUCT, 'label' => 'Excluiu produto'],
        self::PRODUCT_DUPLICATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Duplicou produto'],
        self::PRODUCT_RESUBMITTED => ['group' => self::GROUP_PRODUCT, 'label' => 'Reenviou produto para análise'],
        self::PRODUCT_OFFER_CREATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Criou oferta'],
        self::PRODUCT_OFFER_UPDATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Atualizou oferta'],
        self::PRODUCT_OFFER_DELETED => ['group' => self::GROUP_PRODUCT, 'label' => 'Excluiu oferta'],
        self::PRODUCT_ORDER_BUMP_CREATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Criou order bump'],
        self::PRODUCT_ORDER_BUMP_UPDATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Atualizou order bump'],
        self::PRODUCT_ORDER_BUMP_DELETED => ['group' => self::GROUP_PRODUCT, 'label' => 'Excluiu order bump'],
        self::PRODUCT_PLAN_CREATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Criou plano de assinatura'],
        self::PRODUCT_PLAN_UPDATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Atualizou plano de assinatura'],
        self::PRODUCT_PLAN_DELETED => ['group' => self::GROUP_PRODUCT, 'label' => 'Excluiu plano de assinatura'],
        self::PRODUCT_CHECKOUT_UPDATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Atualizou checkout'],
        self::PRODUCT_CHECKOUT_SLUG_GENERATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Gerou link de checkout'],
        self::PRODUCT_CHECKOUT_SLUG_REMOVED => ['group' => self::GROUP_PRODUCT, 'label' => 'Removeu link exclusivo de checkout'],
        self::PRODUCT_UPSELL_UPDATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Atualizou página de upsell'],
        self::PRODUCT_DOWNSELL_UPDATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Atualizou página de downsell'],
        self::PRODUCT_MEMBER_AREA_UPDATED => ['group' => self::GROUP_PRODUCT, 'label' => 'Atualizou área de membros externa'],
        self::COUPON_CREATED => ['group' => self::GROUP_COMMERCE, 'label' => 'Criou cupom'],
        self::COUPON_UPDATED => ['group' => self::GROUP_COMMERCE, 'label' => 'Atualizou cupom'],
        self::COUPON_DELETED => ['group' => self::GROUP_COMMERCE, 'label' => 'Excluiu cupom'],
        self::STUDENT_CREATED => ['group' => self::GROUP_COMMERCE, 'label' => 'Cadastrou aluno'],
        self::STUDENT_UPDATED => ['group' => self::GROUP_COMMERCE, 'label' => 'Atualizou aluno'],
        self::STUDENT_DELETED => ['group' => self::GROUP_COMMERCE, 'label' => 'Removeu aluno'],
        self::STUDENT_IMPORTED => ['group' => self::GROUP_COMMERCE, 'label' => 'Importou alunos'],
        self::STUDENT_PRODUCT_REMOVED => ['group' => self::GROUP_COMMERCE, 'label' => 'Removeu aluno de um produto'],
        self::STUDENT_PRODUCT_ADDED => ['group' => self::GROUP_COMMERCE, 'label' => 'Adicionou aluno a um produto'],
        self::SHIPPING_STORE_CREATED => ['group' => self::GROUP_COMMERCE, 'label' => 'Criou loja de frete'],
        self::SHIPPING_STORE_UPDATED => ['group' => self::GROUP_COMMERCE, 'label' => 'Atualizou loja de frete'],
        self::SHIPPING_STORE_DELETED => ['group' => self::GROUP_COMMERCE, 'label' => 'Excluiu loja de frete'],
        self::SHIPPING_RULE_CREATED => ['group' => self::GROUP_COMMERCE, 'label' => 'Criou regra de frete'],
        self::SHIPPING_RULE_UPDATED => ['group' => self::GROUP_COMMERCE, 'label' => 'Atualizou regra de frete'],
        self::SHIPPING_RULE_DELETED => ['group' => self::GROUP_COMMERCE, 'label' => 'Excluiu regra de frete'],
        self::AFFILIATE_SETTINGS_UPDATED => ['group' => self::GROUP_PARTNER, 'label' => 'Atualizou configurações de afiliação'],
        self::AFFILIATE_APPROVED => ['group' => self::GROUP_PARTNER, 'label' => 'Aprovou afiliado'],
        self::AFFILIATE_REJECTED => ['group' => self::GROUP_PARTNER, 'label' => 'Recusou afiliado'],
        self::AFFILIATE_REVOKED => ['group' => self::GROUP_PARTNER, 'label' => 'Revogou afiliado'],
        self::AFFILIATE_ENROLLED => ['group' => self::GROUP_PARTNER, 'label' => 'Solicitou afiliação'],
        self::COPRODUCTION_INVITED => ['group' => self::GROUP_PARTNER, 'label' => 'Convidou coprodutor'],
        self::COPRODUCTION_REMOVED => ['group' => self::GROUP_PARTNER, 'label' => 'Removeu coprodutor'],
        self::COPRODUCTION_ACCEPTED => ['group' => self::GROUP_PARTNER, 'label' => 'Aceitou convite de coprodução'],
        self::INTEGRATION_PLUGIN_ENABLED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Ativou plugin'],
        self::INTEGRATION_PLUGIN_DISABLED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Desativou plugin'],
        self::INTEGRATION_PLUGIN_UNINSTALLED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Desinstalou plugin'],
        self::INTEGRATION_UTMIFY_CREATED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Criou integração UTMify'],
        self::INTEGRATION_UTMIFY_UPDATED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Atualizou integração UTMify'],
        self::INTEGRATION_UTMIFY_DELETED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Removeu integração UTMify'],
        self::INTEGRATION_SPEDY_CREATED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Criou integração Spedy'],
        self::INTEGRATION_SPEDY_UPDATED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Atualizou integração Spedy'],
        self::INTEGRATION_SPEDY_DELETED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Removeu integração Spedy'],
        self::INTEGRATION_CADEMI_CREATED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Criou integração Cademí'],
        self::INTEGRATION_CADEMI_UPDATED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Atualizou integração Cademí'],
        self::INTEGRATION_CADEMI_DELETED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Removeu integração Cademí'],
        self::INTEGRATION_WEBHOOK_CREATED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Criou webhook do painel'],
        self::INTEGRATION_WEBHOOK_UPDATED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Atualizou webhook do painel'],
        self::INTEGRATION_WEBHOOK_DELETED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Excluiu webhook do painel'],
        self::INTEGRATION_UAZAPI_CONNECTED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Conectou WhatsApp (uazapi)'],
        self::INTEGRATION_UAZAPI_DISCONNECTED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Desconectou WhatsApp (uazapi)'],
        self::INTEGRATION_UAZAPI_UPDATED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Atualizou WhatsApp (uazapi)'],
        self::INTEGRATION_UAZAPI_CAMPAIGN => ['group' => self::GROUP_INTEGRATION, 'label' => 'Disparou campanha WhatsApp'],
        self::INTEGRATION_EVOLUTION_CONNECTED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Conectou Evolution API'],
        self::INTEGRATION_EVOLUTION_DISCONNECTED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Desconectou Evolution API'],
        self::INTEGRATION_EVOLUTION_UPDATED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Atualizou Evolution API'],
        self::INTEGRATION_MCP_ACTIVATED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Ativou MCP'],
        self::INTEGRATION_MCP_ROTATED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Rotacionou token MCP'],
        self::INTEGRATION_MCP_REVOKED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Revogou MCP'],
        self::INTEGRATION_TELEGRAM_BOT_CONNECTED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Conectou bot Telegram'],
        self::INTEGRATION_TELEGRAM_BOT_DISCONNECTED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Desconectou bot Telegram'],
        self::INTEGRATION_TELEGRAM_COMMUNITY_LINKED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Vinculou comunidade Telegram'],
        self::INTEGRATION_TELEGRAM_COMMUNITY_UNLINKED => ['group' => self::GROUP_INTEGRATION, 'label' => 'Desvinculou comunidade Telegram'],
        self::DISPUTE_DEFENSE_SUBMITTED => ['group' => self::GROUP_DISPUTE, 'label' => 'Enviou defesa de disputa MED'],
        self::DISPUTE_DOSSIER_GENERATED => ['group' => self::GROUP_DISPUTE, 'label' => 'Gerou dossiê de disputa MED'],
        self::SUBSCRIPTION_CANCELLED => ['group' => self::GROUP_SUBSCRIPTION, 'label' => 'Cancelou assinatura'],
    ];

    /**
     * @var array<string, string>
     */
    public const GROUPS = [
        self::GROUP_PAYOUT => 'Dados de saque',
        self::GROUP_WITHDRAWAL => 'Saques',
        self::GROUP_REFUND => 'Reembolsos',
        self::GROUP_TEAM => 'Equipe',
        self::GROUP_API => 'API',
        self::GROUP_AUTH => 'Acesso e segurança',
        self::GROUP_KYC => 'KYC',
        self::GROUP_PRODUCT => 'Produtos e checkout',
        self::GROUP_COMMERCE => 'Cupons, alunos e frete',
        self::GROUP_PARTNER => 'Afiliados e coprodução',
        self::GROUP_INTEGRATION => 'Integrações',
        self::GROUP_DISPUTE => 'Disputas MED',
        self::GROUP_SUBSCRIPTION => 'Assinaturas',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        ?User $actor,
        string $action,
        ?string $targetType = null,
        mixed $targetId = null,
        array $metadata = [],
        ?int $tenantId = null,
        ?string $source = null,
        ?Request $request = null,
    ): void {
        try {
            if (! Schema::hasTable('seller_activity_logs')) {
                return;
            }

            $meta = self::ACTIONS[$action] ?? null;
            if ($meta === null) {
                return;
            }

            $request = $request ?? request();
            $resolvedTenantId = $tenantId
                ?? (int) ($actor?->tenant_id ?: $actor?->id ?: 0);
            if ($resolvedTenantId < 1) {
                return;
            }

            $payload = [
                'tenant_id' => $resolvedTenantId,
                'actor_user_id' => $actor?->id,
                'action' => $action,
                'action_group' => $meta['group'],
                'source' => $source ?: self::detectSource($request),
                'target_type' => $targetType,
                'target_id' => $targetId !== null ? (string) $targetId : null,
                'summary' => mb_substr(self::buildSummary($action, $metadata), 0, 255),
                'metadata' => $metadata ?: null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? (string) $request->userAgent() : null,
            ];

            $persist = static function () use ($payload): void {
                try {
                    SellerActivityLog::query()->create($payload);
                } catch (\Throwable $e) {
                    report($e);
                }
            };

            // Nunca gravar dentro da transação de negócio: no PostgreSQL um INSERT
            // falho aborta o bloco inteiro mesmo se a exceção for capturada.
            if (DB::transactionLevel() > 0) {
                DB::afterCommit($persist);

                return;
            }

            $persist();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function recordRefundFailure(
        ?User $actor,
        Order $order,
        string $reason,
        array $extra = [],
        ?string $source = null,
    ): void {
        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Falha ao solicitar reembolso.';
        }

        self::record(
            actor: $actor,
            action: self::REFUND_FAILED,
            targetType: Order::class,
            targetId: $order->id,
            metadata: array_filter([
                'order_id' => $order->id,
                'amount' => (float) ($order->amount ?? 0),
                'reason' => $reason,
                'gateway' => $order->gateway,
                'outcome' => 'failed',
                ...$extra,
            ], fn ($v) => $v !== null && $v !== ''),
            tenantId: (int) $order->tenant_id,
            source: $source,
        );
    }

    public static function maskValue(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $len = strlen($value);
        if ($len <= 4) {
            return '****';
        }

        return str_repeat('*', max(0, $len - 4)).substr($value, -4);
    }

    /**
     * @return list<array{value: string, label: string, group: string}>
     */
    public static function actionOptions(): array
    {
        $options = [];
        foreach (self::ACTIONS as $value => $meta) {
            $options[] = [
                'value' => $value,
                'label' => $meta['label'],
                'group' => $meta['group'],
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function groupOptions(): array
    {
        $options = [];
        foreach (self::GROUPS as $value => $label) {
            $options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function buildSummary(string $action, array $metadata): string
    {
        $label = self::ACTIONS[$action]['label'] ?? $action;

        return match ($action) {
            self::WITHDRAWAL_REQUESTED, self::WITHDRAWAL_REFERRAL_REQUESTED => $label.self::amountSuffix($metadata),
            self::REFUND_COMPLETED, self::REFUND_REQUEST_APPROVED, self::REFUND_REQUEST_REJECTED => $label.self::orderSuffix($metadata),
            self::REFUND_FAILED => $label.self::orderSuffix($metadata).self::reasonSuffix($metadata),
            self::TEAM_ROLE_CREATED, self::TEAM_ROLE_UPDATED, self::TEAM_ROLE_DELETED => $label.self::namedSuffix($metadata, 'name'),
            self::TEAM_MEMBER_CREATED, self::TEAM_MEMBER_UPDATED, self::TEAM_MEMBER_DELETED => $label.self::namedSuffix($metadata, 'email'),
            self::API_KEY_CREATED, self::API_KEY_UPDATED, self::API_KEY_ROTATED, self::API_KEY_DELETED => $label.self::namedSuffix($metadata, 'name'),
            self::PRODUCT_CREATED, self::PRODUCT_UPDATED, self::PRODUCT_DELETED, self::PRODUCT_DUPLICATED, self::PRODUCT_RESUBMITTED,
            self::PRODUCT_OFFER_CREATED, self::PRODUCT_OFFER_UPDATED, self::PRODUCT_OFFER_DELETED,
            self::COUPON_CREATED, self::COUPON_UPDATED, self::COUPON_DELETED,
            self::SHIPPING_STORE_CREATED, self::SHIPPING_STORE_UPDATED, self::SHIPPING_STORE_DELETED,
            self::SHIPPING_RULE_CREATED, self::SHIPPING_RULE_UPDATED, self::SHIPPING_RULE_DELETED,
            self::INTEGRATION_PLUGIN_ENABLED, self::INTEGRATION_PLUGIN_DISABLED, self::INTEGRATION_PLUGIN_UNINSTALLED,
            self::INTEGRATION_UTMIFY_CREATED, self::INTEGRATION_UTMIFY_UPDATED, self::INTEGRATION_UTMIFY_DELETED,
            self::INTEGRATION_SPEDY_CREATED, self::INTEGRATION_SPEDY_UPDATED, self::INTEGRATION_SPEDY_DELETED,
            self::INTEGRATION_CADEMI_CREATED, self::INTEGRATION_CADEMI_UPDATED, self::INTEGRATION_CADEMI_DELETED,
            self::INTEGRATION_WEBHOOK_CREATED, self::INTEGRATION_WEBHOOK_UPDATED, self::INTEGRATION_WEBHOOK_DELETED,
            self::INTEGRATION_UAZAPI_CONNECTED, self::INTEGRATION_UAZAPI_DISCONNECTED, self::INTEGRATION_UAZAPI_UPDATED, self::INTEGRATION_UAZAPI_CAMPAIGN,
            self::INTEGRATION_EVOLUTION_CONNECTED, self::INTEGRATION_EVOLUTION_DISCONNECTED, self::INTEGRATION_EVOLUTION_UPDATED,
            self::INTEGRATION_MCP_ACTIVATED, self::INTEGRATION_MCP_ROTATED, self::INTEGRATION_MCP_REVOKED,
            self::INTEGRATION_TELEGRAM_BOT_CONNECTED, self::INTEGRATION_TELEGRAM_BOT_DISCONNECTED,
            self::INTEGRATION_TELEGRAM_COMMUNITY_LINKED, self::INTEGRATION_TELEGRAM_COMMUNITY_UNLINKED,
            self::AFFILIATE_SETTINGS_UPDATED => $label.self::namedSuffix($metadata, 'name'),
            self::STUDENT_CREATED, self::STUDENT_UPDATED, self::STUDENT_DELETED => $label.self::namedSuffix($metadata, 'email'),
            self::COPRODUCTION_INVITED, self::COPRODUCTION_REMOVED, self::COPRODUCTION_ACCEPTED => $label.self::namedSuffix($metadata, 'email'),
            self::SUBSCRIPTION_CANCELLED, self::API_PIX_CANCELLED, self::DISPUTE_DEFENSE_SUBMITTED, self::DISPUTE_DOSSIER_GENERATED => $label.self::orderSuffix($metadata),
            self::PROFILE_WHATSAPP_UPDATED => $label.self::namedSuffix($metadata, 'phone_to'),
            default => $label,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function amountSuffix(array $metadata): string
    {
        if (! isset($metadata['amount'])) {
            return '';
        }

        return ' de R$ '.number_format((float) $metadata['amount'], 2, ',', '.');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function orderSuffix(array $metadata): string
    {
        $orderId = $metadata['order_id'] ?? null;
        if ($orderId === null || $orderId === '') {
            return '';
        }

        return ' do pedido #'.$orderId;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function reasonSuffix(array $metadata): string
    {
        $reason = trim((string) ($metadata['reason'] ?? ''));
        if ($reason === '') {
            return '';
        }

        if (mb_strlen($reason) > 140) {
            $reason = mb_substr($reason, 0, 139).'…';
        }

        return ' — '.$reason;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function namedSuffix(array $metadata, string $key): string
    {
        $value = trim((string) ($metadata[$key] ?? ''));
        if ($value === '') {
            return '';
        }

        return ': '.$value;
    }

    private static function detectSource(?Request $request): string
    {
        if ($request === null) {
            return 'panel';
        }

        $path = ltrim($request->path(), '/');
        if (str_starts_with($path, 'api/')) {
            return 'api';
        }
        if ($path === 'mcp' || str_starts_with($path, 'mcp/')) {
            return 'mcp';
        }

        return 'panel';
    }
}
