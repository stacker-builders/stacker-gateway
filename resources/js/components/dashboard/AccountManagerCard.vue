<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

const props = defineProps({
    manager: { type: Object, default: null },
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'kawaii', 'aurora'].includes(v),
    },
});

const page = usePage();
const { isAurora, isKawaii } = useSellerDashboardTemplate();

const manager = computed(() => props.manager ?? page.props.account_manager ?? null);
const open = ref(false);

const resolvedVariant = computed(() => {
    if (props.variant !== 'default') return props.variant;
    if (isKawaii.value) return 'kawaii';
    if (isAurora.value) return 'aurora';
    return 'default';
});

const iconBtnClass = computed(() => {
    if (resolvedVariant.value === 'kawaii') return 'kawaii-icon-btn';
    if (resolvedVariant.value === 'aurora') return 'aurora-icon-btn';
    return 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';
});

const initial = computed(() => (manager.value?.name || '?').charAt(0).toUpperCase());

function openModal() {
    open.value = true;
}

function closeModal() {
    open.value = false;
}

function onKeydown(e) {
    if (e.key === 'Escape' && open.value) {
        closeModal();
    }
}

watch(open, (isOpen) => {
    if (typeof document === 'undefined') return;
    if (isOpen) {
        document.addEventListener('keydown', onKeydown);
        document.body.style.overflow = 'hidden';
    } else {
        document.removeEventListener('keydown', onKeydown);
        document.body.style.overflow = '';
    }
});

onBeforeUnmount(() => {
    if (typeof document === 'undefined') return;
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <template v-if="manager">
        <button
            type="button"
            class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
            :class="iconBtnClass"
            :title="`Gerente: ${manager.name}`"
            :aria-label="`Gerente de conta: ${manager.name}`"
            @click="openModal"
        >
            <img
                v-if="manager.photo_url"
                :src="manager.photo_url"
                :alt="manager.name"
                class="h-6 w-6 rounded-full object-cover ring-1 ring-zinc-200/80 dark:ring-zinc-600/80"
            />
            <span
                v-else
                class="flex h-6 w-6 items-center justify-center rounded-full bg-zinc-200 text-[10px] font-semibold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200"
            >
                {{ initial }}
            </span>
        </button>

        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="open"
                    class="fixed inset-0 z-[100000] flex items-center justify-center p-4"
                    aria-modal="true"
                    role="dialog"
                    aria-labelledby="account-manager-modal-title"
                >
                    <div
                        class="absolute inset-0 bg-black/40"
                        aria-hidden="true"
                        @click="closeModal"
                    />
                    <div
                        class="relative w-full max-w-sm rounded-2xl border border-zinc-200 bg-white p-5 shadow-2xl dark:border-zinc-700 dark:bg-zinc-900"
                        @click.stop
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Gerente de conta
                                </p>
                                <h2
                                    id="account-manager-modal-title"
                                    class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white"
                                >
                                    {{ manager.name }}
                                </h2>
                            </div>
                            <button
                                type="button"
                                class="rounded-lg p-1.5 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                                aria-label="Fechar"
                                @click="closeModal"
                            >
                                <X class="h-4 w-4" />
                            </button>
                        </div>

                        <div class="mt-4 flex items-center gap-3">
                            <img
                                v-if="manager.photo_url"
                                :src="manager.photo_url"
                                :alt="manager.name"
                                class="h-14 w-14 shrink-0 rounded-full object-cover"
                            />
                            <div
                                v-else
                                class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-lg font-semibold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200"
                            >
                                {{ initial }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <p
                                    v-if="manager.email"
                                    class="break-all text-sm text-zinc-600 dark:text-zinc-400"
                                >
                                    {{ manager.email }}
                                </p>
                                <p
                                    v-if="manager.phone_display || manager.phone"
                                    class="text-sm text-zinc-600 dark:text-zinc-400"
                                >
                                    {{ manager.phone_display || manager.phone }}
                                </p>
                            </div>
                        </div>

                        <a
                            v-if="manager.whatsapp_url"
                            :href="manager.whatsapp_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-5 inline-flex w-full items-center justify-center rounded-xl bg-[#25D366] px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90"
                        >
                            Falar no WhatsApp
                        </a>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </template>
</template>
