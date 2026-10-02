<script setup>
import { computed, inject } from 'vue';

const custom = inject('dashboardCustomPeriod', null);

const visible = computed(() => custom?.period?.value === 'personalizado');
const startLabel = computed(() => custom?.startLabel?.value ?? 'Data inicial');
const endLabel = computed(() => custom?.endLabel?.value ?? 'Data final');
const applyLabel = computed(() => custom?.applyLabel?.value ?? 'Aplicar');

const fromDate = computed({
    get: () => custom?.from?.value ?? '',
    set: (value) => {
        if (custom?.from) {
            custom.from.value = value;
        }
    },
});

const toDate = computed({
    get: () => custom?.to?.value ?? '',
    set: (value) => {
        if (custom?.to) {
            custom.to.value = value;
        }
    },
});

function apply() {
    custom?.apply?.();
}
</script>

<template>
    <form
        v-if="visible"
        class="flex flex-wrap items-end gap-2"
        @submit.prevent="apply"
    >
        <label class="text-sm text-zinc-600 dark:text-zinc-400">
            {{ startLabel }}
            <input
                v-model="fromDate"
                type="date"
                class="mt-1 block rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
            />
        </label>
        <label class="text-sm text-zinc-600 dark:text-zinc-400">
            {{ endLabel }}
            <input
                v-model="toDate"
                type="date"
                class="mt-1 block rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
            />
        </label>
        <button type="submit" class="h-10 rounded-xl bg-[var(--color-primary)] px-4 text-sm font-medium text-white">
            {{ applyLabel }}
        </button>
    </form>
</template>
