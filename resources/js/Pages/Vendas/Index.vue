<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import VendasTabs from '@/components/vendas/VendasTabs.vue';
import VendaDetailSidebar from '@/components/vendas/VendaDetailSidebar.vue';
import AfiliadoVendaDetailSidebar from '@/components/afiliados/AfiliadoVendaDetailSidebar.vue';
import AuroraPageHeader from '@/components/aurora/AuroraPageHeader.vue';
import AuroraPageSection from '@/components/aurora/AuroraPageSection.vue';
import AuroraStatCard from '@/components/aurora/AuroraStatCard.vue';
import { useI18n } from '@/composables/useI18n';
import { usePanelThemeClasses } from '@/composables/usePanelThemeClasses';
import {
    Eye,
    EyeOff,
    CircleDollarSign,
    CreditCard,
    Banknote,
    ShoppingCart,
    MoreVertical,
    FileText,
    Mail,
    Download,
    Search,
    X,
    Package,
    ChevronDown,
    RotateCcw,
} from 'lucide-vue-next';
import Checkbox from '@/components/ui/Checkbox.vue';
import { htmlToText } from '@/lib/sanitizeHtml';
import { buildWhatsAppUrl, orderCustomerPhone } from '@/lib/whatsappUrl';

defineOptions({ layout: LayoutInfoprodutor });
const { t } = useI18n();
const {
    pageClass,
    iconBtn,
    btnSecondary,
    stackClass,
    tablePanel,
    themePrefix,
    isThemedShell,
    subnavClass,
} = usePanelThemeClasses();

const props = defineProps({
    view: { type: String, default: 'own' },
    has_affiliate_enrollments: { type: Boolean, default: false },
    vendas: { type: Object, default: () => ({ data: [], links: [] }) },
    stats: { type: Object, default: () => ({}) },
    status_filter: { type: String, default: 'todas' },
    filters: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
    producers: { type: Array, default: () => [] },
    commission_status_options: { type: Array, default: () => [] },
    offers: { type: Array, default: () => [] },
});

const affiliateSidebarOpen = ref(false);
const selectedAffiliateVenda = ref(null);

function isCommissionRow(v) {
    return !!(v?.is_affiliate_commission || v?.is_coproduction_commission);
}

function openAffiliateDetail(venda) {
    selectedAffiliateVenda.value = venda;
    affiliateSidebarOpen.value = true;
}

function vendaRowKey(v) {
    return v?.list_key ?? String(v?.id ?? '');
}

function openRowDetail(v) {
    if (isCommissionRow(v)) {
        openAffiliateDetail(v);
        return;
    }
    openDetail(v);
}

function customerDisplayName(v) {
    if (isCommissionRow(v)) {
        if (v.customer_hidden) return 'Oculto';
        return v.customer_name ?? v.customer_email ?? '—';
    }
    return v.user?.name ?? '—';
}

function customerDisplayEmail(v) {
    if (isCommissionRow(v)) {
        if (v.customer_hidden) return '—';
        return v.customer_email ?? '—';
    }
    return v.email ?? v.user?.email ?? '—';
}

function rowStatusBadgeLabel(v) {
    if (isCommissionRow(v)) {
        return v.status_label ?? v.status ?? '–';
    }
    return v.status_label ?? statusBadgeLabel(v.status);
}

function rowStatusBadgeClass(v) {
    if (isCommissionRow(v)) {
        const map = {
            approved: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
            available: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
            pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
            cancelled: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300',
            refunded: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
            refund_pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
        };
        return map[v.status] ?? 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300';
    }
    if (v.status === 'refunded' && v.manual_refund?.offline) {
        return 'bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-300';
    }
    return statusBadgeClass(v.status);
}

const valuesVisible = ref(true);
const sidebarOpen = ref(false);
const selectedVenda = ref(null);
const openMenuId = ref(null);
const menuAnchorEl = ref(null);
const menuEl = ref(null);
const menuPos = ref({ top: 0, left: 0 });
const resendingId = ref(null);
const refundingId = ref(null);
const refundModalOpen = ref(false);
const refundTarget = ref(null);
const refundReason = ref('');
const toast = ref({ message: null, type: null });
let toastTimer = null;

const filterOptions = [
    { value: 'aprovadas', label: t('sales.filter.approved', 'Aprovadas') },
    { value: 'med', label: 'MED' },
    { value: 'todas', label: t('sales.filter.all_female', 'Todas') },
];

const periodOptions = [
    { value: 'all', label: t('sales.period.all', 'Todo período') },
    { value: 'today', label: t('period.today', 'Hoje') },
    { value: 'yesterday', label: t('period.yesterday', 'Ontem') },
    { value: '7d', label: t('sales.period.last_7_days', 'Últimos 7 dias') },
    { value: '30d', label: t('sales.period.last_30_days', 'Últimos 30 dias') },
    { value: 'this_month', label: t('sales.period.this_month', 'Este mês') },
    { value: 'last_month', label: t('sales.period.last_month', 'Mês passado') },
    { value: 'custom', label: t('sales.period.custom', 'Personalizado') },
];

const paymentMethodOptions = [
    { value: 'all', label: t('sales.payment_method.all', 'Todos métodos') },
    { value: 'pix', label: 'PIX' },
    { value: 'card', label: t('sales.payment_method.card', 'Cartão') },
    { value: 'boleto', label: 'Boleto' },
];

const paymentStatusOptions = [
    { value: 'all', label: t('sales.status.all', 'Todos status') },
    { value: 'completed', label: t('sales.status.paid', 'Pago') },
    { value: 'pending', label: t('sales.status.pending', 'Pendente') },
    { value: 'disputed', label: 'MED' },
    { value: 'cancelled', label: t('sales.status.cancelled', 'Cancelado') },
    { value: 'refunded', label: t('sales.status.refunded', 'Reembolsado') },
];

function initialProductIds(f) {
    if (Array.isArray(f?.product_ids) && f.product_ids.length) {
        return [...f.product_ids];
    }
    return [];
}

/** Exibe data e horário da venda: DD/MM/AAAA - HH:MM */
function formatSaleDateTime(value) {
    if (!value) return '–';
    const d = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(d.getTime())) return '–';
    const pad = (n) => String(n).padStart(2, '0');
    const date = `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
    const time = `${pad(d.getHours())}:${pad(d.getMinutes())}`;
    return `${date} - ${time}`;
}

const filterForm = ref({
    q: props.filters?.q ?? '',
    period: props.filters?.period ?? 'all',
    date_from: props.filters?.date_from ?? '',
    date_to: props.filters?.date_to ?? '',
    product_ids: initialProductIds(props.filters),
    offer_id: props.filters?.offer_id ?? '',
    payment_method: props.filters?.payment_method ?? 'all',
    payment_status: props.filters?.payment_status ?? 'all',
    utm_source: props.filters?.utm_source ?? '',
    utm_medium: props.filters?.utm_medium ?? '',
    utm_campaign: props.filters?.utm_campaign ?? '',
    sale_channel: props.filters?.sale_channel ?? '',
    producer_id: props.filters?.producer_id ?? '',
    commission_status: props.filters?.commission_status ?? 'all',
});

const vendasList = computed(() => props.vendas?.data ?? props.vendas ?? []);

const advancedFiltersOpen = ref(false);
const productFilterOpen = ref(false);
const searchFieldFocused = ref(false);
let searchTimer = null;

watch(
    () => props.filters,
    (f) => {
        if (!f) return;
        // Não sobrescrever a busca enquanto o usuário digita (evita perder espaços entre palavras).
        if (!searchFieldFocused.value) {
            filterForm.value.q = f.q ?? '';
        }
        filterForm.value.period = f.period ?? 'all';
        filterForm.value.date_from = f.date_from ?? '';
        filterForm.value.date_to = f.date_to ?? '';
        filterForm.value.product_ids = Array.isArray(f.product_ids) ? [...f.product_ids] : [];
        filterForm.value.offer_id = f.offer_id ?? '';
        filterForm.value.payment_method = f.payment_method ?? 'all';
        filterForm.value.payment_status = f.payment_status ?? 'all';
        filterForm.value.utm_source = f.utm_source ?? '';
        filterForm.value.utm_medium = f.utm_medium ?? '';
        filterForm.value.utm_campaign = f.utm_campaign ?? '';
        filterForm.value.sale_channel = f.sale_channel ?? '';
        filterForm.value.producer_id = f.producer_id ?? '';
        filterForm.value.commission_status = f.commission_status ?? 'all';
    },
    { deep: true },
);

const offersForSelectedProduct = computed(() => {
    const ids = filterForm.value.product_ids ?? [];
    if (!ids.length) return props.offers ?? [];
    const set = new Set(ids.map((x) => String(x)));
    return (props.offers ?? []).filter((o) => set.has(String(o.product_id)));
});

const selectedProductLabels = computed(() => {
    const ids = filterForm.value.product_ids ?? [];
    return (props.products ?? []).filter((p) => ids.some((x) => String(x) === String(p.id))).map((p) => ({ id: p.id, name: p.name }));
});

function buildQuery(overrides = {}) {
    const f = { ...filterForm.value, ...overrides };
    if (typeof f.q === 'string') {
        f.q = f.q.trim();
    }
    const q = { status_filter: props.status_filter, ...f };

    const cleaned = {};
    Object.entries(q).forEach(([k, v]) => {
        if (v === null || v === undefined) return;
        if (Array.isArray(v)) {
            if (v.length === 0) return;
            cleaned[k] = v;
            return;
        }
        if (typeof v === 'string' && v.trim() === '') return;
        if ((k === 'period' || k === 'payment_method' || k === 'payment_status' || k === 'commission_status') && v === 'all') return;
        if (k === 'sale_channel' && (v === '' || v === 'all')) return;
        cleaned[k] = v;
    });
    if (cleaned.period !== 'custom') {
        delete cleaned.date_from;
        delete cleaned.date_to;
    }
    return cleaned;
}

function applyFilters(overrides = {}) {
    router.get('/vendas', buildQuery(overrides), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const menuVenda = computed(() => {
    if (openMenuId.value == null) return null;
    const list = vendasList.value ?? [];
    return list.find((x) => vendaRowKey(x) === openMenuId.value) ?? null;
});

function setFilter(value) {
    applyFilters({ status_filter: value });
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

function displayCurrency(value) {
    return valuesVisible.value ? formatBRL(value) : '••••••';
}

function displayNumber(value) {
    return valuesVisible.value ? String(value) : '—';
}

function statusBadgeClass(status) {
    const map = {
        completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
        pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
        disputed: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
        cancelled: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300',
        refunded: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
        refund_pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
    };
    return map[status] ?? 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300';
}

function statusBadgeLabel(status) {
    const map = {
        completed: t('sales.status.paid', 'Pago'),
        pending: t('sales.status.pending', 'Pendente'),
        disputed: 'MED',
        cancelled: t('sales.status.cancelled', 'Cancelado'),
        refunded: t('sales.status.refunded', 'Reembolsado'),
        refund_pending: t('sales.status.refund_pending', 'Aguardando reembolso'),
    };
    return map[status] ?? status ?? '–';
}

function openDetail(v) {
    selectedVenda.value = v;
    sidebarOpen.value = true;
    closeMenu();
}

function closeSidebar() {
    sidebarOpen.value = false;
    selectedVenda.value = null;
}

async function updateMenuPosition() {
    const anchor = menuAnchorEl.value;
    if (!anchor || openMenuId.value == null) return;

    const rect = anchor.getBoundingClientRect();
    const minMargin = 8;
    const desiredWidth = 192;
    const viewportW = window.innerWidth || 0;
    const viewportH = window.innerHeight || 0;

    let left = rect.right - desiredWidth;
    left = Math.max(minMargin, Math.min(left, Math.max(minMargin, viewportW - desiredWidth - minMargin)));

    let top = rect.bottom + 4;
    top = Math.max(minMargin, Math.min(top, Math.max(minMargin, viewportH - minMargin)));

    menuPos.value = { top, left };

    await nextTick();
    const menu = menuEl.value;
    if (!menu) return;

    const menuRect = menu.getBoundingClientRect();
    const spaceBelow = viewportH - rect.bottom;
    const spaceAbove = rect.top;
    const shouldOpenUp = menuRect.height + 8 > spaceBelow && spaceAbove >= menuRect.height + 8;

    if (shouldOpenUp) {
        const newTop = Math.max(minMargin, rect.top - menuRect.height - 4);
        menuPos.value = { top: newTop, left: menuPos.value.left };
    }
}

async function toggleMenu(id, event) {
    if (openMenuId.value === id) {
        closeMenu();
        return;
    }
    openMenuId.value = id;
    menuAnchorEl.value = event?.currentTarget ?? null;
    await nextTick();
    await updateMenuPosition();
}

function closeMenu() {
    openMenuId.value = null;
    menuAnchorEl.value = null;
}

function handleClickOutside(event) {
    if (productFilterOpen.value) {
        const pf = document.querySelector('[data-vendas-product-filter]');
        if (pf && !pf.contains(event.target)) {
            productFilterOpen.value = false;
        }
    }
    if (openMenuId.value == null) return;
    const el = document.querySelector(`[data-venda-menu="${openMenuId.value}"]`);
    const menu = menuEl.value;
    if (el && el.contains(event.target)) return;
    if (menu && menu.contains(event.target)) return;
    closeMenu();
}

function toggleProductFilter(id) {
    const cur = [...(filterForm.value.product_ids ?? [])];
    const idx = cur.findIndex((x) => String(x) === String(id));
    if (idx >= 0) {
        cur.splice(idx, 1);
    } else {
        cur.push(id);
    }
    filterForm.value.product_ids = cur;
    const offers = !cur.length
        ? (props.offers ?? [])
        : (props.offers ?? []).filter((o) => cur.some((pid) => String(pid) === String(o.product_id)));
    if (filterForm.value.offer_id && !offers.some((o) => String(o.id) === String(filterForm.value.offer_id))) {
        filterForm.value.offer_id = '';
    }
    onFilterChange();
}

function removeProductFilter(id) {
    filterForm.value.product_ids = (filterForm.value.product_ids ?? []).filter((x) => String(x) !== String(id));
    const offers = !filterForm.value.product_ids.length
        ? (props.offers ?? [])
        : (props.offers ?? []).filter((o) =>
              filterForm.value.product_ids.some((pid) => String(pid) === String(o.product_id)),
          );
    if (filterForm.value.offer_id && !offers.some((o) => String(o.id) === String(filterForm.value.offer_id))) {
        filterForm.value.offer_id = '';
    }
    onFilterChange();
}

async function resendEmail(v) {
    closeMenu();
    if (resendingId.value) return;
    resendingId.value = v.id;
    try {
        const { data } = await axios.post(`/vendas/${v.id}/resend-access-email`);
        if (data.success) {
            showToast(t('sales.toast.email_resent_success', 'E-mail de compra reenviado com sucesso.'), 'success');
        } else {
            showToast(data.message ?? t('sales.toast.email_resent_fail', 'Não foi possível reenviar o e-mail.'), 'error');
        }
    } catch (err) {
        showToast(
            err.response?.data?.message ?? t('sales.toast.email_resent_error', 'Erro ao reenviar e-mail. Tente novamente.'),
            'error'
        );
    } finally {
        resendingId.value = null;
    }
}

function openRefundModal(v) {
    closeMenu();
    refundTarget.value = v;
    refundReason.value = '';
    refundModalOpen.value = true;
}

function closeRefundModal() {
    refundModalOpen.value = false;
    refundTarget.value = null;
    refundReason.value = '';
}

async function submitRefund() {
    const v = refundTarget.value;
    if (!v || refundingId.value) return;

    const reason = refundReason.value?.trim() ?? '';
    if (reason !== '' && reason.length < 3) {
        showToast(t('sales.refund.reason_min', 'O motivo deve ter pelo menos 3 caracteres.'), 'error');
        return;
    }

    refundingId.value = v.id;
    try {
        const { data } = await axios.post(`/vendas/${v.id}/reembolsar`, {
            reason: reason !== '' ? reason : null,
        });
        if (data.success) {
            closeRefundModal();
            showToast(data.message ?? t('sales.refund.success', 'Pedido reembolsado.'), 'success');
            router.reload({ preserveScroll: true });
        } else {
            showToast(data.message ?? t('sales.refund.fail', 'Não foi possível reembolsar.'), 'error');
        }
    } catch (err) {
        showToast(
            err.response?.data?.message ?? t('sales.refund.error', 'Erro ao reembolsar. Tente novamente.'),
            'error'
        );
    } finally {
        refundingId.value = null;
    }
}

function canShowRefundAction(venda) {
    return venda && ['completed', 'disputed'].includes(venda.status);
}

function whatsappCustomerUrl(venda) {
    return buildWhatsAppUrl(orderCustomerPhone(venda));
}

function showToast(message, type) {
    toast.value = { message, type };
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toast.value = { message: null, type: null };
        toastTimer = null;
    }, 4000);
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
    window.addEventListener('resize', updateMenuPosition);
    window.addEventListener('scroll', updateMenuPosition, true);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    window.removeEventListener('resize', updateMenuPosition);
    window.removeEventListener('scroll', updateMenuPosition, true);
    if (toastTimer) clearTimeout(toastTimer);
    if (searchTimer) clearTimeout(searchTimer);
});

function onSearchInput() {
    const q = (filterForm.value.q ?? '').trim();
    if (q !== '' && q.length < 3) {
        if (searchTimer) clearTimeout(searchTimer);
        searchTimer = null;
        return;
    }
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        applyFilters();
        searchTimer = null;
    }, 600);
}

function onSearchBlur() {
    searchFieldFocused.value = false;
    const serverQ = props.filters?.q ?? '';
    const localTrimmed = (filterForm.value.q ?? '').trim();
    if (serverQ !== localTrimmed) {
        filterForm.value.q = serverQ;
    }
}

function onFilterChange() {
    applyFilters();
}

function clearFilters() {
    filterForm.value = {
        q: '',
        period: 'all',
        date_from: '',
        date_to: '',
        product_ids: [],
        offer_id: '',
        payment_method: 'all',
        payment_status: 'all',
        utm_source: '',
        utm_medium: '',
        utm_campaign: '',
        sale_channel: '',
        producer_id: '',
        commission_status: 'all',
    };
    applyFilters();
}

function buildExportSearchParams(format) {
    const q = buildQuery({ format });
    const params = new URLSearchParams();
    Object.entries(q).forEach(([k, v]) => {
        if (v === null || v === undefined) return;
        if (Array.isArray(v)) {
            if (!v.length) return;
            v.forEach((id) => params.append('product_ids[]', String(id)));
            return;
        }
        if (typeof v === 'string' && v.trim() === '') return;
        if ((k === 'period' || k === 'payment_method' || k === 'payment_status') && v === 'all') return;
        if (k === 'sale_channel' && (v === '' || v === 'all')) return;
        params.append(k, String(v));
    });
    return params;
}

const exportCsvUrl = computed(() => `/vendas/export?${buildExportSearchParams('csv').toString()}`);

const exportXlsUrl = computed(() => `/vendas/export?${buildExportSearchParams('xls').toString()}`);
</script>

<template>
    <div :class="pageClass">
        <AuroraPageHeader
            :title="t('sidebar.sales', 'Vendas')"
            :subtitle="t('sales.subtitle', 'Acompanhe pedidos, status de pagamento e desempenho comercial.')"
        />

        <VendasTabs />

        <AuroraPageSection>
            <div class="flex items-center justify-between gap-3">
                <p class="aurora-section-title">
                    {{ t('sales.metrics.summary', 'Resumo do período') }}
                </p>
                <button
                    type="button"
                    :aria-label="valuesVisible ? t('dashboard.hide_values', 'Ocultar valores') : t('dashboard.show_values', 'Mostrar valores')"
                    class="flex h-9 w-9 items-center justify-center rounded-lg transition-colors"
                    :class="iconBtn"
                    @click="valuesVisible = !valuesVisible"
                >
                    <Eye v-if="valuesVisible" class="h-5 w-5" aria-hidden="true" />
                    <EyeOff v-else class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <AuroraStatCard
                    :icon="ShoppingCart"
                    :label="t('sales.metrics.found_sales', 'Vendas encontradas')"
                    :value="displayNumber(stats.vendas_encontradas ?? 0)"
                />
                <AuroraStatCard
                    :icon="CircleDollarSign"
                    :label="t('sales.metrics.net_amount', 'Valor líquido')"
                    :value="displayCurrency(stats.valor_liquido ?? 0)"
                />
                <AuroraStatCard
                    :icon="Banknote"
                    :label="t('sales.metrics.pix_sales', 'Vendas no PIX')"
                    :value="displayNumber(stats.vendas_pix ?? 0)"
                />
                <AuroraStatCard
                    :icon="CreditCard"
                    :label="t('sales.metrics.card_sales', 'Vendas no cartão')"
                    :value="displayNumber(stats.vendas_cartao ?? 0)"
                />
            </div>
        </AuroraPageSection>

        <AuroraPageSection>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <nav
                    :class="[
                        themePrefix
                            ? `${themePrefix}-subnav`
                            : 'inline-flex rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80',
                    ]"
                    :aria-label="t('sales.filter.label', 'Filtro de status')"
                >
                    <button
                        v-for="opt in filterOptions"
                        :key="opt.value"
                        type="button"
                        :aria-current="status_filter === opt.value ? 'true' : undefined"
                        :class="[
                            themePrefix
                                ? [`${themePrefix}-subnav-item`, status_filter === opt.value && `${themePrefix}-subnav-item-active`]
                                : [
                                    'rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                                    status_filter === opt.value
                                        ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                                ],
                        ]"
                        @click="setFilter(opt.value)"
                    >
                        {{ opt.label }}
                    </button>
                </nav>
                <div class="flex flex-wrap items-center gap-2">
                    <a
                        :href="exportCsvUrl"
                        :class="btnSecondary"
                    >
                        <Download class="h-4 w-4" />
                        {{ t('sales.export.csv', 'Exportar CSV') }}
                    </a>
                    <a
                        :href="exportXlsUrl"
                        :class="btnSecondary"
                    >
                        <Download class="h-4 w-4" />
                        {{ t('sales.export.xls', 'Exportar XLS') }}
                    </a>
                </div>
            </div>

        <div :class="stackClass">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="relative w-full max-w-xl">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                    <input
                        v-model="filterForm.q"
                        type="text"
                        class="w-full rounded-xl border border-zinc-200 bg-white py-2 pl-10 pr-10 text-sm text-zinc-900 shadow-sm transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        :placeholder="t('sales.search_placeholder', 'Buscar por cliente, e-mail, pedido, produto...')"
                        @focus="searchFieldFocused = true"
                        @blur="onSearchBlur"
                        @input="onSearchInput"
                    />
                    <button
                        v-if="filterForm.q"
                        type="button"
                        class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-2 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                        :aria-label="t('sales.clear_search', 'Limpar busca')"
                        @click="filterForm.q = ''; onFilterChange()"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <button
                    type="button"
                    :class="btnSecondary"
                    @click="clearFilters"
                >
                    {{ t('sales.clear_filters', 'Limpar filtros') }}
                </button>
            </div>

            <div class="grid gap-3 lg:grid-cols-6">
                <div class="lg:col-span-2">
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ t('dashboard.period', 'Período') }}</label>
                    <select
                        v-model="filterForm.period"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="onFilterChange"
                    >
                        <option v-for="p in periodOptions" :key="p.value" :value="p.value">{{ p.label }}</option>
                    </select>
                </div>

                <div v-if="filterForm.period === 'custom'">
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ t('common.from', 'De') }}</label>
                    <input
                        v-model="filterForm.date_from"
                        type="date"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="onFilterChange"
                    />
                </div>
                <div v-if="filterForm.period === 'custom'">
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ t('common.to', 'Até') }}</label>
                    <input
                        v-model="filterForm.date_to"
                        type="date"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="onFilterChange"
                    />
                </div>

                <div class="min-w-0 space-y-2 lg:col-span-2">
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ t('sidebar.products', 'Produtos') }}</label>
                    <div class="flex flex-wrap items-start gap-2">
                        <div class="relative shrink-0" data-vendas-product-filter>
                            <button
                                type="button"
                                class="inline-flex w-full min-w-[11rem] items-center justify-between gap-2 rounded-xl border border-zinc-200 bg-white px-3 py-2 text-left text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                :class="filterForm.product_ids?.length ? 'border-[var(--color-primary)] text-[var(--color-primary)] dark:border-[var(--color-primary)] dark:text-[var(--color-primary)]' : ''"
                                aria-expanded="productFilterOpen"
                                @click.stop="productFilterOpen = !productFilterOpen"
                            >
                                <span class="inline-flex items-center gap-2 truncate">
                                    <Package class="h-4 w-4 shrink-0" />
                                    <span class="truncate">{{ t('sales.products.filter', 'Filtrar') }}</span>
                                    <span
                                        v-if="filterForm.product_ids?.length"
                                        class="shrink-0 rounded-full bg-[var(--color-primary)]/20 px-1.5 py-0.5 text-xs"
                                    >
                                        {{ filterForm.product_ids.length }}
                                    </span>
                                </span>
                                <ChevronDown class="h-4 w-4 shrink-0 transition" :class="productFilterOpen && 'rotate-180'" />
                            </button>
                            <div
                                v-show="productFilterOpen"
                                class="absolute left-0 top-full z-50 mt-1 max-h-64 w-72 overflow-y-auto rounded-xl border border-zinc-200 bg-white py-1 text-left shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                                @click.stop
                            >
                                <div v-for="p in products" :key="p.id" class="px-2 py-1">
                                    <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/80">
                                        <span class="shrink-0">
                                            <Checkbox
                                                :model-value="filterForm.product_ids?.some((x) => String(x) === String(p.id))"
                                                @update:model-value="toggleProductFilter(p.id)"
                                            />
                                        </span>
                                        <span class="flex-1 text-left text-sm text-zinc-900 dark:text-white">{{ p.name }}</span>
                                    </label>
                                </div>
                                <p v-if="!products.length" class="px-3 py-2 text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ t('products.empty', 'Nenhum produto') }}
                                </p>
                            </div>
                        </div>
                        <div v-if="selectedProductLabels.length" class="flex min-w-0 flex-1 flex-wrap gap-1.5">
                            <span
                                v-for="p in selectedProductLabels"
                                :key="p.id"
                                class="inline-flex max-w-full items-center gap-1 rounded-lg bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300"
                            >
                                <span class="truncate" :title="p.name">{{ p.name }}</span>
                                <button
                                    type="button"
                                    class="shrink-0 rounded p-0.5 hover:bg-zinc-200 dark:hover:bg-zinc-600"
                                    :aria-label="t('sales.products.remove_filter', 'Remover produto do filtro')"
                                    @click="removeProductFilter(p.id)"
                                >
                                    <X class="h-3 w-3" />
                                </button>
                            </span>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ t('sales.offer', 'Oferta') }}</label>
                    <select
                        v-model="filterForm.offer_id"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="onFilterChange"
                    >
                        <option value="">{{ t('sales.offers.all', 'Todas ofertas') }}</option>
                        <option v-for="o in offersForSelectedProduct" :key="o.id" :value="o.id">
                            {{ o.product_name ? `${o.product_name} - ${o.name}` : o.name }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ t('sales.method', 'Método') }}</label>
                    <select
                        v-model="filterForm.payment_method"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="onFilterChange"
                    >
                        <option v-for="m in paymentMethodOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ t('sales.status', 'Status') }}</label>
                    <select
                        v-model="filterForm.payment_status"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="onFilterChange"
                    >
                        <option v-for="s in paymentStatusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </div>
            </div>

            <div>
                <button
                    type="button"
                    class="text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
                    @click="advancedFiltersOpen = !advancedFiltersOpen"
                >
                    {{ advancedFiltersOpen ? t('sales.hide_advanced_filters', 'Ocultar filtros avançados') : t('sales.show_advanced_filters', 'Mostrar filtros avançados') }}
                </button>
                <div v-if="advancedFiltersOpen" class="mt-3 grid gap-3 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Canal</label>
                        <select
                            v-model="filterForm.sale_channel"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            @change="onFilterChange"
                        >
                            <option value="">Todos</option>
                            <option value="api_pix">API PIX</option>
                            <option value="pixgo">PixGO</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_source</label>
                        <input
                            v-model="filterForm.utm_source"
                            type="text"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            @change="onFilterChange"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_medium</label>
                        <input
                            v-model="filterForm.utm_medium"
                            type="text"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            @change="onFilterChange"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_campaign</label>
                        <input
                            v-model="filterForm.utm_campaign"
                            type="text"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            @change="onFilterChange"
                        />
                    </div>
                    <div v-if="has_affiliate_enrollments">
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Produtor</label>
                        <select
                            v-model="filterForm.producer_id"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            @change="onFilterChange"
                        >
                            <option value="">Todos produtores</option>
                            <option v-for="p in producers" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                    <div v-if="has_affiliate_enrollments">
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Status da comissão</label>
                        <select
                            v-model="filterForm.commission_status"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            @change="onFilterChange"
                        >
                            <option v-for="opt in commission_status_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        </AuroraPageSection>

        <AuroraPageSection flush>
        <!-- Tabela de vendas -->
        <div :class="['sm:hidden space-y-3', isThemedShell && 'p-4']">
            <div
                v-for="v in vendasList"
                :key="vendaRowKey(v)"
                class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/60 dark:hover:bg-zinc-700/80"
                role="button"
                tabindex="0"
                @click="openRowDetail(v)"
                @keydown.enter.prevent="openRowDetail(v)"
                @keydown.space.prevent="openRowDetail(v)"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {{ formatSaleDateTime(v.created_at) }}
                        </p>
                        <p class="mt-1 break-words text-sm font-semibold leading-snug text-zinc-900 dark:text-white">
                            {{ v.product_display_name ?? v.product?.name ?? '–' }}
                            <span
                                v-if="v.is_api_pix"
                                class="ml-1 inline-flex rounded-full bg-sky-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-sky-800 dark:bg-sky-900/40 dark:text-sky-200"
                            >API PIX</span>
                            <span
                                v-if="v.is_pixgo"
                                class="ml-1 inline-flex rounded-full bg-lime-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-lime-900 dark:bg-lime-900/40 dark:text-lime-200"
                            >{{ v.sale_channel_label || 'PixGO' }}</span>
                            <span
                                v-if="v.is_affiliate_commission"
                                class="ml-1 inline-flex rounded-full bg-violet-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-violet-800 dark:bg-violet-900/40 dark:text-violet-200"
                            >Afiliados</span>
                            <span
                                v-if="v.is_coproduction_commission"
                                class="ml-1 inline-flex rounded-full bg-sky-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-sky-800 dark:bg-sky-900/40 dark:text-sky-200"
                            >Co-produção</span>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1" @click.stop>
                        <a
                            v-if="!isCommissionRow(v) && whatsappCustomerUrl(v)"
                            :href="whatsappCustomerUrl(v)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex h-9 w-9 items-center justify-center rounded-lg text-[#25D366] transition hover:bg-emerald-50 dark:hover:bg-emerald-900/20"
                            :aria-label="t('sales.whatsapp_customer', 'WhatsApp do cliente')"
                            :title="t('sales.whatsapp_customer', 'WhatsApp do cliente')"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path
                                    d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.881 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"
                                />
                            </svg>
                        </a>
                        <div v-if="!isCommissionRow(v)" class="relative" :data-venda-menu="vendaRowKey(v)">
                            <button
                                type="button"
                                class="flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                                :aria-label="t('common.open_menu', 'Abrir menu')"
                                :aria-expanded="openMenuId === vendaRowKey(v)"
                                @click="toggleMenu(vendaRowKey(v), $event)"
                            >
                                <MoreVertical class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mt-4 rounded-lg bg-zinc-50/60 p-3 dark:bg-zinc-900/30">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                        <div class="min-w-0">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ t('sales.customer', 'Cliente') }}
                            </p>
                            <p class="mt-1 break-words text-sm font-medium leading-snug text-zinc-900 dark:text-white">
                                {{ customerDisplayName(v) }}
                            </p>
                            <p class="mt-0.5 break-words text-xs leading-snug text-zinc-500 dark:text-zinc-400">
                                {{ customerDisplayEmail(v) }}
                            </p>
                        </div>
                        <div class="min-w-0 text-right">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ t('sales.status', 'Status') }}
                            </p>
                            <div class="mt-1 flex flex-col items-end gap-1">
                                <span
                                    :class="[
                                        'inline-flex w-fit rounded-full px-2 py-0.5 text-xs font-medium',
                                        rowStatusBadgeClass(v),
                                    ]"
                                >
                                    {{ rowStatusBadgeLabel(v) }}
                                </span>
                                <span class="break-words text-xs leading-snug text-zinc-500 dark:text-zinc-400">
                                    {{ v.gateway_label ?? '–' }}
                                </span>
                            </div>
                        </div>
                        <div class="col-span-2 flex items-end justify-between gap-3">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ t('sales.metrics.net_amount', 'Valor líquido') }}
                            </p>
                            <p class="text-base font-semibold tabular-nums text-zinc-900 dark:text-white">
                                {{ formatBRL(v.amount_net ?? v.amount_total ?? v.amount) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="!vendasList.length"
                class="rounded-xl border border-zinc-200 bg-white px-4 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60 dark:text-zinc-400"
            >
                {{ t('sales.empty', 'Nenhuma venda encontrada.') }}
            </div>
        </div>

        <div
            :class="[
                'hidden sm:block',
                tablePanel,
            ]"
        >
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            {{ t('common.date', 'Data') }}
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            {{ t('sidebar.products', 'Produto') }}
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            {{ t('sales.customer', 'Cliente') }}
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            {{ t('sales.status', 'Status') }}
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            {{ t('sales.metrics.net_amount', 'Valor líquido') }}
                        </th>
                        <th class="relative w-24 px-2 py-3">
                            <span class="sr-only">Ações</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    <tr
                        v-for="v in vendasList"
                        :key="vendaRowKey(v)"
                        class="cursor-pointer bg-white transition hover:bg-zinc-50 dark:bg-zinc-800/60 dark:hover:bg-zinc-700/80"
                        @click="openRowDetail(v)"
                    >
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">
                            {{ formatSaleDateTime(v.created_at) }}
                        </td>
                        <td class="px-4 py-3 text-sm text-zinc-900 dark:text-white">
                            {{ v.product_display_name ?? v.product?.name ?? '–' }}
                            <span
                                v-if="v.is_api_pix"
                                class="ml-1 inline-flex rounded-full bg-sky-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-sky-800 dark:bg-sky-900/40 dark:text-sky-200"
                            >API PIX</span>
                            <span
                                v-if="v.is_pixgo"
                                class="ml-1 inline-flex rounded-full bg-lime-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-lime-900 dark:bg-lime-900/40 dark:text-lime-200"
                            >{{ v.sale_channel_label || 'PixGO' }}</span>
                            <span
                                v-if="v.is_affiliate_commission"
                                class="ml-1 inline-flex rounded-full bg-violet-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-violet-800 dark:bg-violet-900/40 dark:text-violet-200"
                            >Afiliados</span>
                            <span
                                v-if="v.is_coproduction_commission"
                                class="ml-1 inline-flex rounded-full bg-sky-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-sky-800 dark:bg-sky-900/40 dark:text-sky-200"
                            >Co-produção</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ customerDisplayName(v) }}
                                </span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ customerDisplayEmail(v) }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col gap-0.5">
                                <span
                                    :class="[
                                        'inline-flex w-fit rounded-full px-2 py-0.5 text-xs font-medium',
                                        rowStatusBadgeClass(v),
                                    ]"
                                >
                                    {{ rowStatusBadgeLabel(v) }}
                                </span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ v.gateway_label ?? '–' }}
                                </span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">
                            {{ formatBRL(v.amount_net ?? v.amount_total ?? v.amount) }}
                        </td>
                        <td class="relative whitespace-nowrap px-2 py-3" @click.stop>
                            <div class="flex items-center justify-end gap-1">
                                <a
                                    v-if="!isCommissionRow(v) && whatsappCustomerUrl(v)"
                                    :href="whatsappCustomerUrl(v)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-[#25D366] transition hover:bg-emerald-50 dark:hover:bg-emerald-900/20"
                                    :aria-label="t('sales.whatsapp_customer', 'WhatsApp do cliente')"
                                    :title="orderCustomerPhone(v) || t('sales.whatsapp_customer', 'WhatsApp do cliente')"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path
                                            d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.881 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"
                                        />
                                    </svg>
                                </a>
                                <div v-if="!isCommissionRow(v)" class="relative" :data-venda-menu="vendaRowKey(v)">
                                    <button
                                        type="button"
                                        class="flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                                        :aria-label="t('common.open_menu', 'Abrir menu')"
                                        :aria-expanded="openMenuId === vendaRowKey(v)"
                                        @click="toggleMenu(vendaRowKey(v), $event)"
                                    >
                                        <MoreVertical class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!vendasList.length" class="dark:bg-zinc-800/60">
                        <td colspan="6" class="px-4 py-12 text-center text-zinc-500 dark:text-zinc-400">
                            {{ t('sales.empty', 'Nenhuma venda encontrada.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        </AuroraPageSection>

        <!-- Paginação -->
        <nav
            v-if="vendas?.links?.length > 3"
            class="flex items-center justify-center gap-2"
            :aria-label="t('common.pagination', 'Paginação')"
        >
            <a
                v-for="link in vendas.links"
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

        <!-- Sidebar de detalhes -->
        <VendaDetailSidebar
            :open="sidebarOpen"
            :venda="selectedVenda"
            @close="closeSidebar"
        />

        <AfiliadoVendaDetailSidebar
            :open="affiliateSidebarOpen"
            :venda="selectedAffiliateVenda"
            @close="affiliateSidebarOpen = false"
        />

        <!-- Toast local -->
        <Teleport to="body">
            <div
                v-if="openMenuId != null && menuVenda"
                ref="menuEl"
                class="fixed z-[100000] w-48 rounded-xl border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                :style="{ top: `${menuPos.top}px`, left: `${menuPos.left}px` }"
                role="menu"
                :aria-label="t('sales.actions', 'Ações da venda')"
            >
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="openRowDetail(menuVenda)"
                >
                    <FileText class="h-4 w-4 shrink-0" />
                    {{ t('sales.details', 'Detalhes') }}
                </button>
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800 disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="resendingId === menuVenda?.id || menuVenda.status === 'pending'"
                    :title="t('sales.resend_unavailable_pending', 'Indisponível para pagamentos pendentes')"
                    @click="resendEmail(menuVenda)"
                >
                    <Mail class="h-4 w-4 shrink-0" />
                    {{ resendingId === menuVenda?.id ? t('common.sending', 'Enviando...') : t('sales.resend_purchase_email', 'Reenviar e-mail de compra') }}
                </button>
                <button
                    v-if="canShowRefundAction(menuVenda)"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/30 disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="refundingId === menuVenda?.id"
                    @click="openRefundModal(menuVenda)"
                >
                    <RotateCcw class="h-4 w-4 shrink-0" />
                    {{ t('sales.refund_manual', 'Reembolsar') }}
                </button>
            </div>
            <div
                v-if="refundModalOpen"
                class="fixed inset-0 z-[100002] flex items-center justify-center p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="refund-modal-title"
            >
                <div class="absolute inset-0 bg-zinc-900/50 dark:bg-zinc-950/60" @click="closeRefundModal" />
                <div class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                    <h3 id="refund-modal-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                        {{ t('sales.refund_manual', 'Reembolsar') }}
                    </h3>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        O pedido será marcado como <strong>Reembolsado</strong>. É necessário ter saldo na carteira compatível com o valor líquido do reembolso; se o saldo atual for menor, o reembolso não será efetivado.
                    </p>
                    <label class="mt-4 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        {{ t('sales.refund.reason_optional', 'Motivo (opcional)') }}
                        <textarea
                            v-model="refundReason"
                            rows="3"
                            maxlength="500"
                            class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            :placeholder="t('sales.refund.reason_placeholder', 'Ex.: cliente solicitou por WhatsApp')"
                        />
                    </label>
                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                            :disabled="refundingId != null"
                            @click="closeRefundModal"
                        >
                            {{ t('common.cancel', 'Cancelar') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                            :disabled="refundingId != null"
                            @click="submitRefund"
                        >
                            {{ refundingId ? t('common.processing', 'Processando...') : t('sales.refund.confirm', 'Confirmar reembolso') }}
                        </button>
                    </div>
                </div>
            </div>
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="translate-y-2 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="translate-y-0 opacity-100"
                leave-to-class="translate-y-2 opacity-0"
            >
                <div
                    v-if="toast.message"
                    role="alert"
                    :class="[
                        'fixed bottom-4 right-4 z-[100001] max-w-sm rounded-xl border px-4 py-3 shadow-lg',
                        toast.type === 'error'
                            ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/50 dark:bg-red-900/20 dark:text-red-200'
                            : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/20 dark:text-emerald-200',
                    ]"
                >
                    <p class="text-sm font-medium">{{ toast.message }}</p>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
