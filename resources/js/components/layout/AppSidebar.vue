<script setup>
import AppSidebarDefault from '@/components/layout/sidebar/AppSidebarDefault.vue';
import AppSidebarAurora from '@/components/layout/sidebar/AppSidebarAurora.vue';
import AppSidebarKawaii from '@/components/layout/sidebar/AppSidebarKawaii.vue';
import AppSidebarNoir from '@/components/layout/sidebar/AppSidebarNoir.vue';
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

const { isDefault, isAurora, isKawaii, isNoir } = useSellerDashboardTemplate();
</script>

<template>
    <AppSidebarDefault v-if="isDefault" />
    <AppSidebarAurora v-else-if="isAurora" />
    <AppSidebarKawaii v-else-if="isKawaii" />
    <AppSidebarNoir v-else-if="isNoir" />
</template>
