<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2, CreditCard, Loader2, QrCode, Barcode } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import {
    PAGARME_TOKENIZE_FORM_ACTION,
    loadPagarmeTokenizeScript,
    ensurePagarmeCheckoutInit,
    requestPagarmeTokenFromForm,
    resetPagarmeTokenizeScriptState,
} from '@/composables/usePagarmeTokenizecard.js';
import { requestCieloPaymentToken, formatCieloSopHolderName } from '@/composables/useCieloSilentOrderPost.js';
import { encryptPagBankCard } from '@/composables/usePagBankEncryptCard.js';

const RENEWAL_PAGARME_TOKENIZE_FORM_ID = 'renewal-pagarme-tokenize-form';

defineOptions({ layout: null });

const props = defineProps({
    token: { type: String, required: true },
    subscription: { type: Object, required: true },
    product: { type: Object, required: true },
    plan: { type: Object, required: true },
    amount: { type: Number, required: true },
    amount_brl: { type: Number, required: true },
    available_payment_methods: { type: Array, default: () => [] },
    saved_payment_methods: { type: Array, default: () => [] },
    card_gateway_slug: { type: String, default: null },
    card_payee_code: { type: String, default: '' },
    card_efi_sandbox: { type: Boolean, default: false },
    card_stripe_publishable_key: { type: String, default: '' },
    card_stripe_sandbox: { type: Boolean, default: false },
    card_stripe_link_enabled: { type: Boolean, default: true },
    card_mercadopago_public_key: { type: String, default: '' },
    card_mercadopago_sandbox: { type: Boolean, default: false },
    card_pagarme_public_key: { type: String, default: '' },
    card_pagarme_api_base_url: { type: String, default: 'https://api.pagar.me/core/v5' },
    card_pagbank_public_key: { type: String, default: '' },
    card_installments_enabled: { type: Boolean, default: false },
    card_max_installments: { type: Number, default: 1 },
    customer_cpf: { type: String, default: '' },
});

const page = usePage();
const flashError = computed(() => page.props.flash?.error ?? null);
const flashSuccess = computed(() => page.props.flash?.success ?? null);
const flashInfo = computed(() => page.props.flash?.info ?? null);

const form = useForm({
    token: props.token,
    payment_method: 'manual',
    payment_token: '',
    card_mask: '',
});

const methods = computed(() => {
    const list = props.available_payment_methods || [];
    if (list.length === 0) {
        return [{ id: 'manual', label: 'Outro (instruções por e-mail)' }];
    }
    return list.map((m) => ({ id: m.id, label: m.label }));
});

const amountFormatted = computed(() => {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(props.amount_brl);
});

const canPayWithStripe = computed(() => props.card_gateway_slug === 'stripe' && (props.card_stripe_publishable_key || '').trim() !== '');
const canPayWithEfi = computed(() => props.card_gateway_slug === 'efi' && (props.card_payee_code || '').trim() !== '');
const canPayWithPagarme = computed(() => props.card_gateway_slug === 'pagarme' && (props.card_pagarme_public_key || '').trim() !== '');
const canPayWithPagbank = computed(() => props.card_gateway_slug === 'pagbank' && (props.card_pagbank_public_key || '').trim() !== '');
const canPayWithMercadopago = computed(() => props.card_gateway_slug === 'mercadopago' && (props.card_mercadopago_public_key || '').trim() !== '');
const canPayWithCielo = computed(() => props.card_gateway_slug === 'cielo' && props.product?.id != null && String(props.product.id) !== '');
const canPayWithCard = computed(() => methods.value.some((m) => m.id === 'card') && (canPayWithStripe.value || canPayWithEfi.value || canPayWithPagarme.value || canPayWithPagbank.value || canPayWithMercadopago.value || canPayWithCielo.value));
const cieloExpirationDate = computed(() => {
    const digits = (efiCardExp.value || '').replace(/\D/g, '');
    const month = digits.slice(0, 2);
    let year = digits.slice(2);
    if (year.length === 2) {
        year = `20${year}`;
    }
    if (month.length !== 2 || year.length !== 4) {
        return '';
    }
    return `${month}/${year}`;
});

const stripeCardRef = ref(null);
const stripeInstance = ref(null);
const stripeCardElement = ref(null);
const cardHolderName = ref('');
const efiCardNumber = ref('');
const efiCardExp = ref('');
const efiCardCvv = ref('');
const cardSubmitting = ref(false);
const cardError = ref('');

const pagarmeTokenizeFormId = RENEWAL_PAGARME_TOKENIZE_FORM_ID;
const efiCardNumberDigits = computed(() => (efiCardNumber.value || '').replace(/\D/g, '').slice(0, 19));
const pagarmeTokenizeExpMonthHidden = computed(() => {
    const d = (efiCardExp.value || '').replace(/\D/g, '');
    return d.slice(0, 2);
});
const pagarmeTokenizeExpYearHidden = computed(() => {
    const d = (efiCardExp.value || '').replace(/\D/g, '');
    return d.slice(2, 6);
});

watch(
    () => (props.card_pagarme_public_key || '').trim(),
    (next, prev) => {
        if (prev && next !== prev) {
            resetPagarmeTokenizeScriptState();
        }
    }
);

watch(
    () => form.payment_method,
    async (m) => {
        if (m === 'card' && canPayWithStripe.value) {
            await nextTick();
            setTimeout(() => initStripeCard(), 80);
        } else {
            destroyStripeCard();
        }
    }
);

onMounted(() => {
    if (form.payment_method === 'card' && canPayWithStripe.value) {
        setTimeout(() => initStripeCard(), 120);
    }
});
onBeforeUnmount(() => destroyStripeCard());

async function initStripeCard() {
    if (!props.card_stripe_publishable_key?.trim() || !stripeCardRef.value) return;
    destroyStripeCard();
    try {
        const { loadStripe } = await import('@stripe/stripe-js');
        const stripe = await loadStripe(props.card_stripe_publishable_key.trim());
        if (!stripe) return;
        stripeInstance.value = stripe;
        const elements = stripe.elements();
        const cardElement = elements.create('card', {
            style: { base: { fontSize: '16px', color: '#1f2937' } },
            hidePostalCode: true,
            disableLink: !props.card_stripe_link_enabled,
        });
        cardElement.mount(stripeCardRef.value);
        stripeCardElement.value = cardElement;
    } catch (e) {
        console.warn('Stripe init failed', e);
    }
}

function destroyStripeCard() {
    if (stripeCardElement.value && stripeCardRef.value) {
        try {
            stripeCardElement.value.unmount();
        } catch (_) {}
        stripeCardElement.value = null;
    }
    stripeInstance.value = null;
}

function sanitizePagarmeHolderName(raw) {
    const s = String(raw || '')
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .replace(/[^a-zA-Z\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    return s.length >= 2 ? s : 'Cliente';
}

async function tokenizeAndSubmitCard() {
    cardError.value = '';
    const name = (cardHolderName.value || '').trim();
    if (!name) {
        cardError.value = 'Informe o nome impresso no cartão.';
        return;
    }
    cardSubmitting.value = true;
    try {
        if (canPayWithStripe.value) {
            if (!stripeInstance.value || !stripeCardElement.value) {
                cardError.value = 'Aguarde o formulário do cartão carregar.';
                cardSubmitting.value = false;
                return;
            }
            const { error: stripeError, paymentMethod } = await stripeInstance.value.createPaymentMethod({
                type: 'card',
                card: stripeCardElement.value,
                billing_details: { name },
            });
            if (stripeError) {
                cardError.value = stripeError.message || 'Erro ao processar o cartão.';
                cardSubmitting.value = false;
                return;
            }
            form.payment_token = paymentMethod.id;
            form.card_mask = paymentMethod.card?.last4 ? `**** ${paymentMethod.card.last4}` : '';
        } else if (canPayWithPagarme.value) {
            const numberDigits = efiCardNumberDigits.value;
            const expDigits = (efiCardExp.value || '').replace(/\D/g, '');
            const month = expDigits.slice(0, 2);
            let yearRaw = expDigits.slice(2);
            if (yearRaw.length === 2) yearRaw = `20${yearRaw}`;
            const cvv = (efiCardCvv.value || '').replace(/\D/g, '').slice(0, 4);
            const monthNum = parseInt(month, 10);
            const expYearNum = parseInt(yearRaw, 10);
            if (month.length !== 2 || yearRaw.length !== 4 || !Number.isFinite(monthNum) || monthNum < 1 || monthNum > 12 || !Number.isFinite(expYearNum)) {
                cardError.value = 'Informe a validade no formato MM/AAAA.';
                cardSubmitting.value = false;
                return;
            }
            if (numberDigits.length < 13 || numberDigits.length > 19 || cvv.length < 3) {
                cardError.value = 'Preencha todos os dados do cartão corretamente.';
                cardSubmitting.value = false;
                return;
            }
            const pk = (props.card_pagarme_public_key || '').trim();
            const holderName = sanitizePagarmeHolderName(name);
            let tokenId;
            await nextTick();
            try {
                await loadPagarmeTokenizeScript(pk);
                ensurePagarmeCheckoutInit();
                const { token } = await requestPagarmeTokenFromForm(pagarmeTokenizeFormId);
                tokenId = token;
            } catch {
                const base = String(props.card_pagarme_api_base_url || 'https://api.pagar.me/core/v5').replace(/\/$/, '');
                const url = `${base}/tokens?appId=${encodeURIComponent(pk)}`;
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({
                        type: 'card',
                        card: {
                            number: numberDigits,
                            holder_name: holderName,
                            exp_month: monthNum,
                            exp_year: expYearNum,
                            cvv: String(cvv),
                        },
                    }),
                });
                let data = {};
                try {
                    data = await res.json();
                } catch {
                    data = {};
                }
                if (!res.ok) {
                    cardError.value = typeof data?.message === 'string' ? data.message : 'Não foi possível tokenizar o cartão.';
                    cardSubmitting.value = false;
                    return;
                }
                tokenId = data?.id;
                if (!tokenId || typeof tokenId !== 'string') {
                    cardError.value = 'Resposta inválida da Pagar.me.';
                    cardSubmitting.value = false;
                    return;
                }
            }
            const last4 = numberDigits.slice(-4);
            form.payment_token = JSON.stringify({ card_token: tokenId, installments: 1 });
            form.card_mask = last4 ? `**** ${last4}` : '';
        } else if (canPayWithPagbank.value) {
            const numberDigits = (efiCardNumber.value || '').replace(/\D/g, '');
            const expDigits = (efiCardExp.value || '').replace(/\D/g, '');
            const month = expDigits.slice(0, 2);
            let year = expDigits.slice(2);
            if (year.length === 2) year = `20${year}`;
            const cvv = (efiCardCvv.value || '').replace(/\D/g, '').slice(0, 4);
            if (numberDigits.length < 13 || numberDigits.length > 19 || month.length !== 2 || year.length !== 4 || cvv.length < 3) {
                cardError.value = 'Preencha todos os dados do cartão corretamente.';
                cardSubmitting.value = false;
                return;
            }
            const encrypted = await encryptPagBankCard({
                publicKey: props.card_pagbank_public_key,
                holder: name,
                number: numberDigits,
                expMonth: month,
                expYear: year,
                securityCode: cvv,
            });
            const last4 = numberDigits.slice(-4);
            form.payment_token = JSON.stringify({ encrypted_card: encrypted, installments: 1 });
            form.card_mask = last4 ? `**** ${last4}` : '';
        } else if (canPayWithCielo.value) {
            const numberDigits = (efiCardNumber.value || '').replace(/\D/g, '');
            const expDigits = (efiCardExp.value || '').replace(/\D/g, '');
            const month = expDigits.slice(0, 2);
            let year = expDigits.slice(2);
            if (year.length === 2) year = `20${year}`;
            const cvv = (efiCardCvv.value || '').replace(/\D/g, '').slice(0, 4);
            if (numberDigits.length < 13 || numberDigits.length > 19 || month.length !== 2 || year.length !== 4 || cvv.length < 3) {
                cardError.value = 'Preencha todos os dados do cartão corretamente.';
                cardSubmitting.value = false;
                return;
            }
            await nextTick();
            const { payment_token, card_mask } = await requestCieloPaymentToken({
                productId: props.product.id,
                installments: 1,
            });
            const last4 = numberDigits.slice(-4);
            form.payment_token = payment_token;
            form.card_mask = card_mask || (last4 ? `**** ${last4}` : '');
        } else if (canPayWithEfi.value) {
            const numberDigits = (efiCardNumber.value || '').replace(/\D/g, '');
            const expDigits = (efiCardExp.value || '').replace(/\D/g, '');
            const month = expDigits.slice(0, 2);
            const yearRaw = expDigits.slice(2);
            const year = yearRaw.length === 2 ? `20${yearRaw}` : yearRaw;
            const cvv = (efiCardCvv.value || '').replace(/\D/g, '').slice(0, 4);
            if (numberDigits.length < 13 || numberDigits.length > 16 || month.length !== 2 || year.length !== 4 || cvv.length < 3) {
                cardError.value = 'Preencha todos os dados do cartão corretamente.';
                cardSubmitting.value = false;
                return;
            }
            const EfiPay = (await import('payment-token-efi')).default;
            const env = props.card_efi_sandbox ? 'sandbox' : 'production';
            const instance = EfiPay.CreditCard.setAccount((props.card_payee_code || '').trim()).setEnvironment(env);
            instance.setCardNumber(numberDigits);
            const brand = await instance.verifyCardBrand();
            if (!brand || brand === 'unsupported') {
                cardError.value = 'Bandeira do cartão não suportada.';
                cardSubmitting.value = false;
                return;
            }
            instance.setCreditCardData({
                brand,
                number: numberDigits,
                cvv,
                expirationMonth: month,
                expirationYear: year,
                reuse: false,
                holderName: name || undefined,
                holderDocument: (props.customer_cpf || '').replace(/\D/g, '') || undefined,
            });
            const result = await instance.getPaymentToken();
            const paymentToken = result?.payment_token;
            if (!paymentToken) {
                cardError.value = 'Não foi possível gerar o token do cartão.';
                cardSubmitting.value = false;
                return;
            }
            const last4 = numberDigits.slice(-4);
            form.payment_token = paymentToken;
            form.card_mask = result?.card_mask || (last4 ? `**** ${last4}` : '');
        } else if (canPayWithMercadopago.value) {
            cardError.value = 'Renovação com Mercado Pago: use PIX ou boleto nesta página.';
            cardSubmitting.value = false;
            return;
        } else {
            cardError.value = 'Cartão não disponível para renovação neste momento.';
            cardSubmitting.value = false;
            return;
        }

        form.payment_method = 'card';
        form.post('/renovar', {
            preserveScroll: true,
            onFinish: () => {
                cardSubmitting.value = false;
            },
        });
    } catch (e) {
        cardError.value = e?.message || 'Erro ao processar o cartão.';
        cardSubmitting.value = false;
    }
}

function submitRenewal() {
    cardError.value = '';
    if (form.payment_method === 'card') {
        tokenizeAndSubmitCard();
        return;
    }
    const method = methods.value.find((m) => m.id === form.payment_method);
    form.payment_method = method ? method.id : 'manual';
    form.payment_token = '';
    form.card_mask = '';
    form.post('/renovar', { preserveScroll: true });
}
</script>

<template>
    <Head>
        <title>Renovar assinatura – {{ product.name }}</title>
    </Head>
    <div class="min-h-screen bg-zinc-100 dark:bg-zinc-900">
        <div class="mx-auto max-w-md px-4 py-12 sm:px-6">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-8">
                <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Renovar assinatura</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ product.name }} · {{ plan.name }}</p>

                <div v-if="flashError" class="mt-4 flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200" role="alert">
                    <AlertCircle class="h-5 w-5 shrink-0" />
                    {{ flashError }}
                </div>
                <div v-if="flashSuccess" class="mt-4 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200" role="status">
                    <CheckCircle2 class="h-5 w-5 shrink-0" />
                    {{ flashSuccess }}
                </div>
                <div v-if="flashInfo" class="mt-4 flex items-center gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800 dark:border-sky-800 dark:bg-sky-950/30 dark:text-sky-200" role="status">
                    <CheckCircle2 class="h-5 w-5 shrink-0" />
                    {{ flashInfo }}
                </div>
                <div v-if="cardError" class="mt-4 flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100" role="alert">
                    <AlertCircle class="h-5 w-5 shrink-0" />
                    {{ cardError }}
                </div>

                <div class="mt-6 rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/50">
                    <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ amountFormatted }}</p>
                    <p v-if="subscription.current_period_end" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Próxima renovação: {{ subscription.current_period_end }}
                    </p>
                </div>

                <form class="mt-6 space-y-4" @submit.prevent="submitRenewal">
                    <input v-model="form.token" type="hidden" name="token" />
                    <div>
                        <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Forma de pagamento</label>
                        <div class="space-y-2">
                            <label
                                v-for="m in methods"
                                :key="m.id"
                                class="flex cursor-pointer items-center gap-3 rounded-xl border-2 px-4 py-3 transition"
                                :class="form.payment_method === m.id
                                    ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5 dark:bg-[var(--color-primary)]/10'
                                    : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 dark:hover:border-zinc-500'"
                            >
                                <input v-model="form.payment_method" type="radio" :value="m.id" class="h-4 w-4 border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
                                <QrCode v-if="m.id === 'pix' || m.id === 'pix_auto'" class="h-5 w-5 text-zinc-500 dark:text-zinc-400" />
                                <Barcode v-else-if="m.id === 'boleto'" class="h-5 w-5 text-zinc-500 dark:text-zinc-400" />
                                <CreditCard v-else class="h-5 w-5 text-zinc-500 dark:text-zinc-400" />
                                <span class="font-medium text-zinc-900 dark:text-white">{{ m.label }}</span>
                            </label>
                        </div>
                    </div>

                    <div v-if="form.payment_method === 'card' && canPayWithCard" class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-900/40">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome no cartão</label>
                            <input
                                v-model="cardHolderName"
                                type="text"
                                autocomplete="cc-name"
                                :form="canPayWithPagarme ? pagarmeTokenizeFormId : undefined"
                                :data-pagarmecheckout-element="canPayWithPagarme ? 'holder_name' : undefined"
                                :name="canPayWithPagarme ? 'renewal_pagarme_holder_name' : undefined"
                                class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            />
                        </div>
                        <template v-if="canPayWithStripe">
                            <div ref="stripeCardRef" class="rounded-lg border border-zinc-300 bg-white p-3 dark:border-zinc-600 dark:bg-zinc-800" />
                        </template>
                        <template v-else-if="canPayWithPagarme">
                            <div class="space-y-3">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Número</label>
                                    <input v-model="efiCardNumber" type="text" inputmode="numeric" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                                    <input type="hidden" :form="pagarmeTokenizeFormId" data-pagarmecheckout-element="number" name="renewal_pagarme_number" :value="efiCardNumberDigits" />
                                    <input type="hidden" :form="pagarmeTokenizeFormId" data-pagarmecheckout-element="exp_month" name="renewal_pagarme_exp_month" :value="pagarmeTokenizeExpMonthHidden" />
                                    <input type="hidden" :form="pagarmeTokenizeFormId" data-pagarmecheckout-element="exp_year" name="renewal_pagarme_exp_year" :value="pagarmeTokenizeExpYearHidden" />
                                    <input type="hidden" :form="pagarmeTokenizeFormId" data-pagarmecheckout-element="cvv" name="renewal_pagarme_cvv" :value="efiCardCvv" />
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-zinc-600">Validade (MM/AAAA)</label>
                                        <input v-model="efiCardExp" type="text" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" placeholder="MM/AAAA" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-zinc-600">CVV</label>
                                        <input v-model="efiCardCvv" type="text" inputmode="numeric" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                                    </div>
                                </div>
                            </div>
                        </template>
                        <template v-else-if="canPayWithEfi || canPayWithCielo || canPayWithPagbank">
                            <template v-if="canPayWithCielo">
                                <input type="hidden" class="bp-sop-cardholdername" :value="formatCieloSopHolderName(cardHolderName)" />
                                <input type="hidden" class="bp-sop-cardnumber" :value="(efiCardNumber || '').replace(/\D/g, '')" />
                                <input type="hidden" class="bp-sop-cardexpirationdate" :value="cieloExpirationDate" />
                                <input type="hidden" class="bp-sop-cardcvv bp-sop-cardcvvc" :value="(efiCardCvv || '').replace(/\D/g, '')" />
                                <input type="hidden" class="bp-sop-cardtype" value="creditCard" />
                            </template>
                            <div class="grid gap-3">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-zinc-600">Número</label>
                                    <input v-model="efiCardNumber" type="text" inputmode="numeric" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-zinc-600">Validade (MM/AAAA)</label>
                                        <input v-model="efiCardExp" type="text" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-zinc-600">CVV</label>
                                        <input v-model="efiCardCvv" type="text" inputmode="numeric" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <Button type="submit" class="w-full" :disabled="form.processing || cardSubmitting">
                        <Loader2 v-if="form.processing || cardSubmitting" class="mr-2 h-4 w-4 animate-spin" />
                        {{ form.processing || cardSubmitting ? 'Processando…' : 'Pagar e renovar' }}
                    </Button>
                </form>
                <form
                    :id="pagarmeTokenizeFormId"
                    method="post"
                    :action="PAGARME_TOKENIZE_FORM_ACTION"
                    class="hidden"
                    aria-hidden="true"
                />
            </div>
        </div>
    </div>
</template>
