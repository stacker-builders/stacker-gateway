<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { ChevronDown, ChevronUp, Logs } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    entries: { type: Array, default: () => [] },
    available_dates: { type: Array, default: () => [] },
    file: { type: Object, default: null },
    filters: {
        type: Object,
        default: () => ({
            date: null,
            level: 'warning',
            q: null,
            per_page: 100,
        }),
    },
});

const date = ref(props.filters.date || '');
const level = ref(props.filters.level || 'warning');
const searchQ = ref(props.filters.q || '');
const perPage = ref(String(props.filters.per_page || 100));
const expandedId = ref(null);
const live = ref(false);
let pollTimer = null;

watch(
    () => props.filters,
    (f) => {
        date.value = f.date || '';
        level.value = f.level || 'warning';
        searchQ.value = f.q || '';
        perPage.value = String(f.per_page || 100);
    }
);

const rows = computed(() => (Array.isArray(props.entries) ? props.entries : []));
const dateChips = computed(() => (Array.isArray(props.available_dates) ? props.available_dates.slice(0, 8) : []));

const quickFilters = [
    { label: 'Cielo', q: 'cielo' },
    { label: 'PIX', q: 'pix' },
    { label: 'Caju', q: 'cajupay' },
    { label: 'PaymentService', q: 'PaymentService' },
];

function listingQuery(overrides = {}) {
    return {
        date: (overrides.date !== undefined ? overrides.date : date.value) || undefined,
        level: (overrides.level !== undefined ? overrides.level : level.value) || 'warning',
        q: (overrides.q !== undefined ? overrides.q : searchQ.value)?.trim() || undefined,
        per_page: Number(overrides.per_page !== undefined ? overrides.per_page : perPage.value) || 100,
    };
}

function applyFilters(overrides = {}) {
    if (overrides.date !== undefined) date.value = overrides.date;
    if (overrides.level !== undefined) level.value = overrides.level;
    if (overrides.q !== undefined) searchQ.value = overrides.q;
    router.get('/plataforma/log-sistema', listingQuery(overrides), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    const today = new Date().toISOString().slice(0, 10);
    date.value = today;
    level.value = 'warning';
    searchQ.value = '';
    perPage.value = '100';
    router.get('/plataforma/log-sistema', { date: today, level: 'warning', per_page: 100 }, {
        preserveState: true,
        replace: true,
    });
}

function formatDateTime(value) {
    if (!value) return '—';
    const d = new Date(value.includes('T') ? value : value.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString('pt-BR');
}

function formatChipDate(isoDate) {
    if (!isoDate) return '';
    const [y, m, d] = String(isoDate).split('-');
    if (!d) return isoDate;
    return `${d}/${m}/${y}`;
}

function formatBytes(size) {
    const n = Number(size || 0);
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

function levelClass(levelName) {
    const lv = String(levelName || '').toUpperCase();
    if (['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(lv)) {
        return 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300';
    }
    if (lv === 'WARNING') {
        return 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200';
    }
    return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
}

function rowClass(entry) {
    const lv = String(entry?.level || '').toUpperCase();
    if (['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(lv)) {
        return 'bg-red-50/70 dark:bg-red-950/20';
    }
    if (lv === 'WARNING') {
        return 'bg-amber-50/50 dark:bg-amber-950/10';
    }
    return '';
}

function contextText(context) {
    if (!context) return '';
    try {
        return JSON.stringify(context, null, 2);
    } catch {
        return String(context);
    }
}

function toggleExpanded(id) {
    expandedId.value = expandedId.value === id ? null : id;
}

function startLive() {
    stopLive();
    pollTimer = setInterval(() => {
        router.get('/plataforma/log-sistema', listingQuery(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['entries', 'file', 'available_dates'],
        });
    }, 4000);
}

function stopLive() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function toggleLive() {
    live.value = !live.value;
    if (live.value) {
        startLive();
    } else {
        stopLive();
    }
}

onUnmounted(stopLive);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="flex items-center gap-2 text-xl font-semibold text-zinc-900 dark:text-white">
                    <Logs class="h-6 w-6 text-[var(--color-primary)]" />
                    Log sistema
                </h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Erros e avisos do Laravel (PIX, adquirentes, filas). Visível apenas para o operador.
                </p>
            </div>
            <button
                type="button"
                class="inline-flex items-center gap-2 self-start rounded-lg px-3 py-2 text-sm font-medium transition"
                :class="
                    live
                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200'
                        : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'
                "
                @click="toggleLive"
            >
                <span
                    class="h-2 w-2 rounded-full"
                    :class="live ? 'animate-pulse bg-emerald-500' : 'bg-zinc-400'"
                />
                {{ live ? 'Ao vivo ligado' : 'Ao vivo' }}
            </button>
        </div>

        <p v-if="file" class="text-xs text-zinc-500">
            Arquivo <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ file.name }}</span>
            <template v-if="file.exists"> — {{ formatBytes(file.size) }}</template>
            <template v-else> — ainda não existe neste dia</template>
            <template v-if="file.truncated"> — mostrando o final do arquivo</template>
        </p>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="d in dateChips"
                :key="d"
                type="button"
                class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                :class="
                    date === d
                        ? 'bg-[var(--color-primary)]/20 text-zinc-900 dark:text-white'
                        : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'
                "
                @click="applyFilters({ date: d })"
            >
                {{ formatChipDate(d) }}
            </button>
        </div>

        <form
            class="grid gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/40 sm:grid-cols-2 lg:grid-cols-6"
            @submit.prevent="applyFilters()"
        >
            <label class="text-xs text-zinc-500">
                Dia
                <input
                    v-model="date"
                    type="date"
                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
            </label>
            <label class="text-xs text-zinc-500">
                Nível
                <select
                    v-model="level"
                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                >
                    <option value="warning">Alertas e erros</option>
                    <option value="error">Só erros</option>
                    <option value="all">Todos</option>
                </select>
            </label>
            <label class="text-xs text-zinc-500 sm:col-span-2">
                Busca
                <input
                    v-model="searchQ"
                    type="search"
                    placeholder="cielo, pix, affiliation..."
                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
            </label>
            <label class="text-xs text-zinc-500">
                Limite
                <select
                    v-model="perPage"
                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                >
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="200">200</option>
                </select>
            </label>
            <div class="flex items-end gap-2">
                <Button type="submit" class="flex-1">Filtrar</Button>
                <Button type="button" variant="secondary" class="flex-1" @click="clearFilters">Limpar</Button>
            </div>
        </form>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="chip in quickFilters"
                :key="chip.q"
                type="button"
                class="rounded-lg px-3 py-1.5 text-xs font-medium transition"
                :class="
                    searchQ.toLowerCase() === chip.q.toLowerCase()
                        ? 'bg-[var(--color-primary)]/20 text-zinc-900 dark:text-white'
                        : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'
                "
                @click="applyFilters({ q: chip.q })"
            >
                {{ chip.label }}
            </button>
        </div>

        <div v-if="rows.length" class="space-y-3 md:hidden">
            <button
                v-for="entry in rows"
                :key="`mobile-${entry.id}`"
                type="button"
                class="block w-full rounded-2xl border bg-white p-4 text-left shadow-sm dark:bg-zinc-900/40"
                :class="rowClass(entry) ? 'border-amber-300 dark:border-amber-800' : 'border-zinc-200 dark:border-zinc-700'"
                @click="toggleExpanded(entry.id)"
            >
                <div class="flex items-start justify-between gap-3">
                    <p class="min-w-0 break-words text-sm font-semibold text-zinc-900 dark:text-white">
                        {{ entry.message }}
                    </p>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="levelClass(entry.level)">
                        {{ entry.level }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-zinc-500">{{ formatDateTime(entry.logged_at) }}</p>
                <pre
                    v-if="expandedId === entry.id && entry.context"
                    class="mt-3 overflow-x-auto rounded-lg bg-zinc-50 p-3 text-[11px] text-zinc-800 dark:bg-zinc-800/60 dark:text-zinc-200"
                >{{ contextText(entry.context) }}</pre>
            </button>
        </div>

        <div class="hidden overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40 md:block">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-500">Data</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-500">Nível</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-500">Mensagem</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-500">Detalhes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <template v-for="entry in rows" :key="entry.id">
                            <tr class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/30" :class="rowClass(entry)">
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-300">
                                    {{ formatDateTime(entry.logged_at) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="levelClass(entry.level)">
                                        {{ entry.level }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-zinc-900 dark:text-white">
                                    {{ entry.message }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 text-sm font-medium text-[var(--color-primary)] hover:underline"
                                        @click="toggleExpanded(entry.id)"
                                    >
                                        {{ expandedId === entry.id ? 'Ocultar' : 'Ver' }}
                                        <ChevronUp v-if="expandedId === entry.id" class="h-4 w-4" />
                                        <ChevronDown v-else class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="expandedId === entry.id">
                                <td colspan="4" class="bg-zinc-50 px-4 py-3 text-sm dark:bg-zinc-800/40">
                                    <pre
                                        v-if="entry.context"
                                        class="overflow-x-auto whitespace-pre-wrap break-all text-xs text-zinc-800 dark:text-zinc-200"
                                    >{{ contextText(entry.context) }}</pre>
                                    <pre
                                        v-if="entry.trace"
                                        class="mt-3 overflow-x-auto whitespace-pre-wrap break-all text-[11px] text-zinc-600 dark:text-zinc-400"
                                    >{{ entry.trace }}</pre>
                                    <p v-if="!entry.context && !entry.trace" class="text-zinc-500">Sem detalhes adicionais.</p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="!rows.length" class="rounded-xl border border-dashed border-zinc-200 px-4 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700">
            Nenhum log encontrado para estes filtros.
        </div>
    </div>
</template>
