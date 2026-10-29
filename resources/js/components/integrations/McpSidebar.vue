<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import { Check, Copy, KeyRound, RefreshCw, X } from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({
    open: { type: Boolean, default: false },
    mcp: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);
const { t } = useI18n();

const saving = ref(false);
const errorMessage = ref(null);
const revealedToken = ref('');
const copied = ref('');

watch(
    () => props.open,
    (open) => {
        if (!open) {
            errorMessage.value = null;
            copied.value = '';
            revealedToken.value = '';
        }
    }
);

const connected = computed(() => Boolean(props.mcp?.connected));
const serverUrl = computed(() => props.mcp?.server_url || '');
const lastUsed = computed(() => props.mcp?.last_used_at || null);

const claudeConfig = computed(() => JSON.stringify({
    mcpServers: {
        'getfy-catalog': {
            url: serverUrl.value || 'https://seu-dominio/mcp',
            headers: {
                Authorization: 'Bearer ' + (revealedToken.value || 'mcp_live_SEU_TOKEN'),
            },
        },
    },
}, null, 2));

const canDo = [
    t('integrations.mcp.can.list', 'Listar e ler produtos, ofertas, cupons, order bumps e o resumo de upsell/downsell'),
    t('integrations.mcp.can.sales', 'Ler resumo e lista de vendas deste tenant para relatórios (sem CPF, telefone, e-mail ou IP)'),
    t('integrations.mcp.can.create', 'Criar e atualizar produto, oferta, cupom e order bump'),
    t('integrations.mcp.can.funnel', 'Montar upsell e downsell sem mexer no restante do checkout'),
    t('integrations.mcp.can.members', 'Editar a área de membros: seções, módulos, aulas (vídeo, link, PDF por URL ou texto), drip, turmas, comentários, certificado e aparência'),
    t('integrations.mcp.can.students', 'Listar alunos, conceder ou revogar acesso a um produto e ver o progresso na área de membros'),
    t('integrations.mcp.can.balance', 'Consultar o saldo disponível e o que ainda está a receber (somente leitura)'),
    t('integrations.mcp.can.preview', 'Gerar um plano assinado (preview_id) e só gravar depois da sua confirmação'),
];

const cannotDo = [
    t('integrations.mcp.cannot.pay', 'Cobrar, estornar, sacar ou ver chaves de API de pagamento'),
    t('integrations.mcp.cannot.pixels', 'Ler ou gravar pixels, scripts customizados ou gateways do checkout'),
    t('integrations.mcp.cannot.mass', 'Importar alunos em massa, enviar push, alterar domínio da área ou senha padrão de login'),
    t('integrations.mcp.cannot.other', 'Acessar catálogo ou vendas de outra conta, CPF, telefone, IP ou reembolsar um pedido'),
    t('integrations.mcp.cannot.confirm', 'Confirmar sozinho no chat: alteração com risco precisa de apply_plan com o preview_id'),
];

const prompts = [
    t('integrations.mcp.prompt.list', 'Liste meus produtos com preço e status de aprovação.'),
    t('integrations.mcp.prompt.report', 'Faça um relatório das vendas dos últimos 7 dias por produto e forma de pagamento.'),
    t('integrations.mcp.prompt.create', 'Crie o produto Curso X a R$ 97, tipo área de membros, e me mostre o plano antes de gravar.'),
    t('integrations.mcp.prompt.coupon', 'Crie o cupom BEMVINDO de 10% só no produto Y, no máximo 100 usos.'),
    t('integrations.mcp.prompt.funnel', 'No produto X, proponha order bump + upsell com o produto Z e só aplique depois que eu confirmar.'),
    t('integrations.mcp.prompt.members', 'No curso X, crie o módulo 2 com a aula Introdução (Vimeo) e solte daqui a 7 dias.'),
    t('integrations.mcp.prompt.students', 'Adicione a aluna Maria Silva (maria@email.com) no curso X, turma da noite.'),
    t('integrations.mcp.prompt.balance', 'Qual é o meu saldo disponível para saque?'),
];

function close() {
    emit('close');
}

async function copy(value, key) {
    if (!value) return;
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
        window.setTimeout(() => {
            if (copied.value === key) copied.value = '';
        }, 2000);
    } catch (_) {
        errorMessage.value = t('integrations.mcp.copy_fail', 'Não foi possível copiar. Copie manualmente.');
    }
}

async function activate() {
    saving.value = true;
    errorMessage.value = null;
    try {
        const { data } = await axios.post('/integracoes/mcp');
        revealedToken.value = data.token || '';
        emit('saved');
    } catch (err) {
        errorMessage.value = err.response?.data?.message
            || t('integrations.mcp.error_save', 'Não foi possível ativar o MCP.');
    } finally {
        saving.value = false;
    }
}

async function rotate() {
    if (!window.confirm(t('integrations.mcp.rotate_confirm', 'Gerar um token novo do Claude? O mcp_live_ atual para na hora. O ChatGPT continua com o token OAuth dele.'))) {
        return;
    }
    saving.value = true;
    errorMessage.value = null;
    try {
        const { data } = await axios.post('/integracoes/mcp/rotate');
        revealedToken.value = data.token || '';
        emit('saved');
    } catch (err) {
        errorMessage.value = err.response?.data?.message
            || t('integrations.mcp.error_save', 'Não foi possível rotacionar o token.');
    } finally {
        saving.value = false;
    }
}

async function revoke() {
    if (!window.confirm(t('integrations.mcp.revoke_confirm', 'Revogar o MCP desta conta? Claude e ChatGPT param na hora.'))) {
        return;
    }
    saving.value = true;
    errorMessage.value = null;
    try {
        await axios.delete('/integracoes/mcp');
        revealedToken.value = '';
        emit('saved');
    } catch (err) {
        errorMessage.value = err.response?.data?.message
            || t('integrations.mcp.error_save', 'Não foi possível revogar o MCP.');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            v-show="open"
            class="fixed inset-0 z-[100000] flex justify-end"
            aria-modal="true"
            role="dialog"
        >
            <div
                class="fixed inset-0 bg-zinc-900/50 dark:bg-zinc-950/60"
                aria-hidden="true"
                @click="close"
            />
            <aside class="relative flex h-full w-full max-w-xl flex-col rounded-l-2xl bg-white dark:bg-zinc-900">
                <div class="flex items-center justify-between rounded-tl-2xl bg-zinc-50/80 px-5 py-4 dark:bg-zinc-800/50">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">MCP</h2>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-200/80 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                        :aria-label="t('common.close', 'Fechar')"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex flex-1 flex-col overflow-y-auto p-5">
                    <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ t('integrations.mcp.description', 'Conecte Claude, ChatGPT ou outro cliente MCP para montar produtos, funis, área de membros e alunos desta conta.') }}
                    </p>

                    <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-200">
                        {{ errorMessage }}
                    </p>

                    <div v-if="!connected" class="space-y-4">
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">
                            {{ t('integrations.mcp.inactive_hint', 'Ative para gerar o token do Claude e a URL do servidor. O ChatGPT usa a mesma URL e pede login, sem esse token.') }}
                        </p>
                        <Button variant="primary" :disabled="saving" @click="activate">
                            <KeyRound class="h-4 w-4" />
                            {{ saving ? t('common.saving', 'Salvando...') : t('integrations.mcp.activate', 'Ativar MCP') }}
                        </Button>
                    </div>

                    <div v-else class="space-y-5">
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ t('integrations.mcp.status', 'Status') }}
                            </p>
                            <p class="mt-2 text-sm text-zinc-900 dark:text-zinc-100">
                                {{ t('integrations.mcp.status_on', 'Conectado neste catálogo') }}
                            </p>
                            <p v-if="lastUsed" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ t('integrations.mcp.last_used', 'Último uso') }}: {{ lastUsed }}
                            </p>
                        </div>

                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ t('integrations.mcp.server_url', 'URL do servidor') }}
                            </p>
                            <div class="mt-2 flex items-center gap-2">
                                <code class="min-w-0 flex-1 truncate text-sm text-zinc-900 dark:text-zinc-100">{{ serverUrl }}</code>
                                <Button variant="outline" size="sm" @click="copy(serverUrl, 'url')">
                                    <Check v-if="copied === 'url'" class="h-4 w-4" />
                                    <Copy v-else class="h-4 w-4" />
                                    {{ copied === 'url' ? t('common.copied', 'Copiado') : t('common.copy', 'Copiar') }}
                                </Button>
                            </div>
                        </div>

                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ t('integrations.mcp.token', 'Token do Claude') }}
                            </p>
                            <p class="mt-2 font-mono text-sm text-zinc-900 dark:text-zinc-100">
                                {{ mcp?.masked_token || '—' }}
                            </p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ t('integrations.mcp.token_once', 'O token mcp_live_ aparece só na ativação ou na rotação. O ChatGPT não usa este token.') }}
                            </p>
                        </div>

                        <div
                            v-if="revealedToken"
                            class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/30"
                        >
                            <p class="text-xs font-medium uppercase tracking-wide text-amber-800 dark:text-amber-200">
                                {{ t('integrations.mcp.token_reveal', 'Token (copie agora)') }}
                            </p>
                            <code class="mt-2 block break-all text-sm text-zinc-900 dark:text-zinc-100">{{ revealedToken }}</code>
                            <Button class="mt-3" variant="outline" size="sm" @click="copy(revealedToken, 'token')">
                                <Check v-if="copied === 'token'" class="h-4 w-4" />
                                <Copy v-else class="h-4 w-4" />
                                {{ copied === 'token' ? t('common.copied', 'Copiado') : t('common.copy', 'Copiar token') }}
                            </Button>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Button variant="outline" :disabled="saving" @click="rotate">
                                <RefreshCw class="h-4 w-4" />
                                {{ t('integrations.mcp.rotate', 'Gerar novo token') }}
                            </Button>
                            <Button variant="destructive" :disabled="saving" @click="revoke">
                                {{ t('integrations.mcp.revoke', 'Revogar') }}
                            </Button>
                        </div>
                    </div>

                    <div class="mt-6 space-y-5 border-t border-zinc-200 pt-5 dark:border-zinc-700">
                        <section>
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">
                                {{ t('integrations.mcp.can_title', 'O que o agente pode') }}
                            </h3>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-zinc-600 dark:text-zinc-400">
                                <li v-for="item in canDo" :key="item">{{ item }}</li>
                            </ul>
                        </section>

                        <section>
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">
                                {{ t('integrations.mcp.cannot_title', 'O que o agente não pode') }}
                            </h3>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-zinc-600 dark:text-zinc-400">
                                <li v-for="item in cannotDo" :key="item">{{ item }}</li>
                            </ul>
                        </section>

                        <section>
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">
                                {{ t('integrations.mcp.connect_title', 'Como conectar') }}
                            </h3>
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ t('integrations.mcp.connect_claude', 'No Claude Desktop, cole a URL e o token mcp_live_ no mcpServers. Esse token não muda quando você autoriza o ChatGPT.') }}
                            </p>
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ t('integrations.mcp.connect_chatgpt', 'No ChatGPT, cadastre um conector MCP remoto só com a URL, sem colar o token. O ChatGPT abre o login desta conta e recebe um token próprio (mcp_at_).') }}
                            </p>
                            <pre class="mt-3 overflow-x-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">{{ claudeConfig }}</pre>
                            <Button class="mt-2" variant="outline" size="sm" @click="copy(claudeConfig, 'config')">
                                <Check v-if="copied === 'config'" class="h-4 w-4" />
                                <Copy v-else class="h-4 w-4" />
                                {{ copied === 'config' ? t('common.copied', 'Copiado') : t('integrations.mcp.copy_config', 'Copiar exemplo') }}
                            </Button>
                        </section>

                        <section>
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">
                                {{ t('integrations.mcp.prompts_title', 'Exemplos de pedido') }}
                            </h3>
                            <ul class="mt-2 space-y-2">
                                <li
                                    v-for="(prompt, index) in prompts"
                                    :key="prompt"
                                    class="flex items-start justify-between gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-700 dark:border-zinc-700 dark:text-zinc-300"
                                >
                                    <span>{{ prompt }}</span>
                                    <button
                                        type="button"
                                        class="shrink-0 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                        :aria-label="t('common.copy', 'Copiar')"
                                        @click="copy(prompt, 'prompt-' + index)"
                                    >
                                        <Check v-if="copied === 'prompt-' + index" class="h-4 w-4" />
                                        <Copy v-else class="h-4 w-4" />
                                    </button>
                                </li>
                            </ul>
                        </section>
                    </div>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
