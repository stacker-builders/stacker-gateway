<script setup>
defineProps({
    form: { type: Object, required: true },
});

const providers = [
    { id: 'gemini', label: 'Gemini', hint: 'Chave do Google AI Studio' },
    { id: 'openai', label: 'OpenAI', hint: 'Chave da platform.openai.com' },
];
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Relatório com IA</h2>
                <p class="mt-1 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                    Com a chave ativa, a dashboard do admin ganha o botão Relatório com IA. Ele usa os números do período aberto na tela e devolve uma análise. A chave fica só no servidor.
                </p>
            </div>
            <span
                class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                :class="form.platform_ai_report_enabled === '1'
                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200'
                    : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'"
            >
                {{ form.platform_ai_report_enabled === '1' ? 'Ativo na dashboard' : 'Desativado' }}
            </span>
        </div>

        <label class="mt-5 flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80">
            <input
                type="checkbox"
                class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                :checked="form.platform_ai_report_enabled === '1'"
                @change="form.platform_ai_report_enabled = $event.target.checked ? '1' : '0'"
            />
            <span>
                <span class="block text-sm font-medium text-zinc-900 dark:text-white">Mostrar o botão na dashboard</span>
                <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                    O botão só aparece com esta opção ligada e uma chave salva.
                </span>
            </span>
        </label>

        <div class="mt-5">
            <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">Provedor</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <label
                    v-for="provider in providers"
                    :key="provider.id"
                    class="flex cursor-pointer items-start gap-3 rounded-xl border p-4"
                    :class="form.platform_ai_report_provider === provider.id
                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5'
                        : 'border-zinc-200 dark:border-zinc-600'"
                >
                    <input
                        v-model="form.platform_ai_report_provider"
                        type="radio"
                        class="mt-0.5 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        name="platform_ai_report_provider"
                        :value="provider.id"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ provider.label }}</span>
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">{{ provider.hint }}</span>
                    </span>
                </label>
            </div>
            <p v-if="form.errors.platform_ai_report_provider" class="mt-1 text-xs text-red-600 dark:text-red-400">
                {{ form.errors.platform_ai_report_provider }}
            </p>
        </div>

        <div class="mt-5">
            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300" for="platform-ai-report-api-key">
                Chave da API
            </label>
            <input
                id="platform-ai-report-api-key"
                v-model="form.platform_ai_report_api_key"
                type="password"
                autocomplete="new-password"
                :placeholder="form.platform_ai_report_api_key_configured ? 'Chave já salva. Cole outra para substituir.' : 'Cole a chave do provedor escolhido'"
                class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none ring-[var(--color-primary)] focus:ring-2 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
            />
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Deixe em branco para manter a chave atual. Ao trocar de OpenAI para Gemini, ou o contrário, cole a chave nova.
            </p>
            <p v-if="form.platform_ai_report_api_key_configured" class="mt-1 text-xs text-emerald-700 dark:text-emerald-300">
                Chave configurada.
            </p>
            <p v-if="form.errors.platform_ai_report_api_key" class="mt-1 text-xs text-red-600 dark:text-red-400">
                {{ form.errors.platform_ai_report_api_key }}
            </p>
        </div>
    </section>
</template>
