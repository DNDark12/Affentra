<template>
    <AppShell>
        <div class="flex flex-col gap-6 p-6">
            <!-- Header section -->
            <div class="flex flex-col gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">
                        Click Report
                    </h1>
                    <p class="text-[13px] text-zinc-500 dark:text-zinc-400 mt-1">
                        Raw click logs by click_id, zone, sub_id, referrer and anti-fraud signals
                    </p>
                </div>
                
                <!-- Subnav -->
                <ClicksSubnav />
            </div>

            <!-- Filters -->
            <div class="flex items-center gap-2 flex-wrap">
                <div class="px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition">
                    Date: 01/02 - 26/02
                </div>
                <div class="px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition">
                    Zone: All
                </div>
                <div class="px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition">
                    Sub_id: All
                </div>
                <div class="px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition">
                    Referrer: All
                </div>
                <div class="px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition">
                    Saved View: High Risk
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-1">
                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">Total Clicks</span>
                    <span class="text-[22px] font-bold text-zinc-900 dark:text-zinc-100">26,408</span>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-1">
                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">Unique IP</span>
                    <span class="text-[22px] font-bold text-zinc-900 dark:text-zinc-100">11,219</span>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-1">
                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">Bot Suspected</span>
                    <span class="text-[22px] font-bold text-red-600 dark:text-red-500">382</span>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-1">
                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">Invalid Rate</span>
                    <span class="text-[22px] font-bold text-zinc-900 dark:text-zinc-100">2.1%</span>
                </div>
            </div>

            <!-- Charts -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 h-[280px]">
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-3 h-full">
                    <h3 class="text-[13px] font-semibold text-zinc-700 dark:text-zinc-300">Clicks by Hour</h3>
                    <div class="h-full rounded-lg bg-zinc-50 dark:bg-zinc-800/50 flex items-center justify-center border border-dashed border-zinc-200 dark:border-zinc-700">
                        <span class="text-zinc-400 text-xs">Chart Placeholder</span>
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-3 h-full">
                    <h3 class="text-[13px] font-semibold text-zinc-700 dark:text-zinc-300">Clicks by Zone</h3>
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

            <!-- Log Table -->
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 flex flex-col overflow-hidden">
                <div class="p-4 border-b border-zinc-200 dark:border-zinc-800">
                    <h3 class="text-[13px] font-semibold text-zinc-700 dark:text-zinc-300">Click Log Table</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-[12px]">
                        <thead>
                            <tr class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-800">
                                <th class="py-2.5 px-4 font-medium">Click ID</th>
                                <th class="py-2.5 px-4 font-medium">Time</th>
                                <th class="py-2.5 px-4 font-medium">Zone</th>
                                <th class="py-2.5 px-4 font-medium">Sub ID</th>
                                <th class="py-2.5 px-4 font-medium">Referrer</th>
                                <th class="py-2.5 px-4 font-medium">IP Group</th>
                                <th class="py-2.5 px-4 font-medium">Risk</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Mock Data Row -->
                            <tr class="border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 font-mono text-[11px]">CLK-982611</td>
                                <td class="py-3 px-4 text-zinc-900 dark:text-zinc-100">23:31 26-02</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300">Facebook</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300">sub_tet_live_03</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 truncate max-w-[150px]">fb.com/livestream</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 font-mono text-[11px]">113.161.24.*</td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400">Low</span>
                                </td>
                            </tr>
                            <tr class="border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 font-mono text-[11px]">CLK-982612</td>
                                <td class="py-3 px-4 text-zinc-900 dark:text-zinc-100">23:32 26-02</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300">Website</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300">banner_top</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 truncate max-w-[150px]">-</td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-300 font-mono text-[11px]">182.12.99.*</td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-medium bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400">High (Bot)</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-800 flex items-center justify-between text-[11px] text-zinc-500">
                    <span>Rows per page: 20</span>
                    <span class="flex gap-2">
                        <button class="hover:text-zinc-800">1</button>
                        <button class="hover:text-zinc-800">2</button>
                        <button class="hover:text-zinc-800">3</button>
                        <button class="hover:text-zinc-800">Next</button>
                    </span>
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
    report: {
        type: Object,
        default: () => ({})
    },
    filters: {
        type: Object,
        default: () => ({})
    }
})
</script>
