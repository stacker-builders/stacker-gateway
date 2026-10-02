<?php

namespace App\Services;

use App\Mail\AccessGrantedMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\AccessEmailSendResult;
use App\Support\EmailLogoHtml;
use App\Support\PublicAppUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AccessEmailService
{
    public function __construct(
        protected TenantMailConfigService $mailConfig,
        protected DeliverableAccessLinkService $deliverableAccessLinks,
    ) {}

    public function sendForOrder(Order $order, bool $force = false): AccessEmailSendResult
    {
        Log::info('AccessEmailService: tentando enviar e-mail de acesso.', ['order_id' => $order->id]);

        $order->loadMissing(['product', 'user']);
        $product = $order->product;
        if (! $product) {
            Log::warning('AccessEmailService: e-mail não enviado — pedido sem produto.', ['order_id' => $order->id]);

            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_NO_PRODUCT,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_NO_PRODUCT)
            );
        }

        $productType = $product->type;

        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            Log::info('AccessEmailService: produto área de membros, resolvendo link e senha.', [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'checkout_slug' => $product->checkout_slug,
            ]);
        }

        if ($product->type === Product::TYPE_LINK_PAGAMENTO) {
            Log::info('AccessEmailService: e-mail não enviado — produto é tipo link de pagamento.', [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_type' => $productType,
            ]);

            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_LINK_PAGAMENTO,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_LINK_PAGAMENTO)
            );
        }

        if ($product->type === Product::TYPE_PRODUTO_FISICO) {
            return $this->sendPhysicalProductConfirmationEmail($order, $product, $force);
        }

        $config = $product->checkout_config ?? [];
        $template = array_merge(Product::defaultEmailTemplate(), $config['email_template'] ?? []);
        $subject = (string) ($template['subject'] ?? 'Seu acesso');
        $bodyHtml = (string) ($template['body_html'] ?? '');

        if ($bodyHtml === '') {
            $bodyHtml = (string) (Product::defaultEmailTemplate()['body_html'] ?? '');
        }

        $customerEmail = $order->email ?: $order->user?->email;
        if (! $customerEmail || ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            Log::warning('AccessEmailService: e-mail não enviado — sem e-mail válido para o pedido.', [
                'order_id' => $order->id,
                'product_type' => $productType,
            ]);

            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_INVALID_EMAIL,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_INVALID_EMAIL)
            );
        }

        $customerName = $order->user?->name ?? explode('@', $customerEmail)[0] ?? 'Cliente';
        $linkAcesso = $this->resolveAccessLinkForProduct($product, $order->user);
        $linkEsqueciSenha = $this->resolveForgotPasswordLink();

        if (config('app.debug') && $product->type === Product::TYPE_AREA_MEMBROS) {
            Log::debug('AccessEmailService: link_acesso', ['order_id' => $order->id, 'link' => $linkAcesso]);
        }

        $senha = '';
        $passwordCacheKey = null;
        if ($product->type === Product::TYPE_AREA_MEMBROS && $order->user_id && $order->product_id) {
            $passwordCacheKey = 'access_password.'.$order->user_id.'.'.$order->product_id;
            $decrypted = null;
            $meta = $order->metadata ?? [];
            if (! empty($meta['access_password_temp'])) {
                try {
                    $decrypted = decrypt($meta['access_password_temp']);
                } catch (\Throwable $e) {
                    // ignora erro de decrypt
                }
            }
            if (is_string($decrypted) && $decrypted !== '') {
                $senha = $decrypted;
            } else {
                $cached = Cache::get($passwordCacheKey);
                if (is_string($cached) && $cached !== '') {
                    $senha = $cached;
                }
            }
            Log::info('AccessEmailService: área de membros — senha (metadata ou cache).', [
                'order_id' => $order->id,
                'senha_from_metadata' => isset($meta['access_password_temp']),
                'senha_encontrada' => $senha !== '',
            ]);
        }

        $tenantIdForMail = $order->tenant_id ?? $product->tenant_id;
        $isRenewal = (bool) $order->is_renewal;

        $cacheKey = 'access_email_sent.'.$order->id;
        $cacheTtl = $isRenewal ? now()->addHours(24) : now()->addHours(1);
        if (! $force && Cache::has($cacheKey)) {
            Log::info('AccessEmailService: e-mail já enviado anteriormente (cache).', [
                'order_id' => $order->id,
                'tenant_id_for_mail' => $tenantIdForMail,
            ]);

            return AccessEmailSendResult::ok();
        }

        if ($isRenewal) {
            $subject = 'Renovação confirmada — '.$product->name;
            $bodyHtml = $this->buildRenewalSuccessBody($customerName, $product->name);
            $logoUrl = $this->resolveAccessEmailLogo($tenantIdForMail, $template);
            if ($logoUrl !== null) {
                $bodyHtml = $this->prependLogoToBody($logoUrl, $bodyHtml);
            }
        } elseif ($product->type === Product::TYPE_AREA_MEMBROS_EXTERNA) {
            $subject = 'Compra confirmada — '.$product->name;
            $bodyHtml = $this->buildExternalMemberAreaPendingBody($customerName, $product->name);
            $logoUrl = $this->resolveAccessEmailLogo($tenantIdForMail, $template);
            if ($logoUrl !== null) {
                $bodyHtml = $this->prependLogoToBody($logoUrl, $bodyHtml);
            }
        } elseif ($product->type === Product::TYPE_LINK) {
            $subject = 'Seu acesso a '.$product->name;
            $externalLink = $order->user
                ? ($this->deliverableAccessLinks->trackedUrl($order->user, $product) ?? '')
                : '';
            $bodyHtml = $this->buildLinkProductAccessBody(
                $customerName,
                $product->name,
                $externalLink,
                $this->resolvePlatformLoginLink(),
                $customerEmail,
                $linkEsqueciSenha,
            );
            $logoUrl = $this->resolveAccessEmailLogo($tenantIdForMail, $template);
            if ($logoUrl !== null) {
                $bodyHtml = $this->prependLogoToBody($logoUrl, $bodyHtml);
            }
        } else {
            $bodyHtmlBeforeReplace = $bodyHtml;
            $senhaDisplay = $senha !== ''
                ? $senha
                : 'Não disponível — use Esqueci minha senha na tela de login';
            $replace = [
                '{nome_cliente}' => $customerName,
                '{nome_produto}' => $product->name,
                '{link_acesso}' => $linkAcesso,
                '{email_cliente}' => $customerEmail,
                '{senha}' => $senhaDisplay,
                '{link_esqueci_senha}' => $linkEsqueciSenha,
            ];
            $subject = str_replace(array_keys($replace), array_values($replace), $subject);
            $bodyHtml = str_replace(array_keys($replace), array_values($replace), $bodyHtml);
            $logoUrl = $this->resolveAccessEmailLogo($tenantIdForMail, $template);
            if ($logoUrl !== null) {
                $bodyHtml = $this->prependLogoToBody($logoUrl, $bodyHtml);
            }
            if ($product->type === Product::TYPE_AREA_MEMBROS) {
                if ($senha !== '' && ! str_contains($bodyHtmlBeforeReplace, '{senha}')) {
                    $bodyHtml = $this->appendMemberAreaPasswordCredentialsBlock($bodyHtml, $customerEmail, $senha);
                } elseif ($senha === '' && ! str_contains($bodyHtmlBeforeReplace, '{link_esqueci_senha}')) {
                    $bodyHtml = $this->appendMemberAreaForgotPasswordBlock($bodyHtml, $customerEmail, $linkEsqueciSenha);
                }
            }
        }

        $sendResult = $this->sendAccessMailableWithFallback($subject, $bodyHtml, $customerEmail, $tenantIdForMail, $template, $product);
        if (! $sendResult->success) {
            return $sendResult;
        }

        Cache::put($cacheKey, true, $cacheTtl);

        Log::info($isRenewal ? 'AccessEmailService: e-mail de renovação enviado.' : 'AccessEmailService: e-mail de acesso enviado.', [
            'order_id' => $order->id,
            'product_type' => $productType,
            'tenant_id_for_mail' => $tenantIdForMail,
            'to' => $customerEmail,
        ]);

        if ($passwordCacheKey !== null) {
            Cache::forget($passwordCacheKey);
        }

        $meta = $order->metadata ?? [];
        if (! empty($meta['access_password_temp'])) {
            unset($meta['access_password_temp']);
            $order->update(['metadata' => $meta]);
        }

        return AccessEmailSendResult::ok();
    }

    /**
     * Tenta SMTP do tenant (se configurado) e depois SMTP global da plataforma.
     * From = endereço autenticado do SMTP. O e-mail do seller entra só no corpo (contato de suporte).
     */
    private function sendAccessMailableWithFallback(
        string $subject,
        string $bodyHtml,
        string $customerEmail,
        ?int $tenantIdForMail,
        array $template,
        ?Product $product = null
    ): AccessEmailSendResult {
        $attempts = [];

        if ($tenantIdForMail !== null && $this->mailConfig->isEmailConfigured($tenantIdForMail)) {
            $attempts[] = [
                'label' => 'smtp_tenant',
                'apply' => function () use ($tenantIdForMail): void {
                    $this->mailConfig->applyMailerConfigForTenant($tenantIdForMail, [], null);
                },
            ];
        }

        if ($this->mailConfig->isEmailConfigured(null)) {
            $attempts[] = [
                'label' => 'smtp_plataforma_global',
                'apply' => function (): void {
                    $this->mailConfig->applyPlatformGlobalMailerConfig();
                },
            ];
        }

        if ($attempts === []) {
            Log::warning('AccessEmailService: nenhum SMTP configurado.', [
                'tenant_id_for_mail' => $tenantIdForMail,
            ]);

            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_SMTP_NOT_CONFIGURED,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_SMTP_NOT_CONFIGURED)
            );
        }

        $sellerSender = $this->resolveSellerSenderIdentity($tenantIdForMail, $product);
        $bodyHtml = $this->injectSellerSupportContact($bodyHtml, $sellerSender);

        $lastError = null;

        foreach ($attempts as $attempt) {
            try {
                $attempt['apply']();
                $this->mailConfig->assertSmtpHostIsConfigured();
                Mail::purge('smtp');

                [$fromAddress, $fromName] = $this->resolveBuyerFacingFrom(
                    $template,
                    $attempt['label'] === 'smtp_tenant' ? $tenantIdForMail : null
                );

                // Não forçar Reply-To do seller: o contato dele fica só no corpo do e-mail.
                config(['mail.reply_to' => null]);

                Log::info('AccessEmailService: enviando.', [
                    'via' => $attempt['label'],
                    'tenant_id_for_mail' => $tenantIdForMail,
                    'provider' => $this->mailConfig->getProviderForTenant(
                        $attempt['label'] === 'smtp_plataforma_global' ? null : $tenantIdForMail
                    ),
                    'host' => config('mail.mailers.smtp.host'),
                    'from' => $fromAddress,
                    'from_name' => $fromName,
                    'seller_support' => $sellerSender['email'] ?? null,
                ]);
                $mailable = new AccessGrantedMail($subject, $bodyHtml);
                $mailable->from($fromAddress, $fromName);
                Mail::mailer('smtp')->to($customerEmail)->send($mailable);

                return AccessEmailSendResult::ok();
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('AccessEmailService: tentativa de envio falhou.', [
                    'via' => $attempt['label'],
                    'order_tenant' => $tenantIdForMail,
                    'message' => $lastError,
                ]);
            }
        }

        return AccessEmailSendResult::fail(
            AccessEmailSendResult::REASON_SMTP_SEND_FAILED,
            AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_SMTP_SEND_FAILED, $lastError)
        );
    }

    /**
     * @return array{email: ?string, name: ?string}
     */
    private function resolveSellerSenderIdentity(?int $tenantId, ?Product $product = null): array
    {
        $email = null;
        $name = null;

        if ($product !== null) {
            // Campo "E-mail para Suporte" na aba Geral do produto.
            $productSupport = trim((string) ($product->support_email ?? ''));
            if ($productSupport !== '' && filter_var($productSupport, FILTER_VALIDATE_EMAIL)) {
                $email = $productSupport;
            }

            // Fallback legado: e-mail do rodapé do checkout.
            if ($email === null) {
                $footer = is_array($product->checkout_config['footer'] ?? null)
                    ? $product->checkout_config['footer']
                    : [];
                $support = trim((string) ($footer['support_email'] ?? ''));
                if ($support !== '' && filter_var($support, FILTER_VALIDATE_EMAIL)) {
                    $email = $support;
                }
            }
        }

        $seller = null;
        if ($tenantId !== null && $tenantId > 0) {
            $seller = User::query()->find($tenantId);
        }

        if ($seller instanceof User) {
            if ($email === null) {
                $candidate = trim((string) ($seller->email ?? ''));
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    $email = $candidate;
                }
            }
            $name = trim((string) ($seller->company_name ?: $seller->name ?: ''));
            if ($name === '') {
                $name = null;
            }
        }

        return ['email' => $email, 'name' => $name];
    }

    /**
     * Insere o e-mail do seller apenas como texto de contato de suporte no HTML.
     *
     * @param  array{email: ?string, name: ?string}  $sellerSender
     */
    private function injectSellerSupportContact(string $bodyHtml, array $sellerSender): string
    {
        $sellerEmail = $sellerSender['email'] ?? null;
        if (! is_string($sellerEmail) || $sellerEmail === '' || ! filter_var($sellerEmail, FILTER_VALIDATE_EMAIL)) {
            return $bodyHtml;
        }

        if (str_contains($bodyHtml, 'data-seller-support="1"')) {
            return $bodyHtml;
        }

        $sellerName = trim((string) ($sellerSender['name'] ?? ''));
        $label = $sellerName !== '' ? e($sellerName) : 'suporte do vendedor';
        $mailto = 'mailto:'.e($sellerEmail);
        $supportLine = 'Qualquer dúvida, fale com o '.$label.': <a href="'.$mailto.'" style="color:#0ea5e9;text-decoration:underline;">'.e($sellerEmail).'</a>';

        if (str_contains($bodyHtml, '{email_suporte}')) {
            $bodyHtml = str_replace('{email_suporte}', e($sellerEmail), $bodyHtml);
        }

        $genericPhrase = 'Qualquer dúvida, responda este e-mail.';
        if (str_contains($bodyHtml, $genericPhrase)) {
            return str_replace(
                $genericPhrase,
                '<span data-seller-support="1">'.$supportLine.'</span>',
                $bodyHtml
            );
        }

        return $bodyHtml.'<div data-seller-support="1" style="margin:24px 0 0;padding:16px 20px;background:#f1f5f9;border-radius:8px;">'
            .'<p style="margin:0;font-size:13px;line-height:1.5;color:#64748b;">'.$supportLine.'</p>'
            .'</div>';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveBuyerFacingFrom(array $template, ?int $tenantSmtpScope): array
    {
        // From já aplicado pelo TenantMailConfigService (mailbox autenticada no SMTP/Hostinger).
        $smtpFromAddress = trim((string) (config('mail.from.address') ?? ''));
        $smtpFromName = trim((string) (config('mail.from.name') ?? ''));
        $smtpUsername = trim((string) (config('mail.mailers.smtp.username') ?? ''));

        $fromName = ! empty($template['from_name'])
            ? (string) $template['from_name']
            : ($smtpFromName !== '' ? $smtpFromName : 'Suporte');

        // SMTP/Hostinger: username e-mail sempre vence settings tipo noreply@getfy.com (erro 553).
        $provider = (string) Setting::get('email_provider', 'smtp', $tenantSmtpScope);
        if (in_array($provider, ['smtp', 'hostinger', ''], true)
            && $smtpUsername !== ''
            && filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)
        ) {
            return [$smtpUsername, $fromName !== '' ? $fromName : 'Suporte'];
        }

        $fromAddress = $smtpFromAddress;
        if ($fromAddress === '' || ! filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            foreach (['sendgrid_mail_from_address', 'mail_from_address', 'hostinger_mail_from_address'] as $key) {
                $v = trim((string) Setting::get($key, '', $tenantSmtpScope));
                if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $fromAddress = $v;
                    break;
                }
            }
        }

        if ($fromAddress === '' || ! filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $fromAddress = (string) config('mail.from.address');
        }

        return [$fromAddress, $fromName !== '' ? $fromName : 'Suporte'];
    }

    /**
     * Return the access link for an order (same link used in the access email).
     */
    public function getAccessLinkForOrder(Order $order): string
    {
        $order->loadMissing(['product', 'user']);
        $product = $order->product;
        if (! $product) {
            return '';
        }

        return $this->resolveAccessLinkForProduct($product, $order->user);
    }

    /**
     * Link usado no e-mail de acesso e na página de obrigado.
     * Área de membros: sempre a tela de login da plataforma (aluno vê todos os produtos).
     */
    public function resolveAccessLinkForProduct(Product $product, ?User $user = null): string
    {
        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            return $this->resolvePlatformLoginLink();
        }

        if ($user && $this->deliverableAccessLinks->tracks($product)) {
            return $this->deliverableAccessLinks->trackedUrl($user, $product) ?? '';
        }

        return '';
    }

    public function sendForUserProduct(User $user, Product $product): AccessEmailSendResult
    {
        if ($product->type === Product::TYPE_LINK_PAGAMENTO) {
            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_LINK_PAGAMENTO,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_LINK_PAGAMENTO)
            );
        }

        $config = $product->checkout_config ?? [];
        $template = array_merge(Product::defaultEmailTemplate(), $config['email_template'] ?? []);
        $subject = (string) ($template['subject'] ?? 'Seu acesso');
        $bodyHtml = (string) ($template['body_html'] ?? '');

        if ($bodyHtml === '') {
            $bodyHtml = (string) (Product::defaultEmailTemplate()['body_html'] ?? '');
        }

        if ($bodyHtml === '') {
            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_EMPTY_TEMPLATE,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_EMPTY_TEMPLATE)
            );
        }

        $customerEmail = $user->email;
        if (! $customerEmail || ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_INVALID_EMAIL,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_INVALID_EMAIL)
            );
        }

        $customerName = $user->name ?: explode('@', $customerEmail)[0] ?? 'Cliente';
        $linkAcesso = $this->resolveAccessLinkForProduct($product, $user);
        $linkEsqueciSenha = $this->resolveForgotPasswordLink();

        if ($product->type === Product::TYPE_LINK) {
            $subject = 'Seu acesso a '.$product->name;
            $bodyHtml = $this->buildLinkProductAccessBody(
                $customerName,
                $product->name,
                $this->deliverableAccessLinks->trackedUrl($user, $product) ?? '',
                $this->resolvePlatformLoginLink(),
                $customerEmail,
                $linkEsqueciSenha,
            );
        } else {
            $bodyHtmlBeforeReplace = $bodyHtml;
            $replace = [
                '{nome_cliente}' => $customerName,
                '{nome_produto}' => $product->name,
                '{link_acesso}' => $linkAcesso,
                '{email_cliente}' => $customerEmail,
                '{senha}' => 'Não disponível — use Esqueci minha senha na tela de login',
                '{link_esqueci_senha}' => $linkEsqueciSenha,
            ];
            $subject = str_replace(array_keys($replace), array_values($replace), $subject);
            $bodyHtml = str_replace(array_keys($replace), array_values($replace), $bodyHtml);

            if ($product->type === Product::TYPE_AREA_MEMBROS
                && ! str_contains($bodyHtmlBeforeReplace, '{link_esqueci_senha}')
            ) {
                $bodyHtml = $this->appendMemberAreaForgotPasswordBlock($bodyHtml, $customerEmail, $linkEsqueciSenha);
            }
        }

        $logoUrl = $this->resolveAccessEmailLogo($product->tenant_id, $template);
        if ($logoUrl !== null) {
            $bodyHtml = $this->prependLogoToBody($logoUrl, $bodyHtml);
        }

        return $this->sendAccessMailableWithFallback($subject, $bodyHtml, $customerEmail, $product->tenant_id, $template, $product);
    }

    /**
     * Prioriza a logo do template do produto; se vazia, usa a logo da marca da plataforma.
     */
    private function resolveAccessEmailLogo(?int $tenantId, array $template): ?string
    {
        $productLogo = trim((string) ($template['logo_url'] ?? ''));
        if ($productLogo !== '') {
            return $productLogo;
        }

        $brandingLogo = BrandingEmailData::forTenant($tenantId)['logo_url'] ?? null;
        if (is_string($brandingLogo) && trim($brandingLogo) !== '') {
            return trim($brandingLogo);
        }

        return null;
    }

    private function resolveLinkAcesso(Product $product): string
    {
        if ($product->type === Product::TYPE_LINK) {
            $config = $product->checkout_config ?? [];
            $link = $config['deliverable_link'] ?? '';

            return is_string($link) ? $link : '';
        }

        return '';
    }

    private function resolvePlatformLoginLink(): string
    {
        return rtrim(PublicAppUrl::base(), '/').'/login';
    }

    private function resolveForgotPasswordLink(): string
    {
        return rtrim(PublicAppUrl::base(), '/').'/esqueci-senha';
    }

    private function prependLogoToBody(string $logoUrl, string $bodyHtml): string
    {
        if (str_contains($bodyHtml, 'data-email-logo="1"')) {
            return $bodyHtml;
        }

        return EmailLogoHtml::wrap($logoUrl).$bodyHtml;
    }

    private function appendMemberAreaPasswordCredentialsBlock(string $bodyHtml, string $email, string $password): string
    {
        $block = '<div style="margin:24px 0 0;padding:20px;background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;">'
            .'<p style="margin:0 0 10px;font-size:14px;line-height:1.5;color:#92400e;"><strong>Guarde seus dados de acesso</strong></p>'
            .'<p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:#78350f;">Use o botão acima para abrir a tela de login. Depois, entre com:</p>'
            .'<p style="margin:0 0 10px;font-size:14px;color:#0f172a;"><strong>E-mail:</strong> '.e($email).'</p>'
            .'<p style="margin:0;font-size:15px;color:#0f172a;font-family:Consolas,\'Courier New\',monospace;font-weight:600;letter-spacing:0.02em;word-break:break-all;"><strong>Senha:</strong> '.e($password).'</p>'
            .'</div>';

        return $bodyHtml.$block;
    }

    private function appendMemberAreaForgotPasswordBlock(string $bodyHtml, string $email, string $forgotPasswordUrl): string
    {
        $block = '<div style="margin:24px 0 0;padding:20px;background:#eff6ff;border:1px solid #3b82f6;border-radius:8px;">'
            .'<p style="margin:0 0 10px;font-size:14px;line-height:1.5;color:#1e3a8a;"><strong>Crie sua senha de acesso</strong></p>'
            .'<p style="margin:0 0 12px;font-size:14px;line-height:1.5;color:#1e40af;">Se você ainda não tem uma senha, clique em <strong>Esqueci minha senha</strong> na tela de login (ou no botão abaixo). Você receberá um link por e-mail para criar uma nova senha.</p>'
            .'<p style="margin:0 0 16px;font-size:14px;color:#0f172a;"><strong>E-mail da conta:</strong> '.e($email).'</p>'
            .'<p style="margin:0;text-align:center;"><a href="'.e($forgotPasswordUrl).'" style="display:inline-block;padding:12px 24px;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;border-radius:8px;">Esqueci minha senha</a></p>'
            .'</div>';

        return $bodyHtml.$block;
    }

    private function buildRenewalSuccessBody(string $customerName, string $productName): string
    {
        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Tahoma,sans-serif;background:#f8fafc;padding:32px 24px;"><tr><td style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);"><table width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 32px 24px;text-align:center;border-bottom:1px solid #e2e8f0;"><h1 style="margin:0;font-size:22px;font-weight:600;color:#0f172a;">Olá, '.e($customerName).'!</h1></td></tr><tr><td style="padding:28px 32px;"><p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#334155;">Sua renovação da assinatura de <strong>'.e($productName).'</strong> foi confirmada com sucesso.</p><p style="margin:0;font-size:16px;line-height:1.6;color:#334155;">Você continua com acesso total ao conteúdo. Não é necessário fazer nada.</p></td></tr><tr><td style="padding:20px 32px;background:#f1f5f9;border-radius:0 0 12px 12px;"><p style="margin:0;font-size:13px;color:#64748b;">Qualquer dúvida, responda este e-mail.</p></td></tr></table></td></tr></table>';
    }

    private function buildExternalMemberAreaPendingBody(string $customerName, string $productName): string
    {
        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Tahoma,sans-serif;background:#f8fafc;padding:32px 24px;"><tr><td style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);"><table width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 32px 24px;text-align:center;border-bottom:1px solid #e2e8f0;"><h1 style="margin:0;font-size:22px;font-weight:600;color:#0f172a;">Olá, '.e($customerName).'!</h1></td></tr><tr><td style="padding:28px 32px;"><p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#334155;">Seu pagamento de <strong>'.e($productName).'</strong> foi confirmado.</p><p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#334155;">Este produto é entregue em uma <strong>área de membros externa</strong>. Em instantes você receberá o acesso.</p><p style="margin:0;font-size:14px;line-height:1.6;color:#64748b;">Se você não receber o acesso em alguns minutos, entre em contato com o suporte do vendedor.</p></td></tr><tr><td style="padding:20px 32px;background:#f1f5f9;border-radius:0 0 12px 12px;"><p style="margin:0;font-size:13px;color:#64748b;">Qualquer dúvida, responda este e-mail.</p></td></tr></table></td></tr></table>';
    }

    /**
     * E-mail de produto tipo link: entregável externo + acesso à plataforma (Minha área).
     */
    private function buildLinkProductAccessBody(
        string $customerName,
        string $productName,
        string $externalLink,
        string $platformLoginUrl,
        string $customerEmail,
        string $forgotPasswordUrl,
    ): string {
        $externalSection = $externalLink !== ''
            ? '<p style="margin:0 0 12px;font-size:16px;line-height:1.6;color:#334155;"><strong>Acesso externo ao produto</strong></p>'
                .'<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">Use o link abaixo para abrir o conteúdo:</p>'
                .'<p style="margin:0 0 12px;text-align:center;"><a href="'.e($externalLink).'" style="display:inline-block;padding:14px 28px;background:#0ea5e9;color:#ffffff;text-decoration:none;font-weight:600;font-size:16px;border-radius:8px;">Acessar conteúdo</a></p>'
                .'<p style="margin:0 0 28px;font-size:13px;line-height:1.5;color:#64748b;word-break:break-all;">Este link é pessoal e registra cada acesso. Se encaminhar, o clique também fica no seu histórico.<br/><a href="'.e($externalLink).'" style="color:#0ea5e9;">'.e($externalLink).'</a></p>'
            : '<p style="margin:0 0 28px;font-size:15px;line-height:1.6;color:#64748b;">O link externo deste produto ainda não foi configurado. Entre em contato com o suporte do vendedor.</p>';

        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Tahoma,sans-serif;background:#f8fafc;padding:32px 24px;">'
            .'<tr><td style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">'
            .'<table width="100%" cellpadding="0" cellspacing="0">'
            .'<tr><td style="padding:32px 32px 24px;text-align:center;border-bottom:1px solid #e2e8f0;">'
            .'<h1 style="margin:0;font-size:22px;font-weight:600;color:#0f172a;">Olá, '.e($customerName).'!</h1>'
            .'</td></tr>'
            .'<tr><td style="padding:28px 32px;">'
            .'<p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#334155;">Obrigado por adquirir <strong>'.e($productName).'</strong>.</p>'
            .$externalSection
            .'<div style="margin:0 0 8px;padding-top:8px;border-top:1px solid #e2e8f0;">'
            .'<p style="margin:20px 0 12px;font-size:16px;line-height:1.6;color:#334155;"><strong>Acesse seus produtos na plataforma</strong></p>'
            .'<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">Se quiser ver todos os seus produtos em Minha área, faça login na plataforma:</p>'
            .'<p style="margin:0 0 12px;text-align:center;"><a href="'.e($platformLoginUrl).'" style="display:inline-block;padding:14px 28px;background:#0f172a;color:#ffffff;text-decoration:none;font-weight:600;font-size:16px;border-radius:8px;">Fazer login</a></p>'
            .'<p style="margin:0 0 20px;font-size:13px;line-height:1.5;color:#64748b;word-break:break-all;">Ou copie e cole no navegador:<br/><a href="'.e($platformLoginUrl).'" style="color:#0ea5e9;">'.e($platformLoginUrl).'</a></p>'
            .'<p style="margin:0 0 8px;font-size:14px;color:#0f172a;"><strong>E-mail:</strong> '.e($customerEmail).'</p>'
            .'<p style="margin:0 0 16px;font-size:13px;line-height:1.5;color:#64748b;">Se você não tiver senha, use <a href="'.e($forgotPasswordUrl).'" style="color:#2563eb;font-weight:600;">Esqueci minha senha</a> para criar uma nova.</p>'
            .'</div>'
            .'</td></tr>'
            .'<tr><td style="padding:20px 32px;background:#f1f5f9;border-radius:0 0 12px 12px;">'
            .'<p style="margin:0;font-size:13px;color:#64748b;">Qualquer dúvida, responda este e-mail.</p>'
            .'</td></tr>'
            .'</table></td></tr></table>';
    }

    private function sendPhysicalProductConfirmationEmail(Order $order, Product $product, bool $force): AccessEmailSendResult
    {
        $customerEmail = $order->email ?: $order->user?->email;
        if (! $customerEmail || ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_INVALID_EMAIL,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_INVALID_EMAIL)
            );
        }

        $customerName = $order->user?->name ?? explode('@', $customerEmail)[0] ?? 'Cliente';
        $tenantIdForMail = $order->tenant_id ?? $product->tenant_id;
        $cacheKey = 'access_email_sent.'.$order->id;
        if (! $force && Cache::has($cacheKey)) {
            return AccessEmailSendResult::ok();
        }

        $subject = 'Pedido confirmado — '.$product->name;
        $bodyHtml = $this->buildPhysicalProductConfirmationBody($order, $customerName, $product->name);
        $template = array_merge(Product::defaultEmailTemplate(), ($product->checkout_config ?? [])['email_template'] ?? []);
        $logoUrl = $this->resolveAccessEmailLogo($tenantIdForMail, $template);
        if ($logoUrl !== null) {
            $bodyHtml = $this->prependLogoToBody($logoUrl, $bodyHtml);
        }

        $sendResult = $this->sendAccessMailableWithFallback($subject, $bodyHtml, $customerEmail, $tenantIdForMail, $template, $product);
        if ($sendResult->success) {
            Cache::put($cacheKey, true, now()->addHours(1));
        }

        return $sendResult;
    }

    private function buildPhysicalProductConfirmationBody(Order $order, string $customerName, string $productName): string
    {
        $addr = is_array($order->shipping_address) ? $order->shipping_address : [];
        $lines = array_filter([
            isset($addr['street'], $addr['number']) ? e($addr['street']).', '.e($addr['number']) : null,
            ! empty($addr['complement']) ? e((string) $addr['complement']) : null,
            ! empty($addr['neighborhood']) ? e((string) $addr['neighborhood']) : null,
            isset($addr['city'], $addr['state']) ? e($addr['city']).' — '.e($addr['state']) : null,
            ! empty($addr['zip']) ? 'CEP '.e((string) $addr['zip']) : null,
        ]);
        $addressBlock = $lines !== []
            ? '<p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#334155;">'.implode('<br>', $lines).'</p>'
            : '<p style="margin:0;font-size:14px;color:#64748b;">Endereço registrado no pedido.</p>';

        $shippingAmount = (float) ($order->shipping_amount ?? 0);
        $shippingLine = $shippingAmount > 0
            ? '<p style="margin:0 0 12px;font-size:14px;color:#334155;"><strong>Frete:</strong> R$ '.number_format($shippingAmount, 2, ',', '.').'</p>'
            : '<p style="margin:0 0 12px;font-size:14px;color:#334155;"><strong>Frete:</strong> grátis</p>';

        $meta = $order->metadata ?? [];
        $deliveryHint = '';
        $min = $meta['delivery_days_min'] ?? null;
        $max = $meta['delivery_days_max'] ?? null;
        if ($min !== null) {
            $deliveryHint = '<p style="margin:0;font-size:13px;color:#64748b;">Prazo estimado: '.(int) $min
                .($max !== null && (int) $max !== (int) $min ? '–'.(int) $max : '')
                .' dias úteis após a confirmação do pagamento.</p>';
        }

        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Tahoma,sans-serif;background:#f8fafc;padding:32px 24px;"><tr><td style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);"><table width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 32px 24px;text-align:center;border-bottom:1px solid #e2e8f0;"><h1 style="margin:0;font-size:22px;font-weight:600;color:#0f172a;">Olá, '.e($customerName).'!</h1></td></tr><tr><td style="padding:28px 32px;"><p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#334155;">Recebemos o pagamento do seu pedido <strong>'.e($productName).'</strong>.</p><p style="margin:0 0 12px;font-size:15px;font-weight:600;color:#0f172a;">Endereço de entrega</p>'.$addressBlock.$shippingLine.$deliveryHint.'<p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#64748b;">Você receberá atualizações sobre o envio pelo e-mail informado na compra.</p></td></tr><tr><td style="padding:20px 32px;background:#f1f5f9;border-radius:0 0 12px 12px;"><p style="margin:0;font-size:13px;color:#64748b;">Qualquer dúvida, responda este e-mail.</p></td></tr></table></td></tr></table>';
    }
}
