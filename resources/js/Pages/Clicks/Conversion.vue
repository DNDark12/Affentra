<template>
    <AppShell>
        <div class="flex flex-col gap-6 p-6">
            <!-- Header section -->
            <div class="flex flex-col gap-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">
                            Conversion Report
                        </h1>
                        <p class="text-[13px] text-zinc-500 dark:text-zinc-400 mt-1">
                            Showing: All statuses · Shopee
                        </p>
                    </div>
                </div>
                
                <!-- Subnav -->
                <ClicksSubnav />
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-5 flex flex-col gap-1">
                    <span class="text-[12px] text-zinc-500 dark:text-zinc-400">Converted Orders</span>
                    <span class="text-[22px] font-bold text-zinc-900 dark:text-zinc-100">1,842</span>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-5 flex flex-col gap-1">
                    <span class="text-[12px] text-zinc-500 dark:text-zinc-400">Approved Orders</span>
                    <span class="text-[22px] font-bold text-emerald-700 dark:text-emerald-500">1,206</span>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-5 flex flex-col gap-1">
                    <span class="text-[12px] text-zinc-500 dark:text-zinc-400">Pending Commission</span>
                    <span class="text-[22px] font-bold text-amber-700 dark:text-amber-500">₫18.4M</span>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-5 flex flex-col gap-1">
                    <span class="text-[12px] text-zinc-500 dark:text-zinc-400">Estimated Commission</span>
                    <span class="text-[22px] font-bold text-indigo-600 dark:text-indigo-400">₫62.1M</span>
                </div>
            </div>

            <!-- Searching / Filter row -->
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <div class="px-3.5 py-1.5 rounded-full bg-indigo-600 text-white text-[12px] font-medium cursor-pointer">
                        All
                    </div>
                    <div class="px-3.5 py-1.5 rounded-full bg-white dark:bg-zinc-900 text-zinc-700 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-700 text-[12px] font-medium cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        Approved
                    </div>
                    <div class="px-3.5 py-1.5 rounded-full bg-white dark:bg-zinc-900 text-zinc-700 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-700 text-[12px] font-medium cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        Pending
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-2 px-3 py-1.5 border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 rounded-lg w-[200px]">
                        <i class="ph ph-magnifying-glass text-zinc-400"></i>
                        <input type="text" placeholder="Search order ID..." class="bg-transparent border-none outline-none p-0 text-[12px] w-full text-zinc-700 dark:text-zinc-200">
                    </div>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 flex flex-col overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-[12px]">
                        <thead>
                            <tr class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-800">
                                <th class="w-8 py-3 px-4 text-center"><input type="checkbox" class="rounded border-zinc-300"></th>
                                <th class="py-3 px-4 font-medium w-[140px]">Order ID</th>
                                <th class="py-3 px-4 font-medium">Product Info</th>
                                <th class="py-3 px-4 font-medium w-[100px]">Status</th>
                                <th class="py-3 px-4 font-medium text-right w-[110px]">Order Value</th>
                                <th class="py-3 px-4 font-medium text-right w-[120px]">Commission</th>
                                <th class="py-3 px-4 font-medium text-right w-[90px]">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Mock Row 1 -->
                            <tr class="border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                                <td class="py-3 px-4 text-center"><input type="checkbox" class="rounded border-zinc-300"></td>
                                <td class="py-3 px-4">
                                    <div class="flex flex-col">
                                        <span class="text-zinc-900 dark:text-zinc-100 font-medium">SHP-2891023</span>
                                        <span class="text-[10px] text-zinc-500 font-mono mt-0.5">CLK-982611</span>
                                    </div>
                                </td>
                                <td class="py-3 px-4 text-zinc-700 dark:text-zinc-300">Apple iPhone 15 Pro Max 256GB - Titanium</td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400">Approved</span>
                                </td>
                                <td class="py-3 px-4 text-right text-zinc-700 dark:text-zinc-300">₫28,500,000</td>
                                <td class="py-3 px-4 text-right text-indigo-600 dark:text-indigo-400 font-medium">₫385,000</td>
                                <td class="py-3 px-4 text-right text-zinc-500">26-02</td>
                            </tr>
                            <!-- Mock Row 2 -->
                            <tr class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/20 hover:bg-zinc-100 dark:hover:bg-zinc-800/50 transition-colors">
                                <td class="py-3 px-4 text-center"><input type="checkbox" class="rounded border-zinc-300"></td>
                                <td class="py-3 px-4">
                                    <div class="flex flex-col">
                                        <span class="text-zinc-900 dark:text-zinc-100 font-medium">SHP-2891024</span>
                                        <span class="text-[10px] text-zinc-500 font-mono mt-0.5">CLK-982619</span>
                                    </div>
                                </td>
                                <td class="py-3 px-4 text-zinc-700 dark:text-zinc-300">Áo thun nam basic cổ tròn cotton 100%</td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400">Pending</span>
                                </td>
                                <td class="py-3 px-4 text-right text-zinc-700 dark:text-zinc-300">₫150,000</td>
                                <td class="py-3 px-4 text-right text-amber-600 dark:text-amber-400 font-medium">₫8,500</td>
                                <td class="py-3 px-4 text-right text-zinc-500">26-02</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-indigo-50 dark:bg-indigo-500/10 border-t border-zinc-200 dark:border-zinc-800 font-medium">
                                <td colspan="4" class="py-3 px-4 text-left text-zinc-700 dark:text-zinc-300">Page Summary</td>
                                <td class="py-3 px-4 text-right text-zinc-900 dark:text-zinc-100">₫28,650,000</td>
                                <td class="py-3 px-4 text-right text-indigo-700 dark:text-indigo-400">₫393,500</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="px-4 py-3 flex items-center justify-between text-[11px] text-zinc-500">
                    <span>Showing 1–2 of 1,842 conversion orders</span>
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
    conversion: {
        type: Object,
        default: () => ({})
    },
    filters: {
        type: Object,
        default: () => ({})
    }
})
</script>
