<script setup>
import VueApexCharts from 'vue3-apexcharts';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import DashboardPeriodToolbar from '@/components/dashboard/DashboardPeriodToolbar.vue';
import { CircleDollarSign, ShoppingCart, CreditCard, ShoppingBag, RotateCcw, Package } from 'lucide-vue-next';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, required: true },
    hasAchievementsProgress: { type: Boolean, default: false },
    period: { type: String, required: true },
    periodOptions: { type: Array, required: true },
    valuesVisible: { type: Boolean, required: true },
    periodLabel: { type: String, default: 'Período' },
    hideValuesLabel: { type: String, default: 'Ocultar valores' },
    showValuesLabel: { type: String, default: 'Mostrar valores' },
    vendas_totais: { type: Number, default: 0 },
    vendas_pendentes: { type: Number, default: 0 },
    quantidade_vendas: { type: Number, default: 0 },
    ticket_medio: { type: Number, default: 0 },
    formas_pagamento: { type: Array, default: () => [] },
    taxa_conversao: { type: Number, default: 0 },
    abandono_carrinho: { type: Number, default: 0 },
    taxa_abandono_compras: { type: Number, default: 0 },
    compras_periodo: { type: Number, default: 0 },
    reembolsos_count: { type: Number, default: 0 },
    reembolsos_total: { type: Number, default: 0 },
    quantidade_produtos: { type: Number, default: 0 },
    grafico_vendas: { type: Array, default: () => [] },
    chartOptions: { type: Object, required: true },
    chartSeries: { type: Array, required: true },
    labels: { type: Object, required: true },
    displayCurrency: { type: Function, required: true },
    displayNumber: { type: Function, required: true },
});

const emit = defineEmits(['update:period', 'toggle-values']);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ title }}</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ subtitle }}</p>
        </div>

        <div v-if="hasAchievementsProgress" class="lg:hidden">
            <ConquistasWidget variant="dashboard" />
        </div>

        <DashboardPeriodToolbar
            :period="period"
            :period-options="periodOptions"
            :values-visible="valuesVisible"
            :period-label="periodLabel"
            :hide-values-label="hideValuesLabel"
            :show-values-label="showValuesLabel"
            @update:period="emit('update:period', $event)"
            @toggle-values="emit('toggle-values')"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">
                <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                    <CircleDollarSign class="h-5 w-5" />
                    <span class="text-sm font-medium">{{ labels.totalSales }}</span>
                </div>
                <p class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">{{ displayCurrency(vendas_totais) }}</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ labels.pendingSales }}: {{ displayCurrency(vendas_pendentes) }}
                </p>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">
                <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                    <ShoppingCart class="h-5 w-5" />
                    <span class="text-sm font-medium">{{ labels.salesCount }}</span>
                </div>
                <p class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">{{ displayNumber(quantidade_vendas) }}</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ labels.avgTicket }}: {{ displayCurrency(ticket_medio) }}
                </p>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50 lg:col-span-2">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <CreditCard class="h-4 w-4 text-zinc-500" />
                    {{ labels.paymentMethods }}
                </h2>
                <ul class="mt-4 space-y-3">
                    <li
                        v-for="fp in formas_pagamento"
                        :key="fp.metodo"
                        class="flex items-center justify-between border-b border-zinc-200/60 py-2 last:border-0 dark:border-zinc-700/60"
                    >
                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ fp.label }}</span>
                        <span class="text-sm font-medium text-zinc-900 dark:text-white">
                            {{ displayCurrency(fp.total) }}
                            <span class="font-normal text-zinc-500">({{ displayNumber(fp.quantidade) }})</span>
                        </span>
                    </li>
                    <li v-if="!formas_pagamento.length" class="py-4 text-center text-sm text-zinc-500">
                        {{ labels.noPayments }}
                    </li>
                </ul>
                <div class="mt-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ labels.conversionRate }}</p>
                    <p class="text-xl font-semibold text-zinc-900 dark:text-white">
                        {{ valuesVisible ? `${taxa_conversao}%` : '—' }}
                    </p>
                </div>
            </div>
            <div class="space-y-4">
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                        <ShoppingBag class="h-4 w-4" />
                        <span class="text-sm font-medium">{{ labels.cartAbandonment }}</span>
                    </div>
                    <p class="mt-2 text-lg font-bold text-zinc-900 dark:text-white">
                        {{ valuesVisible ? `${taxa_abandono_compras}%` : '—' }}
                    </p>
                    <p class="text-xs text-zinc-500">
                        {{ displayNumber(abandono_carrinho) }} {{ labels.abandonedCount }}
                        · {{ displayNumber(compras_periodo) }} {{ labels.purchasesCount }}
                    </p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                        <RotateCcw class="h-4 w-4" />
                        <span class="text-sm font-medium">{{ labels.refunds }}</span>
                    </div>
                    <p class="mt-2 text-lg font-bold text-zinc-900 dark:text-white">{{ displayCurrency(reembolsos_total) }}</p>
                    <p class="text-xs text-zinc-500">{{ displayNumber(reembolsos_count) }} {{ labels.ordersCount }}</p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                        <Package class="h-4 w-4" />
                        <span class="text-sm font-medium">{{ labels.products }}</span>
                    </div>
                    <p class="mt-2 text-lg font-bold text-zinc-900 dark:text-white">{{ displayNumber(quantidade_produtos) }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ labels.salesPerformance }}</h2>
            <div class="mt-4 min-h-[280px]">
                <VueApexCharts
                    v-if="grafico_vendas.length"
                    type="area"
                    height="280"
                    :options="chartOptions"
                    :series="chartSeries"
                />
                <p v-else class="flex h-[280px] items-center justify-center text-sm text-zinc-500 dark:text-zinc-400">
                    {{ labels.noSalesData }}
                </p>
            </div>
        </div>
    </div>
</template>
