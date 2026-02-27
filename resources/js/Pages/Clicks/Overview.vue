<template>
    <AppShell>
        <div class="flex flex-col gap-6 p-6">
            <!-- Header section -->
            <div class="flex flex-col gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">
                        Click Analytics
                    </h1>
                    <p class="text-[13px] text-zinc-500 dark:text-zinc-400 mt-1">
                        Funnel and breakdown by campaign, device, referrer and time
                    </p>
                </div>
                
                <!-- Subnav -->
                <ClicksSubnav />
            </div>

            <!-- Filters -->
            <div class="flex items-center gap-2 flex-wrap">
                <div class="px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition">
                    Date: 01/02 - 25/02
                </div>
                <div class="px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition">
                    Campaign: All
                </div>
                <div class="px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition">
                    Device: All
                </div>
                <div class="px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition">
                    Referrer: All
                </div>
                <div class="px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition">
                    Saved View: Top Performers
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-1">
                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">Funnel</span>
                    <span class="text-[22px] font-bold text-zinc-900 dark:text-zinc-100">{{ summary.funnel.clicks }} > {{ summary.funnel.orders }} > {{ summary.funnel.approved }}</span>
                    <div class="flex items-center gap-1 mt-1 text-[10px] text-zinc-500">
                        <i class="ph ph-funnel"></i> Click to Order to Approved
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-1">
                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">CVR</span>
                    <span class="text-[22px] font-bold text-zinc-900 dark:text-zinc-100">{{ summary.cvr }}%</span>
                    <div class="flex items-center gap-1 mt-1 text-[10px] text-green-600">
                        <i class="ph ph-trend-up"></i> +1.2%
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-1">
                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">Approved Rate</span>
                    <span class="text-[22px] font-bold text-zinc-900 dark:text-zinc-100">{{ summary.approved_rate }}%</span>
                    <div class="flex items-center gap-1 mt-1 text-[10px] text-zinc-500">
                        <i class="ph ph-check-circle"></i> Avg. platform
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-1">
                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">EPC</span>
                    <span class="text-[22px] font-bold text-zinc-900 dark:text-zinc-100">₫{{ summary.epc.toLocaleString() }}</span>
                    <div class="flex items-center gap-1 mt-1 text-[10px] text-green-600">
                        <i class="ph ph-trend-up"></i> +₫200
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 h-[280px]">
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-3 h-full">
                    <h3 class="text-[13px] font-semibold text-zinc-700 dark:text-zinc-300">Clicks vs Orders (time)</h3>
                    <div class="h-full rounded-lg bg-zinc-50 dark:bg-zinc-800/50 flex items-center justify-center border border-dashed border-zinc-200 dark:border-zinc-700">
                        <span class="text-zinc-400 text-xs">Chart Placeholder</span>
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-3 h-full">
                    <h3 class="text-[13px] font-semibold text-zinc-700 dark:text-zinc-300">Conversion by Campaign</h3>
                    <div class="h-full rounded-lg bg-zinc-50 dark:bg-zinc-800/50 flex items-center justify-center border border-dashed border-zinc-200 dark:border-zinc-700">
                        <span class="text-zinc-400 text-xs">Chart Placeholder</span>
                    </div>
                </div>
            </div>

            <!-- Breakdown -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-3 flex flex-col">
                    <span class="text-[11px] font-semibold text-zinc-700 dark:text-zinc-300">Device</span>
                    <span class="text-[12px] text-zinc-500 dark:text-zinc-400 mt-0.5">Mobile 88%</span>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-3 flex flex-col">
                    <span class="text-[11px] font-semibold text-zinc-700 dark:text-zinc-300">Referrer</span>
                    <span class="text-[12px] text-zinc-500 dark:text-zinc-400 mt-0.5">Facebook 52%</span>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-3 flex flex-col">
                    <span class="text-[11px] font-semibold text-zinc-700 dark:text-zinc-300">Time</span>
                    <span class="text-[12px] text-zinc-500 dark:text-zinc-400 mt-0.5">Peak 20:00-22:00</span>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-3 flex flex-col">
                    <span class="text-[11px] font-semibold text-zinc-700 dark:text-zinc-300">Campaign</span>
                    <span class="text-[12px] text-zinc-500 dark:text-zinc-400 mt-0.5">Tet Sale 2026</span>
                </div>
            </div>

            <!-- Analytics Table -->
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 flex flex-col overflow-hidden">
                <div class="p-4 border-b border-zinc-200 dark:border-zinc-800">
                    <h3 class="text-[13px] font-semibold text-zinc-700 dark:text-zinc-300">Analytics Table</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-[12px]">
                        <thead>
                            <tr class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-800">
                                <th class="py-2.5 px-4 font-medium">Campaign</th>
                                <th class="py-2.5 px-4 font-medium text-right">Clicks</th>
                                <th class="py-2.5 px-4 font-medium text-right">Orders</th>
                                <th class="py-2.5 px-4 font-medium text-right">Approved</th>
                                <th class="py-2.5 px-4 font-medium text-right">CVR</th>
                                <th class="py-2.5 px-4 font-medium text-right">EPC</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Mock Data Row -->
                            <tr class="border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                                <td class="py-3 px-4 text-zinc-900 dark:text-zinc-100 font-medium">Tet Sale 2026</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">6,743</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">534</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">401</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">7.9%</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">₫3,952</td>
                            </tr>
                            <tr class="border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                                <td class="py-3 px-4 text-zinc-900 dark:text-zinc-100 font-medium">Beauty Festival</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">4,210</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">380</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">250</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">9.0%</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">₫4,100</td>
                            </tr>
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                                <td class="py-3 px-4 text-zinc-900 dark:text-zinc-100 font-medium">Default Link</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">3,939</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">333</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">241</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">8.4%</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 text-right">₫2,450</td>
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
        required: true
    },
    filters: {
        type: Object,
        default: () => ({})
    }
})
</script>
