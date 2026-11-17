<script setup>
import { BadgeCheck } from 'lucide-vue-next';
import Toggle from '@/components/ui/Toggle.vue';

const props = defineProps({
    form: { type: Object, required: true },
});

const identityOptions = [
    { value: 'rg', label: 'RG / CIN', hint: 'Frente e verso' },
    { value: 'cnh', label: 'CNH', hint: 'Arquivo único' },
    { value: 'passport', label: 'Passaporte', hint: 'Página de identificação (dentro da validade)' },
];

function isIdentityChecked(value) {
    const list = Array.isArray(props.form.kyc_allowed_identity_types)
        ? props.form.kyc_allowed_identity_types
        : [];
    return list.includes(value);
}

function diditOn() {
    return props.form.kyc_didit_enabled === '1' || props.form.kyc_didit_enabled === true;
}

function setDidit(enabled) {
    props.form.kyc_didit_enabled = enabled ? '1' : '0';
}

function toggleIdentity(value, checked) {
    const current = Array.isArray(props.form.kyc_allowed_identity_types)
        ? [...props.form.kyc_allowed_identity_types]
        : [];
    if (checked) {
        if (!current.includes(value)) {
            current.push(value);
        }
    } else {
        const next = current.filter((v) => v !== value);
        // Mantém pelo menos um tipo selecionado.
        props.form.kyc_allowed_identity_types = next.length > 0 ? next : [value];
        return;
    }
    props.form.kyc_allowed_identity_types = current;
}
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start gap-3">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
            >
                <BadgeCheck class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Documentos do KYC</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Defina quais documentos os infoprodutores precisam enviar na verificação. Instalações mais
                    leves podem exigir só a identidade; outras podem ser mais criteriosas.
                </p>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Verificação pela Didit</h3>
                    <p class="mt-1 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
                        Ligado, o infoprodutor faz a identidade no fluxo da Didit. Desligado, continua o envio
                        manual de documentos nesta plataforma. A migração de CPF para CNPJ segue pedindo os
                        documentos da empresa aqui.
                    </p>
                </div>
                <Toggle
                    :model-value="diditOn()"
                    label="Usar a Didit"
                    @update:model-value="setDidit"
                />
            </div>
            <div v-if="diditOn()" class="mt-5 space-y-4 border-t border-zinc-200 pt-5 dark:border-zinc-700">
                <label class="block text-sm">
                    <span class="font-medium text-zinc-900 dark:text-white">Chave de API</span>
                    <input
                        v-model="form.kyc_didit_api_key"
                        type="password"
                        autocomplete="new-password"
                        class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        :placeholder="form.kyc_didit_api_key_configured ? 'Deixe em branco para manter a chave atual' : 'x-api-key da Didit'"
                    />
                </label>
                <label class="block text-sm">
                    <span class="font-medium text-zinc-900 dark:text-white">ID do fluxo</span>
                    <input
                        v-model="form.kyc_didit_workflow_id"
                        type="text"
                        class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        placeholder="UUID do workflow de KYC"
                    />
                </label>
                <label class="block text-sm">
                    <span class="font-medium text-zinc-900 dark:text-white">Segredo do webhook</span>
                    <input
                        v-model="form.kyc_didit_webhook_secret"
                        type="password"
                        autocomplete="new-password"
                        class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        :placeholder="form.kyc_didit_webhook_secret_configured ? 'Deixe em branco para manter o segredo atual' : 'secret_shared_key do destino'"
                    />
                </label>
                <label class="block text-sm">
                    <span class="font-medium text-zinc-900 dark:text-white">URL do webhook</span>
                    <input
                        :value="form.kyc_didit_webhook_url || ''"
                        type="text"
                        readonly
                        class="mt-1 w-full rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200"
                    />
                    <span class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">
                        Cadastre este endereço no console da Didit, no destino de webhook, com o evento de status.
                    </span>
                </label>
                <p v-if="form.errors?.kyc_didit_api_key" class="text-sm text-red-600">
                    {{ form.errors.kyc_didit_api_key }}
                </p>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Quando pedir o KYC</h3>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                O padrão é pedir no cadastro. Só no saque, a conta nova fica aguardando aprovação e só vende depois dela.
            </p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <label
                    class="flex cursor-pointer items-start gap-3 rounded-xl border p-4"
                    :class="form.kyc_enforcement !== 'withdrawal'
                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10'
                        : 'border-zinc-200 bg-zinc-50/80 dark:border-zinc-600 dark:bg-zinc-800/80'"
                >
                    <input
                        type="radio"
                        class="mt-0.5 h-4 w-4 border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        name="kyc_enforcement"
                        value="onboarding"
                        :checked="form.kyc_enforcement !== 'withdrawal'"
                        @change="form.kyc_enforcement = 'onboarding'"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">Pedir no cadastro</span>
                        <span class="mt-1 block text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
                            Sem KYC aprovado o infoprodutor não vende nem saca.
                        </span>
                    </span>
                </label>
                <label
                    class="flex cursor-pointer items-start gap-3 rounded-xl border p-4"
                    :class="form.kyc_enforcement === 'withdrawal'
                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10'
                        : 'border-zinc-200 bg-zinc-50/80 dark:border-zinc-600 dark:bg-zinc-800/80'"
                >
                    <input
                        type="radio"
                        class="mt-0.5 h-4 w-4 border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        name="kyc_enforcement"
                        value="withdrawal"
                        :checked="form.kyc_enforcement === 'withdrawal'"
                        @change="form.kyc_enforcement = 'withdrawal'"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">Pedir só no saque</span>
                        <span class="mt-1 block text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
                            Depois da aprovação básica pode criar e vender. O saque exige KYC enviado e aprovado.
                        </span>
                    </span>
                </label>
            </div>
            <div
                v-if="form.kyc_enforcement === 'withdrawal'"
                class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100"
            >
                <p class="font-medium">Aprovação manual</p>
                <p class="mt-1 text-xs leading-relaxed">
                    Ao aceitar contas sem KYC, cada uma precisa ser aprovada em Infoprodutores &gt; Aguardando aprovação
                    antes de vender. Voltar para “Pedir no cadastro” exige KYC de novo para quem foi aprovado sem documentos.
                    Contas suspensas, bloqueadas ou rejeitadas continuam sem vender e sem sacar.
                </p>
            </div>
        </div>

        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100">
            <p class="font-medium">Escopo desta configuração</p>
            <p class="mt-1 text-xs leading-relaxed">
                Vale para novos envios e reenvios (KYC rejeitado) no modo manual. Com a Didit ligada, o fluxo
                dela substitui este pacote na identidade. A migração para CNPJ continua usando estes documentos.
                Contas já aprovadas ou em análise com o pacote antigo continuam válidas.
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Tipos de identificação aceitos</h3>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                O seller escolhe um destes tipos. Selecione ao menos um.
            </p>
            <div class="mt-4 space-y-3">
                <label
                    v-for="opt in identityOptions"
                    :key="opt.value"
                    class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80"
                >
                    <input
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        :checked="isIdentityChecked(opt.value)"
                        @change="toggleIdentity(opt.value, $event.target.checked)"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ opt.label }}</span>
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">{{ opt.hint }}</span>
                    </span>
                </label>
            </div>
            <p v-if="form.errors?.kyc_allowed_identity_types" class="mt-2 text-sm text-red-600">
                {{ form.errors.kyc_allowed_identity_types }}
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Pessoa física — documentos extras</h3>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Além do documento de identificação do titular.
            </p>
            <div class="mt-4 space-y-3">
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80">
                    <input
                        v-model="form.kyc_require_address_proof"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        true-value="1"
                        false-value="0"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">Comprovante de residência</span>
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                            Documento recente com nome e endereço do seller.
                        </span>
                    </span>
                </label>
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80">
                    <input
                        v-model="form.kyc_require_selfie_with_document"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        true-value="1"
                        false-value="0"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">Selfie com documento</span>
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                            Foto do seller segurando o mesmo documento de identificação.
                        </span>
                    </span>
                </label>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Pessoa jurídica — documentos da empresa</h3>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Além do documento de identificação do responsável legal.
            </p>
            <div class="mt-4 space-y-3">
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80">
                    <input
                        v-model="form.kyc_require_company_address_proof"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        true-value="1"
                        false-value="0"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">Comprovante de endereço da empresa</span>
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                            Conta de serviço, contrato de locação ou equivalente no nome da empresa.
                        </span>
                    </span>
                </label>
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80">
                    <input
                        v-model="form.kyc_require_company_constitution"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        true-value="1"
                        false-value="0"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">Documento de constituição</span>
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                            MEI: CCMEI. Demais empresas: contrato social / ato constitutivo atualizado.
                        </span>
                    </span>
                </label>
            </div>
        </div>
    </section>
</template>
