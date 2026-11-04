<script setup>
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { Package } from 'lucide-vue-next';

const props = defineProps({
    sections: { type: Array, default: () => [] },
    platformName: { type: String, default: 'plataforma' },
});

const page = usePage();
const branding = computed(() => page.props.public_branding ?? page.props.appSettings ?? {});
const logoLight = computed(() => branding.value.app_logo || branding.value.app_logo_icon || '');
const logoDark = computed(() => branding.value.app_logo_dark || branding.value.app_logo_icon_dark || logoLight.value);
const selected = ref('all');

const visibleSections = computed(() => {
    if (selected.value === 'all') {
        return props.sections;
    }

    return props.sections.filter((section) => section.key === selected.value);
});

function formatMoney(amount, currency) {
    const code = ['BRL', 'EUR', 'USD'].includes(currency) ? currency : 'BRL';

    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: code }).format(Number(amount) || 0);
}
</script>

<template>
    <div class="min-h-screen bg-zinc-50 text-zinc-900 dark:bg-zinc-950 dark:text-white">
        <Head :title="`Marketplace · ${platformName}`" />
        <header class="border-b border-zinc-200 bg-white px-4 py-8 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mx-auto flex max-w-6xl justify-center">
                <img
                    v-if="logoLight"
                    :src="logoLight"
                    :alt="platformName"
                    class="h-12 w-auto max-w-[220px] object-contain dark:hidden"
                />
                <img
                    v-if="logoDark"
                    :src="logoDark"
                    :alt="platformName"
                    class="hidden h-12 w-auto max-w-[220px] object-contain dark:block"
                />
                <span v-if="!logoLight && !logoDark" class="text-xl font-semibold tracking-tight">{{ platformName }}</span>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-8">
            <div v-if="sections.length" class="flex flex-wrap justify-center gap-2">
                <button
                    type="button"
                    class="rounded-full px-4 py-2 text-sm font-medium transition"
                    :class="selected === 'all'
                        ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900'
                        : 'border border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200'"
                    @click="selected = 'all'"
                >
                    Todos
                </button>
                <button
                    v-for="section in sections"
                    :key="section.key"
                    type="button"
                    class="rounded-full px-4 py-2 text-sm font-medium transition"
                    :class="selected === section.key
                        ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900'
                        : 'border border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200'"
                    @click="selected = section.key"
                >
                    {{ section.label }}
                </button>
            </div>

            <p
                v-if="!visibleSections.length"
                class="mt-10 rounded-2xl border border-dashed border-zinc-200 px-4 py-12 text-center text-sm text-zinc-500 dark:border-zinc-700"
            >
                Nenhum produto no marketplace no momento.
            </p>

            <section
                v-for="section in visibleSections"
                :key="section.key"
                class="mt-10"
            >
                <h2 class="text-lg font-semibold">{{ section.label }}</h2>
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article
                        v-for="product in section.products"
                        :key="product.id"
                        class="flex flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                    >
                        <div class="flex h-40 items-center justify-center bg-zinc-100 dark:bg-zinc-800">
                            <img
                                v-if="product.image_url"
                                :src="product.image_url"
                                :alt="product.name"
                                class="h-full w-full object-cover"
                                loading="lazy"
                            />
                            <Package v-else class="h-10 w-10 text-zinc-400" aria-hidden="true" />
                        </div>
                        <div class="flex flex-1 flex-col gap-2 p-4">
                            <h3 class="line-clamp-2 font-semibold">{{ product.name }}</h3>
                            <p v-if="product.seller_name" class="text-xs text-zinc-500">{{ product.seller_name }}</p>
                            <p class="text-lg font-bold">{{ formatMoney(product.price, product.currency) }}</p>
                            <a
                                :href="product.checkout_url"
                                class="mt-auto inline-flex items-center justify-center rounded-xl bg-[var(--color-primary)] px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90"
                            >
                                Comprar
                            </a>
                        </div>
                    </article>
                </div>
            </section>
        </main>
    </div>
</template>
