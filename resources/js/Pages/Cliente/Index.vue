<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { copyTextToClipboard } from '@/lib/copyText';
import { useI18n } from '@/composables/useI18n';
import {
    Calendar,
    Check,
    Copy,
    ExternalLink,
    Mail,
    Package,
    Search,
    Truck,
    X,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    purchases: { type: Array, default: () => [] },
});

const page = usePage();
const { t, locale } = useI18n();

const numberLocale = computed(() => ({
    pt_BR: 'pt-BR',
    en: 'en-US',
    es: 'es-ES',
    it: 'it-IT',
}[locale.value] || 'pt-BR'));

function interpolate(value, vars = {}) {
    return Object.entries(vars).reduce(
        (out, [key, val]) => out.replaceAll(`{${key}}`, String(val)),
        String(value ?? ''),
    );
}

function productTypeLabel(row) {
    if (row?.product_type) {
        return t(`customer.product_type.${row.product_type}`, row.product_type_label || '');
    }
    return row?.product_type_label || '';
}

function accessCta(row) {
    const keys = {
        area_membros: 'customer.access.members',
        area_membros_externa: 'customer.access.external',
        link: 'customer.access.link',
        aplicativo: 'customer.access.app',
    };
    return t(keys[row?.product_type] || 'customer.access.default', row?.access_cta || t('customer.purchases.access', 'Acessar'));
}

function accessHint(row) {
    if (row?.access_url) return '';
    const keys = {
        produto_fisico: 'customer.hint.physical',
        link_pagamento: 'customer.hint.payment_link',
        area_membros_externa: 'customer.hint.external',
    };
    return t(keys[row?.product_type] || 'customer.hint.default', row?.access_hint || '');
}
const impersonating = computed(() => page.props.customer_impersonation?.active === true);
const searchQuery = ref('');
const filter = ref('all');
const detailsOpen = ref(false);
const detailsRow = ref(null);
const copiedKey = ref('');
const refundOpen = ref(false);
/** Código público do pedido (exibido no texto; o envio usa order_id interno). */
const refundOrderPublicRef = ref('');

const refundForm = useForm({
    order_id: null,
    reason: '',
});

const firstName = computed(() => {
    const name = String(page.props.auth?.user?.name || '').trim();
    if (!name) return '';
    return name.split(/\s+/)[0];
});

const purchaseCount = computed(() => props.purchases?.length || 0);

const filteredPurchases = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return (props.purchases || []).filter((row) => {
        if (filter.value === 'access' && !row.access_url) return false;
        if (filter.value === 'granted' && !row.is_manual_grant) return false;
        if (!q) return true;
        const haystack = [
            row.product_name,
            row.public_reference,
            row.seller_name,
            row.product_type_label,
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();
        return haystack.includes(q);
    });
});

const hasGranted = computed(() => (props.purchases || []).some((row) => row.is_manual_grant));
const hasAccess = computed(() => (props.purchases || []).some((row) => row.access_url));

function formatBRL(n) {
    return new Intl.NumberFormat(numberLocale.value, { style: 'currency', currency: 'BRL' }).format(Number(n) || 0);
}

function refundStatusLabel(status) {
    if (status === 'pending') return t('customer.purchases.refund_pending', 'Reembolso em análise');
    if (status === 'approved') return t('customer.purchases.refund_approved', 'Reembolso aprovado');
    if (status === 'rejected') return t('customer.purchases.refund_rejected', 'Reembolso recusado');
    return '';
}

function openRefund(row) {
    refundForm.order_id = row.order_id;
    refundOrderPublicRef.value = row.public_reference || String(row.order_id);
    refundForm.reason = '';
    refundForm.clearErrors();
    refundOpen.value = true;
}

function closeRefund() {
    refundOpen.value = false;
    refundOrderPublicRef.value = '';
}

function submitRefund() {
    refundForm.post('/painel-cliente/reembolso', {
        preserveScroll: true,
        onSuccess: () => closeRefund(),
    });
}

function openDetails(row) {
    detailsRow.value = row;
    copiedKey.value = '';
    detailsOpen.value = true;
}

function closeDetails() {
    detailsOpen.value = false;
    detailsRow.value = null;
    copiedKey.value = '';
}

async function copyValue(value, key) {
    const ok = await copyTextToClipboard(value);
    if (ok) {
        copiedKey.value = key;
        window.setTimeout(() => {
            if (copiedKey.value === key) copiedKey.value = '';
        }, 1800);
    }
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <p class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-white sm:text-xl">
                {{ t('customer.purchases.hello', 'Olá') }}<span v-if="firstName">, {{ firstName }}</span>
            </p>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                <template v-if="purchaseCount === 1">{{ t('customer.purchases.count_one', 'Você tem 1 produto nesta conta.') }}</template>
                <template v-else-if="purchaseCount > 1">{{ interpolate(t('customer.purchases.count_many', 'Você tem {n} produtos nesta conta.'), { n: purchaseCount }) }}</template>
                <template v-else>{{ t('customer.purchases.empty_intro', 'Quando uma compra for concluída, o acesso aparece aqui.') }}</template>
            </p>
        </div>

        <div
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-100"
        >
            {{ page.props.flash.success }}
        </div>
        <div
            v-if="page.props.flash?.error"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-100"
        >
            {{ page.props.flash.error }}
        </div>

        <template v-if="purchaseCount">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="relative min-w-0 flex-1">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" aria-hidden="true" />
                    <input
                        v-model="searchQuery"
                        type="search"
                        :placeholder="t('customer.purchases.search_placeholder', 'Buscar produto, pedido ou vendedor')"
                        class="w-full rounded-xl border border-zinc-200 bg-white py-2.5 pl-9 pr-3 text-sm text-zinc-900 placeholder:text-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                    />
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        type="button"
                        class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                        :class="filter === 'all'
                            ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900'
                            : 'border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800'"
                        @click="filter = 'all'"
                    >
                        {{ t('customer.purchases.filter_all', 'Todos') }}
                    </button>
                    <button
                        v-if="hasAccess"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                        :class="filter === 'access'
                            ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900'
                            : 'border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800'"
                        @click="filter = 'access'"
                    >
                        {{ t('customer.purchases.filter_access', 'Com acesso') }}
                    </button>
                    <button
                        v-if="hasGranted"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                        :class="filter === 'granted'
                            ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900'
                            : 'border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800'"
                        @click="filter = 'granted'"
                    >
                        {{ t('customer.purchases.filter_granted', 'Liberados') }}
                    </button>
                </div>
            </div>

            <p
                v-if="!filteredPurchases.length"
                class="rounded-xl border border-dashed border-zinc-200 px-4 py-8 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400"
            >
                {{ t('customer.purchases.none_found', 'Nenhum produto encontrado com essa busca.') }}
            </p>

            <div
                v-else
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                <article
                    v-for="row in filteredPurchases"
                    :key="row.purchase_key || row.order_id"
                    class="flex flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm transition hover:border-zinc-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900/70 dark:hover:border-zinc-700"
                >
                    <div class="relative h-40 w-full shrink-0 overflow-hidden bg-zinc-100 sm:h-44 dark:bg-zinc-800/80">
                        <img
                            v-if="row.product_image_url"
                            :src="row.product_image_url"
                            :alt="row.product_name"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        />
                        <div
                            v-else
                            class="flex h-full w-full items-center justify-center text-zinc-400 dark:text-zinc-500"
                        >
                            <Package class="h-12 w-12" aria-hidden="true" />
                        </div>
                        <div class="absolute left-2 top-2 flex flex-wrap gap-1">
                            <span
                                v-if="row.product_type_label"
                                class="rounded-full bg-black/70 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white backdrop-blur-sm"
                            >
                                {{ productTypeLabel(row) }}
                            </span>
                            <span
                                v-if="row.is_order_bump"
                                class="rounded-full bg-zinc-900/80 px-2 py-0.5 text-[10px] font-semibold text-white"
                            >
                                {{ t('customer.purchases.badge_extra', 'Extra') }}
                            </span>
                            <span
                                v-if="row.is_bundle_item"
                                class="rounded-full bg-indigo-700/90 px-2 py-0.5 text-[10px] font-semibold text-white"
                            >
                                {{ t('customer.purchases.badge_bundle', 'Incluso no combo') }}
                            </span>
                            <span
                                v-if="row.is_manual_grant"
                                class="rounded-full bg-emerald-600/90 px-2 py-0.5 text-[10px] font-semibold text-white"
                            >
                                {{ t('customer.purchases.badge_granted', 'Liberado') }}
                            </span>
                            <span
                                v-if="row.is_renewal"
                                class="rounded-full bg-sky-600/90 px-2 py-0.5 text-[10px] font-semibold text-white"
                            >
                                {{ t('customer.purchases.badge_renewal', 'Renovação') }}
                            </span>
                        </div>
                    </div>
                    <div class="flex flex-1 flex-col gap-3 p-4">
                        <div>
                            <h2 class="line-clamp-2 text-base font-semibold leading-snug text-zinc-900 dark:text-white">
                                {{ row.product_name }}
                            </h2>
                            <p v-if="row.seller_name" class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">
                                {{ row.seller_name }}
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                <span v-if="row.purchased_at_label" class="inline-flex items-center gap-1">
                                    <Calendar class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ row.purchased_at_label }}
                                </span>
                                <span v-if="!row.is_manual_grant" class="font-medium text-zinc-700 dark:text-zinc-300">
                                    {{ formatBRL(row.amount) }}
                                </span>
                            </div>
                            <p
                                v-if="row.refund_status"
                                class="mt-2 text-xs font-medium"
                                :class="{
                                    'text-amber-700 dark:text-amber-300': row.refund_status === 'pending',
                                    'text-emerald-700 dark:text-emerald-300': row.refund_status === 'approved',
                                    'text-zinc-500 dark:text-zinc-400': row.refund_status === 'rejected',
                                }"
                            >
                                {{ refundStatusLabel(row.refund_status) }}
                            </p>
                            <p
                                v-if="!row.access_url && accessHint(row)"
                                class="mt-2 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400"
                            >
                                {{ accessHint(row) }}
                            </p>
                        </div>
                        <div class="mt-auto flex flex-col gap-2">
                            <a
                                v-if="row.access_url"
                                :href="row.access_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-[var(--color-primary)] px-3 py-2.5 text-sm font-medium text-white transition hover:opacity-90"
                            >
                                {{ accessCta(row) }}
                                <ExternalLink class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            </a>
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="flex-1 rounded-xl border border-zinc-200 px-3 py-2 text-xs font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                    @click="openDetails(row)"
                                >
                                    {{ t('customer.purchases.details', 'Detalhes') }}
                                </button>
                                <button
                                    v-if="row.can_request_refund && !impersonating"
                                    type="button"
                                    class="flex-1 rounded-xl border border-zinc-200 px-3 py-2 text-xs font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                    @click="openRefund(row)"
                                >
                                    {{ t('customer.purchases.refund', 'Reembolso') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </template>

        <div
            v-else
            class="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50/80 px-6 py-14 text-center dark:border-zinc-700 dark:bg-zinc-900/40"
        >
            <Package class="mx-auto h-12 w-12 text-zinc-400" aria-hidden="true" />
            <p class="mt-4 text-base font-medium text-zinc-800 dark:text-zinc-200">{{ t('customer.purchases.empty_title', 'Nenhuma compra por aqui ainda') }}</p>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ t('customer.purchases.empty_body', 'Assim que o pagamento for confirmado, o produto e o acesso aparecem nesta página.') }}
            </p>
        </div>

        <Teleport to="body">
            <div
                v-if="detailsOpen && detailsRow"
                class="fixed inset-0 z-[200000] flex items-end justify-center bg-black/50 p-4 sm:items-center"
                role="dialog"
                aria-modal="true"
                @click.self="closeDetails"
            >
                <div
                    class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
                    @click.stop
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ detailsRow.product_name }}</h2>
                            <p v-if="detailsRow.product_type_label" class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                                {{ productTypeLabel(detailsRow) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            :aria-label="t('customer.purchases.close', 'Fechar')"
                            @click="closeDetails"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <dl class="mt-5 space-y-3 text-sm">
                        <div v-if="detailsRow.seller_name" class="flex justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ t('customer.purchases.seller', 'Vendedor') }}</dt>
                            <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ detailsRow.seller_name }}</dd>
                        </div>
                        <div v-if="detailsRow.purchased_at_label" class="flex justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ t('customer.purchases.date', 'Data') }}</dt>
                            <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ detailsRow.purchased_at_label }}</dd>
                        </div>
                        <div v-if="!detailsRow.is_manual_grant" class="flex justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ t('customer.purchases.amount', 'Valor') }}</dt>
                            <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ formatBRL(detailsRow.amount) }}</dd>
                        </div>
                        <div v-if="detailsRow.payment_method_label" class="flex justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ t('customer.purchases.payment', 'Pagamento') }}</dt>
                            <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ detailsRow.payment_method_label }}</dd>
                        </div>
                        <div v-if="detailsRow.public_reference" class="flex items-center justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ t('customer.purchases.order', 'Pedido') }}</dt>
                            <dd class="flex items-center gap-2">
                                <span class="font-medium text-zinc-900 dark:text-white">#{{ detailsRow.public_reference }}</span>
                                <button
                                    type="button"
                                    class="rounded-md p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                    :aria-label="copiedKey === 'ref' ? t('customer.purchases.copied', 'Copiado') : t('customer.purchases.copy_order', 'Copiar número do pedido')"
                                    @click="copyValue(detailsRow.public_reference, 'ref')"
                                >
                                    <Check v-if="copiedKey === 'ref'" class="h-4 w-4 text-emerald-600" />
                                    <Copy v-else class="h-4 w-4" />
                                </button>
                            </dd>
                        </div>
                        <div v-if="detailsRow.is_manual_grant" class="flex justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ t('customer.purchases.origin', 'Origem') }}</dt>
                            <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ t('customer.purchases.origin_granted', 'Acesso liberado pelo vendedor') }}</dd>
                        </div>
                        <div v-if="detailsRow.refund_status" class="flex justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ t('customer.purchases.refund_status', 'Reembolso') }}</dt>
                            <dd class="text-right font-medium text-zinc-900 dark:text-white">
                                {{ refundStatusLabel(detailsRow.refund_status) }}
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-if="detailsRow.shipping"
                        class="mt-5 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-950/40"
                    >
                        <p class="inline-flex items-center gap-1.5 text-sm font-medium text-zinc-900 dark:text-white">
                            <Truck class="h-4 w-4" aria-hidden="true" />
                            {{ t('customer.purchases.delivery', 'Entrega') }}
                        </p>
                        <p
                            v-for="(line, idx) in detailsRow.shipping.lines"
                            :key="idx"
                            class="mt-1 text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            {{ line }}
                        </p>
                        <p v-if="detailsRow.shipping.delivery_label" class="mt-2 text-xs text-zinc-500">
                            {{ interpolate(t('customer.purchases.delivery_eta', 'Prazo estimado: {label}'), { label: detailsRow.shipping.delivery_label }) }}
                        </p>
                    </div>

                    <p
                        v-if="accessHint(detailsRow)"
                        class="mt-4 text-sm text-zinc-600 dark:text-zinc-400"
                    >
                        {{ accessHint(detailsRow) }}
                    </p>

                    <div class="mt-5 flex flex-col gap-2">
                        <a
                            v-if="detailsRow.access_url"
                            :href="detailsRow.access_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-[var(--color-primary)] px-3 py-2.5 text-sm font-medium text-white transition hover:opacity-90"
                        >
                            {{ accessCta(detailsRow) }}
                            <ExternalLink class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        </a>
                        <a
                            v-if="detailsRow.support_email"
                            :href="`mailto:${detailsRow.support_email}`"
                            class="inline-flex w-full items-center justify-center gap-1.5 rounded-xl border border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        >
                            <Mail class="h-4 w-4" aria-hidden="true" />
                            {{ t('customer.purchases.contact_seller', 'Falar com o vendedor') }}
                        </a>
                        <Button
                            v-if="detailsRow.can_request_refund && !impersonating"
                            type="button"
                            variant="secondary"
                            class="w-full"
                            @click="openRefund(detailsRow); closeDetails()"
                        >
                            {{ t('customer.purchases.request_refund', 'Solicitar reembolso') }}
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="refundOpen"
                class="fixed inset-0 z-[200000] flex items-end justify-center bg-black/50 p-4 sm:items-center"
                role="dialog"
                aria-modal="true"
                @click.self="closeRefund"
            >
                <div
                    class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
                    @click.stop
                >
                    <div class="flex items-start justify-between gap-4">
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ t('customer.purchases.refund_title', 'Solicitar reembolso') }}</h2>
                        <button
                            type="button"
                            class="rounded-lg p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            :aria-label="t('customer.purchases.close', 'Fechar')"
                            @click="closeRefund"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ interpolate(t('customer.purchases.refund_help', 'Pedido #{ref}. Descreva o motivo; o vendedor será notificado por e-mail.'), { ref: refundOrderPublicRef }) }}
                    </p>
                    <textarea
                        v-model="refundForm.reason"
                        rows="5"
                        class="mt-4 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-950 dark:text-white"
                        :placeholder="t('customer.purchases.refund_placeholder', 'Motivo da solicitação')"
                    />
                    <p v-if="refundForm.errors.reason" class="mt-1 text-xs text-red-600">{{ refundForm.errors.reason }}</p>
                    <p v-if="refundForm.errors.order_id" class="mt-1 text-xs text-red-600">{{ refundForm.errors.order_id }}</p>
                    <div class="mt-4 flex justify-end gap-2">
                        <Button type="button" variant="secondary" @click="closeRefund">{{ t('customer.purchases.cancel', 'Cancelar') }}</Button>
                        <Button type="button" :disabled="refundForm.processing" @click="submitRefund">{{ t('customer.purchases.submit_refund', 'Enviar solicitação') }}</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
