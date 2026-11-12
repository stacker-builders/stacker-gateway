<script setup>
import { Link } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { useSidebar } from '@/composables/useSidebar';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import AppSidebarNavList from '@/components/layout/sidebar/AppSidebarNavList.vue';
import { useAppSidebarNav, navPrefetch } from '@/composables/useAppSidebarNav';

const { isMobileOpen, toggleSidebar, isMobile, closeMobileSidebarIfOpen } = useSidebar();
const {
    page,
    homeHref,
    appSettings,
    appName,
    hasLogoFull,
    navItems,
    isActive,
} = useAppSidebarNav();
</script>

<template>
    <aside
        :class="[
            'noir-sidebar fixed left-0 top-0 z-[99999] flex h-screen flex-col',
            'transition-transform duration-300 ease-in-out',
            isMobileOpen ? 'translate-x-0' : '-translate-x-full',
            isMobile && !isMobileOpen ? 'pointer-events-none' : '',
            'lg:translate-x-0',
        ]"
    >
        <div class="flex h-[72px] shrink-0 items-center justify-between px-5">
            <Link
                :href="homeHref"
                :prefetch="navPrefetch(isMobile)"
                class="flex min-w-0 flex-1 cursor-pointer touch-manipulation items-center gap-3 overflow-hidden"
                @click="closeMobileSidebarIfOpen"
            >
                <template v-if="hasLogoFull()">
                    <img
                        v-if="appSettings().app_logo"
                        :src="appSettings().app_logo"
                        :alt="appName()"
                        class="h-8 max-w-[170px] object-contain object-left dark:hidden"
                    />
                    <img
                        v-if="appSettings().app_logo_dark"
                        :src="appSettings().app_logo_dark"
                        :alt="appName()"
                        class="hidden h-8 max-w-[170px] object-contain object-left dark:block"
                    />
                    <img
                        v-else-if="appSettings().app_logo && !appSettings().app_logo_dark"
                        :src="appSettings().app_logo"
                        :alt="appName()"
                        class="hidden h-8 max-w-[170px] object-contain object-left dark:block"
                    />
                </template>
                <span v-else class="noir-fg truncate text-[15px] font-semibold tracking-tight">
                    {{ appName() }}
                </span>
            </Link>
            <button
                v-if="isMobile"
                type="button"
                class="noir-icon-btn ml-1 flex h-8 w-8 shrink-0 touch-manipulation cursor-pointer select-none items-center justify-center rounded-md"
                aria-label="Fechar menu"
                @click="toggleSidebar"
            >
                <X class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto no-scrollbar px-3 py-4">
            <AppSidebarNavList
                :items="navItems"
                :show-text="true"
                :is-active="isActive"
                :is-mobile="isMobile"
                variant="noir"
            />
        </nav>

        <div class="noir-sidebar-footer shrink-0 space-y-3 px-4 py-4">
            <ConquistasWidget v-if="!page.props.customer_panel" variant="sidebar" />
        </div>
    </aside>
</template>
