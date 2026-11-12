<script setup>
import { ref, computed, onMounted } from 'vue';
import { Download, Smartphone } from 'lucide-vue-next';
import { usePwaInstall } from '@/composables/usePwaInstall';

const props = defineProps({
    /** menu = item simples; banner = destaque no sidebar mobile */
    variant: {
        type: String,
        default: 'menu',
        validator: (v) => ['menu', 'banner'].includes(v),
    },
    /** default | aurora | kawaii | noir */
    theme: {
        type: String,
        default: 'default',
    },
});

const {
    canShowInstallButton,
    isIos,
    isMobile,
    isSecureContextForPwa,
    canTriggerNativeInstallPrompt,
    triggerInstall,
    registerListener,
    syncInstallPromptFromWindow,
} = usePwaInstall('painel');

const showFallbackMessage = ref(false);
let fallbackTimer = null;

const isAndroid = computed(() => isMobile.value && !isIos.value);
const showHttpsWarning = computed(() => showFallbackMessage.value && isAndroid.value && !isSecureContextForPwa.value);

const bannerSurfaceClass = computed(() => {
    if (props.theme === 'aurora') {
        return 'border-[var(--color-primary)]/30 bg-gradient-to-br from-[var(--color-primary)]/20 via-[var(--color-primary)]/8 to-transparent';
    }
    if (props.theme === 'kawaii') {
        return 'border-[var(--color-primary)]/35 bg-gradient-to-br from-[var(--color-primary)]/18 to-[var(--color-primary)]/6';
    }
    if (props.theme === 'noir') {
        return 'border-[var(--color-primary)]/30 bg-[var(--color-primary)]/10';
    }
    return 'border-[var(--color-primary)]/25 bg-gradient-to-br from-[var(--color-primary)]/14 via-[var(--color-primary)]/6 to-transparent dark:from-[var(--color-primary)]/22 dark:via-[var(--color-primary)]/10 dark:to-transparent';
});

const bannerTitleClass = computed(() => {
    if (props.theme === 'aurora') return 'aurora-fg';
    if (props.theme === 'kawaii') return 'kawaii-fg';
    if (props.theme === 'noir') return 'noir-fg';
    return 'text-zinc-900 dark:text-white';
});

const bannerSubtitleClass = computed(() => {
    if (props.theme === 'aurora') return 'aurora-fg-muted';
    if (props.theme === 'kawaii') return 'kawaii-fg-muted';
    if (props.theme === 'noir') return 'noir-fg-muted';
    return 'text-zinc-500 dark:text-zinc-400';
});

async function handleInstallClick() {
    showFallbackMessage.value = false;
    if (fallbackTimer) {
        clearTimeout(fallbackTimer);
        fallbackTimer = null;
    }
    syncInstallPromptFromWindow();
    if (canTriggerNativeInstallPrompt.value) {
        await triggerInstall();
        return;
    }
    if (isIos.value) {
        triggerInstall();
        return;
    }
    showFallbackMessage.value = true;
    fallbackTimer = setTimeout(() => {
        showFallbackMessage.value = false;
        fallbackTimer = null;
    }, 8000);
}

onMounted(() => {
    registerListener();
});
</script>

<template>
    <div v-if="canShowInstallButton" class="space-y-1.5">
        <button
            v-if="variant === 'banner'"
            type="button"
            class="group relative w-full overflow-hidden rounded-xl border p-3 text-left shadow-sm transition hover:brightness-[1.03] active:scale-[0.99]"
            :class="bannerSurfaceClass"
            @click="handleInstallClick"
        >
            <div
                class="pointer-events-none absolute -right-3 -top-3 h-16 w-16 rounded-full bg-[var(--color-primary)]/15 blur-xl"
                aria-hidden="true"
            />
            <div class="relative flex items-center gap-3">
                <span
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[var(--color-primary)] text-white shadow-sm"
                >
                    <Smartphone class="h-5 w-5" aria-hidden="true" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center gap-1.5">
                        <span class="text-sm font-semibold" :class="bannerTitleClass">Instalar app</span>
                        <Download class="h-3.5 w-3.5 text-[var(--color-primary)] opacity-80" aria-hidden="true" />
                    </span>
                    <span class="mt-0.5 block text-xs leading-snug" :class="bannerSubtitleClass">
                        Acesso rápido na tela inicial do celular
                    </span>
                </span>
            </div>
        </button>

        <button
            v-else
            type="button"
            class="menu-item group w-full justify-start menu-item-inactive"
            @click="handleInstallClick"
        >
            <span class="shrink-0 menu-item-icon-inactive">
                <Smartphone class="h-5 w-5" aria-hidden="true" />
            </span>
            <span class="truncate">Instalar App</span>
        </button>

        <p
            v-if="showHttpsWarning"
            class="px-1 text-xs text-zinc-500 dark:text-zinc-400"
        >
            Para instalar como app no Android, abra em <strong>HTTPS</strong> (cadeado na barra). Em HTTP, o Chrome cria apenas atalho e não exibe o prompt nativo de instalação.
        </p>
        <p
            v-else-if="showFallbackMessage && isAndroid"
            class="px-1 text-xs text-zinc-500 dark:text-zinc-400"
        >
            No Chrome, toque no menu (⋮) e escolha <strong>Instalar app</strong>. Se aparecer apenas <strong>Adicionar à tela inicial</strong>, o navegador ainda não considerou a página instalável.
        </p>
        <p
            v-else-if="showFallbackMessage"
            class="px-1 text-xs text-zinc-500 dark:text-zinc-400"
        >
            Use o menu (⋮) do navegador e escolha <strong>Instalar app</strong> ou <strong>Adicionar à tela inicial</strong> para colocar o app na sua tela inicial.
        </p>
    </div>
</template>
