<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { formatCompactCurrency } from '@/lib/utils';
import { navPrefetch } from '@/composables/useAppSidebarNav';
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';
import { useSidebar } from '@/composables/useSidebar';

const props = defineProps({
    variant: { type: String, default: 'header' }, // 'header' | 'sidebar' | 'dashboard'
});

const { isAurora, isNoir, isThemedShell, themePrefix } = useSellerDashboardTemplate();
const { closeMobileSidebarIfOpen, isMobile } = useSidebar();

const page = usePage();
const progress = computed(() => page.props.achievementsProgress ?? null);

const iconUrl = computed(() => {
    if (!progress.value) return null;
    const curr = progress.value.current_achievement;
    const achievements = progress.value.achievements ?? [];
    const first = achievements[0];
    if (curr?.image) return curr.image;
    if (first?.image) return first.image;
    return null;
});

const isLocked = computed(() => {
    if (!progress.value) return true;
    return progress.value.current_achievement === null;
});

const progressPercent = computed(() => {
    return progress.value?.progress_percent ?? 0;
});

const nextLabel = computed(() => {
    const next = progress.value?.next_achievement;
    if (!next) return null;
    return formatCompactCurrency(next.threshold);
});

const remainingLabel = computed(() => {
    const remaining = progress.value?.remaining;
    if (remaining == null || remaining <= 0) return null;
    if (!progress.value?.next_achievement) return null;
    return formatCompactCurrency(remaining);
});

const totalLabel = computed(() => {
    const total = progress.value?.total_valid_sales ?? 0;
    return formatCompactCurrency(total);
});
</script>

<template>
    <Link
        v-if="progress"
        href="/conquistas"
        :prefetch="navPrefetch(isMobile)"
        class="group flex shrink-0 cursor-pointer touch-manipulation items-center gap-3 rounded-lg px-3 py-2 transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-800"
        :class="{
            'flex-col items-stretch gap-2': props.variant === 'sidebar',
            'w-full rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700': props.variant === 'dashboard',
            '!border-0 !bg-transparent !p-0 hover:!bg-transparent': (isAurora || isNoir) && props.variant === 'sidebar',
        }"
        title="Conquistas"
        @click="closeMobileSidebarIfOpen"
    >
        <div
            class="flex shrink-0 items-center justify-center overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-800"
            :class="[
                props.variant === 'sidebar' ? 'h-12 w-12' : 'h-10 w-10',
                { 'opacity-60 grayscale': isLocked },
            ]"
        >
            <img
                v-if="iconUrl"
                :src="iconUrl"
                alt=""
                :class="[
                    props.variant === 'sidebar' ? 'h-8 w-8' : 'h-7 w-7',
                    'object-contain',
                ]"
            />
        </div>
        <div
            class="min-w-0 flex-1"
            :class="[
                props.variant === 'sidebar' ? 'w-full' : '',
                props.variant === 'dashboard' ? 'w-full' : '',
                props.variant === 'header' ? 'hidden w-[130px] sm:block' : '',
            ]"
        >
            <p
                v-if="props.variant === 'header' || props.variant === 'dashboard' || (isThemedShell && props.variant === 'sidebar')"
                class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-400"
                :class="themePrefix && props.variant === 'sidebar' ? `${themePrefix}-fg-muted` : ''"
            >
                FATURAMENTO
            </p>
            <div
                class="w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"
                :class="[
                    props.variant === 'sidebar' || props.variant === 'dashboard' ? 'h-2.5' : 'h-2',
                ]"
            >
                <div
                    class="h-full rounded-full bg-[var(--color-primary)] transition-all duration-500"
                    :style="{ width: `${progressPercent}%` }"
                />
            </div>
            <p
                class="mt-1 truncate text-zinc-500 dark:text-zinc-400"
                :class="[
                    props.variant === 'sidebar' || props.variant === 'dashboard' ? 'text-xs' : 'text-[11px]',
                ]"
            >
                {{ totalLabel }}
                <span v-if="nextLabel"> → {{ nextLabel }}</span>
            </p>
            <p
                v-if="remainingLabel"
                class="truncate text-zinc-400 dark:text-zinc-500"
                :class="[
                    props.variant === 'sidebar' || props.variant === 'dashboard' ? 'text-[10px]' : 'text-[10px]',
                ]"
            >
                Faltam {{ remainingLabel }}
            </p>
        </div>
    </Link>
</template>
