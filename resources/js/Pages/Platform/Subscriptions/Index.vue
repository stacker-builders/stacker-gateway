<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { Search } from 'lucide-vue-next';
import { htmlToText } from '@/lib/sanitizeHtml';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    subscriptions: { type: Object, required: true },
    filters: {
        type: Object,
        default: () => ({ status: 'all', seller: '' }),
    },
    counts: {
        type: Object,
        default: () => ({ all: 0, active: 0, past_due: 0, cancelled: 0 }),
    },
    status_options: { type: Array, default: () => [] },
});

const sellerQ = ref(props.filters?.seller ?? '');
const activeStatus = ref(props.filters?.status ?? 'all');

watch(
    () => props.filters,
    (filters) => {
        sellerQ.value = filters?.seller ?? '';
        activeStatus.value = filters?.status ?? 'all';
    },
    { deep: true },
);

const rows = computed(() => props.subscriptions?.data ?? []);

const statusChips = computed(() =>
    (props.status_options.length ? props.status_options : [
        { value: 'all', label: 'Todas' },
        { value: 'active', label: 'Ativas' },
        { value: 'past_due', label: 'Em atraso' },
        { value: 'cancelled', label: 'Encerradas' },
    ]).map((option) => ({
        ...option,
        count: props.counts?.[option.value] ?? 0,
    })),
);

function applyFilters() {
    router.get(
        '/plataforma/assinaturas',
        {
            status: activeStatus.value === 'all' ? undefined : activeStatus.value,
            seller: sellerQ.value?.trim() || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function selectStatus(value) {
    activeStatus.value = value;
    applyFilters();
}

function formatBRL(value) {
    if (value === null || value === undefined || value === '') return '—';
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value));
}

function formatDate(value) {
    if (!value) return '—';
    const text = String(value);
    if (/^\d{4}-\d{2}-\d{2}$/.test(text)) {
        const [year, month, day] = text.split('-');
        return `${day}/${month}/${year}`;
    }
    const date = new Date(text);
    if (Number.isNaN(date.getTime())) return text;
    return date.toLocaleDateString('pt-BR');
}

function statusClass(status) {
    if (status === 'active') return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200';
    if (status === 'past_due') return 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100';
    if (status === 'cancelled') return 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200';
    return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200';
}
</script>

<template>
    <div class="mx-auto max-w-7xl space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Assinaturas</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Todas as assinaturas dos infoprodutores da plataforma.
            </p>
        </div>

        <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="applyFilters">
            <label class="min-w-0 flex-1 text-sm text-zinc-600 dark:text-zinc-300">
                E-mail do infoprodutor
                <span class="relative mt-1 block">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" aria-hidden="true" />
                    <input
                        v-model="sellerQ"
                        type="search"
                        placeholder="nome@email.com"
                        class="w-full rounded-xl border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                    />
                </span>
            </label>
            <Button type="submit">Filtrar</Button>
        </form>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="chip in statusChips"
                :key="chip.value"
                type="button"
                class="rounded-full px-3 py-1.5 text-sm font-medium transition"
                :class="activeStatus === chip.value
                    ? 'bg-[var(--color-primary)] text-white'
                    : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700'"
                @click="selectStatus(chip.value)"
            >
                {{ chip.label }} ({{ chip.count }})
            </button>
        </div>

        <div v-if="rows.length" class="space-y-3 lg:hidden">
            <article
                v-for="row in rows"
                :key="row.id"
                class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-medium text-zinc-900 dark:text-white">{{ row.customer_name }}</p>
                        <p class="truncate text-xs text-zinc-500">{{ row.customer_email }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="statusClass(row.status)">
                        {{ row.status_label }}
                    </span>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-[11px] uppercase tracking-wide text-zinc-500">Infoprodutor</dt>
                        <dd class="mt-0.5 break-all text-zinc-800 dark:text-zinc-200">{{ row.infoprodutor_email || row.infoprodutor_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wide text-zinc-500">Produto</dt>
                        <dd class="mt-0.5 text-zinc-800 dark:text-zinc-200">{{ row.product_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wide text-zinc-500">Compra</dt>
                        <dd class="mt-0.5 text-zinc-800 dark:text-zinc-200">{{ formatDate(row.purchased_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wide text-zinc-500">Próxima cobrança</dt>
                        <dd class="mt-0.5 text-zinc-800 dark:text-zinc-200">
                            <template v-if="row.next_charge_at">{{ formatDate(row.next_charge_at) }} · {{ formatBRL(row.next_charge_amount) }}</template>
                            <template v-else>Sem próxima cobrança</template>
                        </dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-zinc-500">
                    {{ row.payment_method_label }} · {{ row.charges_count }} cobrança(s), {{ row.automatic_charges_count }} automática(s)
                </p>
            </article>
        </div>

        <div v-if="rows.length" class="hidden overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800 lg:block">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[960px] text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-[11px] uppercase text-zinc-500 dark:border-zinc-600 dark:bg-zinc-900/50">
                        <tr>
                            <th class="px-4 py-3">Cliente</th>
                            <th class="px-4 py-3">Infoprodutor</th>
                            <th class="px-4 py-3">Produto</th>
                            <th class="px-4 py-3">Compra</th>
                            <th class="px-4 py-3">Próxima cobrança</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Valor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        <tr v-for="row in rows" :key="row.id">
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ row.customer_name }}</div>
                                <div class="text-xs text-zinc-500">{{ row.customer_email }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ row.infoprodutor_name }}</div>
                                <div class="text-xs text-zinc-500">{{ row.infoprodutor_email || '—' }}</div>
                            </td>
                            <td class="px-4 py-3 text-zinc-800 dark:text-zinc-200">
                                {{ row.product_name }}
                                <div class="text-xs text-zinc-500">{{ row.plan_name }}<span v-if="row.plan_interval"> · {{ row.plan_interval }}</span></div>
                            </td>
                            <td class="px-4 py-3 text-zinc-800 dark:text-zinc-200">
                                {{ formatDate(row.purchased_at) }}
                                <div class="text-xs text-zinc-500">{{ row.charges_count }} cobrança(s)</div>
                            </td>
                            <td class="px-4 py-3 text-zinc-800 dark:text-zinc-200">
                                <template v-if="row.next_charge_at">
                                    {{ formatDate(row.next_charge_at) }}
                                    <div class="text-xs text-zinc-500">{{ row.payment_method_label }} · {{ formatBRL(row.next_charge_amount) }}</div>
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium" :class="statusClass(row.status)">
                                    {{ row.status_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-zinc-900 dark:text-white">
                                {{ formatBRL(row.plan_price) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="!rows.length" class="rounded-xl border border-dashed border-zinc-200 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700">
            Nenhuma assinatura encontrada com esses filtros.
        </div>

        <nav
            v-if="(subscriptions?.links?.length ?? 0) > 3"
            class="flex w-full min-w-0 flex-wrap items-center justify-center gap-2"
            aria-label="Paginação"
        >
            <a
                v-for="link in subscriptions.links"
                :key="link.label + String(link.url)"
                :href="link.url || undefined"
                :aria-current="link.active ? 'page' : undefined"
                :class="[
                    'relative inline-flex min-h-[2.25rem] items-center rounded-lg px-3 py-2 text-sm font-medium transition',
                    link.active
                        ? 'z-10 bg-[var(--color-primary)] text-white'
                        : link.url
                          ? 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700'
                          : 'cursor-not-allowed text-zinc-400 dark:text-zinc-500',
                ]"
                v-text="htmlToText(link.label)"
                @click.prevent="link.url && router.visit(link.url, { preserveState: true })"
            />
        </nav>
    </div>
</template>
