<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import LayoutGuest from '@/Layouts/LayoutGuest.vue';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: LayoutGuest });

const props = defineProps({
    grant: { type: Object, required: true },
    events: { type: Array, default: () => [] },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success || null);

const deliveryLabel = computed(() => {
    const map = {
        pending: 'Pendente',
        partial: 'Parcial',
        completed: 'Concluída',
        failed: 'Com falha',
    };
    return map[props.grant.delivery_status] || props.grant.delivery_status;
});

function communityStatusLabel(status) {
    const map = {
        pending: 'Aguardando convite',
        invite_ready: 'Convite disponível',
        awaiting_join_request: 'Solicitação em análise',
        joined: 'Entrada confirmada',
        failed: 'Falha técnica',
        revoked: 'Revogado',
    };
    return map[status] || status;
}

function refreshInvites() {
    router.post(`/painel-cliente/telegram/${props.grant.id}/refresh-invites`, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8">
        <div>
            <Link href="/painel-cliente" class="text-sm text-[var(--color-primary)] hover:underline">
                ← Minhas compras
            </Link>
            <h1 class="mt-2 text-2xl font-semibold text-zinc-900 dark:text-white">
                {{ grant.product?.name || 'Acesso Telegram' }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Entrega: {{ deliveryLabel }}
            </p>
        </div>

        <div
            v-if="flashSuccess"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-100"
        >
            {{ flashSuccess }}
        </div>

        <section class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100">
            {{ grant.access_starts_notice }}
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Vigência do acesso</h2>
            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-zinc-500">Início (pagamento confirmado)</dt>
                    <dd class="text-sm font-medium text-zinc-900 dark:text-white">{{ grant.access_starts_at_label || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">Vencimento</dt>
                    <dd class="text-sm font-medium text-zinc-900 dark:text-white">
                        {{ grant.is_lifetime ? 'Vitalício' : (grant.access_ends_at_label || '—') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">Identidade Telegram</dt>
                    <dd class="text-sm font-medium text-zinc-900 dark:text-white">
                        {{ grant.has_identity ? ('@' + (grant.telegram_username || grant.id)) : 'Ainda não vinculada' }}
                    </dd>
                    <a
                        v-if="!grant.has_identity && grant.identity_bot_url"
                        :href="grant.identity_bot_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-1 inline-flex text-xs font-medium text-[var(--color-primary)] hover:underline"
                    >
                        Abrir bot para vincular identidade
                    </a>
                </div>
            </dl>
            <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                Timestamps armazenados em UTC e exibidos no fuso da plataforma.
            </p>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Comunidades</h2>
                <Button type="button" variant="outline" @click="refreshInvites">Atualizar convites</Button>
            </div>
            <p class="mt-1 text-xs text-zinc-500">
                Convite disponível não significa entrega concluída. A entrega só fecha quando a entrada for confirmada em todas as comunidades.
            </p>
            <ul class="mt-4 space-y-3">
                <li
                    v-for="community in grant.communities || []"
                    :key="community.id"
                    class="rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-medium text-zinc-900 dark:text-white">
                                {{ community.title || ('Comunidade #' + community.id) }}
                            </p>
                            <p class="text-xs text-zinc-500">{{ communityStatusLabel(community.status) }}</p>
                        </div>
                        <a
                            v-if="community.invite_link && community.status !== 'joined'"
                            :href="community.invite_link"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="rounded-lg bg-[var(--color-primary)] px-3 py-1.5 text-sm font-medium text-white"
                        >
                            Entrar
                        </a>
                        <span
                            v-else-if="community.status === 'joined'"
                            class="rounded-lg bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200"
                        >
                            Entrou em {{ community.joined_at_label || '—' }}
                        </span>
                    </div>
                    <p v-if="community.last_error" class="mt-2 text-xs text-red-600 dark:text-red-400">
                        {{ community.last_error }}
                    </p>
                    <p
                        v-if="community.invite_link_expires_at_label && community.status !== 'joined'"
                        class="mt-1 text-xs text-zinc-500"
                    >
                        Convite válido até {{ community.invite_link_expires_at_label }}
                    </p>
                </li>
            </ul>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Histórico</h2>
            <ul class="mt-3 space-y-2">
                <li
                    v-for="event in events"
                    :key="event.id"
                    class="flex flex-wrap items-baseline justify-between gap-2 border-b border-zinc-100 py-2 text-sm last:border-0 dark:border-zinc-800"
                >
                    <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ event.event_type }}</span>
                    <span class="text-xs text-zinc-500">{{ event.occurred_at_label }}</span>
                </li>
                <li v-if="!events.length" class="text-sm text-zinc-500">Sem eventos registrados.</li>
            </ul>
        </section>
    </div>
</template>
