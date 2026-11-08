<script setup>
import { ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import { Send } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    settings: { type: Object, required: true },
    filters: { type: Object, default: () => ({ q: '' }) },
    sellers: { type: Array, default: () => [] },
    monitoring: { type: Object, default: () => ({ ready: false }) },
    commercial_readiness: { type: Object, default: () => ({ items: [], recommendation: '', sales_operational: false }) },
});

const page = usePage();
const search = ref(props.filters?.q ?? '');
const sellerDrafts = ref([]);
const savingSellerId = ref(null);

const form = useForm({
    module_enabled: Boolean(props.settings.module_enabled),
    commercial_model: 'free',
    bot_limit: props.settings.bot_limit ?? null,
    community_limit: props.settings.community_limit ?? null,
    campaigns_enabled: Boolean(props.settings.campaigns_enabled),
    auto_delivery_enabled: Boolean(props.settings.auto_delivery_enabled),
    expiry_control_enabled: Boolean(props.settings.expiry_control_enabled),
    expiry_removal_enabled: Boolean(props.settings.expiry_removal_enabled),
    sales_enabled: Boolean(props.settings.sales_enabled),
    auto_compensation_enabled: Boolean(props.settings.auto_compensation_enabled),
});

watch(
    () => props.settings,
    (settings) => {
        form.defaults({
            module_enabled: Boolean(settings.module_enabled),
            commercial_model: 'free',
            bot_limit: settings.bot_limit ?? null,
            community_limit: settings.community_limit ?? null,
            campaigns_enabled: Boolean(settings.campaigns_enabled),
            auto_delivery_enabled: Boolean(settings.auto_delivery_enabled),
            expiry_control_enabled: Boolean(settings.expiry_control_enabled),
            expiry_removal_enabled: Boolean(settings.expiry_removal_enabled),
            sales_enabled: Boolean(settings.sales_enabled),
            auto_compensation_enabled: Boolean(settings.auto_compensation_enabled),
        });
        form.reset();
    },
    { deep: true },
);

watch(
    () => props.sellers,
    (rows) => {
        sellerDrafts.value = (rows ?? []).map((row) => ({ ...row }));
    },
    { immediate: true, deep: true },
);

watch(
    () => props.filters?.q,
    (value) => {
        search.value = value ?? '';
    },
);

const modeOptions = [
    { value: 'inherit', label: 'Herdar plataforma' },
    { value: 'enabled', label: 'Habilitada' },
    { value: 'disabled', label: 'Desabilitada' },
];

function limitInput(field, event) {
    const raw = String(event.target?.value ?? '').trim();
    form[field] = raw === '' ? null : Number(raw);
}

function submitSettings() {
    form
        .transform((data) => ({
            ...data,
            commercial_model: 'free',
            bot_limit: data.bot_limit === '' || data.bot_limit === undefined ? null : data.bot_limit,
            community_limit: data.community_limit === '' || data.community_limit === undefined ? null : data.community_limit,
        }))
        .put('/plataforma/telegram', { preserveScroll: true });
}

function runSearch() {
    router.get('/plataforma/telegram', { q: search.value }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function saveSeller(seller) {
    savingSellerId.value = seller.id;
    router.put(`/plataforma/telegram/sellers/${seller.id}`, {
        visibility: seller.visibility,
        campaigns: seller.campaigns,
        sales: seller.sales,
    }, {
        preserveScroll: true,
        onFinish: () => {
            savingSellerId.value = null;
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold text-zinc-900 dark:text-white">
                <Send class="h-7 w-7 text-[var(--color-primary)]" />
                Telegram
            </h1>
            <p class="mt-1 max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
                Política do módulo, ciclo de vida de acessos e métricas. Liberação de novas vendas e checkout é controlada aqui no painel (global ou por infoprodutor).
            </p>
        </div>

        <div
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200"
        >
            {{ page.props.flash.success }}
        </div>
        <div
            v-if="page.props.flash?.error || page.props.errors?.seller"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
        >
            {{ page.props.flash?.error || page.props.errors?.seller }}
        </div>

        <form class="space-y-6" @submit.prevent="submitSettings">
            <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-6">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Configuração global</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Desligar o módulo esconde o Telegram dos infoprodutores que herdam o padrão. A configuração e o histórico permanecem.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <Toggle v-model="form.module_enabled" label="Módulo Telegram" />
                        <p class="mt-2 text-xs text-zinc-500">Controla a visibilidade global. O ajuste por infoprodutor continua valendo.</p>
                        <p v-if="form.errors.module_enabled" class="mt-2 text-sm text-red-600">{{ form.errors.module_enabled }}</p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <p class="text-sm font-medium text-zinc-900 dark:text-white">Modelo comercial</p>
                        <p class="mt-2 inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200">
                            Gratuito
                        </p>
                        <p class="mt-2 text-xs text-zinc-500">Sem limite comercial de bots ou comunidades. O rate limit da API do Telegram continua valendo nas próximas etapas.</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-zinc-800 dark:text-zinc-200" for="telegram-bot-limit">Limite de bots</label>
                        <input
                            id="telegram-bot-limit"
                            :value="form.bot_limit ?? ''"
                            type="number"
                            min="1"
                            inputmode="numeric"
                            placeholder="Ilimitado"
                            class="mt-2 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                            @input="limitInput('bot_limit', $event)"
                        />
                        <p class="mt-1 text-xs text-zinc-500">Vazio significa ilimitado.</p>
                        <p v-if="form.errors.bot_limit" class="mt-1 text-sm text-red-600">{{ form.errors.bot_limit }}</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-zinc-800 dark:text-zinc-200" for="telegram-community-limit">Limite de grupos e canais</label>
                        <input
                            id="telegram-community-limit"
                            :value="form.community_limit ?? ''"
                            type="number"
                            min="1"
                            inputmode="numeric"
                            placeholder="Ilimitado"
                            class="mt-2 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                            @input="limitInput('community_limit', $event)"
                        />
                        <p class="mt-1 text-xs text-zinc-500">Vazio significa ilimitado.</p>
                        <p v-if="form.errors.community_limit" class="mt-1 text-sm text-red-600">{{ form.errors.community_limit }}</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <Toggle v-model="form.campaigns_enabled" label="Campanhas" />
                    </div>
                    <div class="rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <Toggle v-model="form.auto_delivery_enabled" label="Entrega automática" />
                    </div>
                    <div class="rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <Toggle v-model="form.expiry_control_enabled" label="Controle de vencimentos" />
                    </div>
                    <div class="rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <Toggle v-model="form.expiry_removal_enabled" label="Remoção automática após vencimento" />
                    </div>
                    <div class="rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <Toggle v-model="form.sales_enabled" label="Novas vendas Telegram (checkout)" />
                        <p class="mt-2 text-xs text-zinc-500">
                            Liga publicação e checkout de produtos Telegram para quem herda a política global. Desligar não impede renovação, vencimento nem revogação do que já foi vendido. Para liberar só um seller: deixe isto off e use “Habilitada” na política por infoprodutor.
                        </p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <Toggle v-model="form.auto_compensation_enabled" label="Compensação automática por incidente" />
                        <p class="mt-2 text-xs text-zinc-500">
                            Inicialmente desabilitada. Mesmo autorizada, a aplicação exige revisão administrativa do incidente.
                        </p>
                    </div>
                </div>

                <div class="mt-5 flex justify-end">
                    <Button type="submit" variant="primary" :disabled="form.processing">Salvar configuração</Button>
                </div>
            </section>
        </form>

        <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-6">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Política por infoprodutor</h2>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Visibilidade, campanhas e novas vendas são independentes e não alteram o status da conta.
                Habilitada libera este infoprodutor mesmo com o padrão global desligado. O módulo precisa estar visível para criar bot, vender ou disparar campanha.
            </p>

            <form class="mt-4 flex flex-col gap-2 sm:flex-row" @submit.prevent="runSearch">
                <label class="sr-only" for="telegram-seller-search">Buscar infoprodutor</label>
                <input
                    id="telegram-seller-search"
                    v-model="search"
                    type="search"
                    maxlength="100"
                    placeholder="Nome ou e-mail do infoprodutor"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
                <Button type="submit" variant="secondary" class="shrink-0">Buscar</Button>
            </form>

            <p v-if="!filters.q" class="mt-4 text-sm text-zinc-500">Busque um infoprodutor para ajustar a política.</p>
            <p v-else-if="sellerDrafts.length === 0" class="mt-4 text-sm text-zinc-500">Nenhum infoprodutor encontrado para “{{ filters.q }}”.</p>

            <div v-else class="mt-4 space-y-4">
                <article
                    v-for="seller in sellerDrafts"
                    :key="seller.id"
                    class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium text-zinc-900 dark:text-white">{{ seller.name }}</p>
                        <p class="truncate text-xs text-zinc-500">{{ seller.email }}</p>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-300">
                            Visibilidade do módulo
                            <select v-model="seller.visibility" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                                <option v-for="option in modeOptions" :key="`visibility-${option.value}`" :value="option.value">{{ option.label }}</option>
                            </select>
                        </label>
                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-300">
                            Campanhas
                            <select v-model="seller.campaigns" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                                <option v-for="option in modeOptions" :key="`campaigns-${option.value}`" :value="option.value">{{ option.label }}</option>
                            </select>
                        </label>
                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-300">
                            Novas vendas
                            <select v-model="seller.sales" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                                <option v-for="option in modeOptions" :key="`sales-${option.value}`" :value="option.value">{{ option.label }}</option>
                            </select>
                        </label>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <Button type="button" variant="primary" :disabled="savingSellerId === seller.id" @click="saveSeller(seller)">
                            Salvar política
                        </Button>
                    </div>
                </article>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-6">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Monitoramento</h2>
            <p v-if="!monitoring.ready" class="mt-2 text-sm text-zinc-500">{{ monitoring.message || 'Métricas indisponíveis.' }}</p>
            <dl v-else class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-zinc-200 px-3 py-3 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Bots conectados</dt>
                    <dd class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ monitoring.bots_connected }}</dd>
                </div>
                <div class="rounded-xl border border-zinc-200 px-3 py-3 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Comunidades vinculadas</dt>
                    <dd class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ monitoring.communities_linked }}</dd>
                </div>
                <div class="rounded-xl border border-zinc-200 px-3 py-3 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Assinantes ativos</dt>
                    <dd class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ monitoring.subscribers_active }}</dd>
                </div>
                <div class="rounded-xl border border-zinc-200 px-3 py-3 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Acessos vencidos</dt>
                    <dd class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ monitoring.accesses_expired }}</dd>
                </div>
                <div class="rounded-xl border border-zinc-200 px-3 py-3 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Entregas pendentes</dt>
                    <dd class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ monitoring.deliveries_pending }}</dd>
                </div>
                <div class="rounded-xl border border-zinc-200 px-3 py-3 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Falhas de entrega</dt>
                    <dd class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ monitoring.delivery_failures }}</dd>
                </div>
                <div class="rounded-xl border border-zinc-200 px-3 py-3 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Incidentes técnicos abertos</dt>
                    <dd class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ monitoring.technical_incidents_open }}</dd>
                </div>
                <div class="rounded-xl border border-zinc-200 px-3 py-3 dark:border-zinc-700">
                    <dt class="text-xs text-zinc-500">Renovações processadas</dt>
                    <dd class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ monitoring.renewals_processed }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-zinc-500">
                Vendas no painel: {{ monitoring.product_sales_operational ? 'ligadas' : 'desligadas' }}
                · Override env: {{ monitoring.product_sales_env_override ? 'ligado' : 'desligado' }}
                · Compensação automática: {{ monitoring.auto_compensation_enabled ? 'ligada' : 'desligada' }}
            </p>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-6">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Checklist de prontidão comercial</h2>
            <p class="mt-1 text-sm text-zinc-500">{{ commercial_readiness.recommendation }}</p>
            <ul class="mt-4 space-y-2">
                <li
                    v-for="item in (commercial_readiness.items || [])"
                    :key="item.key"
                    class="flex items-start gap-2 text-sm"
                >
                    <span :class="item.ok ? 'text-emerald-600' : 'text-amber-600'">{{ item.ok ? '✓' : '!' }}</span>
                    <span>
                        <span class="font-medium text-zinc-900 dark:text-white">{{ item.label }}</span>
                        <span v-if="item.note" class="block text-xs text-zinc-500">{{ item.note }}</span>
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
