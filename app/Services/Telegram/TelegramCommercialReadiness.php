<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Schema;

/**
 * Checklist técnico para liberação comercial. Não altera a flag automaticamente.
 */
class TelegramCommercialReadiness
{
    /**
     * @return array{sales_operational: bool, recommendation: string, items: list<array{key: string, label: string, ok: bool, note: string}>}
     */
    public function checklist(): array
    {
        $sales = (bool) config('telegram.product_sales_operational', false);
        $items = [
            $this->item('payment_creates_grant', 'Pagamento aprovado cria direito', Schema::hasTable('telegram_access_grants')),
            $this->item('identity_link', 'Identidade Telegram vinculável', Schema::hasTable('telegram_buyer_identities')),
            $this->item('individual_invites', 'Convites individuais', class_exists(TelegramInviteService::class)),
            $this->item('entry_confirmed', 'Entrada efetiva confirmável via webhook', Schema::hasTable('telegram_webhook_events')),
            $this->item('renewal', 'Renovação estende prazo', Schema::hasTable('telegram_access_order_links') && class_exists(TelegramAccessRenewalService::class)),
            $this->item('expiry', 'Vencimento automático', class_exists(TelegramAccessExpiryService::class)),
            $this->item('removal', 'Remoção segura (ban+unban)', class_exists(TelegramMemberRemovalService::class)),
            $this->item('refunds', 'Estornos/cancelamentos integrados', class_exists(TelegramAccessRevocationService::class)),
            $this->item('multi_tenant', 'Isolamento multi-tenant (tenant_id em grants)', Schema::hasColumn('telegram_access_grants', 'tenant_id')),
            $this->item('tests', 'Suite de testes Telegram presente', file_exists(base_path('tests/Feature/TelegramAccessLifecycleTest.php'))),
            $this->item('frontend_build', 'Build frontend (validar manualmente no CI/local)', true, 'Executar npm run build / Vite no ambiente.'),
            $this->item('flag_held', 'Flag comercial ainda desligada até aprovação', ! $sales, 'Manter TELEGRAM_PRODUCT_SALES_OPERATIONAL=false até autorização expressa.'),
        ];

        $blocking = collect($items)->filter(fn ($i) => ! $i['ok'] && $i['key'] !== 'flag_held')->values();
        $recommendation = $blocking->isEmpty()
            ? 'Checklist técnico ok. Recomenda-se homologação ponta a ponta com bot/grupo real e, só então, autorização expressa para TELEGRAM_PRODUCT_SALES_OPERATIONAL=true.'
            : 'Ainda há itens técnicos pendentes. Não habilitar vendas.';

        return [
            'sales_operational' => $sales,
            'recommendation' => $recommendation,
            'items' => $items,
        ];
    }

    /**
     * @return array{key: string, label: string, ok: bool, note: string}
     */
    private function item(string $key, string $label, bool $ok, string $note = ''): array
    {
        return compact('key', 'label', 'ok', 'note');
    }
}
