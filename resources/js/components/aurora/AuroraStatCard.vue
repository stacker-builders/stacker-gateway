<script setup>
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

defineProps({
    icon: { type: Object, required: true },
    label: { type: String, required: true },
    value: { type: String, required: true },
});

const { isAurora, isKawaii, isNoir } = useSellerDashboardTemplate();
</script>

<template>
    <div
        v-if="isAurora"
        class="aurora-stat-card"
    >
        <div class="aurora-stat-card-label">
            <component :is="icon" class="h-5 w-5 text-[var(--color-primary)]" aria-hidden="true" />
            <span>{{ label }}</span>
        </div>
        <p class="aurora-stat-card-value">
            {{ value }}
        </p>
    </div>
    <div
        v-else-if="isKawaii"
        class="kawaii-stat-card"
    >
        <div class="kawaii-stat-card-label flex items-center gap-2">
            <component :is="icon" class="h-5 w-5 text-[var(--kawaii-accent-text)]" aria-hidden="true" />
            <span>{{ label }}</span>
        </div>
        <p class="kawaii-stat-card-value">
            {{ value }}
        </p>
    </div>
    <div
        v-else-if="isNoir"
        class="noir-stat-card"
    >
        <div class="noir-stat-card-label">
            <component :is="icon" class="h-5 w-5 text-[var(--color-primary)]" aria-hidden="true" />
            <span>{{ label }}</span>
        </div>
        <p class="noir-stat-card-value">
            {{ value }}
        </p>
    </div>
    <div
        v-else
        class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50"
    >
        <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
            <component :is="icon" class="h-5 w-5" aria-hidden="true" />
            <span class="text-sm font-medium">{{ label }}</span>
        </div>
        <p class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">
            {{ value }}
        </p>
    </div>
</template>
