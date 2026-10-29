<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import {
    Smartphone,
    Users,
    BookOpen,
    Link,
    CreditCard,
    ChevronRight,
    X,
    Truck,
} from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import { useI18n } from '@/composables/useI18n';
import { sanitizeHtmlAllowlist } from '@/lib/sanitizeHtml';
import { normalizeMoneyInput } from '@/lib/moneyDecimal';

const props = defineProps({
    open: { type: Boolean, default: false },
    productTypes: { type: Array, default: () => [] },
    billingTypes: { type: Array, default: () => [] },
    productCategories: { type: Array, default: () => [] },
    exchangeRates: { type: Object, default: () => ({ brl_eur: 0.16, brl_usd: 0.18 }) },
    pluginFormSections: { type: Array, default: () => [] },
    checkoutGatewayUi: {
        type: Object,
        default: () => ({
            card_show_installments: false,
            card_installments_gateway_name: '',
            platform_card_installments_enabled: false,
            platform_card_installments_max: 12,
        }),
    },
    bundleCandidates: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'success']);
const { t } = useI18n();
const page = usePage();

const platformMinCharge = computed(() => Number(page.props.platform_minimum_charge_brl ?? 0));
const platformMinChargeLabel = computed(() =>
    platformMinCharge.value > 0
        ? new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(platformMinCharge.value)
        : null
);

const step = ref(1);
const selectedType = ref(null);

const typeIcons = {
    aplicativo: Smartphone,
    area_membros: Users,
    link: Link,
    link_pagamento: CreditCard,
    produto_fisico: Truck,
};

const form = useForm({
    name: '',
    description: '',
    category: '',
    type: '',
    billing_type: 'one_time',
    price: '',
    currency: 'BRL',
    is_active: true,
    image: null,
    deliverable_link: '',
    card_installments: {
        enabled: false,
        max: 2,
    },
    bundle_product_ids: [],
});

const priceNum = computed(() => parseFloat(form.price) || 0);
const priceEur = computed(() => (priceNum.value * (props.exchangeRates.brl_eur ?? 0.16)).toFixed(2));
const priceUsd = computed(() => (priceNum.value * (props.exchangeRates.brl_usd ?? 0.18)).toFixed(2));
const MIN_PARCELA_BRL = 5;
const showCardInstallments = computed(
    () => form.billing_type !== 'subscription' && Boolean(props.checkoutGatewayUi?.card_show_installments)
);
const platformInstallmentMax = computed(() => {
    const fromUi = Number(props.checkoutGatewayUi?.platform_card_installments_max);
    const fromShared = Number(page.props.platform_card_installments?.max);
    const raw = Number.isFinite(fromUi) && fromUi > 0 ? fromUi : fromShared;
    return Math.min(12, Math.max(2, raw || 12));
});
const maxAllowedInstallments = computed(() => {
    if (form.billing_type === 'subscription') return 1;
    const p = priceNum.value;
    if (!p || p < MIN_PARCELA_BRL) return 1;
    const byAmount = Math.min(12, Math.max(1, Math.floor(p / MIN_PARCELA_BRL)));
    return Math.min(byAmount, platformInstallmentMax.value);
});
const installmentMaxOptions = computed(() => {
    const max = maxAllowedInstallments.value;
    if (max < 2) return [];
    const out = [];
    for (let n = 2; n <= max; n++) out.push(n);
    return out;
});

watch(
    () => form.billing_type,
    (type) => {
        if (type === 'subscription') {
            form.card_installments.enabled = false;
            form.card_installments.max = 1;
        }
    }
);

watch(maxAllowedInstallments, (maxAllowed) => {
    if (form.card_installments.max > maxAllowed) {
        form.card_installments.max = Math.max(2, maxAllowed);
    }
});

const availableTypes = computed(() =>
    props.productTypes.filter((t) => t.available)
);

function selectType(type) {
    if (!type.available) return;
    selectedType.value = type.value;
    form.type = type.value;
    step.value = 2;
}

function back() {
    step.value = 1;
    selectedType.value = null;
    form.type = '';
}

function close() {
    step.value = 1;
    selectedType.value = null;
    form.reset();
    emit('close');
}

function submit() {
    const fd = new FormData();
    fd.append('name', form.name);
    fd.append('description', form.description ?? '');
    fd.append('category', form.category ?? '');
    fd.append('type', form.type);
    fd.append('billing_type', form.billing_type);
    fd.append('price', String(normalizeMoneyInput(form.price)));
    fd.append('currency', form.currency);
    fd.append('is_active', form.is_active ? '1' : '0');
    if (form.deliverable_link) {
        fd.append('deliverable_link', form.deliverable_link);
    }
    if (form.image instanceof File) {
        fd.append('image', form.image);
    }
    (form.bundle_product_ids || []).forEach((id) => fd.append('bundle_product_ids[]', String(id)));
    if (showCardInstallments.value && form.card_installments) {
        const enabled = form.billing_type !== 'subscription' && form.card_installments.enabled;
        fd.append('card_installments[enabled]', enabled ? '1' : '0');
        fd.append(
            'card_installments[max]',
            String(enabled ? Math.min(platformInstallmentMax.value, Math.max(2, form.card_installments.max || 2)) : 1)
        );
    }

    form.transform(() => fd).post('/produtos', {
        forceFormData: true,
        onSuccess: () => {
            close();
            emit('success');
        },
    });
}

function onFileChange(e) {
    const file = e.target.files?.[0];
    form.image = file || null;
}

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            step.value = 1;
            selectedType.value = null;
            form.reset();
        } else if (page.props.product_approval_required) {
            form.is_active = false;
        }
    }
);

function safePluginSectionHtml(html) {
    return sanitizeHtmlAllowlist(html, {
        FORBID_TAGS: ['script', 'iframe', 'object', 'embed'],
    });
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[100000] flex justify-end"
            aria-modal="true"
            role="dialog"
            aria-labelledby="sidebar-title"
        >
            <div
                class="fixed inset-0 bg-zinc-900/50 dark:bg-zinc-950/70"
                aria-hidden="true"
                @click="close"
            />
            <aside
                class="relative z-[100001] flex h-full w-full max-w-md flex-col rounded-l-2xl bg-white shadow-xl dark:bg-zinc-900 sm:w-[420px]"
                @click.stop
            >
                <div
                    class="flex shrink-0 items-center justify-between rounded-tl-2xl border-b border-zinc-200 px-4 py-3 dark:border-zinc-800"
                >
                    <h2 id="sidebar-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                        {{ step === 1 ? t('products.create.new_product', 'Novo produto') : t('products.create.create_product', 'Criar produto') }}
                    </h2>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                        :aria-label="t('common.close', 'Fechar')"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-4 py-4">
                    <!-- Step 1: Tipo -->
                    <div v-if="step === 1" class="space-y-3">
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">
                            {{ t('products.create.choose_delivery_type', 'Escolha o tipo de entrega do produto.') }}
                        </p>
                        <div class="grid gap-3">
                            <button
                                v-for="typeOption in availableTypes"
                                :key="typeOption.value"
                                type="button"
                                class="flex items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-left transition hover:border-[var(--color-primary)] hover:bg-[var(--color-primary)]/5 dark:border-zinc-700 dark:bg-zinc-800/50 dark:hover:border-[var(--color-primary)]"
                                @click="selectType(typeOption)"
                            >
                                <span
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white dark:bg-zinc-700"
                                >
                                    <component
                                        :is="typeIcons[typeOption.value] || BookOpen"
                                        class="h-5 w-5 text-zinc-600 dark:text-zinc-300"
                                    />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <span class="font-medium text-zinc-900 dark:text-white">
                                        {{ typeOption.label }}
                                    </span>
                                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ typeOption.description }}
                                    </p>
                                </div>
                                <ChevronRight class="h-5 w-5 shrink-0 text-zinc-400" />
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Formulário -->
                    <form v-else class="space-y-4" @submit.prevent="submit">
                        <p
                            v-if="form.errors.image || (form.hasErrors && !form.errors.name && !form.errors.price)"
                            class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300"
                        >
                            {{ form.errors.image || Object.values(form.errors)[0] }}
                        </p>
                        <div>
                            <button
                                type="button"
                                class="mb-2 text-sm text-[var(--color-primary)] hover:underline"
                                @click="back"
                            >
                                {{ t('products.create.back_to_type', '← Voltar ao tipo') }}
                            </button>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                {{ t('common.name', 'Nome') }} *
                            </label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-zinc-900 placeholder-zinc-400 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                                :placeholder="t('products.create.name_placeholder', 'Ex: Curso de Desenvolvimento Web')"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.name }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                {{ t('products.create.billing_type', 'Tipo de cobrança') }}
                            </label>
                            <div class="mt-1.5 flex gap-2">
                                <button
                                    v-for="bt in billingTypes"
                                    :key="bt.value"
                                    type="button"
                                    :class="[
                                        'flex-1 rounded-lg border px-3 py-2.5 text-sm font-medium transition',
                                        form.billing_type === bt.value
                                            ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-[var(--color-primary)] dark:bg-[var(--color-primary)]/20'
                                            : 'border-zinc-300 bg-white text-zinc-600 hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700',
                                    ]"
                                    @click="form.billing_type = bt.value"
                                >
                                    {{ bt.label }}
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                {{ t('common.description', 'Descrição') }}
                            </label>
                            <textarea
                                v-model="form.description"
                                rows="3"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-zinc-900 placeholder-zinc-400 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                                :placeholder="t('products.create.description_placeholder', 'Breve descrição do produto')"
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                {{ t('common.category', 'Categoria') }} *
                            </label>
                            <select
                                v-model="form.category"
                                required
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-zinc-900 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            >
                                <option value="" disabled>
                                    {{ t('products.create.category_placeholder', 'Selecione a categoria') }}
                                </option>
                                <option
                                    v-for="cat in productCategories"
                                    :key="cat.value"
                                    :value="cat.value"
                                >
                                    {{ cat.label }}
                                </option>
                            </select>
                            <p v-if="form.errors.category" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.category }}
                            </p>
                        </div>
                        <div v-if="form.type === 'link'">
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                {{ t('products.create.deliverable_link', 'Link do entregável') }}
                            </label>
                            <input
                                v-model="form.deliverable_link"
                                type="url"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-zinc-900 placeholder-zinc-400 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                                placeholder="https://..."
                            />
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ t('products.create.deliverable_link_hint', 'Enviado por e-mail após a compra.') }}
                            </p>
                            <p v-if="form.errors.deliverable_link" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.deliverable_link }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                {{ t('products.create.price_brl', 'Preço (BRL)') }} *
                            </label>
                            <input
                                v-model="form.price"
                                type="number"
                                step="any"
                                :min="platformMinCharge"
                                inputmode="decimal"
                                required
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-zinc-900 placeholder-zinc-400 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                                placeholder="0,00"
                            />
                            <p v-if="platformMinChargeLabel" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                Ticket mínimo da plataforma: {{ platformMinChargeLabel }}
                            </p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                ≈ € {{ priceEur }} · $ {{ priceUsd }}
                            </p>
                            <p v-if="form.errors.price" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.price }}
                            </p>
                        </div>
                        <div
                            v-if="showCardInstallments"
                            class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                        Permitir pagamento parcelado neste produto
                                    </p>
                                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                        Produto vendido à vista se estiver desmarcado
                                    </p>
                                </div>
                                <Toggle
                                    v-model="form.card_installments.enabled"
                                    :disabled="installmentMaxOptions.length === 0"
                                    class="shrink-0"
                                />
                            </div>
                            <div v-if="form.card_installments.enabled" class="space-y-3">
                                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                                    <p>As vendas parceladas seguem o cronograma de cobrança das parcelas do comprador.</p>
                                    <p class="mt-1.5">O recebimento e a liberação dos valores ocorrerão conforme o processamento das parcelas pagas.</p>
                                    <p class="mt-1.5">Quanto maior a quantidade de parcelas escolhida pelo cliente, maior será o período total de recebimento da venda.</p>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                        Máximo de parcelas para este produto
                                    </label>
                                    <select
                                        v-model.number="form.card_installments.max"
                                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                    >
                                        <option v-for="n in installmentMaxOptions" :key="'create-inst-' + n" :value="n">
                                            {{ n }}x
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div v-if="form.type !== 'produto_fisico' && bundleCandidates.length" class="space-y-2">
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                {{ t('products.edit.bundle_title', 'Entregar também') }}
                            </label>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ t('products.edit.bundle_hint', 'O cliente paga só o preço deste produto e recebe acesso aos marcados. Este produto pode ter área de membros própria.') }}
                            </p>
                            <label
                                v-for="candidate in bundleCandidates"
                                :key="candidate.id"
                                class="flex cursor-pointer items-start gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700"
                            >
                                <input
                                    type="checkbox"
                                    class="mt-0.5 h-4 w-4 rounded border-zinc-300"
                                    :value="String(candidate.id)"
                                    v-model="form.bundle_product_ids"
                                />
                                <span class="text-sm text-zinc-800 dark:text-zinc-200">{{ candidate.name }}</span>
                            </label>
                            <p v-if="form.errors.bundle_product_ids" class="text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.bundle_product_ids }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                {{ t('common.image', 'Imagem') }}
                            </label>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ t('products.create.image_hint', 'Exibida em formato quadrado (1:1). Recomendado enviar imagem quadrada.') }}
                            </p>
                            <input
                                type="file"
                                accept="image/*"
                                class="mt-1 block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-[var(--color-primary)] file:px-4 file:py-2 file:text-white dark:text-zinc-400"
                                @change="onFileChange"
                            />
                            <p v-if="form.image" class="mt-1 text-sm text-zinc-500">
                                {{ form.image.name }}
                            </p>
                            <p v-if="form.errors.image" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.image }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <Toggle
                                v-model="form.is_active"
                                :label="t('products.create.active_product', 'Produto ativo')"
                                :disabled="page.props.product_approval_required === true"
                            />
                        </div>
                        <p
                            v-if="page.props.product_approval_required"
                            class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100"
                        >
                            <span class="font-semibold">Entra em análise da plataforma.</span>
                            Você poderá editar normalmente; o checkout
                            <code class="text-[11px]">/c/…</code>
                            só fica online após a aprovação do admin (aí o produto é ativado automaticamente).
                        </p>
                        <!-- Área para plugins -->
                        <div v-if="pluginFormSections?.length" class="space-y-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <template v-for="(section, idx) in pluginFormSections" :key="idx">
                                <div v-if="section.html" v-html="safePluginSectionHtml(section.html)" />
                                <div v-else-if="section.slot" class="text-sm text-zinc-500">
                                    {{ section.slot }}
                                </div>
                            </template>
                        </div>
                        <div class="flex gap-2 pt-2">
                            <Button type="submit" :disabled="form.processing">
                                {{ t('products.create.create_product', 'Criar produto') }}
                            </Button>
                            <Button type="button" variant="outline" @click="close">
                                {{ t('common.cancel', 'Cancelar') }}
                            </Button>
                        </div>
                    </form>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
