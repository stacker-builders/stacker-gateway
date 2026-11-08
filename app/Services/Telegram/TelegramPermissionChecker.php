<?php

namespace App\Services\Telegram;

use App\Models\TelegramCommunity;

class TelegramPermissionChecker
{
    /**
     * Avalia permissões a partir do resultado de getChatMember do bot.
     *
     * @param  array<string, mixed>  $member
     * @return array{
     *     is_admin: bool,
     *     ready_for_delivery: bool,
     *     permissions: list<array{key: string, label: string, status: string, required: bool, detail: string|null}>
     * }
     */
    public function evaluate(string $communityType, array $member): array
    {
        $status = (string) ($member['status'] ?? '');
        $isAdmin = in_array($status, ['administrator', 'creator'], true);
        $rights = is_array($member['permissions'] ?? null)
            ? $member['permissions']
            : $member;

        $canInvite = $this->boolRight($rights, ['can_invite_users', 'can_manage_invite_links']);
        $canRestrict = $this->boolRight($rights, ['can_restrict_members']);
        $canManageJoin = $this->boolRight($rights, ['can_manage_join_requests']);
        $canPost = $this->boolRight($rights, ['can_post_messages']);

        if ($status === 'creator') {
            $canInvite = true;
            $canRestrict = true;
            $canManageJoin = true;
            $canPost = true;
        }

        $checks = [
            [
                'key' => 'administrator',
                'label' => 'Administração do grupo/canal',
                'required' => true,
                'ok' => $isAdmin,
                'detail' => $isAdmin ? 'Bot é administrador.' : 'Adicione o bot como administrador.',
            ],
            [
                'key' => 'invite_links',
                'label' => 'Criação de links de convite',
                'required' => true,
                'ok' => $isAdmin && $canInvite,
                'detail' => $canInvite ? 'Pode criar convites.' : 'Ative “Convidar usuários” / gerenciar links.',
            ],
        ];

        if ($communityType === TelegramCommunity::TYPE_CHANNEL) {
            $checks[] = [
                'key' => 'post_messages',
                'label' => 'Publicação de mensagens no canal',
                'required' => true,
                'ok' => $isAdmin && $canPost,
                'detail' => $canPost ? 'Pode publicar no canal.' : 'Ative “Publicar mensagens”.',
            ];
            $checks[] = [
                'key' => 'restrict_members',
                'label' => 'Restrição e remoção de membros',
                'required' => false,
                'ok' => null,
                'detail' => 'Não se aplica da mesma forma em canais.',
            ];
        } else {
            $checks[] = [
                'key' => 'restrict_members',
                'label' => 'Restrição e remoção de membros',
                'required' => true,
                'ok' => $isAdmin && $canRestrict,
                'detail' => $canRestrict ? 'Pode restringir/remover membros.' : 'Ative “Restringir membros”.',
            ];
            $checks[] = [
                'key' => 'post_messages',
                'label' => 'Publicação de mensagens em canais',
                'required' => false,
                'ok' => null,
                'detail' => 'Opcional para grupos.',
            ];
        }

        $checks[] = [
            'key' => 'manage_join_requests',
            'label' => 'Gerenciamento de solicitações de entrada',
            'required' => false,
            'ok' => $isAdmin ? $canManageJoin : null,
            'detail' => $canManageJoin
                ? 'Pode gerenciar solicitações de entrada.'
                : 'Recomendado se a comunidade usar aprovação de entrada.',
        ];

        $permissions = array_map(function (array $check) {
            $status = 'yellow';
            if ($check['ok'] === true) {
                $status = 'green';
            } elseif ($check['ok'] === false && $check['required']) {
                $status = 'red';
            } elseif ($check['ok'] === false) {
                $status = 'yellow';
            }

            return [
                'key' => $check['key'],
                'label' => $check['label'],
                'status' => $status,
                'required' => $check['required'],
                'detail' => $check['detail'],
            ];
        }, $checks);

        $ready = collect($permissions)
            ->where('required', true)
            ->every(fn (array $item) => $item['status'] === 'green');

        return [
            'is_admin' => $isAdmin,
            'ready_for_delivery' => $ready,
            'permissions' => $permissions,
        ];
    }

    /**
     * @param  array<string, mixed>  $rights
     * @param  list<string>  $keys
     */
    private function boolRight(array $rights, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $rights)) {
                return (bool) $rights[$key];
            }
        }

        return false;
    }
}
