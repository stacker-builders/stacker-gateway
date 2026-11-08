<script setup>
import { ref, computed } from 'vue';
import { X, ExternalLink } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    venda: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const activeTab = ref('venda');

function trackingValue(key) {
    const v = props.venda;
    if (!v) return '';
    const fromSession = v.checkout_session?.[key];
    const fromMeta = v.metadata?.[key];
    return String(fromSession ?? fromMeta ?? '').trim();
}

const utmRows = computed(() => {
    const keys = [
        ['utm_source', 'utm_source'],
        ['utm_medium', 'utm_medium'],
        ['utm_campaign', 'utm_campaign'],
        ['utm_content', 'utm_content'],
        ['utm_term', 'utm_term'],
        ['sck', 'sck'],
        ['src', 'src'],
    ];
    return keys.map(([key, label]) => ({
        key,
        label,
        value: trackingValue(key),
    }));
});

function close() {
    emit('close');
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

function formatDate(value) {
    if (!value) return '–';
    const d = new Date(value);
    return d.toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function statusLabel(status) {
    if (props.venda?.status_label) {
        return props.venda.status_label;
    }
    if (status === 'refunded' && props.venda?.manual_refund?.offline) {
        return 'Reembolso manual';
    }
    const map = {
        completed: 'Pago',
        pending: 'Pendente',
        disputed: 'MED',
        cancelled: 'Cancelado',
        refund_pending: 'Aguardando reembolso',
        refunded: 'Reembolsado',
    };
    return map[status] ?? status ?? '–';
}

function refundAuthorLabel(manualRefund) {
    if (!manualRefund) return '—';
    if (manualRefund.initiated_by === 'platform') {
        return 'Plataforma';
    }
    if (manualRefund.initiated_by === 'seller') {
        return 'Você';
    }
    return manualRefund.initiated_by_label ?? manualRefund.initiated_by_name ?? '—';
}

function itemLabel(item) {
    const isBump = Number(item?.position ?? 0) > 0;
    const baseName =
        item?.product?.name ??
        item?.product_offer?.name ??
        item?.subscription_plan?.name ??
        'Item';
    return isBump ? `${baseName} (Bump)` : baseName;
}

const hasShipping = computed(() => {
    const v = props.venda;
    if (!v) return false;
    return Number(v.shipping_amount ?? 0) > 0 || (v.shipping_address && Object.keys(v.shipping_address).length > 0);
});

const shippingAddressLines = computed(() => {
    const addr = props.venda?.shipping_address;
    if (!addr || typeof addr !== 'object') return [];
    const lines = [];
    if (addr.street) {
        let line = addr.street;
        if (addr.number) line += `, ${addr.number}`;
        lines.push(line);
    }
    if (addr.complement) lines.push(addr.complement);
    if (addr.neighborhood) lines.push(addr.neighborhood);
    if (addr.city || addr.state) {
        lines.push([addr.city, addr.state].filter(Boolean).join(' — '));
    }
    if (addr.zip) lines.push(`CEP ${addr.zip}`);
    return lines;
});

const shippingDeliveryLabel = computed(() => {
    const meta = props.venda?.metadata ?? {};
    const min = meta.delivery_days_min;
    const max = meta.delivery_days_max;
    if (min == null) return '';
    if (max != null && max !== min) return `${min}–${max} dias úteis`;
    return `${min} dias úteis`;
});
</script>

<template>
    <Teleport to="body">
        <div
            v-show="open"
            class="fixed inset-0 z-[100000] flex justify-end"
            aria-modal="true"
            role="dialog"
        >
            <div
                class="fixed inset-0 bg-zinc-900/50 dark:bg-zinc-950/60"
                aria-hidden="true"
                @click="close"
            />
            <aside
                class="relative flex h-full w-full max-w-md flex-col rounded-l-2xl bg-white shadow-2xl dark:bg-zinc-900"
            >
                <div class="flex items-center justify-between rounded-tl-2xl px-5 py-5">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Detalhes da venda
                    </h2>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                        aria-label="Fechar"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div v-if="!venda" class="flex flex-1 items-center justify-center p-8">
                    <p class="text-sm text-zinc-500">Nenhuma venda selecionada.</p>
                </div>

                <div v-else class="flex flex-1 flex-col overflow-hidden">
                    <nav
                        class="flex gap-1 bg-zinc-50/80 px-4 py-2 dark:bg-zinc-800/50"
                        aria-label="Abas"
                    >
                        <button
                            type="button"
                            :class="[
                                'rounded-lg px-4 py-2.5 text-sm font-medium transition-colors',
                                activeTab === 'venda'
                                    ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-800 dark:text-[var(--color-primary)]'
                                    : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200',
                            ]"
                            @click="activeTab = 'venda'"
                        >
                            Venda
                        </button>
                        <button
                            type="button"
                            :class="[
                                'rounded-lg px-4 py-2.5 text-sm font-medium transition-colors',
                                activeTab === 'cliente'
                                    ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-800 dark:text-[var(--color-primary)]'
                                    : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200',
                            ]"
                            @click="activeTab = 'cliente'"
                        >
                            Cliente
                        </button>
                    </nav>

                    <div class="flex-1 overflow-y-auto p-5">
                        <!-- Aba Venda -->
                        <div v-show="activeTab === 'venda'" class="space-y-5">
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">ID da venda</p>
                                <p class="font-mono text-sm text-zinc-700 dark:text-zinc-300">{{ String(venda.id) }}</p>
                            </div>
                            <div
                                v-if="venda.tenant_owner && (venda.tenant_owner.name || venda.tenant_owner.email)"
                                class="space-y-1 rounded-xl border border-zinc-200 bg-zinc-50/80 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800/50"
                            >
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Infoprodutor</p>
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ venda.tenant_owner.name ?? '—' }}
                                </p>
                                <p class="text-xs text-zinc-500">{{ venda.tenant_owner.email ?? '—' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Status</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ statusLabel(venda.status) }}</p>
                            </div>
                            <div
                                v-if="venda.status === 'refunded' && venda.manual_refund"
                                class="space-y-2 rounded-xl border border-red-200 bg-red-50/80 px-3 py-3 dark:border-red-900/50 dark:bg-red-950/30"
                            >
                                <p class="text-xs font-medium uppercase tracking-wide text-red-800 dark:text-red-200">
                                    {{ venda.manual_refund.offline ? 'Reembolso manual' : 'Reembolso' }}
                                </p>
                                <p class="text-sm text-red-900 dark:text-red-100">
                                    Por: <strong>{{ refundAuthorLabel(venda.manual_refund) }}</strong>
                                    <span v-if="venda.manual_refund.initiated_by_name">
                                        ({{ venda.manual_refund.initiated_by_name }})
                                    </span>
                                </p>
                                <p v-if="venda.manual_refund.refunded_at" class="text-xs text-red-800 dark:text-red-200">
                                    Em {{ formatDate(venda.manual_refund.refunded_at) }}
                                </p>
                                <p v-if="venda.manual_refund.reason" class="text-sm text-red-900 dark:text-red-100">
                                    <span class="font-medium">Motivo:</span> {{ venda.manual_refund.reason }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tipo</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.payment_type_label ?? 'Pagamento único' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Valor bruto</p>
                                <p class="text-sm text-zinc-900 dark:text-white">
                                    {{ formatBRL(venda.amount_gross ?? venda.amount_total ?? venda.amount) }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Taxas</p>
                                <p class="text-sm text-zinc-900 dark:text-white">
                                    {{ formatBRL(venda.amount_fee ?? 0) }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Valor líquido</p>
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ formatBRL(venda.amount_net ?? venda.amount_total ?? venda.amount) }}
                                </p>
                            </div>
                            <div
                                v-if="venda.is_affiliate_sale"
                                class="space-y-2 rounded-xl border border-violet-200 bg-violet-50/70 px-3 py-3 dark:border-violet-900/50 dark:bg-violet-950/30"
                            >
                                <p class="text-xs font-medium uppercase tracking-wide text-violet-800 dark:text-violet-200">Afiliado</p>
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ venda.affiliate_name ?? '—' }}</p>
                                <p class="text-sm text-zinc-700 dark:text-zinc-300">
                                    Comissão: {{ formatBRL(venda.affiliate_commission_gross ?? 0) }}
                                    <span v-if="venda.affiliate_commission_percent != null">({{ venda.affiliate_commission_percent }}%)</span>
                                </p>
                            </div>
                            <div v-if="venda.sale_origin_label" class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Origem da venda</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.sale_origin_label }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Produto</p>
                                <p class="text-sm text-zinc-900 dark:text-white">
                                    {{ venda.product_display_name ?? venda.product?.name ?? '–' }}
                                    <span
                                        v-if="venda.is_pixgo"
                                        class="ml-1 inline-flex rounded-full bg-lime-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-lime-900 dark:bg-lime-900/40 dark:text-lime-200"
                                    >{{ venda.sale_channel_label || 'PixGO' }}</span>
                                </p>
                            </div>
                            <div v-if="venda.product_type_label" class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tipo</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.product_type_label }}</p>
                            </div>
                            <div
                                v-if="hasShipping"
                                class="space-y-2 rounded-xl border border-emerald-200 bg-emerald-50/60 px-3 py-3 dark:border-emerald-900 dark:bg-emerald-950/30"
                            >
                                <p class="text-xs font-medium uppercase tracking-wide text-emerald-800 dark:text-emerald-300">Entrega</p>
                                <p v-if="Number(venda.shipping_amount) > 0" class="text-sm text-zinc-900 dark:text-white">
                                    Frete: {{ formatBRL(venda.shipping_amount) }}
                                </p>
                                <p v-else class="text-sm text-zinc-900 dark:text-white">Frete grátis</p>
                                <p v-if="shippingDeliveryLabel" class="text-xs text-zinc-600 dark:text-zinc-400">
                                    Prazo estimado: {{ shippingDeliveryLabel }}
                                </p>
                                <p v-if="venda.metadata?.shipping_label" class="text-xs text-zinc-500">
                                    Regra: {{ venda.metadata.shipping_label }}
                                </p>
                                <div v-if="shippingAddressLines.length" class="text-sm text-zinc-700 dark:text-zinc-300">
                                    <p v-for="(line, i) in shippingAddressLines" :key="i">{{ line }}</p>
                                </div>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Método de pagamento</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.gateway_label ?? '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Recebedor</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.recebedor || '—' }}</p>
                                <p
                                    v-if="venda.cajupay_account_badge"
                                    class="mt-1 inline-flex rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-900 dark:bg-sky-900/40 dark:text-sky-100"
                                >
                                    {{ venda.cajupay_account_badge }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Parcelas</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.installments > 1 ? venda.installments + 'x' : '1x' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Recorrência</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.subscription_plan_id ? 'Assinatura' : '–' }}</p>
                            </div>
                            <div class="space-y-2" v-if="(venda.order_items ?? []).length">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Itens da compra</p>
                                <div class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
                                    <div
                                        v-for="(item, idx) in (venda.order_items ?? [])"
                                        :key="idx"
                                        class="flex items-center justify-between gap-3 px-4 py-3"
                                    >
                                        <p class="text-sm text-zinc-900 dark:text-white">
                                            {{ itemLabel(item) }}
                                        </p>
                                        <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                            {{ formatBRL(item.amount) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">URL do Checkout</p>
                                <a
                                    v-if="venda.checkout_url"
                                    :href="venda.checkout_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 text-sm text-[var(--color-primary)] hover:underline"
                                >
                                    {{ venda.checkout_url }}
                                    <ExternalLink class="h-3.5 w-3.5 shrink-0" />
                                </a>
                                <p v-else class="text-sm text-zinc-500">–</p>
                            </div>
                            <div v-if="venda.partner_checkout_url || venda.metadata?.partner_checkout_url" class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Link checkout parceiro (API)</p>
                                <a
                                    :href="venda.partner_checkout_url || venda.metadata?.partner_checkout_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 break-all text-sm text-[var(--color-primary)] hover:underline"
                                >
                                    {{ venda.partner_checkout_url || venda.metadata?.partner_checkout_url }}
                                    <ExternalLink class="h-3.5 w-3.5 shrink-0" />
                                </a>
                            </div>
                            <div
                                v-for="row in utmRows"
                                :key="row.key"
                                class="space-y-1"
                            >
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ row.label }}</p>
                                <p class="text-sm break-all" :class="row.value ? 'text-zinc-900 dark:text-white' : 'text-zinc-500'">
                                    {{ row.value || 'Não informado' }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Data de criação</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ formatDate(venda.created_at) }}</p>
                            </div>
                        </div>

                        <!-- Aba Cliente -->
                        <div v-show="activeTab === 'cliente'" class="space-y-5">
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Nome</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.user?.name ?? venda.email ?? '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">E-mail</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.email ?? venda.user?.email ?? '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Celular</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.phone ?? '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">CPF</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.cpf ?? '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">IP</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.customer_ip ?? '–' }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
