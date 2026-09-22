<script setup>
import { ref, watch } from 'vue';
import { sanitizeLocalizedDecimalTyping } from '@/lib/localizedDecimalInput';
import {
    formatPercentForDisplayBr,
    formatPercentForInput,
    normalizePercentInput,
} from '@/lib/percentDecimal';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    /** Quando true, permite vazio (override opcional). */
    allowEmpty: { type: Boolean, default: false },
    inputClass: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const local = ref(toDisplay(props.modelValue));
const focused = ref(false);

watch(
    () => props.modelValue,
    (value) => {
        if (focused.value) {
            return;
        }
        local.value = toDisplay(value);
    }
);

function toDisplay(value) {
    if (props.allowEmpty && (value === '' || value === null || value === undefined)) {
        return '';
    }
    return formatPercentForDisplayBr(value);
}

function samePercent(a, b) {
    const empty = (value) => value === '' || value === null || value === undefined;
    if (empty(a) && empty(b)) return true;
    if (empty(a) || empty(b)) return false;
    return normalizePercentInput(a) === normalizePercentInput(b);
}

function commit() {
    if (props.allowEmpty && local.value.trim() === '') {
        if (!samePercent(props.modelValue, '')) {
            emit('update:modelValue', '');
        }
        local.value = '';
        return;
    }

    const normalized = normalizePercentInput(local.value);
    const canonical = formatPercentForInput(normalized) || (props.allowEmpty ? '' : '0');
    const emitted = canonical === '' ? '' : normalized;
    if (!samePercent(props.modelValue, emitted)) {
        emit('update:modelValue', emitted);
    }
    local.value = canonical === '' ? '' : formatPercentForDisplayBr(canonical);
}

function onFocus() {
    focused.value = true;
}

function onInput(event) {
    local.value = sanitizeLocalizedDecimalTyping(event.target.value, 4);
}

function onBlur() {
    focused.value = false;
    commit();
}

defineExpose({ commit });
</script>

<template>
    <div class="relative w-full max-w-[160px]">
        <input
            :value="local"
            type="text"
            inputmode="decimal"
            lang="pt-BR"
            autocomplete="off"
            :placeholder="allowEmpty ? 'Padrão' : '0,00'"
            :class="[
                'w-full rounded-lg border border-zinc-300 py-2 pl-3 pr-8 text-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100',
                inputClass,
            ]"
            @focus="onFocus"
            @input="onInput"
            @blur="onBlur"
        />
        <span
            class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-sm font-medium text-zinc-500 dark:text-zinc-400"
            aria-hidden="true"
        >%</span>
    </div>
</template>
