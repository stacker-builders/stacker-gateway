<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import ProdutosTabs from '@/components/produtos/ProdutosTabs.vue';
import ProdutoCreateSidebar from '@/components/produtos/ProdutoCreateSidebar.vue';
import ProdutoCardMenu from '@/components/produtos/ProdutoCardMenu.vue';
import AuroraPageHeader from '@/components/aurora/AuroraPageHeader.vue';
import AuroraPageSection from '@/components/aurora/AuroraPageSection.vue';
import { useI18n } from '@/composables/useI18n';
import { usePanelThemeClasses } from '@/composables/usePanelThemeClasses';
import { LayoutGrid, LayoutList, Package } from 'lucide-vue-next';
import { htmlToText } from '@/lib/sanitizeHtml';

defineOptions({ layout: LayoutInfoprodutor });
const { t } = useI18n();
const { pageClass, mobileCardClass, themePrefix, isKawaii, isAurora, isNoir } = usePanelThemeClasses();

const VIEW_STORAGE_KEY = 'produtos.view-mode';

function readViewMode() {
    try {
        const saved = localStorage.getItem(VIEW_STORAGE_KEY);
        if (saved === 'grid' || saved === 'list') return saved;
    } catch {
        // preferência local indisponível
    }
    return 'list';
}

const viewMode = ref(readViewMode());

function setViewMode(mode) {
    viewMode.value = mode;
    try {
        localStorage.setItem(VIEW_STORAGE_KEY, mode);
    } catch {
        // preferência local indisponível
    }
}

const viewToggleGroupClass = computed(() => {
    if (themePrefix.value) return `${themePrefix.value}-subnav inline-flex self-start`;
    return 'inline-flex self-start rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80';
});

const listPanelClass = computed(() => {
    if (isAurora.value) return 'rounded-xl border border-[var(--aurora-border)] bg-[var(--aurora-panel)]';
    if (isKawaii.value) return 'rounded-[var(--kawaii-radius)] border border-[var(--kawaii-border)] bg-[var(--kawaii-panel)] shadow-[var(--kawaii-shadow)]';
    if (isNoir.value) return 'rounded-xl border border-[var(--noir-border)] bg-[var(--noir-panel)]';
    return 'rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800/80';
});

function viewToggleClass(mode) {
    const active = viewMode.value === mode;
    if (themePrefix.value) {
        return [
            `${themePrefix.value}-subnav-item inline-flex items-center gap-1.5`,
            active && `${themePrefix.value}-subnav-item-active`,
        ];
    }
    return [
        'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition',
        active
            ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white'
            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
    ];
}

function productExcerpt(product) {
    return String(product?.description || '').replace(/\s+/g, ' ').trim();
}

const props = defineProps({
    produtos: { type: [Array, Object], default: () => [] },
    productTypes: { type: Array, default: () => [] },
    billingTypes: { type: Array, default: () => [] },
    productCategories: { type: Array, default: () => [] },
    exchange_rates: { type: Object, default: () => ({ brl_eur: 0.16, brl_usd: 0.18 }) },
    plugin_card_actions: { type: Object, default: () => ({}) },
    plugin_form_sections: { type: Array, default: () => [] },
    checkout_gateway_ui: {
        type: Object,
        default: () => ({
            card_show_installments: false,
            platform_card_installments_max: 12,
        }),
    },
    bundle_candidates: { type: Array, default: () => [] },
    telegram_delivery: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const produtosList = computed(() => props.produtos?.data ?? (Array.isArray(props.produtos) ? props.produtos : []));
const selectedCategory = computed(() => props.filters?.category || '');

const categorySelectClass = computed(() => {
    const base = 'h-9 max-w-full rounded-xl border px-3 text-sm font-medium shadow-sm';
    if (isAurora.value) return `${base} border-[var(--aurora-border)] bg-[var(--aurora-panel)] text-zinc-800`;
    if (isKawaii.value) return `${base} border-[var(--kawaii-border)] bg-[var(--kawaii-panel)] text-zinc-800`;
    if (isNoir.value) return `${base} border-[var(--noir-border)] bg-[var(--noir-panel)] text-zinc-100`;
    return `${base} border-zinc-200 bg-white text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200`;
});

function setCategory(event) {
    const value = String(event.target.value || '');
    router.get('/produtos', value ? { category: value } : {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const sidebarOpen = ref(false);
const openMenuId = ref(null);
const productToDelete = ref(null);

function openSidebar() {
    sidebarOpen.value = true;
}

function closeSidebar() {
    sidebarOpen.value = false;
}

function toggleMenu(id) {
    openMenuId.value = openMenuId.value === id ? null : id;
}

function closeMenu() {
    openMenuId.value = null;
}

function handleClickOutside(event) {
    if (openMenuId.value == null) return;
    const menuEl = document.querySelector(`[data-product-menu="${openMenuId.value}"]`);
    if (menuEl && !menuEl.contains(event.target)) {
        closeMenu();
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

function duplicate(p) {
    router.post(`/produtos/${p.id}/duplicate`, {}, { preserveScroll: true });
    closeMenu();
}

function resubmit(p) {
    if (!confirm(`Reenviar "${p.name}" para análise?`)) return;
    router.post(`/produtos/${p.id}/reenviar-analise`, {}, { preserveScroll: true });
    closeMenu();
}

function openDeleteModal(p) {
    closeMenu();
    productToDelete.value = p;
}

function closeDeleteModal() {
    productToDelete.value = null;
}

function confirmDestroy() {
    const p = productToDelete.value;
    if (!p) return;
    router.delete(`/produtos/${p.id}`, { preserveScroll: true });
    closeDeleteModal();
}

function pluginActions(productId) {
    return props.plugin_card_actions?.[productId] ?? [];
}
</script>

<template>
    <div :class="pageClass">
        <AuroraPageHeader
            :title="t('sidebar.products', 'Produtos')"
            :subtitle="t('products.subtitle', 'Gerencie seus produtos, ofertas e acessos de checkout.')"
        />

        <ProdutosTabs />

        <AuroraPageSection>
            <div class="space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap items-center gap-2">
                <div
                    :class="viewToggleGroupClass"
                    role="group"
                    aria-label="Visualização dos produtos"
                >
                    <button
                        type="button"
                        :class="viewToggleClass('list')"
                        :aria-pressed="viewMode === 'list'"
                        @click="setViewMode('list')"
                    >
                        <LayoutList class="h-4 w-4" aria-hidden="true" />
                        Lista
                    </button>
                    <button
                        type="button"
                        :class="viewToggleClass('grid')"
                        :aria-pressed="viewMode === 'grid'"
                        @click="setViewMode('grid')"
                    >
                        <LayoutGrid class="h-4 w-4" aria-hidden="true" />
                        Grade
                    </button>
                </div>
                <label class="sr-only" for="produtos-category-filter">Categoria</label>
                <select
                    id="produtos-category-filter"
                    :value="selectedCategory"
                    :class="categorySelectClass"
                    aria-label="Filtrar por categoria"
                    @change="setCategory"
                >
                    <option value="">Todos</option>
                    <option
                        v-for="cat in productCategories"
                        :key="cat.value"
                        :value="cat.value"
                    >
                        {{ cat.label }}
                    </option>
                </select>
                </div>
                <Button class="self-start sm:self-auto" @click="openSidebar">
                    Novo produto
                </Button>
            </div>

            <div
                v-if="produtosList.length && viewMode === 'list'"
                :class="listPanelClass"
            >
                <div
                    v-for="p in produtosList"
                    :key="p.id"
                    :class="[
                        'flex items-start gap-3 border-b border-zinc-200 px-3 py-3 transition last:border-b-0 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-700/40 sm:items-center sm:gap-4 sm:px-4',
                        openMenuId === p.id && 'relative z-20',
                    ]"
                >
                    <Link
                        :href="`/produtos/${p.id}/edit`"
                        class="shrink-0"
                    >
                        <div class="relative h-14 w-14 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-600 sm:h-16 sm:w-16">
                            <img
                                v-if="p.image_url"
                                :src="p.image_url"
                                :alt="p.name"
                                class="absolute inset-0 h-full w-full object-cover object-center"
                            />
                            <div
                                v-else
                                class="flex h-full w-full items-center justify-center bg-zinc-100 text-zinc-400 dark:bg-zinc-700/50 dark:text-zinc-500"
                            >
                                <Package class="h-6 w-6" aria-hidden="true" />
                            </div>
                        </div>
                    </Link>
                    <div class="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="`/produtos/${p.id}/edit`"
                                class="block truncate text-sm font-medium text-zinc-900 hover:underline dark:text-white"
                            >
                                {{ p.name }}
                            </Link>
                            <p
                                v-if="productExcerpt(p)"
                                class="mt-0.5 line-clamp-1 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                {{ productExcerpt(p) }}
                            </p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <span class="inline-block rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400">
                                    {{ p.type_label }}
                                </span>
                                <span class="inline-block rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400">
                                    {{ p.billing_type_label ?? 'Pagamento único' }}
                                </span>
                                <span
                                    v-if="p.category_label"
                                    class="inline-block rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400"
                                >
                                    {{ p.category_label }}
                                </span>
                                <span
                                    v-if="p.is_coproduction"
                                    class="inline-block rounded bg-sky-100 px-1.5 py-0.5 text-xs font-medium text-sky-800 dark:bg-sky-900/40 dark:text-sky-200"
                                >
                                    Co-produção{{ p.coproduction_percent != null ? ' · ' + p.coproduction_percent + '%' : '' }}
                                </span>
                                <span
                                    :class="[
                                        'inline-block rounded px-2 py-0.5 text-xs font-medium',
                                        p.is_active
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                            : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400',
                                    ]"
                                >
                                    {{ p.is_active ? 'Ativo' : 'Inativo' }}
                                </span>
                                <span
                                    v-if="p.approval && p.approval.status !== 'approved'"
                                    :class="[
                                        'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium',
                                        p.approval.status === 'pending'
                                            ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
                                            : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                                    ]"
                                    :title="p.approval.description"
                                >
                                    {{ p.approval.label }}
                                </span>
                                <span
                                    v-else-if="p.approval?.status === 'approved'"
                                    class="inline-flex items-center rounded-md bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800 dark:bg-sky-900/40 dark:text-sky-200"
                                    :title="p.approval.description"
                                >
                                    {{ p.approval.label }}
                                </span>
                            </div>
                            <div
                                v-if="p.approval?.status === 'pending'"
                                class="mt-2 rounded-lg border border-amber-200/80 bg-amber-50/90 px-2.5 py-2 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100"
                            >
                                <p class="font-medium">Checkout offline até a aprovação</p>
                                <p class="mt-0.5 opacity-90">Você pode editar o produto; o link `/c/…` só vende depois da análise da plataforma.</p>
                            </div>
                            <div
                                v-else-if="p.approval?.status === 'rejected'"
                                class="mt-2 rounded-lg border border-red-200/80 bg-red-50/90 px-2.5 py-2 text-xs text-red-900 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-100"
                            >
                                <p class="font-medium">Não aprovado — checkout offline</p>
                                <p class="mt-0.5 opacity-90">Motivo: {{ p.approval.reason || 'Consulte o painel de edição.' }}</p>
                            </div>
                        </div>
                        <div class="shrink-0 sm:w-36 sm:text-right">
                            <p class="text-sm font-semibold text-[var(--color-primary)]">
                                {{ formatBRL(p.price_brl ?? p.price) }}
                            </p>
                            <a
                                v-if="p.checkout_slug && p.available_for_purchase"
                                :href="`/c/${p.checkout_slug}`"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-0.5 inline-block text-xs text-zinc-500 hover:text-[var(--color-primary)] dark:text-zinc-400 dark:hover:text-[var(--color-primary)]"
                            >
                                Ver checkout →
                            </a>
                            <span
                                v-else-if="p.checkout_slug && !p.available_for_purchase"
                                class="mt-0.5 block text-xs text-zinc-400 dark:text-zinc-500"
                                :title="p.approval?.description || 'Checkout offline'"
                            >
                                Checkout offline
                            </span>
                        </div>
                    </div>
                    <ProdutoCardMenu
                        class="self-start sm:self-center"
                        :product="p"
                        :open="openMenuId === p.id"
                        :actions="pluginActions(p.id)"
                        @toggle="toggleMenu(p.id)"
                        @close="closeMenu"
                        @duplicate="duplicate(p)"
                        @resubmit="resubmit(p)"
                        @delete="openDeleteModal(p)"
                    />
                </div>
            </div>

            <div v-else-if="produtosList.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="p in produtosList"
                :key="p.id"
                :class="[
                    'group relative flex flex-row gap-3 p-3 pr-2 transition',
                    mobileCardClass,
                ]"
            >
                <!-- Coluna da imagem: clicável → edição -->
                <Link
                    :href="`/produtos/${p.id}/edit`"
                    class="flex w-20 shrink-0 items-center justify-center self-stretch"
                >
                    <div class="relative h-20 w-20 shrink-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-600">
                        <img
                            v-if="p.image_url"
                            :src="p.image_url"
                            :alt="p.name"
                            class="absolute inset-0 h-full w-full object-cover object-center"
                        />
                        <div
                            v-else
                            class="flex h-full w-full items-center justify-center bg-zinc-100 text-zinc-400 dark:bg-zinc-700/50 dark:text-zinc-500"
                        >
                            <Package class="h-8 w-8" aria-hidden="true" />
                        </div>
                    </div>
                </Link>
                <!-- Conteúdo à direita (só padding horizontal para não somar com o do card no topo/baixo) -->
                <div class="flex min-w-0 flex-1 flex-col px-2">
                    <div class="flex items-start justify-between gap-1.5">
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="`/produtos/${p.id}/edit`"
                                class="font-medium text-zinc-900 dark:text-white line-clamp-2 block text-sm leading-tight hover:underline"
                            >
                                {{ p.name }}
                            </Link>
                            <div class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                <span class="inline-block rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400">
                                    {{ p.type_label }}
                                </span>
                                <span class="inline-block rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400">
                                    {{ p.billing_type_label ?? 'Pagamento único' }}
                                </span>
                                <span
                                    v-if="p.is_coproduction"
                                    class="inline-block rounded bg-sky-100 px-1.5 py-0.5 text-xs font-medium text-sky-800 dark:bg-sky-900/40 dark:text-sky-200"
                                >
                                    Co-produção{{ p.coproduction_percent != null ? ' · ' + p.coproduction_percent + '%' : '' }}
                                </span>
                                <span
                                    :class="[
                                        'inline-block rounded px-2 py-0.5 text-xs font-medium',
                                        p.is_active
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                            : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400',
                                    ]"
                                >
                                    {{ p.is_active ? 'Ativo' : 'Inativo' }}
                                </span>
                                <span
                                    v-if="p.approval && p.approval.status !== 'approved'"
                                    :class="[
                                        'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium',
                                        p.approval.status === 'pending'
                                            ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
                                            : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                                    ]"
                                    :title="p.approval.description"
                                >
                                    {{ p.approval.label }}
                                </span>
                                <span
                                    v-else-if="p.approval?.status === 'approved'"
                                    class="inline-flex items-center rounded-md bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800 dark:bg-sky-900/40 dark:text-sky-200"
                                    :title="p.approval.description"
                                >
                                    {{ p.approval.label }}
                                </span>
                            </div>
                            <div
                                v-if="p.approval?.status === 'pending'"
                                class="mt-2 rounded-lg border border-amber-200/80 bg-amber-50/90 px-2.5 py-2 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100"
                            >
                                <p class="font-medium">Checkout offline até a aprovação</p>
                                <p class="mt-0.5 opacity-90">Você pode editar o produto; o link `/c/…` só vende depois da análise da plataforma.</p>
                            </div>
                            <div
                                v-else-if="p.approval?.status === 'rejected'"
                                class="mt-2 rounded-lg border border-red-200/80 bg-red-50/90 px-2.5 py-2 text-xs text-red-900 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-100"
                            >
                                <p class="font-medium">Não aprovado — checkout offline</p>
                                <p class="mt-0.5 opacity-90">Motivo: {{ p.approval.reason || 'Consulte o painel de edição.' }}</p>
                            </div>
                        </div>
                        <ProdutoCardMenu
                            :product="p"
                            :open="openMenuId === p.id"
                            :actions="pluginActions(p.id)"
                            @toggle="toggleMenu(p.id)"
                            @close="closeMenu"
                            @duplicate="duplicate(p)"
                            @resubmit="resubmit(p)"
                            @delete="openDeleteModal(p)"
                        />
                    </div>
                    <p class="mt-0.5 text-sm font-semibold text-[var(--color-primary)]">
                        {{ formatBRL(p.price_brl ?? p.price) }}
                    </p>
                    <a
                        v-if="p.checkout_slug && p.available_for_purchase"
                        :href="`/c/${p.checkout_slug}`"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-0.5 text-xs text-zinc-500 hover:text-[var(--color-primary)] dark:text-zinc-400 dark:hover:text-[var(--color-primary)]"
                    >
                        Ver checkout →
                    </a>
                    <span
                        v-else-if="p.checkout_slug && !p.available_for_purchase"
                        class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500"
                        :title="p.approval?.description || 'Checkout offline'"
                    >
                        Checkout offline
                    </span>
                </div>
            </div>
        </div>

            <div
                v-if="!produtosList.length"
                class="flex flex-col items-center justify-center rounded-xl border border-dashed py-16"
                :class="isAurora ? 'border-[var(--aurora-border)]' : isKawaii ? 'border-[var(--kawaii-border)]' : isNoir ? 'border-[var(--noir-border)]' : 'border-zinc-300 dark:border-zinc-700'"
            >
                <Package class="h-14 w-14 text-zinc-400 dark:text-zinc-500" />
                <p v-if="selectedCategory" class="mt-3 text-zinc-600 dark:text-zinc-400">Nenhum produto nesta categoria.</p>
                <template v-else>
                    <p class="mt-3 text-zinc-600 dark:text-zinc-400">Nenhum produto ainda.</p>
                    <Button class="mt-4" @click="openSidebar">
                        Criar primeiro produto
                    </Button>
                </template>
            </div>
            </div>
        </AuroraPageSection>

        <nav
            v-if="produtos?.links?.length > 3"
            class="flex items-center justify-center gap-2"
            aria-label="Paginação"
        >
            <a
                v-for="link in produtos.links"
                :key="link.label"
                :href="link.url"
                :aria-current="link.active ? 'page' : undefined"
                :aria-disabled="!link.url"
                :class="[
                    'relative inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium transition',
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

    <!-- Modal de confirmação de exclusão -->
    <Teleport to="body">
        <div
            v-if="productToDelete"
            class="fixed inset-0 z-[100002] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="delete-modal-title"
        >
            <div
                class="fixed inset-0 bg-zinc-900/60 dark:bg-zinc-950/70"
                aria-hidden="true"
                @click="closeDeleteModal"
            />
            <div
                class="relative w-full max-w-sm rounded-xl border border-zinc-200 bg-white p-5 shadow-xl dark:border-zinc-700 dark:bg-zinc-800"
            >
                <h2 id="delete-modal-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Excluir produto?
                </h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    Tem certeza que deseja excluir
                    <strong class="text-zinc-900 dark:text-white">"{{ productToDelete?.name }}"</strong>?
                    Esta ação não pode ser desfeita.
                </p>
                <div class="mt-5 flex gap-3 justify-end">
                    <Button variant="outline" @click="closeDeleteModal">
                        Cancelar
                    </Button>
                    <Button variant="destructive" @click="confirmDestroy">
                        Excluir
                    </Button>
                </div>
            </div>
        </div>
    </Teleport>

    <ProdutoCreateSidebar
        :open="sidebarOpen"
        :product-types="productTypes"
        :billing-types="billingTypes"
        :product-categories="productCategories"
        :exchange-rates="exchange_rates"
        :plugin-form-sections="plugin_form_sections"
        :checkout-gateway-ui="checkout_gateway_ui"
        :bundle-candidates="bundle_candidates"
        :telegram-delivery="telegram_delivery"
        @close="closeSidebar"
        @success="closeSidebar"
    />
</template>
