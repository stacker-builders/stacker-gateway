<script setup>
import { CreditCard } from 'lucide-vue-next';

defineProps({
    method: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    primaryColor: { type: String, default: '#0ea5e9' },
});
</script>

<template>
    <span
        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg"
        :style="{ backgroundColor: selected ? primaryColor + '20' : undefined }"
    >
        <CreditCard
            class="h-5 w-5"
            :style="{ color: selected ? primaryColor : undefined }"
        />
    </span>
    <div class="min-w-0 flex-1">
        <span class="block font-medium text-gray-900">{{ method.label }}</span>
        <span v-if="method.hint" class="mt-0.5 block text-xs leading-snug text-gray-500">{{ method.hint }}</span>
    </div>
</template>
