<script setup>
import { computed } from 'vue';
import {
    SelectContent,
    SelectItem,
    SelectItemIndicator,
    SelectItemText,
    SelectPortal,
    SelectRoot,
    SelectTrigger,
    SelectViewport,
} from 'radix-vue';
import { Eye, EyeOff, ChevronDown, Check, Calendar } from 'lucide-vue-next';

const props = defineProps({
    period: { type: String, required: true },
    periodOptions: { type: Array, required: true },
    valuesVisible: { type: Boolean, required: true },
    periodLabel: { type: String, default: 'Período' },
    hideValuesLabel: { type: String, default: 'Ocultar valores' },
    showValuesLabel: { type: String, default: 'Mostrar valores' },
    auroraStyle: { type: Boolean, default: false },
    shell: { type: String, default: '' },
});

const emit = defineEmits(['update:period', 'toggle-values']);

const triggerClassDefault = 'flex h-10 w-[240px] shrink-0 cursor-pointer items-center justify-between gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2 text-left text-sm transition hover:border-zinc-300 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 focus:ring-offset-0 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:hover:border-zinc-500';
const contentClassDefault = 'z-[9999] min-w-[var(--radix-select-trigger-width)] overflow-hidden rounded-xl border border-zinc-200 bg-white py-1 shadow-xl dark:border-zinc-600 dark:bg-zinc-800';
const contentClassAurora = 'aurora-select-content z-[99999] min-w-[var(--radix-select-trigger-width)] overflow-hidden rounded-xl border py-1 shadow-xl';

const themedPrefix = computed(() => {
    if (props.shell === 'noir') return 'noir';
    if (props.auroraStyle || props.shell === 'aurora') return 'aurora';
    return null;
});

function formatLongDate(date) {
    return date.toLocaleDateString('pt-BR', { day: 'numeric', month: 'long' });
}

const periodDisplayLabel = computed(() => {
    const now = new Date();

    if (props.period === 'hoje') {
        return `Hoje, ${formatLongDate(now)}`;
    }

    if (props.period === 'ontem') {
        const yesterday = new Date(now);
        yesterday.setDate(yesterday.getDate() - 1);
        return `Ontem, ${formatLongDate(yesterday)}`;
    }

    const selected = props.periodOptions.find((opt) => opt.value === props.period);
    return selected?.label ?? props.periodLabel;
});
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-2"
        :class="themedPrefix ? 'w-full justify-end' : 'justify-between'"
    >
        <div v-if="themedPrefix" class="flex w-full items-center justify-end gap-2">
            <button
                type="button"
                :aria-label="valuesVisible ? hideValuesLabel : showValuesLabel"
                :class="`${themedPrefix}-icon-btn flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors`"
                @click="emit('toggle-values')"
            >
                <Eye v-if="valuesVisible" class="h-4 w-4" aria-hidden="true" />
                <EyeOff v-else class="h-4 w-4" aria-hidden="true" />
            </button>

            <SelectRoot :model-value="period" @update:model-value="emit('update:period', $event)">
                <SelectTrigger
                    type="button"
                    :aria-label="periodLabel"
                    :class="`${themedPrefix}-date-picker-btn shrink-0`"
                >
                    <Calendar class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span class="truncate">{{ periodDisplayLabel }}</span>
                    <ChevronDown class="ml-1 h-3 w-3 shrink-0 opacity-50" aria-hidden="true" />
                </SelectTrigger>
                <SelectPortal to="body">
                    <SelectContent
                        :class="themedPrefix === 'noir'
                            ? 'noir-select-content z-[99999] min-w-[var(--radix-select-trigger-width)] overflow-hidden rounded-xl border py-1 shadow-xl'
                            : contentClassAurora"
                        :side-offset="4"
                        position="popper"
                        :avoid-collisions="true"
                    >
                        <SelectViewport class="p-1">
                            <SelectItem
                                v-for="opt in periodOptions"
                                :key="opt.value"
                                :value="opt.value"
                                class="relative flex cursor-pointer select-none items-center rounded-lg py-2.5 pl-10 pr-4 text-sm outline-none transition data-[highlighted]:bg-[var(--color-primary)]/10 data-[highlighted]:text-[var(--color-primary)] data-[state=checked]:bg-[var(--color-primary)]/10 data-[state=checked]:text-[var(--color-primary)]"
                            >
                                <SelectItemIndicator class="absolute left-3 flex h-4 w-4 items-center justify-center">
                                    <Check class="h-4 w-4 text-[var(--color-primary)]" />
                                </SelectItemIndicator>
                                <SelectItemText>{{ opt.label }}</SelectItemText>
                            </SelectItem>
                        </SelectViewport>
                    </SelectContent>
                </SelectPortal>
            </SelectRoot>
        </div>

        <template v-else>
            <div class="flex w-full items-center gap-2 lg:hidden">
                <SelectRoot :model-value="period" @update:model-value="emit('update:period', $event)">
                    <SelectTrigger
                        type="button"
                        :aria-label="periodLabel"
                        :class="triggerClassDefault"
                    >
                        <span class="truncate">{{ periodDisplayLabel }}</span>
                        <ChevronDown class="h-4 w-4 shrink-0 text-zinc-400" aria-hidden="true" />
                    </SelectTrigger>
                    <SelectPortal to="body">
                        <SelectContent
                            :class="contentClassDefault"
                            :side-offset="4"
                            position="popper"
                            :avoid-collisions="true"
                        >
                            <SelectViewport class="p-1">
                                <SelectItem
                                    v-for="opt in periodOptions"
                                    :key="opt.value"
                                    :value="opt.value"
                                    class="relative flex cursor-pointer select-none items-center rounded-lg py-2.5 pl-10 pr-4 text-sm outline-none transition data-[highlighted]:bg-[var(--color-primary)]/10 data-[highlighted]:text-[var(--color-primary)] data-[state=checked]:bg-[var(--color-primary)]/10 data-[state=checked]:text-[var(--color-primary)]"
                                >
                                    <SelectItemIndicator class="absolute left-3 flex h-4 w-4 items-center justify-center">
                                        <Check class="h-4 w-4 text-[var(--color-primary)]" />
                                    </SelectItemIndicator>
                                    <SelectItemText>{{ opt.label }}</SelectItemText>
                                </SelectItem>
                            </SelectViewport>
                        </SelectContent>
                    </SelectPortal>
                </SelectRoot>
                <button
                    type="button"
                    :aria-label="valuesVisible ? hideValuesLabel : showValuesLabel"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-500 transition-colors hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400"
                    @click="emit('toggle-values')"
                >
                    <Eye v-if="valuesVisible" class="h-5 w-5" aria-hidden="true" />
                    <EyeOff v-else class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            <nav class="hidden flex-wrap items-center gap-1 lg:flex" :aria-label="periodLabel">
                <button
                    v-for="opt in periodOptions"
                    :key="opt.value"
                    type="button"
                    :aria-current="period === opt.value ? 'true' : undefined"
                    class="rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                    :class="period === opt.value
                        ? 'bg-[var(--color-primary)] text-white shadow-sm'
                        : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                    @click="emit('update:period', opt.value)"
                >
                    {{ opt.label }}
                </button>
            </nav>
            <button
                type="button"
                :aria-label="valuesVisible ? hideValuesLabel : showValuesLabel"
                class="hidden h-9 w-9 shrink-0 items-center justify-center rounded-lg text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 lg:flex"
                @click="emit('toggle-values')"
            >
                <Eye v-if="valuesVisible" class="h-5 w-5" aria-hidden="true" />
                <EyeOff v-else class="h-5 w-5" aria-hidden="true" />
            </button>
        </template>
    </div>
</template>
