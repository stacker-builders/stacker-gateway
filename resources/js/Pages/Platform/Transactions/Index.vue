<script setup>
import { ref, watch, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import VendaDetailSidebar from '@/components/vendas/VendaDetailSidebar.vue';
import Button from '@/components/ui/Button.vue';
import { htmlToText } from '@/lib/sanitizeHtml';
import {
    MoreVertical,
    FileText,
    User,
    CheckCircle,
    Ban,
    RotateCcw,
    Banknote,
    AlertTriangle,
    Trash2,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const page = usePage();

const props = defineProps({
    orders: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({ status: 'all', q: '', per_page: 25, gateway: '', method: '' }),
    },
    gateway_options: {
        type: Array,
        default: () => [],
    },
    payment_method_options: {
        type: Array,
        default: () => [],
    },
    refund_requests_pending_count: {
        type: Number,
        default: 0,
    },
});

const filterStatus = ref(props.filters?.status ?? 'all');
const filterQ = ref(props.filters?.q ?? '');
const filterPerPage = ref(String(props.filters?.per_page ?? 25));
const filterGateway = ref(props.filters?.gateway ?? '');
const filterMethod = ref(props.filters?.method ?? '');

const sidebarOpen = ref(false);
const selectedVenda = ref(null);

const openMenuId = ref(null);
const menuAnchorEl = ref(null);
const menuEl = ref(null);
const menuPos = ref({ top: 0, left: 0 });

const refundModalOpen = ref(false);
const refundMode = ref('gateway');
const refundTargetId = ref(null);
const refundReason = ref('');
const refundTotpCode = ref('');
const refundSubmitting = ref(false);

const totpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));
const refundFormError = computed(() => {
    const errors = page.props.errors || {};
    return errors.totp_code || errors.reason || '';
});

const selectedIds = ref([]);
const bulkDeleteOpen = ref(false);
const bulkDeleteReason = ref('');
const bulkDeleteLoading = ref(false);

watch(
    () => props.filters,
    (f) => {
        filterStatus.value = f?.status ?? 'all';
        filterQ.value = f?.q ?? '';
        filterPerPage.value = String(f?.per_page ?? 25);
        filterGateway.value = f?.gateway ?? '';
        filterMethod.value = f?.method ?? '';
    },
    { deep: true }
);

watch(
    () => props.orders?.data,
    () => {
        const visible = new Set((props.orders?.data ?? []).map((o) => o.id));
        selectedIds.value = selectedIds.value.filter((id) => visible.has(id));
    }
);

const filterChips = [
    { status: 'all', label: 'Todos' },
    { status: 'pending', label: 'Pendente' },
    { status: 'completed', label: 'Aprovado' },
    { status: 'disputed', label: 'MED' },
    { status: 'refunded', label: 'Reembolsado' },
    { status: 'cancelled', label: 'Cancelado' },
    { status: 'refund_requests', label: 'Reembolsos' },
];

const isRefundRequestsFilter = computed(() => filterStatus.value === 'refund_requests');

const refundRequestsPendingCount = computed(() => {
    const fromProp = Number(props.refund_requests_pending_count ?? 0);
    const fromBadge = Number(page.props.platform_admin_sidebar_badges?.transacoes ?? 0);
    return Math.max(fromProp, fromBadge, 0);
});

function chipIsActive(chip) {
    return filterStatus.value === chip.status;
}

function chipBadge(chip) {
    if (chip.status !== 'refund_requests') return 0;
    return refundRequestsPendingCount.value > 0 ? refundRequestsPendingCount.value : 0;
}

function listQueryParams(extra = {}) {
    return {
        status: filterStatus.value,
        q: filterQ.value?.trim() || undefined,
        per_page: Number(filterPerPage.value) || 25,
        gateway: filterGateway.value || undefined,
        method: filterMethod.value || undefined,
        ...extra,
    };
}

function selectChip(chip) {
    filterStatus.value = chip.status;
    router.get('/plataforma/transacoes', listQueryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
}

function formatDateParts(value) {
    if (!value) {
        return { date: '—', time: '' };
    }
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) {
        return { date: '—', time: '' };
    }
    return {
        date: d.toLocaleDateString('pt-BR'),
        time: d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }),
    };
}

function formatDateTime(value) {
    const parts = formatDateParts(value);
    if (!parts.time) return parts.date;
    return `${parts.date} · ${parts.time}`;
}

function statusLabel(orderOrStatus) {
    if (orderOrStatus && typeof orderOrStatus === 'object') {
        if (orderOrStatus.status_label) return orderOrStatus.status_label;
        if (orderOrStatus.status === 'refunded' && orderOrStatus.manual_refund?.offline) {
            return 'Reembolso manual';
        }
        return statusLabel(orderOrStatus.status);
    }
    const map = {
        completed: 'Aprovado',
        pending: 'Pendente',
        disputed: 'MED',
        cancelled: 'Cancelado',
        refund_pending: 'Aguardando reembolso',
        refunded: 'Reembolsado',
    };
    return map[orderOrStatus] ?? orderOrStatus ?? '—';
}

function statusBadgeClass(orderOrStatus) {
    const status = orderOrStatus && typeof orderOrStatus === 'object' ? orderOrStatus.status : orderOrStatus;
    const offline = Boolean(orderOrStatus && typeof orderOrStatus === 'object' && orderOrStatus.manual_refund?.offline);
    if (status === 'completed') return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200';
    if (status === 'pending' || status === 'refund_pending') return 'bg-amber-100 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100';
    if (status === 'disputed') return 'bg-orange-100 text-orange-900 dark:bg-orange-900/30 dark:text-orange-100';
    if (status === 'refunded' && offline) return 'bg-rose-100 text-rose-900 dark:bg-rose-900/40 dark:text-rose-200';
    if (status === 'cancelled' || status === 'refunded') return 'bg-zinc-200 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-200';
    return 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200';
}

function applyFilters() {
    router.get('/plataforma/transacoes', listQueryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function changePerPage() {
    router.get('/plataforma/transacoes', listQueryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function approveQuerySuffix() {
    const params = new URLSearchParams();
    if (filterStatus.value && filterStatus.value !== 'all') params.set('status', filterStatus.value);
    if (filterQ.value?.trim()) params.set('q', filterQ.value.trim());
    if (filterPerPage.value) params.set('per_page', filterPerPage.value);
    if (filterGateway.value) params.set('gateway', filterGateway.value);
    if (filterMethod.value) params.set('method', filterMethod.value);
    const s = params.toString();
    return s ? `?${s}` : '';
}

/** action: aprovar-manualmente | cancelar | reembolsar | reembolso-manual | marcar-med */
function orderActionUrl(action, id) {
    return `/plataforma/transacoes/pedidos/${id}/${action}${approveQuerySuffix()}`;
}

function openCustomer(row) {
    if (!row?.customer_url) return;
    router.visit(row.customer_url);
}

function approvePendingOrder(id) {
    if (!confirm('Aprovar este pedido como pago? O infoprodutor será creditado na carteira e o cliente receberá acesso conforme o produto.')) return;
    router.post(orderActionUrl('aprovar-manualmente', id), {}, { preserveScroll: true });
}

function confirmCancel(id) {
    if (!confirm('Cancelar este pedido pendente? O cliente não será cobrado (se ainda estiver pendente).')) return;
    router.post(orderActionUrl('cancelar', id), {}, { preserveScroll: true });
}

function openRefundModal(id, mode = 'gateway') {
    refundTargetId.value = id;
    refundMode.value = mode;
    refundReason.value = '';
    refundTotpCode.value = '';
    refundModalOpen.value = true;
}

function closeRefundModal() {
    refundModalOpen.value = false;
    refundTargetId.value = null;
    refundMode.value = 'gateway';
    refundReason.value = '';
    refundTotpCode.value = '';
}

function submitRefund() {
    const id = refundTargetId.value;
    const reason = refundReason.value?.trim() ?? '';
    const offline = refundMode.value === 'offline';
    if (!id) return;
    if (reason.length < 3) {
        window.alert('Informe o motivo do reembolso (mínimo 3 caracteres).');
        return;
    }
    const totp = (refundTotpCode.value || '').replace(/\D/g, '');
    if (offline && totpEnabled.value && totp.length < 6) {
        window.alert('Informe o código 2FA para confirmar o reembolso manual.');
        return;
    }
    refundSubmitting.value = true;
    const payload = offline
        ? (totpEnabled.value ? { reason, totp_code: totp } : { reason })
        : { reason };
    router.post(
        orderActionUrl(offline ? 'reembolso-manual' : 'reembolsar', id),
        payload,
        {
            preserveScroll: true,
            onSuccess: () => closeRefundModal(),
            onFinish: () => {
                refundSubmitting.value = false;
            },
        }
    );
}

function confirmRefund(id) {
    openRefundModal(id, 'gateway');
}

function confirmOfflineRefund(id) {
    openRefundModal(id, 'offline');
}

function confirmMed(id, wasPaid) {
    const msg = wasPaid
        ? 'Marcar como MED (contestação)? O valor líquido já creditado na carteira do infoprodutor será movido do saldo disponível para o saldo pendente (bloqueado) até a resolução.'
        : 'Marcar este pedido como MED (contestação)?';
    if (!confirm(msg)) return;
    router.post(orderActionUrl('marcar-med', id), {}, { preserveScroll: true });
}

function confirmDeleteOrder(id) {
    if (
        !confirm(
            'Excluir este pedido do histórico?\n\nPedidos pagos ou em MED só podem ser removidos após reembolso. Esta ação não pode ser desfeita.'
        )
    ) {
        return;
    }
    router.delete(`/plataforma/transacoes/pedidos/${id}${approveQuerySuffix()}`, { preserveScroll: true });
}

function openDetail(v) {
    selectedVenda.value = v;
    sidebarOpen.value = true;
}

function closeSidebar() {
    sidebarOpen.value = false;
    selectedVenda.value = null;
}

const rows = computed(() => props.orders?.data ?? []);

const pendingRows = computed(() => rows.value.filter((o) => o.status === 'pending'));

const selectedCount = computed(() => selectedIds.value.length);

const allPendingOnPageSelected = computed(() => {
    if (!pendingRows.value.length) return false;
    return pendingRows.value.every((o) => selectedIds.value.includes(o.id));
});

const resultsSummary = computed(() => {
    const total = Number(props.orders?.total ?? 0);
    if (!total) return 'Nenhuma transação encontrada com os filtros aplicados.';
    const from = Number(props.orders?.from ?? 0);
    const to = Number(props.orders?.to ?? 0);
    return `Exibindo ${from}–${to} de ${total} transações`;
});

const bulkConfirmMessage = computed(() => {
    const n = selectedCount.value;
    if (n === 1) {
        return 'Você está prestes a excluir esta transação pendente. Esta ação não poderá ser desfeita. Deseja continuar?';
    }
    return `Você está prestes a excluir ${n} transações pendentes. Esta ação não poderá ser desfeita. Deseja continuar?`;
});

function toggleSelect(id) {
    if (selectedIds.value.includes(id)) {
        selectedIds.value = selectedIds.value.filter((rowId) => rowId !== id);
        return;
    }
    selectedIds.value = [...selectedIds.value, id];
}

function toggleSelectAllPending() {
    if (allPendingOnPageSelected.value) {
        const pendingSet = new Set(pendingRows.value.map((o) => o.id));
        selectedIds.value = selectedIds.value.filter((id) => !pendingSet.has(id));
        return;
    }
    const merged = new Set([...selectedIds.value, ...pendingRows.value.map((o) => o.id)]);
    selectedIds.value = [...merged];
}

function openBulkDeleteModal() {
    if (!selectedIds.value.length) return;
    bulkDeleteReason.value = '';
    bulkDeleteOpen.value = true;
}

function closeBulkDeleteModal() {
    if (bulkDeleteLoading.value) return;
    bulkDeleteOpen.value = false;
    bulkDeleteReason.value = '';
}

function submitBulkDelete() {
    if (!selectedIds.value.length || bulkDeleteLoading.value) return;
    bulkDeleteLoading.value = true;
    router.post(
        '/plataforma/transacoes/pedidos/excluir-em-massa',
        {
            ids: selectedIds.value,
            reason: bulkDeleteReason.value?.trim() || undefined,
            status: filterStatus.value,
            q: filterQ.value?.trim() || undefined,
            per_page: Number(filterPerPage.value) || 25,
            gateway: filterGateway.value || undefined,
            method: filterMethod.value || undefined,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                bulkDeleteLoading.value = false;
            },
            onSuccess: () => {
                selectedIds.value = [];
                bulkDeleteOpen.value = false;
                bulkDeleteReason.value = '';
            },
        }
    );
}

const menuOrder = computed(() => {
    if (openMenuId.value == null) return null;
    return rows.value.find((x) => x.id === openMenuId.value) ?? null;
});

function hasActionsMenu() {
    return true;
}

async function updateMenuPosition() {
    const anchor = menuAnchorEl.value;
    if (!anchor || openMenuId.value == null) return;

    const rect = anchor.getBoundingClientRect();
    const minMargin = 8;
    const desiredWidth = 220;
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
    if (openMenuId.value == null) return;
    const wraps = document.querySelectorAll(`[data-tx-menu="${openMenuId.value}"]`);
    const menu = menuEl.value;
    for (const wrap of wraps) {
        if (wrap.contains(event.target)) return;
    }
    if (menu && menu.contains(event.target)) return;
    closeMenu();
}

/**
 * Captura o pedido antes de fechar o menu — senão `menuOrder` fica null e o sidebar/ações quebram.
 *
 * @param {function(row: object): void} action
 */
function runMenuAction(action) {
    const row = menuOrder.value;
    if (!row) return;
    closeMenu();
    action(row);
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
});

const paginationLinks = computed(() => props.orders?.links ?? []);

const tableColCount = computed(() => (isRefundRequestsFilter.value ? 12 : 11));
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Transações</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Pedidos da plataforma por status (Aprovado, MED, Reembolsado, etc.). O filtro
                <strong class="font-medium text-zinc-800 dark:text-zinc-200">Reembolsos</strong>
                lista pedidos com solicitação do cliente ainda pendente (mesmo contador do menu). Saques estão em
                <a href="/plataforma/saques" class="font-medium text-[var(--color-primary)] underline-offset-2 hover:underline"
                    >Saques</a
                >. Aprovação manual apenas para pedidos pendentes.
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

        <div
            v-if="isRefundRequestsFilter"
            class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100"
        >
            Exibindo pedidos com <strong>solicitação de reembolso pendente</strong> do comprador.
            A resolução fica no painel do vendedor em Vendas → Reembolsos; aqui você analisa o que gera o alerta do menu.
        </div>

        <div
            class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800"
        >
            <div class="w-full overflow-x-auto [-webkit-overflow-scrolling:touch]">
                <div
                    class="inline-flex min-w-full flex-wrap gap-2"
                    role="tablist"
                    aria-label="Filtros de transações"
                >
                    <button
                        v-for="chip in filterChips"
                        :key="chip.status"
                        type="button"
                        role="tab"
                        :aria-selected="chipIsActive(chip)"
                        :class="[
                            'inline-flex items-center gap-2 whitespace-nowrap rounded-lg border px-3 py-2 text-sm font-medium transition',
                            chipIsActive(chip)
                                ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-[var(--color-primary)] dark:bg-[var(--color-primary)]/20'
                                : 'border-zinc-200 bg-zinc-50 text-zinc-700 hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-900/50 dark:text-zinc-200',
                        ]"
                        @click="selectChip(chip)"
                    >
                        {{ chip.label }}
                        <span
                            v-if="chipBadge(chip) > 0"
                            class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white"
                        >
                            {{ chipBadge(chip) > 99 ? '99+' : chipBadge(chip) }}
                        </span>
                    </button>
                </div>
            </div>

            <form
                class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end"
                @submit.prevent="applyFilters"
            >
                <div class="min-w-[220px] flex-[2]">
                    <label for="tx-q" class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Buscar (cliente, infoprodutor, e-mail, produto, CPF, ID)
                    </label>
                    <input
                        id="tx-q"
                        v-model="filterQ"
                        type="search"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        placeholder="Ex.: cliente@email.com, vendedor ou 12345"
                        autocomplete="off"
                    />
                </div>
                <div class="w-full sm:w-56">
                    <label for="tx-gateway" class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Recebedor
                    </label>
                    <select
                        id="tx-gateway"
                        v-model="filterGateway"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        @change="applyFilters"
                    >
                        <option value="">Todos</option>
                        <option v-for="opt in gateway_options" :key="opt.value" :value="opt.value">
                            {{ opt.label }}
                        </option>
                    </select>
                </div>
                <div class="w-full sm:w-48">
                    <label for="tx-method" class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Método
                    </label>
                    <select
                        id="tx-method"
                        v-model="filterMethod"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        @change="applyFilters"
                    >
                        <option value="">Todos</option>
                        <option v-for="opt in payment_method_options" :key="opt.value" :value="opt.value">
                            {{ opt.label }}
                        </option>
                    </select>
                </div>
                <div class="w-full sm:w-36">
                    <label for="tx-per-page" class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Por página
                    </label>
                    <select
                        id="tx-per-page"
                        v-model="filterPerPage"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        @change="changePerPage"
                    >
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <Button type="submit">Buscar</Button>
            </form>
        </div>

        <div
            v-if="selectedCount > 0"
            class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/50 dark:bg-amber-950/30"
        >
            <p class="text-sm text-amber-900 dark:text-amber-100">
                {{ selectedCount }} transação(ões) pendente(s) selecionada(s)
            </p>
            <div class="flex flex-wrap gap-2">
                <Button type="button" size="sm" variant="secondary" @click="selectedIds = []">Limpar seleção</Button>
                <Button type="button" size="sm" class="!bg-red-600 !text-white hover:!bg-red-700" @click="openBulkDeleteModal">
                    Excluir {{ selectedCount }} {{ selectedCount === 1 ? 'transação selecionada' : 'transações selecionadas' }}
                </Button>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-zinc-600 dark:text-zinc-400">
            <p>{{ resultsSummary }}</p>
        </div>

        <div v-if="rows.length" class="space-y-3 lg:hidden">
            <article
                v-for="o in rows"
                :key="`mobile-${o.id}`"
                class="cursor-pointer rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700/40"
                tabindex="0"
                role="button"
                @click="openDetail(o)"
                @keydown.enter.prevent="openDetail(o)"
                @keydown.space.prevent="openDetail(o)"
            >
                <div class="flex items-start gap-3">
                    <div class="pt-1" @click.stop>
                        <input
                            v-if="o.status === 'pending'"
                            type="checkbox"
                            class="rounded border-zinc-300"
                            :checked="selectedIds.includes(o.id)"
                            :aria-label="`Selecionar pedido ${o.id}`"
                            @change="toggleSelect(o.id)"
                        />
                        <span v-else class="inline-block w-4" aria-hidden="true" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-semibold text-zinc-900 dark:text-white">
                                    Pedido #{{ o.id }}
                                </p>
                                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ formatDateTime(o.created_at) }}
                                </p>
                            </div>
                            <div class="relative shrink-0" :data-tx-menu="o.id" @click.stop>
                                <button
                                    type="button"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                                    :aria-expanded="openMenuId === o.id"
                                    aria-label="Abrir ações"
                                    @click="toggleMenu(o.id, $event)"
                                >
                                    <MoreVertical class="h-4 w-4 shrink-0" />
                                </button>
                            </div>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <span
                                :class="['inline-flex w-fit max-w-full rounded-full px-2 py-0.5 text-[11px] font-medium leading-tight', statusBadgeClass(o)]"
                            >
                                {{ statusLabel(o) }}
                            </span>
                            <span
                                v-if="o.pending_refund_request && !isRefundRequestsFilter"
                                class="inline-flex w-fit rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 dark:bg-amber-900/40 dark:text-amber-100"
                            >
                                Reembolso pendente
                            </span>
                            <span
                                v-if="o.approved_manually"
                                class="text-[10px] font-medium uppercase tracking-wide text-violet-600 dark:text-violet-400"
                            >
                                Aprovação manual
                            </span>
                            <span class="ml-auto text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">
                                {{ formatBRL(o.amount_gross) }}
                            </span>
                        </div>
                    </div>
                </div>

                <dl class="mt-4 grid grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)] gap-x-3 border-t border-zinc-100 pt-3 dark:border-zinc-700">
                    <div class="min-w-0 space-y-3">
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Cliente
                            </dt>
                            <dd class="mt-0.5 break-words text-sm font-medium text-zinc-900 dark:text-white">
                                {{ o.customer_name }}
                            </dd>
                            <dd class="mt-0.5 break-all text-xs text-zinc-500 dark:text-zinc-400">
                                {{ o.customer_email }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Infoprodutor
                            </dt>
                            <dd class="mt-0.5 break-words text-sm font-medium text-zinc-900 dark:text-white">
                                {{ o.infoprodutor_name }}
                            </dd>
                            <dd class="mt-0.5 break-all text-xs text-zinc-500 dark:text-zinc-400">
                                {{ o.infoprodutor_email ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Produto
                            </dt>
                            <dd class="mt-0.5 break-words text-sm text-zinc-800 dark:text-zinc-200">
                                {{ o.product_label }}
                            </dd>
                            <span
                                v-if="o.is_pixgo"
                                class="mt-1 inline-flex rounded-full bg-lime-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-lime-900 dark:bg-lime-900/40 dark:text-lime-200"
                            >{{ o.pixgo_label || 'PixGO' }}</span>
                        </div>
                    </div>
                    <div class="min-w-0 space-y-3 border-l border-zinc-100 pl-3 dark:border-zinc-700">
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Tipo
                            </dt>
                            <dd class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">
                                {{ o.sale_type_label || 'Único' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Método
                            </dt>
                            <dd class="mt-0.5 break-words text-sm text-zinc-800 dark:text-zinc-200">
                                {{ o.payment_method_label }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Recebedor
                            </dt>
                            <dd class="mt-0.5 break-words text-sm font-medium text-zinc-900 dark:text-white">
                                {{ o.recebedor || '—' }}
                            </dd>
                            <dd
                                v-if="o.cajupay_account_badge"
                                class="mt-0.5 break-words text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                {{ o.cajupay_account_badge }}
                            </dd>
                        </div>
                    </div>
                    <div v-if="isRefundRequestsFilter" class="col-span-2 mt-3">
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Solicitação
                        </dt>
                        <dd class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ formatDateTime(o.pending_refund_request?.created_at) }}
                        </dd>
                        <dd class="mt-0.5 break-words text-sm text-zinc-800 dark:text-zinc-200">
                            {{ o.pending_refund_request?.customer_reason || 'Sem motivo informado' }}
                        </dd>
                    </div>
                </dl>
            </article>
        </div>
        <div
            v-else
            class="rounded-2xl border border-dashed border-zinc-200 px-4 py-12 text-center text-sm text-zinc-500 dark:border-zinc-700 lg:hidden"
        >
            {{
                isRefundRequestsFilter
                    ? 'Nenhuma solicitação de reembolso pendente.'
                    : 'Nenhuma transação encontrada com os filtros aplicados.'
            }}
        </div>

        <div
            class="hidden overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800 lg:block"
        >
            <div class="overflow-x-auto lg:overflow-x-visible">
                <table class="w-full min-w-[720px] table-fixed text-left text-sm lg:min-w-0">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-[11px] uppercase leading-tight text-zinc-500 dark:border-zinc-600 dark:bg-zinc-900/50">
                        <tr>
                            <th class="w-9 px-2 py-2.5">
                                <input
                                    type="checkbox"
                                    class="rounded border-zinc-300"
                                    :checked="allPendingOnPageSelected"
                                    :disabled="!pendingRows.length"
                                    :title="pendingRows.length ? 'Selecionar pendentes da página' : 'Nenhuma pendente nesta página'"
                                    @change="toggleSelectAllPending"
                                    @click.stop
                                />
                            </th>
                            <th class="w-[4.75rem] px-2 py-2.5">Data</th>
                            <th class="w-14 px-2 py-2.5">Pedido</th>
                            <th class="min-w-0 px-2 py-2.5">Cliente</th>
                            <th class="min-w-0 px-2 py-2.5">Infoprodutor</th>
                            <th class="min-w-0 px-2 py-2.5">Produto</th>
                            <th class="w-[6.75rem] px-2 py-2.5">Status</th>
                            <th class="w-[5.5rem] px-2 py-2.5">Tipo</th>
                            <th v-if="isRefundRequestsFilter" class="min-w-0 px-2 py-2.5">Solicitação</th>
                            <th class="w-[5.25rem] px-2 py-2.5">Método</th>
                            <th class="w-[6.25rem] px-2 py-2.5">Recebedor</th>
                            <th class="w-[6.5rem] px-2 py-2.5 text-right">Valor (bruto)</th>
                            <th class="relative w-10 px-1 py-2.5"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        <tr
                            v-for="o in rows"
                            :key="o.id"
                            class="cursor-pointer bg-white transition hover:bg-zinc-50 dark:bg-zinc-800/40 dark:hover:bg-zinc-700/50"
                            tabindex="0"
                            role="button"
                            @click="openDetail(o)"
                            @keydown.enter.prevent="openDetail(o)"
                            @keydown.space.prevent="openDetail(o)"
                        >
                            <td class="px-2 py-2.5 align-top" @click.stop>
                                <input
                                    v-if="o.status === 'pending'"
                                    type="checkbox"
                                    class="rounded border-zinc-300"
                                    :checked="selectedIds.includes(o.id)"
                                    @change="toggleSelect(o.id)"
                                />
                                <span v-else class="inline-block w-4" aria-hidden="true" />
                            </td>
                            <td class="px-2 py-2.5 align-top text-zinc-600 dark:text-zinc-300">
                                <div class="leading-tight">{{ formatDateParts(o.created_at).date }}</div>
                                <div
                                    v-if="formatDateParts(o.created_at).time"
                                    class="mt-0.5 text-[11px] leading-tight text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ formatDateParts(o.created_at).time }}
                                </div>
                            </td>
                            <td class="px-2 py-2.5 align-top font-mono text-xs text-zinc-700 dark:text-zinc-200">
                                #{{ o.id }}
                            </td>
                            <td class="min-w-0 px-2 py-2.5 align-top">
                                <div class="truncate font-medium text-zinc-900 dark:text-white" :title="o.customer_name">
                                    {{ o.customer_name }}
                                </div>
                                <div class="truncate text-xs text-zinc-500" :title="o.customer_email">
                                    {{ o.customer_email }}
                                </div>
                            </td>
                            <td class="min-w-0 px-2 py-2.5 align-top">
                                <div class="truncate font-medium text-zinc-900 dark:text-white" :title="o.infoprodutor_name">
                                    {{ o.infoprodutor_name }}
                                </div>
                                <div class="truncate text-xs text-zinc-500" :title="o.infoprodutor_email ?? ''">
                                    {{ o.infoprodutor_email ?? '—' }}
                                </div>
                            </td>
                            <td class="min-w-0 px-2 py-2.5 align-top">
                                <span class="line-clamp-2 break-words" :title="o.product_label">{{ o.product_label }}</span>
                                <span
                                    v-if="o.is_pixgo"
                                    class="mt-1 inline-flex rounded-full bg-lime-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-lime-900 dark:bg-lime-900/40 dark:text-lime-200"
                                >{{ o.pixgo_label || 'PixGO' }}</span>
                            </td>
                            <td class="px-2 py-2.5 align-top">
                                <div class="flex flex-wrap items-center gap-1">
                                    <span
                                        :class="['inline-flex w-fit max-w-full rounded-full px-2 py-0.5 text-[11px] font-medium leading-tight', statusBadgeClass(o)]"
                                    >
                                        {{ statusLabel(o) }}
                                    </span>
                                    <span
                                        v-if="o.pending_refund_request && !isRefundRequestsFilter"
                                        class="inline-flex w-fit rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 dark:bg-amber-900/40 dark:text-amber-100"
                                    >
                                        Reembolso pendente
                                    </span>
                                    <span
                                        v-if="o.approved_manually"
                                        class="text-[10px] font-medium uppercase tracking-wide text-violet-600 dark:text-violet-400"
                                    >
                                        Aprovação manual
                                    </span>
                                </div>
                            </td>
                            <td class="px-2 py-2.5 align-top">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="o.sale_type_label === 'Assinatura'
                                        ? 'bg-[var(--color-primary)]/15 text-[var(--color-primary)]'
                                        : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200'"
                                >
                                    {{ o.sale_type_label || 'Único' }}
                                </span>
                            </td>
                            <td v-if="isRefundRequestsFilter" class="min-w-0 px-2 py-2.5 align-top">
                                <div class="leading-tight text-zinc-500 dark:text-zinc-400">
                                    <div>{{ formatDateParts(o.pending_refund_request?.created_at).date }}</div>
                                    <div
                                        v-if="formatDateParts(o.pending_refund_request?.created_at).time"
                                        class="text-[11px]"
                                    >
                                        {{ formatDateParts(o.pending_refund_request?.created_at).time }}
                                    </div>
                                </div>
                                <p
                                    class="mt-1 line-clamp-2 break-words text-sm text-zinc-800 dark:text-zinc-200"
                                    :title="o.pending_refund_request?.customer_reason || ''"
                                >
                                    {{ o.pending_refund_request?.customer_reason || 'Sem motivo informado' }}
                                </p>
                            </td>
                            <td class="min-w-0 px-2 py-2.5 align-top text-zinc-600 dark:text-zinc-300">
                                <span class="line-clamp-2 break-words" :title="o.payment_method_label">{{ o.payment_method_label }}</span>
                            </td>
                            <td class="min-w-0 px-2 py-2.5 align-top">
                                <div class="truncate font-medium text-zinc-900 dark:text-white" :title="o.recebedor || ''">
                                    {{ o.recebedor || '—' }}
                                </div>
                                <div
                                    v-if="o.cajupay_account_badge"
                                    class="truncate text-xs text-zinc-500"
                                    :title="o.cajupay_account_badge"
                                >
                                    {{ o.cajupay_account_badge }}
                                </div>
                            </td>
                            <td class="px-2 py-2.5 align-top text-right text-xs tabular-nums font-medium leading-tight text-zinc-900 dark:text-white">
                                {{ formatBRL(o.amount_gross) }}
                            </td>
                            <td class="relative px-1 py-2 text-right align-top" @click.stop>
                                <div
                                    class="relative flex justify-end"
                                    :data-tx-menu="o.id"
                                >
                                    <button
                                        type="button"
                                        class="flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                        :aria-expanded="openMenuId === o.id"
                                        aria-label="Abrir ações"
                                        @click="toggleMenu(o.id, $event)"
                                    >
                                        <MoreVertical class="h-4 w-4 shrink-0" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td :colspan="tableColCount" class="px-4 py-12 text-center text-zinc-500">
                                {{
                                    isRefundRequestsFilter
                                        ? 'Nenhuma solicitação de reembolso pendente.'
                                        : 'Nenhuma transação encontrada com os filtros aplicados.'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <nav
            v-if="paginationLinks.length > 3"
            class="flex flex-wrap items-center justify-center gap-2"
            aria-label="Paginação"
        >
            <a
                v-for="link in paginationLinks"
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

        <VendaDetailSidebar
            :key="selectedVenda?.id ?? 'none'"
            :open="sidebarOpen"
            :venda="selectedVenda"
            @close="closeSidebar"
        />

        <Teleport to="body">
            <div
                v-if="openMenuId != null && menuOrder"
                ref="menuEl"
                class="fixed z-[100000] w-[220px] rounded-xl border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                :style="{ top: `${menuPos.top}px`, left: `${menuPos.left}px` }"
                role="menu"
                aria-label="Ações do pedido"
            >
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="runMenuAction((r) => openDetail(r))"
                >
                    <FileText class="h-4 w-4 shrink-0" />
                    Detalhes
                </button>
                <button
                    v-if="menuOrder.customer_url"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="runMenuAction((r) => openCustomer(r))"
                >
                    <User class="h-4 w-4 shrink-0" />
                    Ver cliente
                </button>
                <template v-if="menuOrder.status === 'pending'">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-emerald-950/30"
                        @click="runMenuAction((r) => approvePendingOrder(r.id))"
                    >
                        <CheckCircle class="h-4 w-4 shrink-0" />
                        Aprovar
                    </button>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="runMenuAction((r) => confirmCancel(r.id))"
                    >
                        <Ban class="h-4 w-4 shrink-0" />
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="runMenuAction((r) => confirmMed(r.id, false))"
                    >
                        <AlertTriangle class="h-4 w-4 shrink-0" />
                        Marcar MED
                    </button>
                </template>
                <template v-else-if="menuOrder.status === 'completed'">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/30"
                        @click="runMenuAction((r) => confirmRefund(r.id))"
                    >
                        <RotateCcw class="h-4 w-4 shrink-0" />
                        Reembolsar
                    </button>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-amber-800 hover:bg-amber-50 dark:text-amber-200 dark:hover:bg-amber-950/30"
                        @click="runMenuAction((r) => confirmOfflineRefund(r.id))"
                    >
                        <Banknote class="h-4 w-4 shrink-0" />
                        Reembolso manual
                    </button>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="runMenuAction((r) => confirmMed(r.id, true))"
                    >
                        <AlertTriangle class="h-4 w-4 shrink-0" />
                        Marcar MED
                    </button>
                </template>
                <template v-else-if="menuOrder.status === 'disputed'">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/30"
                        @click="runMenuAction((r) => confirmRefund(r.id))"
                    >
                        <RotateCcw class="h-4 w-4 shrink-0" />
                        Reembolsar
                    </button>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-amber-800 hover:bg-amber-50 dark:text-amber-200 dark:hover:bg-amber-950/30"
                        @click="runMenuAction((r) => confirmOfflineRefund(r.id))"
                    >
                        <Banknote class="h-4 w-4 shrink-0" />
                        Reembolso manual
                    </button>
                </template>
                <div class="my-1 border-t border-zinc-200 dark:border-zinc-700" role="separator" />
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/30"
                    @click="runMenuAction((r) => confirmDeleteOrder(r.id))"
                >
                    <Trash2 class="h-4 w-4 shrink-0" />
                    Excluir pedido
                </button>
            </div>
        </Teleport>

        <div
            v-if="refundModalOpen"
            class="fixed inset-0 z-[100002] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="platform-refund-modal-title"
        >
            <div class="absolute inset-0 bg-zinc-900/50 dark:bg-zinc-950/60" @click="closeRefundModal" />
            <div class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                <h3 id="platform-refund-modal-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                    {{ refundMode === 'offline' ? 'Reembolso manual' : 'Reembolsar pedido' }}
                </h3>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    <template v-if="refundMode === 'offline'">
                        Use quando o reembolso já foi feito <strong>fora do sistema</strong> (por exemplo, se o reembolso no gateway falhou). O pedido ficará com status <strong>Reembolso manual</strong>, o cliente perderá o acesso ao material e o valor será debitado da carteira do infoprodutor.
                    </template>
                    <template v-else>
                        O pedido será marcado como <strong>Reembolsado</strong>. O saldo do infoprodutor será debitado se houver crédito na carteira. O motivo ficará visível para o infoprodutor.
                    </template>
                </p>
                <label class="mt-4 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    Motivo do reembolso
                    <textarea
                        v-model="refundReason"
                        rows="4"
                        maxlength="500"
                        required
                        class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        placeholder="Descreva o motivo (visível ao infoprodutor)"
                    />
                </label>
                <div v-if="refundMode === 'offline'" class="mt-4 space-y-3">
                    <p
                        class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100"
                    >
                        Recomendamos ativar o 2FA em
                        <a href="/plataforma/meu-perfil" class="font-semibold underline">Meu perfil</a>
                        para proteger ações sensíveis como o reembolso manual.
                    </p>
                    <label v-if="totpEnabled" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Código 2FA
                        <input
                            v-model="refundTotpCode"
                            type="text"
                            inputmode="numeric"
                            maxlength="6"
                            autocomplete="one-time-code"
                            class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm tracking-widest text-zinc-900 placeholder:text-zinc-400 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            placeholder="000000"
                        />
                    </label>
                </div>
                <p v-if="refundFormError" class="mt-3 text-sm text-red-600 dark:text-red-400">
                    {{ refundFormError }}
                </p>
                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        :disabled="refundSubmitting"
                        @click="closeRefundModal"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                        :disabled="refundSubmitting"
                        @click="submitRefund"
                    >
                        {{ refundSubmitting ? 'Processando...' : (refundMode === 'offline' ? 'Confirmar reembolso manual' : 'Confirmar reembolso') }}
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="bulkDeleteOpen"
            class="fixed inset-0 z-[100002] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="platform-bulk-delete-title"
        >
            <div class="absolute inset-0 bg-zinc-900/50 dark:bg-zinc-950/60" @click="closeBulkDeleteModal" />
            <div class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                <h3 id="platform-bulk-delete-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Confirmar exclusão
                </h3>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    {{ bulkConfirmMessage }}
                </p>
                <label class="mt-4 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    Motivo da exclusão (opcional)
                    <textarea
                        v-model="bulkDeleteReason"
                        rows="3"
                        maxlength="500"
                        class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        placeholder="Ex.: Cobranças de teste geradas incorretamente."
                        :disabled="bulkDeleteLoading"
                    />
                </label>
                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        :disabled="bulkDeleteLoading"
                        @click="closeBulkDeleteModal"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                        :disabled="bulkDeleteLoading"
                        @click="submitBulkDelete"
                    >
                        {{ bulkDeleteLoading ? 'Excluindo...' : 'Excluir transações' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
