<script setup>
import { ref, watch, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import { Search, Store } from 'lucide-vue-next';
import { htmlToText } from '@/lib/sanitizeHtml';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    products: { type: Object, required: true },
    filters: {
        type: Object,
        default: () => ({ seller: null, category: null, status: 'all' }),
    },
    categories: { type: Array, default: () => [] },
    auto_accept: { type: Boolean, default: true },
    public_enabled: { type: Boolean, default: false },
    public_url: { type: String, default: '' },
    counts: { type: Object, default: () => ({ all: 0, pending: 0, authorized: 0 }) },
});

const page = usePage();
const sellerQ = ref(props.filters?.seller ?? '');
const selectedCategory = ref(props.filters?.category ?? '');
const activeStatus = ref(props.filters?.status ?? 'all');
const autoAccept = ref(Boolean(props.auto_accept));
const publicEnabled = ref(Boolean(props.public_enabled));

watch(
    () => props.filters,
    (f) => {
        sellerQ.value = f?.seller ?? '';
        selectedCategory.value = f?.category ?? '';
        activeStatus.value = f?.status ?? 'all';
    },
    { deep: true }
);

watch(
    () => props.auto_accept,
    (value) => {
        autoAccept.value = Boolean(value);
    }
);

watch(
    () => props.public_enabled,
    (value) => {
        publicEnabled.value = Boolean(value);
    }
);

const statusChips = computed(() => [
    { value: 'all', label: `Todos (${props.counts?.all ?? 0})` },
    { value: 'pending', label: `Aguardando (${props.counts?.pending ?? 0})` },
    { value: 'authorized', label: `Autorizados (${props.counts?.authorized ?? 0})` },
]);

const productRows = computed(() => props.products?.data ?? []);

function applyFilters() {
    router.get(
        '/plataforma/marketplace',
        {
            seller: sellerQ.value?.trim() || undefined,
            category: selectedCategory.value || undefined,
            status: activeStatus.value === 'all' ? undefined : activeStatus.value,
        },
        { preserveState: true, replace: true }
    );
}

function selectStatus(value) {
    activeStatus.value = value;
    applyFilters();
}

function setAutoAccept(enabled) {
    autoAccept.value = enabled;
    router.post(
        '/plataforma/marketplace/aceitacao-automatica',
        { auto_accept: enabled },
        {
            preserveScroll: true,
            onError: () => {
                autoAccept.value = Boolean(props.auto_accept);
            },
        }
    );
}

function setPublicEnabled(enabled) {
    publicEnabled.value = enabled;
    router.post(
        '/plataforma/marketplace/link-publico',
        { public_enabled: enabled },
        {
            preserveScroll: true,
            onError: () => {
                publicEnabled.value = Boolean(props.public_enabled);
            },
        }
    );
}

function authorizeProduct(product) {
    if (!confirm(`Autorizar "${product.name}" no marketplace da plataforma?`)) return;
    router.post(`/plataforma/marketplace/${product.id}/autorizar`, {}, { preserveScroll: true });
}

function revokeProduct(product) {
    if (!confirm(`Remover a autorização de "${product.name}"? Ele deixa de aparecer para os clientes.`)) return;
    router.post(`/plataforma/marketplace/${product.id}/revogar`, {}, { preserveScroll: true });
}

function formatMoney(value, currency) {
    const code = ['BRL', 'EUR', 'USD'].includes(currency) ? currency : 'BRL';
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: code }).format(Number(value) || 0);
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="flex items-center gap-2 text-xl font-semibold text-zinc-900 dark:text-white">
                <Store class="h-6 w-6 text-[var(--color-primary)]" aria-hidden="true" />
                Marketplace
            </h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Produtos que infoprodutores marcaram para o marketplace. Com a aceitação automática desligada, só entram no ar depois da sua autorização.
            </p>
        </div>

        <p
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"
        >
            {{ page.props.flash.success }}
        </p>
        <p
            v-if="page.props.flash?.error"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
        >
            {{ page.props.flash.error }}
        </p>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Aceitação automática</h2>
                    <p class="mt-1 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                        Ligada: o produto entra no marketplace assim que o infoprodutor ativa a opção no painel.
                        Desligada: cada produto fica aguardando autorização aqui.
                    </p>
                </div>
                <span
                    class="inline-flex w-fit shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                    :class="
                        autoAccept
                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200'
                            : 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200'
                    "
                >
                    {{ autoAccept ? 'Automática' : 'Autorização manual' }}
                </span>
            </div>
            <div class="mt-4">
                <Toggle
                    :model-value="autoAccept"
                    label="Permitir aceitação automática"
                    @update:model-value="setAutoAccept"
                />
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Link público</h2>
                    <p class="mt-1 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                        Ligado: qualquer pessoa abre o marketplace sem login.
                        Desligado: o link não abre.
                    </p>
                </div>
                <span
                    class="inline-flex w-fit shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                    :class="
                        publicEnabled
                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200'
                            : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'
                    "
                >
                    {{ publicEnabled ? 'No ar' : 'Fechado' }}
                </span>
            </div>
            <div class="mt-4">
                <Toggle
                    :model-value="publicEnabled"
                    label="Exibir marketplace em link público"
                    @update:model-value="setPublicEnabled"
                />
            </div>
            <p v-if="publicEnabled && public_url" class="mt-4 break-all text-sm text-zinc-700 dark:text-zinc-300">
                <span class="font-medium">Link:</span>
                <a :href="public_url" class="ml-1 text-[var(--color-primary)] underline" target="_blank" rel="noopener">{{ public_url }}</a>
            </p>
        </section>

        <form class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center" @submit.prevent="applyFilters">
            <div class="relative min-w-[220px] flex-1">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                <input
                    v-model="sellerQ"
                    type="search"
                    placeholder="Infoprodutor: nome, e-mail ou WhatsApp"
                    class="w-full rounded-xl border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
            </div>
            <select
                v-model="selectedCategory"
                class="rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                @change="applyFilters"
            >
                <option value="">Todas as categorias</option>
                <option
                    v-for="cat in categories"
                    :key="cat.value"
                    :value="cat.value"
                >
                    {{ cat.label }}
                </option>
                <option value="uncategorized">Sem categoria</option>
            </select>
            <button
                type="submit"
                class="rounded-xl bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white transition hover:opacity-90"
            >
                Filtrar
            </button>
        </form>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="chip in statusChips"
                :key="chip.value"
                type="button"
                :class="[
                    'rounded-lg border px-3 py-2 text-sm font-medium transition',
                    activeStatus === chip.value
                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-[var(--color-primary)] dark:bg-[var(--color-primary)]/20'
                        : 'border-zinc-200 bg-zinc-50 text-zinc-700 hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-900/50 dark:text-zinc-200',
                ]"
                @click="selectStatus(chip.value)"
            >
                {{ chip.label }}
            </button>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <div class="overflow-x-auto">
                <table class="min-w-[880px] w-full divide-y divide-zinc-200 text-left text-sm dark:divide-zinc-800">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/80">
                        <tr>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Produto</th>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Infoprodutor</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Preço</th>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        <tr v-for="p in productRows" :key="p.id" class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ p.name }}</div>
                                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ p.category_label || 'Sem categoria' }}
                                </div>
                                <div v-if="p.checkout_slug" class="mt-0.5 font-mono text-xs text-zinc-500">/c/{{ p.checkout_slug }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-zinc-900 dark:text-white">{{ p.infoprodutor_name }}</div>
                                <div class="text-xs text-zinc-500">{{ p.infoprodutor_email || '—' }}</div>
                                <div class="text-xs text-zinc-500">{{ p.infoprodutor_phone || '—' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-zinc-800 dark:text-zinc-200">
                                {{ formatMoney(p.price, p.currency) }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex w-fit rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        p.marketplace_approved
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200'
                                            : 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200'
                                    "
                                >
                                    {{ p.marketplace_approved ? 'Autorizado' : 'Aguardando' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <Button
                                        v-if="p.can_authorize"
                                        type="button"
                                        size="sm"
                                        @click="authorizeProduct(p)"
                                    >
                                        Autorizar
                                    </Button>
                                    <Button
                                        v-if="p.can_revoke"
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        @click="revokeProduct(p)"
                                    >
                                        Remover autorização
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="!productRows.length" class="rounded-xl border border-dashed border-zinc-200 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700">
            Nenhum produto marcado para o marketplace com esses filtros.
        </div>

        <nav
            v-if="(products?.links?.length ?? 0) > 3"
            class="flex w-full min-w-0 flex-wrap items-center justify-center gap-2"
            aria-label="Paginação"
        >
            <a
                v-for="link in products.links"
                :key="link.label + String(link.url)"
                :href="link.url || undefined"
                :aria-current="link.active ? 'page' : undefined"
                :aria-disabled="!link.url"
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
