<script setup>
import { Link } from '@inertiajs/vue3';
import MemberAreaAppLayout from '@/Layouts/MemberAreaAppLayout.vue';
import Button from '@/components/ui/Button.vue';
import { useMemberAreaHref } from '@/composables/useMemberAreaHref';

defineOptions({ layout: MemberAreaAppLayout });

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    items: { type: Array, default: () => [] },
    slug: { type: String, required: true },
    base_url: { type: String, default: '' },
});

const { href } = useMemberAreaHref(props.slug, props.base_url);

function checkoutHref(item) {
    if (item?.checkout_url) {
        return item.checkout_url;
    }
    if (item?.checkout_slug) {
        return `/c/${item.checkout_slug}`;
    }
    return '#';
}
</script>

<template>
    <div class="space-y-8">
        <h1 class="text-2xl font-bold">Loja</h1>
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="item in items" :key="item.id" class="rounded-xl border border-zinc-700 bg-zinc-800/50 overflow-hidden">
                <div class="aspect-video bg-zinc-700 flex items-center justify-center">
                    <img v-if="item.image_url" :src="item.image_url" :alt="item.name" class="h-full w-full object-cover" />
                    <svg v-else class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14" /></svg>
                </div>
                <div class="p-4">
                    <h2 class="font-semibold">{{ item.name }}</h2>
                    <p v-if="item.description" class="mt-1 text-sm text-zinc-400 line-clamp-2">{{ item.description }}</p>
                    <div class="mt-4">
                        <a
                            v-if="item.has_access && item.access_url"
                            :href="item.access_url"
                            class="text-sm text-[var(--ma-primary)] hover:underline"
                            :target="/^https?:\/\//i.test(item.access_url) ? '_blank' : undefined"
                            :rel="/^https?:\/\//i.test(item.access_url) ? 'noopener' : undefined"
                        >Acessar</a>
                        <Link v-else-if="item.has_access" :href="href('/')" class="text-sm text-[var(--ma-primary)] hover:underline">Acessar área</Link>
                        <Button
                            v-else
                            as="a"
                            :href="checkoutHref(item)"
                            target="_blank"
                            rel="noopener"
                            size="sm"
                            class="touch-manipulation"
                        >
                            Comprar · R$ {{ item.price }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
