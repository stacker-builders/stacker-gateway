<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import AuroraPageHeader from '@/components/aurora/AuroraPageHeader.vue';
import AuroraPageSection from '@/components/aurora/AuroraPageSection.vue';
import Button from '@/components/ui/Button.vue';
import { Search } from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';
import { usePanelThemeClasses } from '@/composables/usePanelThemeClasses';
import { htmlToText } from '@/lib/sanitizeHtml';

defineOptions({ layout: LayoutInfoprodutor });

const { t } = useI18n();
const { pageClass, tablePanel, mobileCardClass, isThemedShell } = usePanelThemeClasses();

const props = defineProps({
    products: { type: Array, default: () => [] },
    grants: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: {
        type: Object,
        default: () => ({ product_id: '', email: '', name: '' }),
    },
});

const form = ref({
    product_id: props.filters.product_id || '',
    email: props.filters.email || '',
    name: props.filters.name || '',
});

watch(
    () => props.filters,
    (next) => {
        form.value = {
            product_id: next?.product_id || '',
            email: next?.email || '',
            name: next?.name || '',
        };
    },
    { deep: true },
);

const rows = computed(() => props.grants?.data ?? []);
const paginationLinks = computed(() => (Array.isArray(props.grants?.links) ? props.grants.links : []));
const showPagination = computed(() => Number(props.grants?.last_page ?? 1) > 1);

function applyFilters() {
    const query = {};
    if (form.value.product_id) query.product_id = form.value.product_id;
    if (form.value.email?.trim()) query.email = form.value.email.trim();
    if (form.value.name?.trim()) query.name = form.value.name.trim();

    router.get('/produtos/telegram/assinantes', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    form.value = { product_id: '', email: '', name: '' };
    router.get('/produtos/telegram/assinantes', {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function goToPage(url) {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function entryHint(row) {
    if (row.entry_kind === 'identity') {
        return t('telegram.subscribers.entry_identity', 'Vínculo do bot');
    }
    if (row.entry_kind === 'joined') {
        return t('telegram.subscribers.entry_joined', 'Entrada no grupo/canal');
    }
    return null;
}
</script>

<template>
    <div :class="pageClass">
        <AuroraPageHeader
            :title="t('telegram.subscribers.title', 'Assinantes Telegram')"
            :subtitle="t('telegram.subscribers.subtitle', 'Compradores com acesso a grupos e canais dos seus produtos Telegram.')"
        />

        <AuroraPageSection>
            <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="applyFilters">
                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ t('sidebar.products', 'Produto') }}
                    </label>
                    <select
                        v-model="form.product_id"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    >
                        <option value="">{{ t('common.all', 'Todos') }}</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ t('telegram.subscribers.filter_email', 'E-mail de compra') }}
                    </label>
                    <input
                        v-model="form.email"
                        type="search"
                        autocomplete="off"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        :placeholder="t('telegram.subscribers.email_placeholder', 'buscar@email.com')"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ t('telegram.subscribers.filter_name', 'Nome') }}
                    </label>
                    <input
                        v-model="form.name"
                        type="search"
                        autocomplete="off"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        :placeholder="t('telegram.subscribers.name_placeholder', 'Nome do comprador')"
                    />
                </div>
                <div class="flex items-end gap-2">
                    <Button type="submit" class="inline-flex items-center gap-2">
                        <Search class="h-4 w-4" />
                        {{ t('common.filter', 'Filtrar') }}
                    </Button>
                    <Button type="button" variant="secondary" @click="clearFilters">
                        {{ t('common.clear', 'Limpar') }}
                    </Button>
                </div>
            </form>
        </AuroraPageSection>

        <AuroraPageSection flush>
            <div :class="['sm:hidden space-y-3', isThemedShell && 'p-4']">
                <div
                    v-for="row in rows"
                    :key="row.id"
                    :class="mobileCardClass || 'rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/60'"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">
                                {{ row.buyer_name || '—' }}
                            </p>
                            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                {{ row.buyer_email || '—' }}
                            </p>
                            <p v-if="row.telegram_username_label" class="mt-1 text-xs font-medium text-sky-700 dark:text-sky-300">
                                {{ row.telegram_username_label }}
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-medium text-zinc-700 dark:bg-zinc-700/60 dark:text-zinc-200">
                            {{ row.status_label }}
                        </span>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="text-zinc-500">{{ t('sidebar.products', 'Produto') }}</dt>
                            <dd class="mt-0.5 font-medium text-zinc-900 dark:text-white">{{ row.product?.name || '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ t('telegram.subscribers.purchase', 'Compra') }}</dt>
                            <dd class="mt-0.5 font-medium text-zinc-900 dark:text-white">{{ row.paid_at_label || '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ t('telegram.subscribers.entry', 'Entrada') }}</dt>
                            <dd class="mt-0.5 font-medium text-zinc-900 dark:text-white">
                                {{ row.entry_at_label || '—' }}
                                <span v-if="entryHint(row)" class="block font-normal text-zinc-500">{{ entryHint(row) }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ t('telegram.subscribers.validity', 'Validade') }}</dt>
                            <dd class="mt-0.5 font-medium text-zinc-900 dark:text-white">{{ row.access_ends_at_label || '—' }}</dd>
                        </div>
                    </dl>
                </div>
                <div
                    v-if="!rows.length"
                    class="rounded-xl border border-zinc-200 bg-white px-4 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60 dark:text-zinc-400"
                >
                    {{ t('telegram.subscribers.empty', 'Nenhum assinante encontrado.') }}
                </div>
            </div>

            <div :class="['hidden sm:block', tablePanel]">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ t('sales.customer', 'Cliente') }}
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ t('telegram.subscribers.username', '@ Telegram') }}
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ t('sidebar.products', 'Produto') }}
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ t('telegram.subscribers.purchase', 'Compra') }}
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ t('telegram.subscribers.entry', 'Entrada') }}
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ t('telegram.subscribers.validity', 'Validade') }}
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ t('sales.status', 'Status') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            class="bg-white dark:bg-zinc-800/60"
                        >
                            <td class="px-4 py-3">
                                <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ row.buyer_name || '—' }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ row.buyer_email || '—' }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-sky-700 dark:text-sky-300">
                                {{ row.telegram_username_label || '—' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-zinc-900 dark:text-white">
                                {{ row.product?.name || '—' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">
                                {{ row.paid_at_label || '—' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">
                                <div>{{ row.entry_at_label || '—' }}</div>
                                <div v-if="entryHint(row)" class="text-xs text-zinc-500">{{ entryHint(row) }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">
                                {{ row.access_ends_at_label || '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-700/60 dark:text-zinc-200">
                                    {{ row.status_label }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                {{ t('telegram.subscribers.empty', 'Nenhum assinante encontrado.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AuroraPageSection>

        <nav
            v-if="showPagination"
            class="mt-4 flex flex-wrap items-center justify-center gap-1"
            :aria-label="t('common.pagination', 'Paginação')"
        >
            <template v-for="(link, idx) in paginationLinks" :key="`${idx}-${link.label}`">
                <button
                    v-if="!link.url"
                    type="button"
                    disabled
                    class="rounded-lg px-3 py-1.5 text-sm text-zinc-400 dark:text-zinc-600"
                    v-html="link.label"
                />
                <button
                    v-else
                    type="button"
                    :class="[
                        'rounded-lg px-3 py-1.5 text-sm transition',
                        link.active
                            ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900'
                            : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800',
                    ]"
                    @click="goToPage(link.url)"
                >
                    {{ htmlToText(link.label) }}
                </button>
            </template>
        </nav>
    </div>
</template>
