<script setup>
import { ref, computed, watch, onMounted, onUnmounted, toRef, provide } from 'vue';
import { getMetaEntries } from '@/lib/metaTracking/browserPixel.js';
import { Head } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2 } from 'lucide-vue-next';
import { useCheckoutLocale } from '@/composables/useCheckoutLocale';
import CheckoutTimer from '@/components/checkout/CheckoutTimer.vue';
import CheckoutBanners from '@/components/checkout/CheckoutBanners.vue';
import CheckoutYoutube from '@/components/checkout/CheckoutYoutube.vue';
import CheckoutSummary from '@/components/checkout/CheckoutSummary.vue';
import CheckoutForm from '@/components/checkout/CheckoutForm.vue';
import CheckoutSidebar from '@/components/checkout/CheckoutSidebar.vue';
import CheckoutPurchaseSummary from '@/components/checkout/CheckoutPurchaseSummary.vue';
import CheckoutLegalFooter from '@/components/checkout/CheckoutLegalFooter.vue';
import { usePlatformBranding } from '@/composables/usePlatformBranding';
import SalesNotification from '@/components/checkout/SalesNotification.vue';
import SupportButton from '@/components/checkout/SupportButton.vue';
import ExitPopup from '@/components/checkout/ExitPopup.vue';
import ConversionPixels from '@/components/checkout/ConversionPixels.vue';
import { runCheckoutMetaTracking } from '@/composables/useMetaCheckoutTracking';
import { trackMetricsEvent } from '@/lib/metricsTracking.js';

defineOptions({ layout: null });

const PREVIEW_MESSAGE_TYPE = 'checkout-builder-preview-config';
const PREVIEW_READY_TYPE = 'checkout-builder-preview-ready';
const PREVIEW_STORAGE_KEY = 'checkout-builder-live-preview-v1';

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    checkout_session_token: { type: String, default: '' },
    available_payment_methods: { type: Array, default: () => [] },
    flash: { type: Object, default: () => ({}) },
    exit_popup_coupon: { type: Object, default: null },
    suggested_locale: { type: String, default: 'pt_BR' },
    suggested_currency: { type: String, default: 'BRL' },
    suggested_country_code: { type: String, default: null },
    checkout_translations: { type: Object, default: () => ({}) },
    currencies: { type: Array, default: () => [] },
    order_bumps: { type: Array, default: () => [] },
    conversion_pixels: { type: Object, default: () => ({}) },
    /** Payee code Efí para tokenização de cartão (quando método card está disponível com gateway efi). */
    card_payee_code: { type: String, default: '' },
    /** Se o gateway Efí está em homologação (token deve ser gerado com setEnvironment('sandbox')). */
    card_efi_sandbox: { type: Boolean, default: false },
    /** Publishable Key Stripe (quando método cartão está disponível com gateway stripe). */
    card_stripe_publishable_key: { type: String, default: '' },
    /** Se o gateway Stripe está em ambiente de teste. */
    card_stripe_sandbox: { type: Boolean, default: false },
    /** Se o Stripe Link está habilitado no Card Element. */
    card_stripe_link_enabled: { type: Boolean, default: true },
    card_installments_enabled: { type: Boolean, default: false },
    card_max_installments: { type: Number, default: 1 },
    /** Public Key Mercado Pago (quando método cartão está disponível com gateway mercadopago). */
    card_mercadopago_public_key: { type: String, default: '' },
    /** Se o gateway Mercado Pago está em sandbox. */
    card_mercadopago_sandbox: { type: Boolean, default: false },
    /** Chaves por gateway slug para gateways de plugin (checkout_payload_keys na definição). Ex.: { 'meu-gateway': { publishable_key: '...' } } */
    card_gateway_keys: { type: Object, default: () => ({}) },
    paypal_client_id: { type: String, default: '' },
    paypal_sandbox: { type: Boolean, default: false },
    linaopenx_pix_auto: { type: Object, default: () => ({ participants: [] }) },
    subscription_plan: { type: Object, default: null },
    /** Definido no servidor quando a URL traz `?preview=1` (preview no iframe do Builder). */
    checkout_builder_preview: { type: Boolean, default: false },
    turnstile: { type: Object, default: () => ({ enabled: false, site_key: '', mode: 'pix_boleto' }) },
    /** Código de afiliado (`?ref=`) propagado ao checkout. */
    affiliate_ref: { type: String, default: '' },
    /** Quando true, loga motivos de tracking Meta no console do browser. */
    meta_tracking_debug: { type: Boolean, default: false },
    /** Aviso legal da plataforma no rodapé do quadro. Vazio = Termos · Privacidade. */
    platform_checkout_notice: { type: [String, Object], default: null },
});

const previewConfig = ref(null);
const previewEpoch = ref(0);
const conversionPixelsRef = ref(null);
provide('checkoutConversionPixelsRef', conversionPixelsRef);

const isBuilderPreview = computed(() => {
    if (props.checkout_builder_preview) return true;
    if (typeof window === 'undefined') return false;
    try {
        return new URLSearchParams(window.location.search).get('preview') === '1';
    } catch (_) {
        return false;
    }
});

function applyPreviewConfig(config) {
    if (config == null || typeof config !== 'object') return;
    try {
        const next = JSON.stringify(config);
        const prev = previewConfig.value != null ? JSON.stringify(previewConfig.value) : '';
        if (next === prev) return;
    } catch (_) {}
    previewConfig.value = config;
    previewEpoch.value += 1;
}

function announcePreviewReady() {
    if (!isBuilderPreview.value || typeof window === 'undefined') return;
    if (window.parent === window) return;
    try {
        window.parent.postMessage({ type: PREVIEW_READY_TYPE }, '*');
    } catch (_) {}
}

function onPreviewMessage(event) {
    if (!isBuilderPreview.value) return;
    const fromParent = event.source === window.parent;
    const sameOrigin = event.origin === window.location.origin;
    if (!fromParent && !sameOrigin) return;
    if (event?.data?.type !== PREVIEW_MESSAGE_TYPE || event.data.config == null) return;
    applyPreviewConfig(event.data.config);
}

function readPreviewFromStorage() {
    try {
        const raw = localStorage.getItem(PREVIEW_STORAGE_KEY);
        if (!raw) return;
        const parsed = JSON.parse(raw);
        if (parsed?.config == null) return;
        applyPreviewConfig(parsed.config);
    } catch (_) {}
}

let previewPollTimer = null;

/** Listener no setup (não só no onMounted) para não perder postMessage se o parent disparar no @load do iframe antes do mount. */
if (typeof window !== 'undefined') {
    if (isBuilderPreview.value) {
        window.addEventListener('message', onPreviewMessage);
        window.__applyCheckoutBuilderPreview = applyPreviewConfig;
        readPreviewFromStorage();
    }
    if (props.meta_tracking_debug) {
        window.__GETFY_META_TRACKING_DEBUG__ = true;
    }
}

/** Config ao vivo do Builder (postMessage / localStorage / bridge); antes da primeira mensagem usa o config do servidor. */
const effectiveConfig = computed(() => {
    if (previewConfig.value != null) {
        return previewConfig.value;
    }
    return props.config;
});

/** Chave visual: força remount controlado só no modo preview quando o config muda. */
const previewRemountKey = computed(() => {
    if (!isBuilderPreview.value) return 'checkout';
    try {
        return `p-${previewEpoch.value}-${JSON.stringify({
            a: effectiveConfig.value?.appearance,
            t: effectiveConfig.value?.timer,
            n: effectiveConfig.value?.sales_notification,
            f: effectiveConfig.value?.customer_fields,
            s: effectiveConfig.value?.summary,
            y: effectiveConfig.value?.youtube_url,
            yp: effectiveConfig.value?.youtube_position,
            sb: effectiveConfig.value?.support_button,
            ft: effectiveConfig.value?.footer,
            ep: effectiveConfig.value?.exit_popup?.enabled,
            rv: effectiveConfig.value?.reviews,
        })}`;
    } catch (_) {
        return `p-${previewEpoch.value}`;
    }
});

onMounted(() => {
    if (!isBuilderPreview.value) return;
    announcePreviewReady();
    readPreviewFromStorage();
    previewPollTimer = window.setInterval(readPreviewFromStorage, 200);
    [40, 160, 400].forEach((ms) => setTimeout(() => announcePreviewReady(), ms));
});
onUnmounted(() => {
    if (typeof window !== 'undefined' && isBuilderPreview.value) {
        window.removeEventListener('message', onPreviewMessage);
        if (window.__applyCheckoutBuilderPreview === applyPreviewConfig) {
            delete window.__applyCheckoutBuilderPreview;
        }
    }
    if (previewPollTimer) {
        clearInterval(previewPollTimer);
        previewPollTimer = null;
    }
});

const {
    locale,
    setLocale,
    currency: displayCurrency,
    setCurrency,
    t,
    currencies: currencyList,
    priceInCurrency,
    formatPrice,
    supportedLocales,
} = useCheckoutLocale({
    translations: toRef(props, 'checkout_translations'),
    currencies: toRef(props, 'currencies'),
    suggestedLocale: toRef(props, 'suggested_locale'),
    suggestedCurrency: toRef(props, 'suggested_currency'),
    suggestedCountryCode: toRef(props, 'suggested_country_code'),
    storageKey: props.product?.checkout_slug || 'default',
});

const localeLabels = { pt_BR: 'PT', en: 'EN', es: 'ES', it: 'IT' };

function noticeForLocale(notice, loc) {
    if (!notice) return '';
    if (typeof notice === 'string') return notice;
    return notice[loc] || notice.pt_BR || notice.en || notice.es || notice.it || '';
}

const platformNoticeForLocale = computed(() => noticeForLocale(props.platform_checkout_notice, locale.value));
const { branding, appName } = usePlatformBranding();
const platformLogoUrl = computed(() =>
    String(branding.value?.app_logo || branding.value?.app_logo_icon || '').trim()
);
const appearance = computed(() => effectiveConfig.value?.appearance ?? {});
const backgroundColor = computed(() => appearance.value.background_color || '#E3E3E3');
const primaryColor = computed(() => appearance.value.primary_color || '#0ea5e9');
const banners = computed(() => appearance.value.banners ?? []);
const sideBannersFiltered = computed(() => (appearance.value.side_banners ?? []).filter(Boolean));
const timerConfig = computed(() => effectiveConfig.value?.timer ?? {});
const salesNotificationConfig = computed(() => effectiveConfig.value?.sales_notification ?? {});
const storageKey = computed(() => props.product?.checkout_slug || 'default');

const seo = computed(() => effectiveConfig.value?.seo ?? {});
/** Título da aba do navegador e para compartilhamento (Open Graph). Vem do "Título para compartilhamento" no Builder. */
const pageTitle = computed(() => (seo.value.title || '').trim() || props.product?.name || 'Checkout');

watch(pageTitle, (title) => {
    if (typeof document !== 'undefined' && title) {
        document.title = title;
    }
}, { immediate: true });

const pageDescription = computed(() => seo.value.description || props.product?.description || '');
const ogImage = computed(() => {
    const url = seo.value.og_image || props.product?.image_url;
    if (!url) return null;
    if (typeof window !== 'undefined' && url.startsWith('/')) {
        return `${window.location.origin}${url}`;
    }
    return url;
});
const faviconHref = computed(() => seo.value.favicon || '/favicon.ico');

const productImageUrlForNotification = computed(() => {
    const url = props.product?.image_url;
    if (!url) return '';
    if (typeof window !== 'undefined' && url.startsWith('/')) {
        return `${window.location.origin}${url}`;
    }
    return url;
});

const exitPopupAcceptedCoupon = ref('');
function onExitPopupAccept(code) {
    exitPopupAcceptedCoupon.value = code || '';
}

const appliedCoupon = ref(null);
function onCouponApplied(data) {
    appliedCoupon.value = data;
}
function onCouponCleared() {
    appliedCoupon.value = null;
}

const selectedOrderBumpIds = ref([]);
const selectedOrderBumpsList = computed(() => {
    const ids = new Set(selectedOrderBumpIds.value);
    return (props.order_bumps || []).filter((b) => ids.has(b.id));
});
const orderBumpsTotalBrl = computed(() =>
    selectedOrderBumpsList.value.reduce((sum, b) => sum + (Number(b.amount_brl) || 0), 0)
);

const shippingAmountBrl = ref(0);
function onShippingAmountUpdate(amount) {
    shippingAmountBrl.value = Number(amount) || 0;
}
const requiresShipping = computed(() => Boolean(props.product?.requires_shipping));
watch(
    requiresShipping,
    (needs) => {
        if (needs && displayCurrency.value !== 'BRL') {
            setCurrency('BRL');
        }
    },
    { immediate: true }
);
const checkoutTotalBrl = computed(() => {
    const base = appliedCoupon.value?.final_price ?? props.product?.price_brl ?? props.product?.price ?? 0;
    return Number(base) + orderBumpsTotalBrl.value + (requiresShipping.value ? shippingAmountBrl.value : 0);
});

const conversionPixels = computed(() => props.conversion_pixels || {});

const checkoutTotalInCurrency = computed(() => priceInCurrency(checkoutTotalBrl.value));

let checkoutMetaTrackingDone = false;

async function startCheckoutMetaTracking() {
    if (checkoutMetaTrackingDone || props.checkout_builder_preview) return;
    if (!props.checkout_session_token) return;

    // Tracking interno (paralelo; nunca bloqueia Meta/UTMify).
    try {
        trackMetricsEvent({
            event_name: 'checkout_view',
            event_id: `chk-view:${props.checkout_session_token}`,
            product_id: props.product?.id,
            tenant_id: props.product?.tenant_id,
            offer_id: props.offer?.id,
            plan_id: props.subscription_plan?.id,
            affiliate_ref: props.affiliate_ref || undefined,
            properties: { checkout_session_token: props.checkout_session_token },
        });
    } catch (_) {}

    const result = await runCheckoutMetaTracking({
        pixels: conversionPixels.value,
        checkoutSessionToken: props.checkout_session_token,
        value: checkoutTotalInCurrency.value,
        currency: displayCurrency.value,
        contentKey: props.product?.checkout_slug || '',
        contentName: props.product?.name || '',
    });

    if (result?.ok) {
        checkoutMetaTrackingDone = true;
    }
}

function onConversionPixelsMetaReady() {
    startCheckoutMetaTracking();
}

function onConversionPixelsReady() {
    if (getMetaEntries(conversionPixels.value).length === 0) {
        startCheckoutMetaTracking();
    }
}
</script>

<template>
    <ConversionPixels
        ref="conversionPixelsRef"
        :pixels="conversionPixels"
        @ready="onConversionPixelsReady"
        @meta-ready="onConversionPixelsMetaReady"
    />
    <Head>
        <title>{{ pageTitle }}</title>
        <meta v-if="pageDescription" name="description" :content="pageDescription" />
        <meta property="og:title" :content="pageTitle" />
        <meta v-if="pageDescription" property="og:description" :content="pageDescription" />
        <meta v-if="ogImage" property="og:image" :content="ogImage" />
        <link rel="icon" :href="faviconHref" />
    </Head>
    <div
        id="getfy-checkout-root"
        :key="previewRemountKey"
        data-checkout="page"
        class="min-h-screen transition-colors duration-300"
        :style="{ backgroundColor }"
    >
        <CheckoutTimer :config="timerConfig" :storage-key="storageKey" :t="t" />

        <div
            class="mx-auto max-w-6xl px-4 pb-6 sm:px-6 sm:pb-8 lg:pb-10"
            :class="banners.length ? 'pt-2' : 'pt-3'"
            data-checkout="layout-inner"
        >
            <!-- Flash -->
            <div
                v-if="flash?.error"
                class="mb-6 flex items-center gap-3 rounded-2xl border border-red-200/80 bg-red-50/95 px-4 py-3.5 text-sm font-medium text-red-800 shadow-sm backdrop-blur sm:px-5"
                data-checkout="flash-error"
                role="alert"
            >
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                    <AlertCircle class="h-4 w-4" />
                </span>
                {{ flash.error }}
            </div>
            <div
                v-if="flash?.success"
                class="mb-6 flex items-center gap-3 rounded-2xl border border-emerald-200/80 bg-emerald-50/95 px-4 py-3.5 text-sm font-medium text-emerald-800 shadow-sm backdrop-blur sm:px-5"
                data-checkout="flash-success"
                role="status"
            >
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <CheckCircle2 class="h-4 w-4" />
                </span>
                {{ flash.success }}
            </div>
            <div
                v-if="flash?.info"
                class="mb-6 flex items-center gap-3 rounded-2xl border border-sky-200/80 bg-sky-50/95 px-4 py-3.5 text-sm font-medium text-sky-800 shadow-sm backdrop-blur sm:px-5"
                data-checkout="flash-info"
                role="status"
            >
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-600">
                    <CheckCircle2 class="h-4 w-4" />
                </span>
                {{ flash.info }}
            </div>

            <CheckoutBanners v-if="banners.length" :urls="banners" />
            <CheckoutYoutube v-if="(effectiveConfig?.youtube_position ?? 'top') !== 'bottom'" :url="effectiveConfig?.youtube_url" />

            <div class="flex flex-col gap-8 lg:flex-row lg:gap-10" data-checkout="layout-columns">
                <!-- Coluna principal -->
                <div class="w-full lg:w-2/3" data-checkout="column-primary">
                    <div
                        class="overflow-visible rounded-3xl border border-white/20 bg-white/95 p-6 shadow-xl shadow-black/5 backdrop-blur sm:p-8"
                        data-checkout="card-main"
                    >
                        <CheckoutSummary
                            :product="product"
                            :subscription-plan="subscription_plan"
                            :config="effectiveConfig"
                            :primary-color="primaryColor"
                            :applied-coupon="appliedCoupon"
                            :t="t"
                            :display-currency="displayCurrency"
                            :price-in-currency="priceInCurrency"
                            :format-price="formatPrice"
                            :locale="locale"
                            :supported-locales="supportedLocales"
                            :currency-list="currencyList"
                            :locale-labels="localeLabels"
                            @set-locale="setLocale"
                            @set-currency="setCurrency"
                        />
                        <hr class="my-8 border-0 border-t border-gray-100" data-checkout="divider-summary-form" />
                        <CheckoutForm
                            :product-id="product.id"
                            :product-offer-id="product.product_offer_id ?? null"
                            :subscription-plan-id="product.subscription_plan_id ?? null"
                            :affiliate-ref="affiliate_ref || ''"
                            :checkout-session-token="checkout_session_token || ''"
                            :content-key="product?.checkout_slug || ''"
                            :content-name="product?.name || ''"
                            :tenant-id="product?.tenant_id"
                            :turnstile="turnstile || {}"
                            :checkout-builder-preview="isBuilderPreview"
                            :order-bumps="order_bumps || []"
                            v-model:order-bump-ids="selectedOrderBumpIds"
                            :primary-color="primaryColor"
                            :config="effectiveConfig"
                            :available-payment-methods="available_payment_methods"
                            :prefill-coupon="exitPopupAcceptedCoupon"
                            :t="t"
                            :display-currency="displayCurrency"
                            :format-price="formatPrice"
                            :price-in-currency="priceInCurrency"
                            :suggested-country-code="props.suggested_country_code"
                            :card-payee-code="card_payee_code || ''"
                            :card-efi-sandbox="card_efi_sandbox"
                            :card-stripe-publishable-key="card_stripe_publishable_key || ''"
                            :card-stripe-sandbox="card_stripe_sandbox"
                            :card-stripe-link-enabled="card_stripe_link_enabled"
                            :card-installments-enabled="card_installments_enabled"
                            :card-max-installments="card_max_installments || 1"
                            :card-mercadopago-public-key="card_mercadopago_public_key || ''"
                            :card-mercadopago-sandbox="card_mercadopago_sandbox"
                            :card-gateway-keys="card_gateway_keys || {}"
                            :paypal-client-id="paypal_client_id || ''"
                            :paypal-sandbox="paypal_sandbox"
                            :linaopenx-pix-auto="linaopenx_pix_auto || { participants: [] }"
                            :checkout-locale="locale"
                            :checkout-total-brl="checkoutTotalBrl"
                            :conversion-pixels="conversion_pixels"
                            :requires-shipping="requiresShipping"
                            :product-subtotal-brl="
                                appliedCoupon?.final_price ?? product?.price_brl ?? product?.price ?? 0
                            "
                            :platform-checkout-notice="platformNoticeForLocale"
                            @update:shipping-amount="onShippingAmountUpdate"
                            @coupon-applied="onCouponApplied"
                            @coupon-cleared="onCouponCleared"
                        >
                            <template #before-submit>
                                <CheckoutPurchaseSummary
                                    compact
                                    :product="product"
                                    :subscription-plan="subscription_plan"
                                    :applied-coupon="appliedCoupon"
                                    :selected-order-bumps="selectedOrderBumpsList"
                                    :order-bumps-total-brl="orderBumpsTotalBrl"
                                    :requires-shipping="requiresShipping"
                                    :shipping-amount-brl="shippingAmountBrl"
                                    :t="t"
                                    :display-currency="displayCurrency"
                                    :price-in-currency="priceInCurrency"
                                    :format-price="formatPrice"
                                    :primary-color="primaryColor"
                                />
                            </template>
                        </CheckoutForm>
                    </div>
                    <div
                        class="mt-4 overflow-hidden rounded-3xl border border-white/20 bg-white/95 p-6 shadow-xl shadow-black/5 backdrop-blur lg:hidden"
                        data-checkout="card-legal-footer"
                    >
                        <CheckoutLegalFooter
                            :logo-url="platformLogoUrl"
                            :app-name="appName"
                            :notice="platformNoticeForLocale"
                            :t="t"
                            :secure-purchase-text="t('checkout.secure_purchase')"
                        />
                    </div>
                </div>

                <!-- Coluna lateral: resumo + banners -->
                <CheckoutSidebar
                    :product="product"
                    :subscription-plan="subscription_plan"
                    :config="effectiveConfig"
                    :applied-coupon="appliedCoupon"
                    :selected-order-bumps="selectedOrderBumpsList"
                    :order-bumps-total-brl="orderBumpsTotalBrl"
                    :requires-shipping="requiresShipping"
                    :shipping-amount-brl="shippingAmountBrl"
                    :t="t"
                    :display-currency="displayCurrency"
                    :price-in-currency="priceInCurrency"
                    :format-price="formatPrice"
                />
            </div>

            <!-- Banners laterais: no mobile aparecem no final da página -->
            <div
                v-if="sideBannersFiltered.length"
                class="mt-8 space-y-4 lg:hidden"
                data-checkout="banners-side-mobile"
            >
                <img
                    v-for="(url, i) in sideBannersFiltered"
                    :key="i"
                    :src="url"
                    alt="Banner"
                    class="w-full rounded-2xl object-cover shadow-lg"
                    @error="(e) => e?.target && (e.target.style.display = 'none')"
                />
            </div>

            <!-- Vídeo YouTube em baixo da página (quando a posição for "bottom") -->
            <CheckoutYoutube v-if="(effectiveConfig?.youtube_position ?? 'top') === 'bottom'" :url="effectiveConfig?.youtube_url" class="mt-8" />
        </div>

        <SalesNotification
            :config="salesNotificationConfig"
            :product-name="product?.name"
            :product-image-url="productImageUrlForNotification"
        />

        <SupportButton :config="effectiveConfig?.support_button" :primary-color="primaryColor" />
        <ExitPopup
            :config="effectiveConfig"
            :primary-color="primaryColor"
            :exit-popup-coupon="exit_popup_coupon"
            :storage-key="storageKey"
            :t="t"
            @accept="onExitPopupAccept"
        />
    </div>
</template>
