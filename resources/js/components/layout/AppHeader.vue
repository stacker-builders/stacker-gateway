<script setup>
import { computed, inject } from 'vue';
import { PanelsTopLeft, Bell } from 'lucide-vue-next';
import { usePage } from '@inertiajs/vue3';
import { useSidebar } from '@/composables/useSidebar';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import UserMenu from '@/components/layout/UserMenu.vue';
import KawaiiHeaderRevenue from '@/components/kawaii/KawaiiHeaderRevenue.vue';
import LocaleThemeControls from '@/components/layout/LocaleThemeControls.vue';
import AccountManagerCard from '@/components/dashboard/AccountManagerCard.vue';
import { useI18n } from '@/composables/useI18n';
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';
import { useThemedPageHeading } from '@/composables/useThemedPageHeading';

const props = defineProps({
    pageTitle: { type: String, default: null },
    pageTitleBadge: { type: String, default: null },
});

const page = usePage();
const { isAurora, isKawaii, isThemedShell } = useSellerDashboardTemplate();
const { heading: themedPageHeading } = useThemedPageHeading();
const customerPanel = computed(() => !!page.props.customer_panel);
const themedShell = computed(() => isThemedShell.value && !customerPanel.value);
const isDashboard = computed(() => page.url === '/dashboard' || page.url.startsWith('/dashboard?'));
const hidePageTitleOnThemedDashboard = computed(
    () => isThemedShell.value && isDashboard.value && !customerPanel.value,
);
const showThemedPageHeading = computed(
    () => isThemedShell.value && !customerPanel.value && !isDashboard.value,
);
const themedHeaderCompact = computed(
    () => hidePageTitleOnThemedDashboard.value || showThemedPageHeading.value,
);
const themedHeadingTitle = computed(
    () => themedPageHeading.value.title || props.pageTitle || '',
);
const themedHeadingSubtitle = computed(() => themedPageHeading.value.subtitle || '');

const greetingName = computed(() => {
    const name = page.props.auth?.user?.name;
    if (name && String(name).trim()) return String(name).trim();
    return 'infoprodutor';
});

const titleClass = computed(() => {
    if (isKawaii.value) return 'kawaii-fg';
    if (isAurora.value) return 'aurora-fg';
    return 'text-zinc-900 dark:text-white';
});

const subtitleClass = computed(() => {
    if (isKawaii.value) return 'kawaii-fg-muted';
    if (isAurora.value) return 'aurora-fg-muted';
    return 'text-zinc-500 dark:text-zinc-400';
});

const iconBtnClass = computed(() => {
    if (isKawaii.value) return 'kawaii-icon-btn';
    if (isAurora.value) return 'aurora-icon-btn';
    return 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';
});

const { toggleSidebar, isMobileOpen, isMobile } = useSidebar();

const openNotificationsPanel = inject('openNotificationsPanel', () => {});
const notificationsUnreadCount = inject('notificationsUnreadCount', { value: 0 });
const unreadBadge = computed(() => Math.max(0, notificationsUnreadCount?.value ?? 0));
const { t } = useI18n();

const controlsVariant = computed(() => {
    if (isKawaii.value) return 'kawaii';
    if (isAurora.value) return 'aurora';
    return 'default';
});

const dashboardGreetingTitleClass = computed(() => {
    if (isAurora.value) return 'text-base font-semibold tracking-tight sm:text-lg';
    if (isKawaii.value) return 'text-lg font-bold tracking-tight md:text-xl';
    return 'text-xl font-bold tracking-tight md:text-2xl';
});

const dashboardGreetingSubtitleClass = computed(() => {
    if (isAurora.value) return 'mt-0.5 truncate text-xs sm:text-sm';
    return 'mt-0.5 truncate text-sm';
});
</script>

<template>
    <header
        class="z-[99998] flex shrink-0 w-full items-center justify-between gap-4 bg-transparent px-4 lg:px-6"
        :class="[
            themedHeaderCompact ? 'py-2 lg:py-2.5' : 'py-3 lg:py-4',
            isMobile && isMobileOpen ? 'pointer-events-none' : '',
        ]"
    >
        <div class="flex min-w-0 flex-1 items-center gap-3">
            <button
                v-if="isMobile && !isMobileOpen"
                type="button"
                class="flex h-9 w-9 shrink-0 touch-manipulation cursor-pointer select-none items-center justify-center rounded-lg transition-colors"
                :class="hidePageTitleOnThemedDashboard ? iconBtnClass : iconBtnClass"
                aria-label="Abrir menu"
                @click="toggleSidebar"
            >
                <PanelsTopLeft class="h-5 w-5" aria-hidden="true" />
            </button>
            <div v-if="hidePageTitleOnThemedDashboard" class="min-w-0">
                <h1 class="truncate" :class="[dashboardGreetingTitleClass, titleClass]">
                    Olá, {{ greetingName }} 👋
                </h1>
                <p class="truncate" :class="[dashboardGreetingSubtitleClass, subtitleClass]">
                    Aqui está o resumo do seu negócio hoje.
                </p>
            </div>
            <div v-else-if="showThemedPageHeading && themedHeadingTitle" class="min-w-0">
                <h1 class="truncate text-xl font-bold tracking-tight md:text-2xl" :class="titleClass">
                    {{ themedHeadingTitle }}
                </h1>
                <p v-if="themedHeadingSubtitle" class="mt-0.5 truncate text-sm" :class="subtitleClass">
                    {{ themedHeadingSubtitle }}
                </p>
            </div>
            <template v-else-if="pageTitle">
                <h1 class="truncate text-xl font-semibold md:text-2xl" :class="titleClass">
                    {{ pageTitle }}
                </h1>
                <span
                    v-if="pageTitleBadge"
                    class="shrink-0 truncate max-w-[160px] md:max-w-[220px] rounded-md bg-[var(--color-primary)]/15 px-2.5 py-0.5 text-xs font-medium text-[var(--color-primary)] dark:bg-[var(--color-primary)]/25 dark:text-[var(--color-primary)]"
                    :title="pageTitleBadge"
                >
                    {{ pageTitleBadge }}
                </span>
            </template>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <KawaiiHeaderRevenue v-if="isKawaii && isDashboard && !customerPanel" />
            <ConquistasWidget v-if="!customerPanel && !themedShell && (!isDashboard || !isMobile)" />
            <AccountManagerCard
                v-if="!customerPanel"
                :variant="controlsVariant"
            />
            <LocaleThemeControls
                language-only
                :variant="controlsVariant"
                size="md"
            />
            <LocaleThemeControls
                theme-only
                class="hidden lg:flex"
                :variant="controlsVariant"
                theme-style="toggle"
                size="md"
            />
            <button
                v-if="!customerPanel"
                type="button"
                class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                :class="iconBtnClass"
                :aria-label="t('header.notifications', 'Notificações')"
                @click="openNotificationsPanel()"
            >
                <Bell class="h-5 w-5" aria-hidden="true" />
                <span
                    v-if="unreadBadge > 0"
                    class="absolute -right-0.5 -top-0.5 flex h-4 min-w-[1rem] items-center justify-center rounded-full px-1 text-[10px] font-semibold text-white"
                    :class="isKawaii ? 'kawaii-notify-dot' : 'bg-[var(--color-primary)]'"
                >
                    {{ unreadBadge > 99 ? '99+' : unreadBadge }}
                </span>
            </button>
            <UserMenu />
        </div>
    </header>
</template>
