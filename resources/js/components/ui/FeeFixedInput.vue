<script setup>
import { ref, watch } from 'vue';
import { sanitizeLocalizedDecimalTyping } from '@/lib/localizedDecimalInput';
import { formatMoneyForDisplayBr, normalizeMoneyInput } from '@/lib/moneyDecimal';

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
    if (value === '' || value === null || value === undefined) {
        return formatMoneyForDisplayBr(0);
    }
    return formatMoneyForDisplayBr(value);
}

function sameMoney(a, b) {
    const empty = (value) => value === '' || value === null || value === undefined;
    if (props.allowEmpty && empty(a) && empty(b)) return true;
    if (props.allowEmpty && (empty(a) || empty(b))) return false;
    return normalizeMoneyInput(a) === normalizeMoneyInput(b);
}

function commit() {
    if (props.allowEmpty && local.value.trim() === '') {
        if (!sameMoney(props.modelValue, '')) {
            emit('update:modelValue', '');
        }
        local.value = '';
        return;
    }

    const normalized = normalizeMoneyInput(local.value);
    if (!sameMoney(props.modelValue, normalized)) {
        emit('update:modelValue', normalized);
    }
    local.value = formatMoneyForDisplayBr(normalized);
}

function onFocus() {
    focused.value = true;
}

function onInput(event) {
    local.value = sanitizeLocalizedDecimalTyping(event.target.value, 2);
}

function onBlur() {
    focused.value = false;
    commit();
}

defineExpose({ commit });
</script>

<template>
    <div
        class="flex w-full max-w-[160px] overflow-hidden rounded-lg border border-zinc-300 dark:border-zinc-600 dark:bg-zinc-900 focus-within:ring-2 focus-within:ring-zinc-400/30 dark:focus-within:ring-zinc-500/40"
        :class="inputClass"
    >
        <span
            class="flex shrink-0 items-center border-r border-zinc-300 bg-zinc-50 px-2.5 text-sm font-medium text-zinc-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400"
            aria-hidden="true"
        >R$</span>
        <input
            :value="local"
            type="text"
            inputmode="decimal"
            lang="pt-BR"
            autocomplete="off"
            :placeholder="allowEmpty ? 'Padrão' : '0,00'"
            class="min-w-0 flex-1 border-0 bg-transparent py-2 pl-2 pr-3 text-zinc-900 outline-none dark:text-zinc-100"
            @focus="onFocus"
            @input="onInput"
            @blur="onBlur"
        />
    </div>
</template>
