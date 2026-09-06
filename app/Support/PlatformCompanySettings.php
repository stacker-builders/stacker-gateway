<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\BrandingEmailData;
use App\Services\LegalDocumentsService;

/**
 * Razão social, CNPJ e aviso legal do checkout (config global da plataforma).
 */
final class PlatformCompanySettings
{
    public const KEY_LEGAL_NAME = 'platform_legal_name';

    public const KEY_CNPJ = 'platform_cnpj';

    public const KEY_CHECKOUT_NOTICE_ENABLED = 'platform_checkout_notice_enabled';

    public const KEY_CHECKOUT_NOTICE = 'platform_checkout_notice';

    public const DEFAULT_CHECKOUT_NOTICE = <<<'TXT'
Ao concluir a compra na {plataforma}, você declara estar de acordo com os {termos} e a política de {privacidade} da empresa {razao_social}, inscrita no CNPJ {cnpj}.

A responsabilidade pela oferta, entrega e qualidade do produto é de {infoprodutor}, que realiza a venda por meio da nossa plataforma, através do email {email_infoprodutor}.
TXT;

    /**
     * @var list<array{token: string, label: string}>
     */
    public const CHECKOUT_NOTICE_PLACEHOLDERS = [
        ['token' => '{cnpj}', 'label' => 'CNPJ da empresa operadora'],
        ['token' => '{email}', 'label' => 'E-mail de contato da plataforma'],
        ['token' => '{razao_social}', 'label' => 'Razão social da empresa operadora'],
        ['token' => '{plataforma}', 'label' => 'Nome da plataforma'],
        ['token' => '{infoprodutor}', 'label' => 'Nome do infoprodutor vendedor'],
        ['token' => '{email_infoprodutor}', 'label' => 'E-mail do infoprodutor vendedor'],
        ['token' => '{email_suporte_produto}', 'label' => 'E-mail para suporte configurado no produto'],
        ['token' => '{empresa}', 'label' => 'Nome comercial (Empresa) do infoprodutor'],
        ['token' => '{termos}', 'label' => 'Link clicável “Termos” para /termos-de-uso'],
        ['token' => '{privacidade}', 'label' => 'Link clicável “Privacidade” para /politica-privacidade'],
    ];

    public static function legalName(): string
    {
        return trim((string) Setting::get(self::KEY_LEGAL_NAME, '', null));
    }

    public static function cnpjDigits(): string
    {
        return BrazilianDocuments::digits((string) Setting::get(self::KEY_CNPJ, '', null));
    }

    public static function cnpjFormatted(): string
    {
        return BrazilianDocuments::formatCnpj(self::cnpjDigits());
    }

    public static function isCheckoutNoticeEnabled(): bool
    {
        $value = Setting::get(self::KEY_CHECKOUT_NOTICE_ENABLED, '0', null);

        return $value === '1'
            || $value === 1
            || $value === true
            || $value === 'true';
    }

    public static function checkoutNoticeTemplate(): string
    {
        $stored = HtmlSanitizer::plainTextMultiline(
            (string) Setting::get(self::KEY_CHECKOUT_NOTICE, '', null),
            5000
        );

        return $stored !== '' ? $stored : self::DEFAULT_CHECKOUT_NOTICE;
    }

    /**
     * @return array{legal_name: string, cnpj: string, cnpj_formatted: string}
     */
    public static function publicPayload(): array
    {
        $digits = self::cnpjDigits();

        return [
            'legal_name' => self::legalName(),
            'cnpj' => $digits,
            'cnpj_formatted' => $digits !== '' ? BrazilianDocuments::formatCnpj($digits) : '',
        ];
    }

    /**
     * Texto interpolado para o checkout. Null quando desativado ou vazio.
     * Mantém {termos} e {privacidade} para o front renderizar os links.
     */
    public static function resolvedCheckoutNoticeForTenant(?int $tenantId, ?Product $product = null): ?string
    {
        if (! self::isCheckoutNoticeEnabled()) {
            return null;
        }

        $template = HtmlSanitizer::plainTextMultiline(
            (string) Setting::get(self::KEY_CHECKOUT_NOTICE, '', null),
            5000
        );
        if ($template === '') {
            return null;
        }

        $text = trim(self::replaceCheckoutNoticePlaceholders($template, $tenantId, $product));

        return $text !== '' ? $text : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        $digits = self::cnpjDigits();

        return [
            'platform_legal_name' => self::legalName(),
            'platform_cnpj' => $digits !== '' ? BrazilianDocuments::formatCnpj($digits) : '',
            'platform_checkout_notice_enabled' => self::isCheckoutNoticeEnabled() ? '1' : '0',
            'platform_checkout_notice' => self::checkoutNoticeTemplate(),
            'platform_checkout_notice_default' => self::DEFAULT_CHECKOUT_NOTICE,
            'platform_checkout_notice_placeholders' => self::CHECKOUT_NOTICE_PLACEHOLDERS,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function persistFromValidated(array $validated): void
    {
        if (array_key_exists('platform_legal_name', $validated)) {
            Setting::set(
                self::KEY_LEGAL_NAME,
                trim((string) ($validated['platform_legal_name'] ?? '')),
                null
            );
        }

        if (array_key_exists('platform_cnpj', $validated)) {
            $digits = BrazilianDocuments::digits((string) ($validated['platform_cnpj'] ?? ''));
            Setting::set(self::KEY_CNPJ, $digits, null);
        }

        if (array_key_exists('platform_checkout_notice_enabled', $validated)) {
            $enabled = ($validated['platform_checkout_notice_enabled'] ?? false) === true
                || $validated['platform_checkout_notice_enabled'] === '1'
                || $validated['platform_checkout_notice_enabled'] === 1;
            Setting::set(self::KEY_CHECKOUT_NOTICE_ENABLED, $enabled ? '1' : '0', null);
        }

        if (array_key_exists('platform_checkout_notice', $validated)) {
            Setting::set(
                self::KEY_CHECKOUT_NOTICE,
                HtmlSanitizer::plainTextMultiline((string) ($validated['platform_checkout_notice'] ?? ''), 5000),
                null
            );
        }
    }

    public static function replaceCheckoutNoticePlaceholders(string $template, ?int $tenantId, ?Product $product = null): string
    {
        $seller = self::sellerForTenant($tenantId);
        $cnpj = self::cnpjFormatted();
        $tradeName = trim((string) ($seller?->trade_name ?? ''));
        $map = [
            '{email_infoprodutor}' => trim((string) ($seller?->email ?? '')),
            '{email_suporte_produto}' => self::productSupportEmail($product),
            '{nome do infoprodutor}' => trim((string) ($seller?->name ?? '')),
            '{razao_social}' => self::legalName(),
            '{razão_social}' => self::legalName(),
            '{razão social}' => self::legalName(),
            '{infoprodutor}' => trim((string) ($seller?->name ?? '')),
            '{plataforma}' => self::platformName(),
            '{empresa}' => $tradeName,
            '{cnpj}' => $cnpj,
            '{email}' => self::platformContactEmail(),
        ];

        $text = $template;
        foreach ($map as $token => $value) {
            $text = str_ireplace($token, $value, $text);
        }

        return $text;
    }

    public static function platformName(): string
    {
        $name = trim((string) (BrandingEmailData::forTenant(null)['app_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return trim((string) config('getfy.app_name', config('app.name', 'Stacker')));
    }

    public static function platformContactEmail(): string
    {
        $email = trim((string) Setting::get(LegalDocumentsService::SETTING_PRIVACY_EMAIL, '', null));
        if ($email !== '') {
            return $email;
        }

        $from = trim((string) Setting::get('mail_from_address', '', null));
        if ($from !== '') {
            return $from;
        }

        return trim((string) config('mail.from.address', ''));
    }

    private static function productSupportEmail(?Product $product): string
    {
        $email = strtolower(trim((string) ($product?->support_email ?? '')));

        return ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : '';
    }

    private static function sellerForTenant(?int $tenantId): ?User
    {
        if ($tenantId === null || $tenantId < 1) {
            return null;
        }

        $byId = User::query()
            ->where('id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();
        if ($byId) {
            return $byId;
        }

        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->orderBy('id')
            ->first();
    }
}
