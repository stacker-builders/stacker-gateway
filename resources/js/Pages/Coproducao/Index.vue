<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import CoproducaoTabs from '@/components/coproducao/CoproducaoTabs.vue';
import AuroraStatCard from '@/components/aurora/AuroraStatCard.vue';
import Button from '@/components/ui/Button.vue';
import { useI18n } from '@/composables/useI18n';
import {
    CircleDollarSign,
    Handshake,
    Hourglass,
    Package,
    ShoppingCart,
    ExternalLink,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const { t } = useI18n();

const props = defineProps({
    tab: { type: String, default: 'painel' },
    transactions: { type: Object, required: true },
    stats: { type: Object, default: () => ({}) },
    participations: { type: Array, default: () => [] },
    participation_counts: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
    period_options: { type: Array, default: () => [] },
    status_options: { type: Array, default: () => [] },
});

const panelHref = '/coproducao';

const activeTab = computed(() => (props.tab === 'participacoes' ? 'participacoes' : 'painel'));

function participationIsLive(row) {
    if (row.status !== 'active') {
        return false;
    }
    if (!row.ends_at) {
        return true;
    }
    const ends = Date.parse(row.ends_at);

    return Number.isFinite(ends) && ends > Date.now();
}

const activeParticipations = computed(() =>
    (props.participations ?? []).filter(participationIsLive)
);
const pendingParticipations = computed(() =>
    (props.participations ?? []).filter((row) => row.status === 'pending')
);

function formatBRL(v) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
}

function formatDate(v) {
    if (!v) return '—';
    return new Date(v).toLocaleString('pt-BR');
}

function durationPresetLabel(preset) {
    if (preset === 'eternal') return 'Por tempo indeterminado';
    if (['30', '60', '90', '120'].includes(preset)) return preset + ' dias';
    return preset || '—';
}

function statusBadgeClass(status) {
    if (status === 'available') {
        return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200';
    }
    if (status === 'pending') {
        return 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200';
    }
    return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
}

function participationStatusLabel(s) {
    const map = {
        pending: 'Pendente',
        active: 'Ativa',
        declined: 'Recusada',
        revoked: 'Revogada',
        expired: 'Expirada',
    };
    return map[s] || s;
}

function applyFilters(patch = {}) {
    router.get(panelHref, { ...props.filters, tab: 'painel', ...patch }, { preserveState: true, replace: true });
}

function acceptInvite(token) {
    router.post('/coproducao/convite/' + token + '/aceitar', {}, { preserveScroll: true });
}

function openInvitePage(token) {
    window.location.href = '/coproducao/convite/' + token;
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                {{ t('coproduction.panel_title', 'Co-produção') }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{
                    t(
                        'coproduction.panel_subtitle',
                        'Acompanhe suas participações e as comissões creditadas nas vendas em que você é co-produtor.'
                    )
                }}
            </p>
        </div>

        <CoproducaoTabs :tab="activeTab" />

        <template v-if="activeTab === 'painel'">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <AuroraStatCard
                    :label="t('coproduction.stat_net', 'Comissão líquida')"
                    :value="formatBRL(stats.total_liquido)"
                    :icon="CircleDollarSign"
                />
                <AuroraStatCard
                    :label="t('coproduction.stat_available', 'Disponível')"
                    :value="formatBRL(stats.disponivel)"
                    :icon="CircleDollarSign"
                />
                <AuroraStatCard
                    :label="t('coproduction.stat_pending', 'Em liquidação')"
                    :value="formatBRL(stats.pendente)"
                    :icon="Hourglass"
                />
                <AuroraStatCard
                    :label="t('coproduction.stat_count', 'Transações')"
                    :value="String(stats.total_transacoes ?? 0)"
                    :icon="ShoppingCart"
                />
            </div>

            <section class="space-y-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    {{ t('coproduction.transactions_title', 'Transações') }}
                </h2>

                <div class="flex flex-wrap gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <input
                        :value="filters.q"
                        type="search"
                        placeholder="Buscar pedido, produto, cliente..."
                        class="min-w-[180px] flex-1 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        @change="applyFilters({ q: $event.target.value })"
                    />
                    <select
                        :value="filters.product_id"
                        class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        @change="applyFilters({ product_id: $event.target.value })"
                    >
                        <option value="">Todos os produtos</option>
                        <option v-for="prod in products" :key="prod.id" :value="prod.id">{{ prod.name }}</option>
                    </select>
                    <select
                        :value="filters.period"
                        class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        @change="applyFilters({ period: $event.target.value })"
                    >
                        <option v-for="opt in period_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                    <select
                        :value="filters.status"
                        class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        @change="applyFilters({ status: $event.target.value })"
                    >
                        <option v-for="opt in status_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                    <template v-if="filters.period === 'personalizado'">
                        <input
                            :value="filters.date_from"
                            type="date"
                            class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                            @change="applyFilters({ date_from: $event.target.value })"
                        />
                        <input
                            :value="filters.date_to"
                            type="date"
                            class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                            @change="applyFilters({ date_to: $event.target.value })"
                        />
                    </template>
                </div>

                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-zinc-50 text-left text-xs uppercase text-zinc-500 dark:bg-zinc-900/50">
                                <tr>
                                    <th class="px-4 py-3">Data</th>
                                    <th class="px-4 py-3">Produto</th>
                                    <th class="px-4 py-3">Produtor</th>
                                    <th class="px-4 py-3">Pedido</th>
                                    <th class="px-4 py-3 text-right">Bruto</th>
                                    <th class="px-4 py-3 text-right">Taxa</th>
                                    <th class="px-4 py-3 text-right">Líquido</th>
                                    <th class="px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="tx in transactions.data"
                                    :key="tx.id"
                                    class="border-t border-zinc-100 dark:border-zinc-700"
                                >
                                    <td class="px-4 py-3 whitespace-nowrap text-zinc-600 dark:text-zinc-300">
                                        {{ formatDate(tx.created_at) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-zinc-900 dark:text-white">{{ tx.product_name }}</p>
                                        <p class="text-xs text-zinc-500">{{ tx.payment_method || '—' }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ tx.producer_name }}</td>
                                    <td class="px-4 py-3 tabular-nums text-zinc-600 dark:text-zinc-300">
                                        {{ tx.public_reference || ('#' + tx.order_id) }}
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ formatBRL(tx.amount_gross) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-zinc-500">{{ formatBRL(tx.amount_fee) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums font-medium">{{ formatBRL(tx.amount_net) }}</td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium"
                                            :class="statusBadgeClass(tx.status)"
                                        >
                                            {{ tx.status_label }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p
                        v-if="!transactions.data?.length"
                        class="p-8 text-center text-sm text-zinc-500"
                    >
                        Nenhuma comissão de co-produção encontrada para os filtros selecionados.
                    </p>
                    <div
                        v-if="transactions.links?.length > 3"
                        class="flex flex-wrap items-center justify-center gap-1 border-t border-zinc-100 p-3 dark:border-zinc-700"
                    >
                        <Link
                            v-for="(link, idx) in transactions.links"
                            :key="idx"
                            :href="link.url || '#'"
                            :class="[
                                'rounded-md px-3 py-1.5 text-xs font-medium',
                                link.active
                                    ? 'bg-[var(--color-primary)] text-white'
                                    : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700',
                                !link.url ? 'pointer-events-none opacity-40' : '',
                            ]"
                            v-html="link.label"
                            preserve-scroll
                        />
                    </div>
                </div>
            </section>
        </template>

        <template v-else>
            <section class="space-y-4">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ t('coproduction.participations_title', 'Suas participações') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-zinc-500">
                        {{ participation_counts.active ?? 0 }} ativa(s)
                        <span v-if="(participation_counts.pending ?? 0) > 0">
                            · {{ participation_counts.pending }} pendente(s)
                        </span>
                    </p>
                </div>

                <div v-if="pendingParticipations.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="row in pendingParticipations"
                        :key="'p-' + row.id"
                        class="flex flex-col rounded-xl border border-amber-200 bg-amber-50/50 p-4 dark:border-amber-900/50 dark:bg-amber-950/20"
                    >
                        <div class="flex gap-3">
                            <div class="relative h-14 w-14 shrink-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-600">
                                <img
                                    v-if="row.product?.image_url"
                                    :src="row.product.image_url"
                                    :alt="row.product.name"
                                    class="absolute inset-0 h-full w-full object-cover"
                                />
                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center bg-zinc-100 text-zinc-400 dark:bg-zinc-700/50"
                                >
                                    <Package class="h-6 w-6" />
                                </div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-zinc-900 line-clamp-2 dark:text-white">{{ row.product?.name }}</p>
                                <p class="mt-0.5 text-xs text-zinc-500">
                                    Produtor: {{ row.product?.owner_name || '—' }}
                                </p>
                                <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">
                                    {{ row.commission_percent }}% · {{ durationPresetLabel(row.duration_preset) }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <Button type="button" class="flex-1" @click="acceptInvite(row.token)">Aceitar</Button>
                            <Button type="button" variant="outline" class="flex-1" @click="openInvitePage(row.token)">
                                Detalhes
                            </Button>
                        </div>
                    </div>
                </div>

                <div v-if="activeParticipations.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="row in activeParticipations"
                        :key="'a-' + row.id"
                        class="flex flex-col rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <div class="flex gap-3">
                            <div class="relative h-14 w-14 shrink-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-600">
                                <img
                                    v-if="row.product?.image_url"
                                    :src="row.product.image_url"
                                    :alt="row.product.name"
                                    class="absolute inset-0 h-full w-full object-cover"
                                />
                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center bg-zinc-100 text-zinc-400 dark:bg-zinc-700/50"
                                >
                                    <Handshake class="h-6 w-6" />
                                </div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-medium text-zinc-900 line-clamp-2 dark:text-white">{{ row.product?.name }}</p>
                                    <span class="rounded-md bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200">
                                        {{ participationStatusLabel(row.status) }}
                                    </span>
                                </div>
                                <p class="mt-0.5 text-xs text-zinc-500">
                                    Produtor: {{ row.product?.owner_name || '—' }}
                                </p>
                                <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">
                                    {{ row.commission_percent }}%
                                    <span v-if="row.commission_on_direct_sales"> · vendas diretas</span>
                                    <span v-if="row.commission_on_affiliate_sales"> · vendas afiliado</span>
                                </p>
                            </div>
                        </div>
                        <button
                            v-if="row.product?.id"
                            type="button"
                            class="mt-3 self-start text-xs font-medium text-[var(--color-primary)] hover:underline"
                            @click="applyFilters({ tab: 'painel', product_id: String(row.product.id) })"
                        >
                            Ver transações deste produto
                        </button>
                        <Link
                            v-if="row.product?.id"
                            :href="'/produtos/' + row.product.id + '/edit'"
                            class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-[var(--color-primary)] hover:underline"
                        >
                            Abrir produto
                        </Link>
                        <a
                            v-if="row.product?.checkout_slug"
                            :href="'/c/' + row.product.checkout_slug"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-zinc-500 hover:text-[var(--color-primary)]"
                        >
                            <ExternalLink class="h-3.5 w-3.5" />
                            Ver checkout
                        </a>
                    </div>
                </div>

                <div
                    v-if="!activeParticipations.length && !pendingParticipations.length"
                    class="rounded-xl border border-dashed border-zinc-200 bg-zinc-50/80 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900/40 dark:text-zinc-400"
                >
                    Você ainda não participa de nenhuma co-produção.
                </div>
            </section>
        </template>
    </div>
</template>
