<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useAttrs } from 'vue';
import { Moon, Sun } from 'lucide-vue-next';
import { router, usePage } from '@inertiajs/vue3';
import ThemeToggler from '@/components/layout/ThemeToggler.vue';
import LocaleFlag from '@/components/ui/LocaleFlag.vue';
import { useI18n } from '@/composables/useI18n';
import { usePanelColorScheme } from '@/composables/usePanelColorScheme';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'kawaii', 'aurora', 'noir'].includes(v),
    },
    /** cycle = um ícone que alterna; toggle = dois botões (desktop) */
    themeStyle: {
        type: String,
        default: 'cycle',
        validator: (v) => ['cycle', 'toggle'].includes(v),
    },
    size: {
        type: String,
        default: 'sm',
        validator: (v) => ['sm', 'md'].includes(v),
    },
    languageOnly: { type: Boolean, default: false },
    themeOnly: { type: Boolean, default: false },
});

const attrs = useAttrs();
const page = usePage();
const showLanguage = computed(
    () => !props.themeOnly && !!page.props?.auth?.user && !page.url.startsWith('/plataforma'),
);
const showTheme = computed(() => !props.languageOnly);

const { theme, showToggler, setTheme } = usePanelColorScheme();
const switchingLanguage = ref(false);
const languageOpen = ref(false);
const rootRef = ref(null);

const { t, locale, availableLanguages } = useI18n();
const currentLanguageName = computed(() => {
    const current = (availableLanguages.value || []).find((lang) => lang.code === locale.value);
    return current?.name || t('header.language', 'Idioma');
});

const flagSize = computed(() => (props.size === 'md' ? 'md' : 'sm'));

const btnSizeClass = computed(() => (props.size === 'md' ? 'h-9 w-9' : 'h-8 w-8'));
const iconSizeClass = computed(() => (props.size === 'md' ? 'h-5 w-5' : 'h-4 w-4'));

const iconBtnClass = computed(() => {
    if (props.variant === 'kawaii') return 'kawaii-icon-btn';
    if (props.variant === 'aurora') return 'aurora-icon-btn';
    if (props.variant === 'noir') return 'noir-icon-btn';
    return 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';
});

const dropdownClass = computed(() => {
    if (props.variant === 'kawaii') {
        return 'border-[var(--kawaii-border)] bg-[var(--kawaii-surface)] shadow-lg';
    }
    if (props.variant === 'aurora') {
        return 'aurora-surface aurora-divider border shadow-lg';
    }
    if (props.variant === 'noir') {
        return 'noir-surface noir-divider border shadow-lg';
    }
    return 'border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-900';
});

const dropdownItemClass = computed(() => {
    if (props.variant === 'kawaii') {
        return 'text-[var(--kawaii-fg)] hover:bg-[var(--kawaii-surface-hover)]';
    }
    if (props.variant === 'aurora') {
        return 'aurora-fg hover:bg-white/5';
    }
    if (props.variant === 'noir') {
        return 'noir-fg hover:bg-white/5';
    }
    return 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800';
});

function cycleTheme() {
    setTheme(theme.value === 'dark' ? 'light' : 'dark');
}

async function switchLanguage(nextLocale) {
    if (!nextLocale || switchingLanguage.value || nextLocale === locale.value) return;
    switchingLanguage.value = true;
    try {
        await window.axios.post('/painel/idioma', { locale: nextLocale });
        languageOpen.value = false;
        router.reload({ preserveScroll: true });
    } finally {
        switchingLanguage.value = false;
    }
}

function onDocumentClick(event) {
    if (!languageOpen.value || !rootRef.value) return;
    if (!rootRef.value.contains(event.target)) {
        languageOpen.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
});
</script>

<template>
    <div ref="rootRef" v-bind="attrs" class="flex shrink-0 items-center gap-0.5">
        <div v-if="showLanguage" class="relative">
            <button
                type="button"
                class="flex items-center justify-center rounded-lg transition-colors"
                :class="[btnSizeClass, iconBtnClass]"
                :aria-label="t('header.language', 'Idioma')"
                :title="currentLanguageName"
                @click.stop="languageOpen = !languageOpen"
            >
                <LocaleFlag :locale="locale" :size="flagSize" />
            </button>
            <div
                v-if="languageOpen"
                class="absolute right-0 z-[100002] mt-1.5 min-w-[180px] overflow-hidden rounded-xl border"
                :class="dropdownClass"
            >
                <button
                    v-for="lang in availableLanguages"
                    :key="lang.code"
                    type="button"
                    class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition"
                    :class="dropdownItemClass"
                    @click="switchLanguage(lang.code)"
                >
                    <span class="flex min-w-0 items-center gap-2.5">
                        <LocaleFlag :locale="lang.code" size="sm" />
                        <span class="truncate">{{ lang.name }}</span>
                    </span>
                    <span v-if="lang.code === locale" class="shrink-0 text-xs text-[var(--color-primary)]">✓</span>
                </button>
            </div>
        </div>
        <template v-if="showTheme">
            <ThemeToggler v-if="showToggler && themeStyle === 'toggle'" />
            <button
                v-else-if="showToggler && themeStyle === 'cycle'"
                type="button"
                class="flex items-center justify-center rounded-lg transition-colors"
                :class="[btnSizeClass, iconBtnClass]"
                :aria-label="theme === 'dark' ? 'Ativar tema claro' : 'Ativar tema escuro'"
                :title="theme === 'dark' ? 'Tema claro' : 'Tema escuro'"
                @click="cycleTheme"
            >
                <Sun v-if="theme === 'light'" :class="iconSizeClass" aria-hidden="true" />
                <Moon v-else :class="iconSizeClass" aria-hidden="true" />
            </button>
        </template>
    </div>
</template>
