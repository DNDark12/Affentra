<template>
    <AppShell>
        <Head title="Click Analytics Overview" />

        <div class="flex flex-col gap-6 p-6">
            <div class="flex flex-col gap-2">
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">
                    Click Analytics
                </h1>
                <p class="text-[13px] text-zinc-500 dark:text-zinc-400">
                    Overview from synchronized clicks and orders.
                </p>
                <ClicksSubnav />
            </div>

            <div class="flex items-center gap-2 flex-wrap text-[12px]">
                <span class="px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-700">
                    From: {{ filters.date_from || '-' }}
                </span>
                <span class="px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-700">
                    To: {{ filters.date_to || '-' }}
                </span>
                <span class="px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-700">
                    Campaign: {{ filters.campaign_id || 'All' }}
                </span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4">
                    <div class="text-[11px] text-zinc-500 dark:text-zinc-400">Funnel</div>
                    <div class="text-[18px] font-semibold text-zinc-900 dark:text-zinc-100 mt-1">
                        {{ summary.funnel.clicks }} > {{ summary.funnel.orders }} > {{ summary.funnel.approved }}
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4">
                    <div class="text-[11px] text-zinc-500 dark:text-zinc-400">CVR</div>
                    <div class="text-[22px] font-bold text-zinc-900 dark:text-zinc-100 mt-1">
                        {{ percent(summary.cvr) }}
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4">
                    <div class="text-[11px] text-zinc-500 dark:text-zinc-400">Approved Rate</div>
                    <div class="text-[22px] font-bold text-zinc-900 dark:text-zinc-100 mt-1">
                        {{ percent(summary.approved_rate) }}
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4">
                    <div class="text-[11px] text-zinc-500 dark:text-zinc-400">EPC</div>
                    <div class="text-[22px] font-bold text-zinc-900 dark:text-zinc-100 mt-1">
                        {{ money(summary.epc) }}
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-800">
                    <h2 class="text-[13px] font-semibold text-zinc-800 dark:text-zinc-200">
                        Daily Trend
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-[12px]">
                        <thead>
                            <tr class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-800">
                                <th class="px-4 py-2.5 text-left font-medium">Date</th>
                                <th class="px-4 py-2.5 text-right font-medium">Clicks</th>
                                <th class="px-4 py-2.5 text-right font-medium">Orders</th>
                                <th class="px-4 py-2.5 text-right font-medium">Approved</th>
                                <th class="px-4 py-2.5 text-right font-medium">Commission</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in summary.trend_by_day"
                                :key="row.date"
                                class="border-b border-zinc-100 dark:border-zinc-800"
                            >
                                <td class="px-4 py-2.5 text-zinc-900 dark:text-zinc-100">{{ row.date }}</td>
                                <td class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300">{{ number(row.clicks) }}</td>
                                <td class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300">{{ number(row.orders) }}</td>
                                <td class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300">{{ number(row.approved) }}</td>
                                <td class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300">{{ money(row.commission) }}</td>
                            </tr>
                            <tr v-if="summary.trend_by_day.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                    No trend data in selected window.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-800">
                    <h2 class="text-[13px] font-semibold text-zinc-800 dark:text-zinc-200">
                        Campaign Breakdown
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-[12px]">
                        <thead>
                            <tr class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-800">
                                <th class="px-4 py-2.5 text-left font-medium">Campaign</th>
                                <th class="px-4 py-2.5 text-right font-medium">Clicks</th>
                                <th class="px-4 py-2.5 text-right font-medium">Orders</th>
                                <th class="px-4 py-2.5 text-right font-medium">Approved</th>
                                <th class="px-4 py-2.5 text-right font-medium">Commission</th>
                                <th class="px-4 py-2.5 text-right font-medium">CVR</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in summary.campaign_breakdown"
                                :key="row.campaign_id ?? row.campaign_name"
                                class="border-b border-zinc-100 dark:border-zinc-800"
                            >
                                <td class="px-4 py-2.5 text-zinc-900 dark:text-zinc-100">
                                    {{ row.campaign_name }}
                                </td>
                                <td class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300">{{ number(row.clicks) }}</td>
                                <td class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300">{{ number(row.orders) }}</td>
                                <td class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300">{{ number(row.approved) }}</td>
                                <td class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300">{{ money(row.commission) }}</td>
                                <td class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300">
                                    {{ percent(cvrByCampaign(row)) }}
                                </td>
                            </tr>
                            <tr v-if="summary.campaign_breakdown.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                    No campaign rows available.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppShell>
</template>

<script setup>
import AppShell from '@/Layouts/AppShell.vue'
import ClicksSubnav from './Partials/ClicksSubnav.vue'
import { Head } from '@inertiajs/vue3'

const props = defineProps({
    summary: {
        type: Object,
        default: () => ({
            funnel: { clicks: 0, orders: 0, approved: 0 },
            totals: { clicks: 0, unique_clicks: 0, valid_clicks: 0, bot_clicks: 0, orders: 0, approved: 0, commission: 0 },
            cvr: 0,
            approved_rate: 0,
            epc: 0,
            trend_by_day: [],
            campaign_breakdown: [],
        }),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
})

function number(value) {
    return Number(value || 0).toLocaleString()
}

function percent(value) {
    return `${Number(value || 0).toFixed(2)}%`
}

function money(value) {
    return `VND ${Number(value || 0).toLocaleString()}`
}

function cvrByCampaign(row) {
    const clicks = Number(row.clicks || 0)
    if (clicks <= 0) return 0
    return (Number(row.orders || 0) / clicks) * 100
}
</script>
