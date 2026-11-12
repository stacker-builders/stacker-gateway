<script setup>
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

defineProps({
    flush: { type: Boolean, default: false },
});

const { isAurora, isKawaii, isNoir } = useSellerDashboardTemplate();
</script>

<template>
    <section
        v-if="isAurora"
        class="aurora-page-section"
        :class="flush ? 'aurora-page-section-flush' : ''"
    >
        <slot />
    </section>
    <section
        v-else-if="isKawaii"
        class="kawaii-page-section"
    >
        <slot />
    </section>
    <section
        v-else-if="isNoir"
        class="noir-page-section"
        :class="flush ? 'noir-page-section-flush' : ''"
    >
        <slot />
    </section>
    <div v-else>
        <slot />
    </div>
</template>
