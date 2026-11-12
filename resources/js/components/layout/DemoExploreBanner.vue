<script setup>
import { ref, watch, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Sparkles, Sun, LayoutGrid } from 'lucide-vue-next';

const DEMO_TEMPLATE_KEY = 'demo_template_preview';

const page = usePage();
const enabled = computed(() => !!page.props.demo_mode?.enabled);

const templates = [
    { id: 'default', label: 'Padrão' },
    { id: 'aurora', label: 'Aurora' },
    { id: 'kawaii', label: 'Kawaii' },
    { id: 'noir', label: 'Noir' },
];

const selected = ref(readPreview());

function readPreview() {
    if (typeof window === 'undefined') return 'default';
    const raw = localStorage.getItem(DEMO_TEMPLATE_KEY);
    if (raw === 'aurora' || raw === 'kawaii' || raw === 'noir') return raw;
    return 'default';
}

function setTemplate(id) {
    if (selected.value === id) {
        return;
    }
    selected.value = id;
    if (typeof window === 'undefined') {
        return;
    }
    localStorage.setItem(DEMO_TEMPLATE_KEY, id);
    window.dispatchEvent(new CustomEvent('demo-template-preview-changed', { detail: id }));
    router.reload({ preserveScroll: true });
}

watch(
    () => page.props.demo_mode?.enabled,
    (on) => {
        if (on) selected.value = readPreview();
    }
);
</script>

<template>
    <div
        v-if="enabled"
        class="rounded-xl border border-sky-200 bg-gradient-to-r from-sky-50 to-violet-50 px-4 py-3 text-sm text-zinc-800 dark:border-sky-900/50 dark:from-sky-950/40 dark:to-violet-950/30 dark:text-zinc-100"
        role="region"
        aria-label="Explorar visual do painel"
    >
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-start gap-2">
                <Sparkles class="mt-0.5 h-4 w-4 shrink-0 text-sky-600 dark:text-sky-400" aria-hidden="true" />
                <div>
                    <p class="font-medium">Explore o visual do painel</p>
                    <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">
                        Use o botão de tema no header (<Sun class="inline h-3.5 w-3.5 align-text-bottom" aria-hidden="true" /> claro/escuro)
                        e teste os templates do dashboard abaixo — preview local, sem alterar a plataforma.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="flex items-center gap-1 text-xs font-medium text-zinc-500">
                    <LayoutGrid class="h-3.5 w-3.5" aria-hidden="true" />
                    Template:
                </span>
                <button
                    v-for="tpl in templates"
                    :key="tpl.id"
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium transition"
                    :class="selected === tpl.id
                        ? 'bg-[var(--color-primary)] text-zinc-900 shadow-sm'
                        : 'border border-zinc-300 bg-white hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:hover:bg-zinc-700'"
                    @click="setTemplate(tpl.id)"
                >
                    {{ tpl.label }}
                </button>
            </div>
        </div>
    </div>
</template>
