<?php

namespace App\Services\Telegram;

use App\Models\TelegramBot;
use App\Models\TelegramCommunity;

class TelegramWizardProgress
{
    /**
     * Progresso derivado dos dados reais do tenant.
     *
     * @return array{
     *     current_step: int,
     *     steps: array<int, array{id: int, key: string, label: string, done: bool}>,
     *     ready_for_sales_prep: bool,
     *     summary: array<string, mixed>
     * }
     */
    public function forTenant(int $tenantId): array
    {
        $bots = TelegramBot::query()->forTenant($tenantId)->active()->orderBy('id')->get();
        $communities = TelegramCommunity::query()
            ->forTenant($tenantId)
            ->active()
            ->with('bot')
            ->orderBy('id')
            ->get();

        $hasBot = $bots->isNotEmpty();
        $hasCommunity = $communities->isNotEmpty();
        $hasChecked = $communities->contains(fn (TelegramCommunity $c) => $c->last_checked_at !== null);
        $hasReadyCommunity = $communities->contains(fn (TelegramCommunity $c) => $c->ready_for_delivery);
        $ready = $hasBot && $hasReadyCommunity;

        $steps = [
            [
                'id' => 1,
                'key' => 'bot',
                'label' => 'Conectar bot',
                'done' => $hasBot,
            ],
            [
                'id' => 2,
                'key' => 'community',
                'label' => 'Vincular grupo ou canal',
                'done' => $hasCommunity,
            ],
            [
                'id' => 3,
                'key' => 'permissions',
                'label' => 'Verificar permissões',
                'done' => $hasChecked && $hasReadyCommunity,
            ],
            [
                'id' => 4,
                'key' => 'complete',
                'label' => 'Integração concluída',
                'done' => $ready,
            ],
        ];

        $current = 1;
        foreach ($steps as $step) {
            if (! $step['done']) {
                $current = $step['id'];
                break;
            }
            $current = min(4, $step['id'] + 1);
        }
        if ($ready) {
            $current = 4;
        }

        return [
            'current_step' => $current,
            'steps' => $steps,
            'ready_for_sales_prep' => $ready,
            'summary' => [
                'bots_count' => $bots->count(),
                'communities_count' => $communities->count(),
                'ready_communities_count' => $communities->where('ready_for_delivery', true)->count(),
                'bots_with_error' => $bots->where('status', TelegramBot::STATUS_ERROR)->count(),
                'communities_with_error' => $communities->where('status', TelegramCommunity::STATUS_ERROR)->count(),
            ],
        ];
    }
}
