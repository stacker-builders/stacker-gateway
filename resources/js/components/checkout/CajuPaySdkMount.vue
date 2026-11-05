<script setup>
import { onBeforeUnmount, ref, watch, computed, defineExpose } from 'vue';
import { mountCajuPayCheckout, confirmCajuPayController, cajupayDefaultMethodFor, setCajuPayPayer, readCajuPayInstallments } from '@/composables/useCajuPaySdk';

const props = defineProps({
    paymentMethod: { type: String, required: true },
    sessionToken: { type: String, default: '' },
    /** Base da API CajuPay (ex.: resposta `sdk_base_url` do checkout). */
    apiBaseUrl: { type: String, default: '' },
    initialPayer: { type: Object, default: () => ({}) },
    containerId: { type: String, default: 'cajupay-method' },
    /** Apple/Google Pay: chamado imediatamente antes do 1º `confirm()` do SDK. */
    beforeWalletPrime: { type: Function, default: null },
    locale: { type: String, default: '' },
});

const error = ref('');
const controller = ref(null);
const mountedKey = ref('');
const cardFieldReady = ref(false);
const cardPrimingInFlight = ref(false);
const selectedInstallments = ref(1);
/** Invalida mounts obsoletos quando token/baseUrl/método mudam durante await assíncrono. */
let mountGeneration = 0;

const containerSelector = computed(() => `#${props.containerId}`);
const isCardMethod = computed(() => props.paymentMethod === 'card');
const isWalletMethod = computed(() => props.paymentMethod === 'apple_pay' || props.paymentMethod === 'google_pay');
const needsWalletPriming = computed(() => isWalletMethod.value);

function buildMountKey() {
    const token = (props.sessionToken || '').trim();
    if (!token) return '';
    const base = (props.apiBaseUrl || '').trim();
    const method = props.paymentMethod || '';

    return `${token}|${base}|${method}|${(props.locale || '').trim()}`;
}

function syncPayerFromProps() {
    if (!controller.value) return;
    setCajuPayPayer(controller.value, {
        name: props.initialPayer?.name,
        email: props.initialPayer?.email,
        document: props.initialPayer?.document,
        phone: props.initialPayer?.phone,
    });
}

function destroyController() {
    try {
        controller.value?.destroy?.();
    } catch (_) {
        // ignore
    }
    controller.value = null;
    mountedKey.value = '';
    cardFieldReady.value = false;
    cardPrimingInFlight.value = false;
    selectedInstallments.value = 1;
    const el = typeof document !== 'undefined' ? document.querySelector(containerSelector.value) : null;
    if (el) {
        try {
            el.innerHTML = '';
        } catch (_) { /* ignore */ }
    }
}

function onSdkStatus(event) {
    const phase = event?.phase || event?.status || '';
    if (phase === 'awaiting_card_details' || (isWalletMethod.value && phase === 'awaiting_wallet_confirmation')) {
        cardFieldReady.value = true;
    }
    const fromEvent = Number(event?.session?.installments ?? event?.installments ?? 0);
    if (fromEvent >= 1) {
        selectedInstallments.value = Math.min(12, fromEvent);
    }
}

function onSdkSuccess(event) {
    const fromEvent = Number(event?.session?.installments ?? event?.installments ?? 0);
    if (fromEvent >= 1) {
        selectedInstallments.value = Math.min(12, fromEvent);
    }
}

function getInstallments() {
    const fromDom = readCajuPayInstallments(containerSelector.value);
    if (fromDom > 1) {
        selectedInstallments.value = fromDom;

        return fromDom;
    }
    if (selectedInstallments.value > 1) {
        return selectedInstallments.value;
    }

    return fromDom;
}

function payerEmailReady() {
    return (props.initialPayer?.email || '').trim().includes('@');
}

async function onCardMountReady() {
    syncPayerFromProps();
}

async function primeCardField() {
    if (!controller.value || cardPrimingInFlight.value || cardFieldReady.value || !isCardMethod.value) return;
    cardPrimingInFlight.value = true;
    syncPayerFromProps();

    try {
        await controller.value.confirm();
        cardFieldReady.value = true;
        error.value = '';
    } catch (e) {
        const msg = (e?.message || e?.error || '').toString().toLowerCase();
        if (msg.includes('awaiting') || msg.includes('card_details')) {
            cardFieldReady.value = true;
            error.value = '';
        } else if (msg.includes('payer_name') || msg.includes('payer_email') || msg.includes('payer_document')) {
            error.value = 'Preencha seus dados acima para carregar o pagamento.';
        } else if (!cardFieldReady.value) {
            error.value = e?.message || 'Falha ao iniciar o cartão.';
        }
    } finally {
        cardPrimingInFlight.value = false;
    }
}

async function primeWalletField() {
    if (!controller.value || cardPrimingInFlight.value || cardFieldReady.value) return;
    cardPrimingInFlight.value = true;

    syncPayerFromProps();

    try {
        if (typeof props.beforeWalletPrime === 'function') {
            await props.beforeWalletPrime();
        }
        await controller.value.confirm();
        cardFieldReady.value = true;
        error.value = '';
    } catch (e) {
        const msg = (e?.message || e?.error || '').toString().toLowerCase();
        if (msg.includes('awaiting') || msg.includes('card_details') || msg.includes('wallet')) {
            cardFieldReady.value = true;
            error.value = '';
        } else if (msg.includes('payer_name') || msg.includes('payer_email') || msg.includes('payer_document')) {
            error.value = 'Preencha seus dados acima para carregar o pagamento.';
        } else if (msg.includes('method_not_available') || msg.includes('confirm_unavailable_for_method')) {
            const label = props.paymentMethod === 'apple_pay' ? 'Apple Pay' : 'Google Pay';
            error.value = `${label} não está disponível para esta conta CajuPay no momento. Selecione outra forma de pagamento (ex.: Cartão).`;
        } else if (!cardFieldReady.value) {
            const label = props.paymentMethod === 'apple_pay' ? 'Apple Pay' : 'Google Pay';
            error.value = e?.message || `Falha ao iniciar o ${label}.`;
        }
    } finally {
        cardPrimingInFlight.value = false;
    }
}

async function tryMount() {
    const key = buildMountKey();
    if (!key) {
        mountGeneration += 1;
        if (controller.value) destroyController();

        return;
    }
    if (mountedKey.value === key && controller.value) {
        return;
    }

    const generation = ++mountGeneration;
    error.value = '';
    destroyController();

    try {
        await new Promise((r) => { setTimeout(r, 0); });
        if (generation !== mountGeneration) return;

        const base = (props.apiBaseUrl || '').trim() || undefined;
        const defaultMethod = cajupayDefaultMethodFor(props.paymentMethod);
        const primeCardOnMount = defaultMethod === 'card' && payerEmailReady();
        const nextController = await mountCajuPayCheckout(containerSelector.value, {
            token: props.sessionToken,
            baseUrl: base,
            defaultMethod,
            preparePaymentUIOnMount: primeCardOnMount,
            initialPayer: props.initialPayer,
            locale: (props.locale || '').trim(),
            onStatus: onSdkStatus,
            onSuccess: onSdkSuccess,
        });

        if (generation !== mountGeneration) {
            try {
                nextController?.destroy?.();
            } catch (_) { /* ignore */ }

            return;
        }

        controller.value = nextController;
        mountedKey.value = key;

        if (isCardMethod.value) {
            await onCardMountReady();
        } else if (needsWalletPriming.value) {
            await primeWalletField();
        } else {
            cardFieldReady.value = true;
        }

        if (generation !== mountGeneration) {
            destroyController();
        }
    } catch (e) {
        if (generation !== mountGeneration) return;

        const raw = (e?.message || '').toString();
        const lower = raw.toLowerCase();
        if (lower.includes('cors') || lower.includes('failed to fetch') || lower.includes('network')) {
            error.value = 'Não foi possível conectar ao pagamento CajuPay. Em ambiente local use HTTPS ou recarregue a página; em produção o checkout deve estar em HTTPS.';
        } else {
            error.value = raw || 'Não foi possível carregar o checkout CajuPay.';
        }
        controller.value = null;
        mountedKey.value = '';
    }
}

watch(
    () => [props.sessionToken, props.apiBaseUrl, props.paymentMethod, props.locale],
    () => {
        void tryMount();
    },
    { immediate: true },
);

let payerRetryTimer = null;
watch(
    () => props.initialPayer,
    (val) => {
        if (!controller.value) return;
        const hasEmail = (val?.email || '').trim().includes('@');
        if (!hasEmail) return;
        clearTimeout(payerRetryTimer);
        payerRetryTimer = setTimeout(() => {
            if (!controller.value) return;
            syncPayerFromProps();
            if (isCardMethod.value && !cardFieldReady.value && !cardPrimingInFlight.value) {
                void primeCardField();
            }
            if (isWalletMethod.value && !cardFieldReady.value && !cardPrimingInFlight.value) {
                void primeWalletField();
            }
        }, 400);
    },
    { deep: true },
);

onBeforeUnmount(() => {
    mountGeneration += 1;
    clearTimeout(payerRetryTimer);
    destroyController();
});

async function confirm() {
    if (!controller.value) {
        throw new Error('CajuPay: aguarde o checkout terminar de carregar.');
    }
    if ((isCardMethod.value || needsWalletPriming.value) && !cardFieldReady.value) {
        const start = Date.now();
        while (!cardFieldReady.value && Date.now() - start < 8000) {
            await new Promise((r) => { setTimeout(r, 100); });
        }
        if (!cardFieldReady.value) {
            throw new Error('CajuPay: o método de pagamento ainda não está pronto. Aguarde 1-2 segundos e clique novamente.');
        }
    }

    return await confirmCajuPayController(controller.value);
}

function setPayer(payer) {
    if (!controller.value) return false;

    return setCajuPayPayer(controller.value, payer);
}

defineExpose({
    confirm,
    isReady: () => !!controller.value,
    setPayer,
    isCardFieldReady: () => cardFieldReady.value,
    getInstallments,
});
</script>

<template>
    <div class="space-y-2">
        <div :id="containerId" class="min-h-0 w-full" />
        <div v-if="error" class="text-xs text-red-600">{{ error }}</div>
    </div>
</template>
