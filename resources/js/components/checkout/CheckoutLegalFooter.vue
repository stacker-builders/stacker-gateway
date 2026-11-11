<script setup>
import { computed } from 'vue';
import { Shield } from 'lucide-vue-next';
import CheckoutPlatformNotice from './CheckoutPlatformNotice.vue';

const props = defineProps({
    logoUrl: { type: String, default: '' },
    appName: { type: String, default: '' },
    notice: { type: String, default: '' },
    showSecureLine: { type: Boolean, default: true },
    securePurchaseText: { type: String, default: 'Compra 100% segura' },
    t: { type: Function, default: (key) => key },
});

const year = new Date().getFullYear();

function footerText(key, fallback) {
    const fn = props.t;
    const value = typeof fn === 'function' ? fn(key) : '';
    const text = !value || value === key ? fallback : value;
    return String(text).replaceAll('{year}', String(year));
}

const secureText = computed(() => footerText('checkout.secure_purchase', props.securePurchaseText));
const recaptchaText = computed(() => footerText('checkout.footer_recaptcha', 'Este site é protegido pelo reCAPTCHA do Google'));
const copyrightText = computed(() => footerText('checkout.footer_copyright', `Copyright © ${year}. Todos os direitos reservados.`));
const termsText = computed(() => footerText('checkout.footer_terms', 'Termos'));
const privacyText = computed(() => footerText('checkout.footer_privacy', 'Privacidade'));
</script>

<template>
    <div data-checkout="legal-footer">
        <div v-if="logoUrl" class="mb-4 flex justify-center" data-checkout="platform-logo">
            <img
                :src="logoUrl"
                :alt="appName"
                class="h-8 w-auto max-w-[180px] object-contain"
                loading="lazy"
            />
        </div>
        <p v-if="showSecureLine" class="flex items-center justify-center gap-2 text-sm text-gray-500">
            <Shield class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ secureText }}
        </p>
        <p class="text-center text-xs text-gray-400" :class="showSecureLine ? 'mt-2' : ''">
            {{ recaptchaText }}
        </p>
        <p class="mt-2 text-center text-xs text-gray-400">
            {{ copyrightText }}
        </p>
        <CheckoutPlatformNotice
            v-if="notice"
            data-checkout="platform-notice"
            class="mt-3 text-center text-xs leading-relaxed text-gray-400"
            :text="notice"
        />
        <p v-else class="mt-3 text-center text-xs text-gray-400">
            <a href="/termos-de-uso" target="_blank" rel="noopener" class="underline hover:text-gray-600">{{ termsText }}</a>
            <span class="mx-1">·</span>
            <a href="/politica-privacidade" target="_blank" rel="noopener" class="underline hover:text-gray-600">{{ privacyText }}</a>
        </p>
    </div>
</template>
