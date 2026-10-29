<?php

namespace App\Services;

use App\Models\Setting;

class TenantMailConfigService
{
    public const PROVIDERS = ['smtp', 'hostinger', 'sendgrid', 'resend'];

    /**
     * Whether the tenant has at least one email provider configured (SMTP, Hostinger, SendGrid or Resend).
     */
    public function isEmailConfigured(?int $tenantId): bool
    {
        $smtpHost = Setting::get('smtp_host', '', $tenantId);
        if ($smtpHost !== null && $smtpHost !== '') {
            return true;
        }
        $hostingerUser = Setting::get('hostinger_smtp_username', '', $tenantId);
        if ($hostingerUser !== null && $hostingerUser !== '') {
            $encrypted = Setting::get('hostinger_smtp_password', null, $tenantId);
            $password = $encrypted ? @decrypt($encrypted) : null;
            if ($password !== null && $password !== '') {
                return true;
            }
        }
        foreach (['sendgrid_api_key', 'resend_api_key'] as $apiKey) {
            $encrypted = Setting::get($apiKey, null, $tenantId);
            if ($encrypted) {
                $key = @decrypt($encrypted);
                if ($key !== null && $key !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{host: string, port: int, encryption: ?string, username: ?string, password: ?string}
     */
    public function getMailConfigForProvider(?int $tenantId, array $overrides, string $provider): array
    {
        if ($provider === 'sendgrid') {
            $password = $overrides['sendgrid_api_key'] ?? null;
            if ($password === null) {
                $encrypted = Setting::get('sendgrid_api_key', null, $tenantId);
                $password = $encrypted ? @decrypt($encrypted) : null;
            }
            return [
                'host' => 'smtp.sendgrid.net',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'apikey',
                'password' => $password,
            ];
        }
        if ($provider === 'resend') {
            $password = $overrides['resend_api_key'] ?? null;
            if ($password === null) {
                $encrypted = Setting::get('resend_api_key', null, $tenantId);
                $password = $encrypted ? @decrypt($encrypted) : null;
            }

            return [
                'host' => 'smtp.resend.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'resend',
                'password' => $password,
            ];
        }
        if ($provider === 'hostinger') {
            $host = 'smtp.hostinger.com';
            $port = 465;
            $encryption = 'ssl';
            $username = $overrides['smtp_username'] ?? Setting::get('hostinger_smtp_username', '', $tenantId);
            $password = $overrides['smtp_password'] ?? null;
            if ($password === null) {
                $encrypted = Setting::get('hostinger_smtp_password', null, $tenantId);
                $password = $encrypted ? @decrypt($encrypted) : null;
            }
            return ['host' => $host, 'port' => $port, 'encryption' => $encryption, 'username' => $username, 'password' => $password];
        }
        $host = trim((string) ($overrides['smtp_host'] ?? Setting::get('smtp_host', '', $tenantId)));
        $port = (int) ($overrides['smtp_port'] ?? Setting::get('smtp_port', '587', $tenantId));
        $encryption = $overrides['smtp_encryption'] ?? Setting::get('smtp_encryption', 'tls', $tenantId);
        if ($encryption === '' || $encryption === null) {
            $encryption = null;
        } elseif (! in_array($encryption, ['tls', 'ssl'], true)) {
            $encryption = 'tls';
        }
        $username = $overrides['smtp_username'] ?? Setting::get('smtp_username', '', $tenantId);
        $password = $overrides['smtp_password'] ?? null;
        if ($password === null) {
            $encrypted = Setting::get('smtp_password', null, $tenantId);
            $password = $encrypted ? @decrypt($encrypted) : null;
        }
        return ['host' => $host, 'port' => $port, 'encryption' => $encryption, 'username' => $username, 'password' => $password];
    }

    /**
     * Return the email provider name for the tenant (smtp, hostinger, sendgrid, resend).
     * Uses resolveTenantIdForMail so it matches the tenant used when applying config.
     */
    public function getProviderForTenant(?int $tenantId): string
    {
        $resolved = $this->resolveTenantIdForMail($tenantId);

        return (string) Setting::get('email_provider', 'smtp', $resolved);
    }

    /**
     * Quando não há usuário logado (ex.: esqueci a senha), as configs de SMTP foram salvas
     * com o tenant_id do infoprodutor. Retorna o primeiro tenant_id que tem smtp_host
     * configurado, ou null para usar fallback do .env.
     *
     * IMPORTANTE (Plataforma, tenant_id null): Configurações em Plataforma → E-mail ficam sempre
     * em settings com tenant_id null. Um ORDER BY tenant_id em PostgreSQL põe NULL por último,
     * fazendo esta função ignorar essas configs e ler o primeiro infoprodutor com SMTP —
     * o painel salvava Hostinger/email_provider correto mas o runtime aplicava SMTP de outro tenant.
     * Por isso, quando há e-mail configurado no escopo global, devolve-se null explicitamente.
     */
    public function resolveTenantIdForMail(?int $tenantId): ?int
    {
        if ($tenantId !== null) {
            return $tenantId;
        }
        if ($this->isEmailConfigured(null)) {
            return null;
        }
        $row = Setting::query()
            ->where('key', 'smtp_host')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->orderBy('tenant_id')
            ->first();
        if ($row !== null) {
            return $row->tenant_id;
        }
        $row = Setting::query()
            ->whereIn('key', ['hostinger_smtp_username', 'sendgrid_api_key', 'resend_api_key'])
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->orderBy('tenant_id')
            ->first();
        return $row?->tenant_id;
    }

    public function applyMailerConfigForTenant(?int $tenantId, array $overrides = [], ?string $provider = null): void
    {
        $tenantId = $this->resolveTenantIdForMail($tenantId);
        $provider = $provider ?? Setting::get('email_provider', 'smtp', $tenantId);
        $config = $this->getMailConfigForProvider($tenantId, $overrides, $provider);

        $this->applySmtpConfigToLaravel($config, $tenantId, $provider, $overrides);
    }

    /**
     * SMTP guardado em Configurações da plataforma (tenant_id null), sem resolver outro tenant.
     * Usado como fallback quando o infoprodutor ainda não configurou e-mail.
     */
    public function applyPlatformGlobalMailerConfig(array $overrides = [], ?string $provider = null): void
    {
        $tenantId = null;
        $provider = $provider ?? Setting::get('email_provider', 'smtp', null);
        $config = $this->getMailConfigForProvider(null, $overrides, $provider);

        $this->applySmtpConfigToLaravel($config, $tenantId, $provider, $overrides);
    }

    /**
     * Aplica SMTP para redefinição de senha.
     *
     * Login geral (/esqueci-senha): prioriza SMTP global da plataforma (mesmo do teste em Plataforma → E-mail),
     * depois tenant do usuário, depois qualquer tenant com e-mail configurado.
     *
     * Área de membros: use $preferPlatformGlobal = false para priorizar o tenant do produto.
     *
     * @throws \RuntimeException quando nenhum provedor está configurado
     */
    public function applyForPasswordReset(?\App\Models\User $user, bool $preferPlatformGlobal = true): void
    {
        if ($user?->canAccessPlatformPanel()) {
            if (! $this->isEmailConfigured(null)) {
                throw new \RuntimeException('Configure o SMTP em Plataforma → Configurações → E-mail.');
            }
            $this->applyPlatformGlobalMailerConfig();

            return;
        }

        if ($preferPlatformGlobal && $this->isEmailConfigured(null)) {
            $this->applyPlatformGlobalMailerConfig();

            return;
        }

        $tenantId = $user?->tenant_id;
        if ($tenantId !== null && $this->isEmailConfigured($tenantId)) {
            $this->applyMailerConfigForTenant($tenantId);

            return;
        }

        if ($this->isEmailConfigured(null)) {
            $this->applyPlatformGlobalMailerConfig();

            return;
        }

        $resolved = $this->resolveTenantIdForMail(null);
        if ($resolved !== null && $this->isEmailConfigured($resolved)) {
            $this->applyMailerConfigForTenant($resolved);

            return;
        }

        throw new \RuntimeException(
            'Nenhum servidor de e-mail configurado. Em Configurações → E-mail, preencha SMTP, Hostinger, SendGrid ou Resend e salve.'
        );
    }

    /**
     * Evita envio silencioso para 127.0.0.1:2525 (default do .env) quando o painel não tem host SMTP.
     */
    public function assertSmtpHostIsConfigured(): void
    {
        $host = trim((string) config('mail.mailers.smtp.host'));
        $port = (int) config('mail.mailers.smtp.port');
        $isLaravelDevDefault = ($host === '127.0.0.1' || $host === 'localhost') && $port === 2525;
        if ($host === '' || $isLaravelDevDefault) {
            throw new \RuntimeException(
                'Servidor SMTP inválido ou não configurado. Verifique Configurações → E-mail (host, porta e senha).'
            );
        }
    }

    /**
     * @param  array{host: string, port: int, encryption: ?string, username: ?string, password: ?string}  $config
     */
    private function applySmtpConfigToLaravel(array $config, ?int $tenantId, string $provider, array $overrides): void
    {
        $encryption = $config['encryption'] ?? null;
        $scheme = match ($encryption) {
            'ssl' => 'smtps',
            'tls' => 'smtp',
            default => 'smtp',
        };

        config(['mail.mailers.smtp.transport' => 'smtp']);
        config(['mail.mailers.smtp.scheme' => $scheme]);
        config(['mail.mailers.smtp.host' => $config['host']]);
        config(['mail.mailers.smtp.port' => $config['port']]);
        config(['mail.mailers.smtp.username' => $config['username']]);
        config(['mail.mailers.smtp.encryption' => $encryption]);
        config(['mail.mailers.smtp.password' => $config['password']]);

        // SMTP/Hostinger exigem From = mailbox autenticada (evita 553 "not owned by user").
        // SendGrid/Resend usam username de API — o From verificado no painel é obrigatório.
        $username = trim((string) ($config['username'] ?? ''));
        $fromAddress = config('mail.from.address');
        $fromName = config('mail.from.name', 'Getfy');
        $replyTo = null;

        if ($provider === 'sendgrid') {
            $fromAddress = $overrides['sendgrid_mail_from_address'] ?? Setting::get('sendgrid_mail_from_address', config('mail.from.address'), $tenantId);
            $fromName = $overrides['sendgrid_mail_from_name'] ?? Setting::get('sendgrid_mail_from_name', config('mail.from.name'), $tenantId);
        } elseif ($provider === 'resend') {
            $fromAddress = $overrides['resend_mail_from_address'] ?? Setting::get('resend_mail_from_address', config('mail.from.address'), $tenantId);
            $fromName = $overrides['resend_mail_from_name'] ?? Setting::get('resend_mail_from_name', config('mail.from.name'), $tenantId);
        } elseif ($provider === 'hostinger') {
            $fromName = Setting::get('hostinger_mail_from_name', config('mail.from.name'), $tenantId);
            $replyTo = Setting::get('hostinger_reply_to', null, $tenantId);
            $fromAddress = $this->resolveOwnedSmtpFromAddress(
                $username,
                Setting::get('hostinger_mail_from_address', '', $tenantId)
            );
        } else {
            $fromName = Setting::get('mail_from_name', config('mail.from.name'), $tenantId);
            $replyTo = Setting::get('reply_to', null, $tenantId);
            $fromAddress = $this->resolveOwnedSmtpFromAddress(
                $username,
                Setting::get('mail_from_address', '', $tenantId)
            );
        }

        config(['mail.from' => [
            'address' => $fromAddress ?: config('mail.from.address'),
            'name' => $fromName ?: config('mail.from.name', 'Getfy'),
        ]]);
        if ($replyTo) {
            config(['mail.reply_to' => ['address' => $replyTo, 'name' => null]]);
        }
    }

    /**
     * From permitido pelo SMTP autenticado: se o username é e-mail, ele vence.
     * mail_from_address só entra se for exatamente o mesmo endereço (ou se username não for e-mail).
     */
    private function resolveOwnedSmtpFromAddress(string $smtpUsername, mixed $configuredFrom): string
    {
        $configured = is_string($configuredFrom) ? trim($configuredFrom) : '';
        $configuredOk = $configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL);

        if ($smtpUsername !== '' && filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)) {
            if ($configuredOk && strcasecmp($configured, $smtpUsername) === 0) {
                return $configured;
            }

            return $smtpUsername;
        }

        if ($configuredOk) {
            return $configured;
        }

        return (string) config('mail.from.address');
    }
}
