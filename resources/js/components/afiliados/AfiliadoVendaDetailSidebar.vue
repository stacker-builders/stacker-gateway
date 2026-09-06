<script setup>
import { ref, computed } from 'vue';
import { X, ExternalLink } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    venda: { type: Object, default: null },
});

const emit = defineEmits(['close']);

function close() {
    emit('close');
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

function formatDate(value) {
    if (!value) return '–';
    return new Date(value).toLocaleString('pt-BR');
}
</script>

<template>
    <Teleport to="body">
        <div v-show="open" class="fixed inset-0 z-[100000] flex justify-end" aria-modal="true" role="dialog">
            <div class="fixed inset-0 bg-zinc-900/50" aria-hidden="true" @click="close" />
            <aside class="relative flex h-full w-full max-w-md flex-col rounded-l-2xl bg-white shadow-2xl dark:bg-zinc-900">
                <div class="flex items-center justify-between px-5 py-5">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        {{ venda?.is_coproduction_commission ? 'Detalhes da co-produção' : 'Detalhes da comissão' }}
                    </h2>
                    <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100" aria-label="Fechar" @click="close">
                        <X class="h-5 w-5" />
                    </button>
                </div>
                <div v-if="!venda" class="flex flex-1 items-center justify-center p-8 text-sm text-zinc-500">
                    Nenhuma venda selecionada.
                </div>
                <div v-else class="flex-1 space-y-5 overflow-y-auto p-5">
                    <div class="space-y-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">ID da venda</p>
                        <p class="font-mono text-sm">{{ venda.order_id }}</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Tipo</p>
                        <p class="text-sm">{{ venda.is_coproduction_commission ? 'Co-produção' : 'Afiliado' }}</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Status da comissão</p>
                        <p class="text-sm">{{ venda.status_label }}</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Produto</p>
                        <p class="text-sm">{{ venda.product_name ?? '—' }}</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Cliente</p>
                        <p v-if="venda.customer_hidden" class="text-sm text-zinc-500">Dados ocultos pelo produtor</p>
                        <template v-else>
                            <p class="text-sm">{{ venda.customer_name ?? '—' }}</p>
                            <p class="text-xs text-zinc-500">{{ venda.customer_email ?? '' }}</p>
                        </template>
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Valor da venda</p>
                        <p class="text-sm">{{ formatBRL(venda.sale_gross) }}</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Comissão</p>
                        <p class="text-sm">
                            {{ formatBRL(venda.commission_net) }}
                            <span class="text-zinc-500">({{ venda.commission_percent }}%)</span>
                        </p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Produtor</p>
                        <p class="text-sm">{{ venda.producer_name ?? '—' }}</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Método de pagamento</p>
                        <p class="text-sm">{{ venda.payment_method_label ?? venda.payment_method ?? '—' }}</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Origem</p>
                        <p class="text-sm">{{ venda.sale_origin_label ?? '—' }}</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Data</p>
                        <p class="text-sm">{{ formatDate(venda.created_at) }}</p>
                    </div>
                    <div v-if="venda.affiliate_ref && !venda.is_coproduction_commission" class="space-y-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Ref. afiliado</p>
                        <p class="font-mono text-sm">{{ venda.affiliate_ref }}</p>
                    </div>
                    <a
                        v-if="venda.affiliate_link && !venda.is_coproduction_commission"
                        :href="venda.affiliate_link"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 text-sm text-[var(--color-primary)] hover:underline"
                    >
                        URL do link
                        <ExternalLink class="h-3.5 w-3.5" />
                    </a>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
