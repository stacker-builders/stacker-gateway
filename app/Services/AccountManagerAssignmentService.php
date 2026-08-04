<?php

namespace App\Services;

use App\Models\AccountManager;
use App\Models\AccountManagerAssignment;
use App\Models\User;
use App\Services\InertiaSharedPropsCache;
use App\Support\AccountManagerSettings;
use App\Support\SellerPanelSupportSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Vínculos e redistribuição de gerentes de conta (contact-only).
 */
class AccountManagerAssignmentService
{
    public const SYNC_CHUNK_LIMIT = 500;

    public static function ready(): bool
    {
        return Schema::hasTable('account_managers')
            && Schema::hasTable('account_manager_assignments')
            && Schema::hasColumn('users', 'account_manager_id');
    }

    /**
     * Payload seguro para o painel do infoprodutor.
     *
     * @return array<string, mixed>|null
     */
    public function publicCardForMerchant(User $merchant): ?array
    {
        if (! self::ready() || ! $merchant->account_manager_id) {
            return null;
        }

        $manager = AccountManager::query()->find($merchant->account_manager_id);
        if (! $manager || ! $manager->is_active) {
            return null;
        }

        $card = [
            'name' => $manager->name,
        ];

        if ($manager->show_photo && $manager->avatar) {
            $card['photo_url'] = app(StorageService::class)->url($manager->avatar);
        }

        if ($manager->show_email) {
            $card['email'] = $manager->email;
        }

        if ($manager->show_phone && $manager->phone) {
            $card['phone'] = $manager->phone;
            $card['phone_display'] = $this->formatPhoneBr($manager->phone);
        }

        if ($manager->show_whatsapp && $manager->phone) {
            $base = SellerPanelSupportSettings::buildWhatsAppHref($manager->phone);
            if ($base) {
                $msg = sprintf(
                    'Olá, %s. Sou %s e gostaria de falar sobre minha conta na plataforma.',
                    $manager->name,
                    $merchant->name
                );
                $card['whatsapp_url'] = $base.'?text='.rawurlencode($msg);
            }
        }

        return $card;
    }

    /**
     * Payload admin (todos os campos).
     *
     * @return array<string, mixed>|null
     */
    public function adminPayload(?AccountManager $manager): ?array
    {
        if (! $manager) {
            return null;
        }

        return [
            'id' => $manager->id,
            'name' => $manager->name,
            'email' => $manager->email,
            'phone' => $manager->phone,
            'phone_display' => $manager->phone ? $this->formatPhoneBr($manager->phone) : null,
            'whatsapp_url' => SellerPanelSupportSettings::buildWhatsAppHref($manager->phone),
            'avatar_url' => $manager->avatar ? app(StorageService::class)->url($manager->avatar) : null,
            'is_active' => (bool) $manager->is_active,
            'show_email' => (bool) $manager->show_email,
            'show_phone' => (bool) $manager->show_phone,
            'show_whatsapp' => (bool) $manager->show_whatsapp,
            'show_photo' => (bool) $manager->show_photo,
            'notes' => $manager->notes,
            'merchants_count' => $manager->activeMerchantsCount(),
            'created_at' => $manager->created_at?->toIso8601String(),
        ];
    }

    public function assign(
        User $merchant,
        ?AccountManager $manager,
        ?User $actor,
        string $source = AccountManagerAssignment::SOURCE_MANUAL,
        ?string $reason = null,
        ?Request $request = null,
    ): void {
        if (! self::ready()) {
            throw new InvalidArgumentException('Execute as migrações para usar Gerentes de Conta.');
        }

        if (! $merchant->isInfoprodutor()) {
            throw new InvalidArgumentException('Somente infoprodutores podem receber gerente de conta.');
        }

        if ($manager && ! $manager->is_active) {
            throw new InvalidArgumentException('Não é possível atribuir um gerente inativo.');
        }

        DB::transaction(function () use ($merchant, $manager, $actor, $source, $reason, $request) {
            $locked = User::query()->whereKey($merchant->id)->lockForUpdate()->firstOrFail();
            $fromId = $locked->account_manager_id ? (int) $locked->account_manager_id : null;
            $toId = $manager?->id;

            if ($fromId === $toId) {
                return;
            }

            AccountManagerAssignment::query()
                ->where('merchant_user_id', $locked->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);

            $locked->forceFill(['account_manager_id' => $toId])->save();

            if ($toId !== null) {
                AccountManagerAssignment::query()->create([
                    'merchant_user_id' => $locked->id,
                    'account_manager_id' => $toId,
                    'assigned_by' => $actor?->id,
                    'assigned_at' => now(),
                    'ended_at' => null,
                    'reason' => $reason,
                    'source' => $source,
                ]);
            }

            $action = $toId === null ? 'account_managers.unassigned' : 'account_managers.assigned';
            PlatformAuditService::log($action, [
                'merchant_user_id' => $locked->id,
                'from_account_manager_id' => $fromId,
                'to_account_manager_id' => $toId,
                'source' => $source,
                'reason' => $reason,
            ], $request);
        });

        InertiaSharedPropsCache::forgetAccountManagerCard((int) $merchant->id);
    }

    /**
     * @param  list<int>  $merchantIds
     * @return array{processed: int, skipped: int}
     */
    public function transfer(
        AccountManager $from,
        AccountManager $to,
        array $merchantIds,
        ?User $actor,
        string $source = AccountManagerAssignment::SOURCE_BULK_TRANSFER,
        ?string $reason = null,
        ?Request $request = null,
    ): array {
        if (! $to->is_active) {
            throw new InvalidArgumentException('O gerente de destino precisa estar ativo.');
        }
        if ($from->id === $to->id) {
            throw new InvalidArgumentException('Origem e destino devem ser diferentes.');
        }
        if (count($merchantIds) > self::SYNC_CHUNK_LIMIT) {
            throw new InvalidArgumentException('Selecione no máximo '.self::SYNC_CHUNK_LIMIT.' infoprodutores por operação.');
        }

        $processed = 0;
        $skipped = 0;

        DB::transaction(function () use ($from, $to, $merchantIds, $actor, $source, $reason, $request, &$processed, &$skipped) {
            $merchants = User::query()
                ->whereIn('id', $merchantIds)
                ->where('role', User::ROLE_INFOPRODUTOR)
                ->where('account_manager_id', $from->id)
                ->lockForUpdate()
                ->get();

            foreach ($merchants as $merchant) {
                try {
                    $this->assign($merchant, $to, $actor, $source, $reason, $request);
                    $processed++;
                } catch (InvalidArgumentException) {
                    $skipped++;
                }
            }
        });

        PlatformAuditService::log('account_managers.bulk_transferred', [
            'from_account_manager_id' => $from->id,
            'to_account_manager_id' => $to->id,
            'requested' => count($merchantIds),
            'processed' => $processed,
            'skipped' => $skipped,
            'reason' => $reason,
        ], $request);

        return ['processed' => $processed, 'skipped' => $skipped];
    }

    /**
     * @param  list<int>  $merchantIds
     * @param  list<int>  $managerIds
     * @return array{processed: int, preview: array<int, array{id: int, name: string, before: int, after: int}>}
     */
    public function distribute(
        array $merchantIds,
        array $managerIds,
        string $mode,
        ?User $actor,
        bool $dryRun = false,
        ?string $reason = null,
        ?Request $request = null,
    ): array {
        $managers = AccountManager::query()
            ->whereIn('id', $managerIds)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($managers->isEmpty()) {
            throw new InvalidArgumentException('Selecione ao menos um gerente ativo.');
        }

        $merchants = User::query()
            ->whereIn('id', $merchantIds)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->orderBy('id')
            ->get();

        if ($merchants->isEmpty()) {
            throw new InvalidArgumentException('Nenhum infoprodutor válido para distribuir.');
        }

        if ($merchants->count() > self::SYNC_CHUNK_LIMIT) {
            throw new InvalidArgumentException('Selecione no máximo '.self::SYNC_CHUNK_LIMIT.' infoprodutores por operação.');
        }

        $counts = [];
        foreach ($managers as $m) {
            $counts[(int) $m->id] = $m->activeMerchantsCount();
        }
        $before = $counts;

        $plan = $this->buildDistributionPlan($merchants, $managers, $mode, $counts);

        $preview = [];
        foreach ($managers as $m) {
            $id = (int) $m->id;
            $preview[] = [
                'id' => $id,
                'name' => $m->name,
                'before' => $before[$id] ?? 0,
                'after' => $counts[$id] ?? ($before[$id] ?? 0),
            ];
        }

        if ($dryRun) {
            return ['processed' => 0, 'preview' => $preview];
        }

        $processed = 0;
        DB::transaction(function () use ($plan, $managers, $actor, $reason, $request, &$processed) {
            $byId = $managers->keyBy('id');
            foreach ($plan as $merchantId => $managerId) {
                $merchant = User::query()->whereKey($merchantId)->lockForUpdate()->first();
                $manager = $byId->get($managerId);
                if (! $merchant || ! $manager) {
                    continue;
                }
                $this->assign(
                    $merchant,
                    $manager,
                    $actor,
                    AccountManagerAssignment::SOURCE_AUTOMATIC_DISTRIBUTION,
                    $reason,
                    $request
                );
                $processed++;
            }
        });

        PlatformAuditService::log('account_managers.distributed', [
            'mode' => $mode,
            'manager_ids' => $managers->pluck('id')->all(),
            'processed' => $processed,
            'reason' => $reason,
        ], $request);

        return ['processed' => $processed, 'preview' => $preview];
    }

    public function autoAssignIfConfigured(User $merchant, ?Request $request = null): void
    {
        if (! self::ready() || ! $merchant->isInfoprodutor()) {
            return;
        }
        if ($merchant->account_manager_id) {
            return;
        }
        if (AccountManagerSettings::mode() !== AccountManagerSettings::MODE_LEAST_LOAD) {
            return;
        }

        $manager = $this->pickLeastLoadedActiveManager();
        if (! $manager) {
            return;
        }

        $this->assign(
            $merchant,
            $manager,
            null,
            AccountManagerAssignment::SOURCE_NEW_INFOPRODUCER,
            null,
            $request
        );
    }

    public function pickLeastLoadedActiveManager(): ?AccountManager
    {
        $managers = AccountManager::query()->active()->orderBy('id')->get();
        if ($managers->isEmpty()) {
            return null;
        }

        $best = null;
        $bestCount = PHP_INT_MAX;
        foreach ($managers as $manager) {
            $count = $manager->activeMerchantsCount();
            if ($count < $bestCount) {
                $best = $manager;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @param  Collection<int, User>  $merchants
     * @param  Collection<int, AccountManager>  $managers
     * @param  array<int, int>  $counts
     * @return array<int, int> merchantId => managerId
     */
    private function buildDistributionPlan(Collection $merchants, Collection $managers, string $mode, array &$counts): array
    {
        $list = $merchants->values();
        if ($mode === 'random') {
            $list = $list->shuffle()->values();
        }

        $managerIds = $managers->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $plan = [];

        if ($mode === 'equal') {
            $i = 0;
            foreach ($list as $merchant) {
                $oldId = (int) ($merchant->account_manager_id ?? 0);
                if ($oldId > 0 && array_key_exists($oldId, $counts)) {
                    $counts[$oldId] = max(0, ($counts[$oldId] ?? 0) - 1);
                }
                $managerId = $managerIds[$i % count($managerIds)];
                $plan[(int) $merchant->id] = $managerId;
                $counts[$managerId] = ($counts[$managerId] ?? 0) + 1;
                $i++;
            }

            return $plan;
        }

        // least_load (default) e random (após shuffle): sempre menor carteira.
        foreach ($list as $merchant) {
            $oldId = (int) ($merchant->account_manager_id ?? 0);
            if ($oldId > 0 && array_key_exists($oldId, $counts)) {
                $counts[$oldId] = max(0, ($counts[$oldId] ?? 0) - 1);
            }
            $managerId = $this->minCountManagerId($managerIds, $counts);
            $plan[(int) $merchant->id] = $managerId;
            $counts[$managerId] = ($counts[$managerId] ?? 0) + 1;
        }

        return $plan;
    }

    /**
     * @param  list<int>  $managerIds
     * @param  array<int, int>  $counts
     */
    private function minCountManagerId(array $managerIds, array $counts): int
    {
        $best = $managerIds[0];
        $bestCount = $counts[$best] ?? 0;
        foreach ($managerIds as $id) {
            $c = $counts[$id] ?? 0;
            if ($c < $bestCount || ($c === $bestCount && $id < $best)) {
                $best = $id;
                $bestCount = $c;
            }
        }

        return $best;
    }

    public function formatPhoneBr(string $digits): string
    {
        $d = preg_replace('/\D/', '', $digits) ?? '';
        if (str_starts_with($d, '55') && strlen($d) >= 12) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 11) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
        }
        if (strlen($d) === 10) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
        }

        return $digits;
    }
}
