<script setup>
import { computed, ref } from 'vue';
import { Building2 } from 'lucide-vue-next';
import { formatCnpjMask } from '@/utils/brazilianDocuments';
import CheckoutPlatformNotice from '@/components/checkout/CheckoutPlatformNotice.vue';

const props = defineProps({
    form: { type: Object, required: true },
    noticeDefault: { type: String, default: '' },
    placeholders: { type: Array, default: () => [] },
});

const noticeTextarea = ref(null);

const fallbackPlaceholders = [
    { token: '{cnpj}', label: 'CNPJ da empresa operadora' },
    { token: '{email}', label: 'E-mail de contato da plataforma' },
    { token: '{razao_social}', label: 'Razão social da empresa operadora' },
    { token: '{plataforma}', label: 'Nome da plataforma' },
    { token: '{infoprodutor}', label: 'Nome do infoprodutor vendedor' },
    { token: '{email_infoprodutor}', label: 'E-mail do infoprodutor vendedor' },
    { token: '{email_suporte_produto}', label: 'E-mail para suporte configurado no produto' },
    { token: '{empresa}', label: 'Nome comercial (Empresa) do infoprodutor' },
    { token: '{termos}', label: 'Link clicável “Termos” para /termos-de-uso' },
    { token: '{privacidade}', label: 'Link clicável “Privacidade” para /politica-privacidade' },
];

const placeholderList = computed(() =>
    (props.placeholders || []).length ? props.placeholders : fallbackPlaceholders
);

const noticeEnabled = computed({
    get: () => props.form.platform_checkout_notice_enabled === '1'
        || props.form.platform_checkout_notice_enabled === true
        || props.form.platform_checkout_notice_enabled === 1,
    set: (value) => {
        props.form.platform_checkout_notice_enabled = value ? '1' : '0';
    },
});

function onCnpjInput(event) {
    props.form.platform_cnpj = formatCnpjMask(event.target.value);
}

function insertToken(token) {
    const el = noticeTextarea.value;
    const current = String(props.form.platform_checkout_notice ?? '');
    if (!el || typeof el.selectionStart !== 'number') {
        props.form.platform_checkout_notice = `${current}${token}`;
        return;
    }
    const start = el.selectionStart;
    const end = el.selectionEnd;
    props.form.platform_checkout_notice = `${current.slice(0, start)}${token}${current.slice(end)}`;
    requestAnimationFrame(() => {
        const pos = start + token.length;
        el.focus();
        el.setSelectionRange(pos, pos);
    });
}

function applyDefaultNotice() {
    if (props.form.platform_checkout_notice && !confirm('Substituir o texto atual pelo modelo padrão?')) {
        return;
    }
    props.form.platform_checkout_notice = props.noticeDefault
        || `Ao concluir a compra na {plataforma}, você declara estar de acordo com os {termos} e a política de {privacidade} da empresa {razao_social}, inscrita no CNPJ {cnpj}.

A responsabilidade pela oferta, entrega e qualidade do produto é de {infoprodutor}, que realiza a venda por meio da nossa plataforma, através do email {email_infoprodutor}.`;
}

function previewReplace(template) {
    let text = String(template || '');
    const vars = [
        ['{email_infoprodutor}', 'email.infoprodutor@exemplo.com'],
        ['{email_suporte_produto}', 'suporte.produto@exemplo.com'],
        ['{nome do infoprodutor}', 'Nome do infoprodutor'],
        ['{razao_social}', props.form.platform_legal_name || 'Razão social'],
        ['{razão_social}', props.form.platform_legal_name || 'Razão social'],
        ['{razão social}', props.form.platform_legal_name || 'Razão social'],
        ['{infoprodutor}', 'Nome do infoprodutor'],
        ['{plataforma}', 'Nome da plataforma'],
        ['{empresa}', 'Nome Comercial'],
        ['{cnpj}', props.form.platform_cnpj || '00.000.000/0000-00'],
        ['{email}', 'contato@plataforma.com'],
    ];
    vars.forEach(([token, value]) => {
        text = text.split(token).join(value);
    });
    return text;
}

const noticePreview = computed(() => previewReplace(props.form.platform_checkout_notice));
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start gap-3">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300"
            >
                <Building2 class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Dados da plataforma</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Razão social, CNPJ e aviso exibido no final da página de checkout.
                </p>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="space-y-4">
                <div>
                    <label for="platform_legal_name" class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                        Razão social
                    </label>
                    <input
                        id="platform_legal_name"
                        v-model="form.platform_legal_name"
                        type="text"
                        maxlength="255"
                        autocomplete="organization"
                        placeholder="Nome empresarial completo"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                    />
                    <p v-if="form.errors.platform_legal_name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                        {{ form.errors.platform_legal_name }}
                    </p>
                </div>

                <div>
                    <label for="platform_cnpj" class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                        CNPJ
                    </label>
                    <input
                        id="platform_cnpj"
                        :value="form.platform_cnpj"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="00.000.000/0000-00"
                        class="w-full max-w-md rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                        @input="onCnpjInput"
                    />
                    <p v-if="form.errors.platform_cnpj" class="mt-1 text-sm text-red-600 dark:text-red-400">
                        {{ form.errors.platform_cnpj }}
                    </p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <label class="flex cursor-pointer items-start gap-3">
                <input
                    v-model="noticeEnabled"
                    type="checkbox"
                    class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-sky-600 focus:ring-sky-500"
                />
                <span>
                    <span class="block text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        Exibir aviso no final do checkout
                    </span>
                    <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                        Desativado: o checkout mostra Termos · Privacidade. Ativado: a mensagem abaixo substitui esses links no quadro do checkout.
                    </span>
                </span>
            </label>
            <p v-if="form.errors.platform_checkout_notice_enabled" class="mt-2 text-sm text-red-600 dark:text-red-400">
                {{ form.errors.platform_checkout_notice_enabled }}
            </p>

            <div class="mt-5 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <label for="platform_checkout_notice" class="text-xs font-medium text-zinc-600 dark:text-zinc-400">
                        Mensagem do checkout
                    </label>
                    <button
                        type="button"
                        class="text-xs font-medium text-sky-700 hover:underline dark:text-sky-300"
                        @click="applyDefaultNotice"
                    >
                        Inserir modelo
                    </button>
                </div>

                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Use as variáveis abaixo. Clique para inserir no texto. Elas são preenchidas automaticamente na hora da compra.
                </p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="item in placeholderList"
                        :key="item.token"
                        type="button"
                        class="rounded-md border border-zinc-200 bg-zinc-50 px-2 py-1 font-mono text-[11px] text-zinc-700 hover:border-sky-300 hover:bg-sky-50 dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-300 dark:hover:border-sky-700 dark:hover:bg-sky-950/40"
                        :title="item.label"
                        @click="insertToken(item.token)"
                    >
                        {{ item.token }}
                    </button>
                </div>
                <ul class="space-y-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                    <li v-for="item in placeholderList" :key="`help-${item.token}`">
                        <code class="font-mono text-zinc-700 dark:text-zinc-300">{{ item.token }}</code>
                        — {{ item.label }}
                    </li>
                </ul>

                <textarea
                    id="platform_checkout_notice"
                    ref="noticeTextarea"
                    v-model="form.platform_checkout_notice"
                    rows="8"
                    maxlength="5000"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm leading-relaxed text-zinc-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                    placeholder="Escreva o aviso ou clique em Inserir modelo."
                />
                <p v-if="form.errors.platform_checkout_notice" class="text-sm text-red-600 dark:text-red-400">
                    {{ form.errors.platform_checkout_notice }}
                </p>

                <div
                    v-if="noticeEnabled && noticePreview"
                    class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-3 dark:border-zinc-700 dark:bg-zinc-950/50"
                >
                    <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Pré-visualização
                    </p>
                    <CheckoutPlatformNotice
                        class="mt-2 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300 [&_a]:text-sky-700 dark:[&_a]:text-sky-300"
                        :text="noticePreview"
                    />
                </div>
            </div>
        </div>
    </section>
</template>
