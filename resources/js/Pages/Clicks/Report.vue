<template>
    <AppShell>
        <Head title="Click Report" />

        <div class="flex flex-col gap-6 p-6">
            <div class="flex flex-col gap-2">
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">
                    Click Report
                </h1>
                <p class="text-[13px] text-zinc-500 dark:text-zinc-400">
                    Aggregated click analytics and reporting.
                </p>
                <ClicksSubnav />
            </div>

            <div class="flex flex-wrap items-end gap-3 bg-zinc-50 dark:bg-zinc-800/20 p-4 rounded-xl border border-zinc-200 dark:border-zinc-800">
                <div class="flex flex-col gap-1 w-[160px]">
                    <label class="text-[10px] uppercase font-bold text-zinc-500 dark:text-zinc-400">View By</label>
                    <select
                        v-model="activeGroupBy"
                        @change="applyFilter"
                        class="h-8 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-[12px] px-2 py-0"
                    >
                        <option value="date">Date</option>
                        <option value="utm_source">UTM Source</option>
                        <option value="utm_campaign">Campaign</option>
                        <option value="device_type">Device Type</option>
                        <option value="tracking_link_id">Tracking Link</option>
                    </select>
                </div>

                <div class="flex flex-col gap-1 w-[160px]">
                    <label class="text-[10px] uppercase font-bold text-zinc-500 dark:text-zinc-400">UTM Source</label>
                    <select
                        v-model="filterSource"
                        @change="applyFilter"
                        class="h-8 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-[12px] px-2 py-0"
                    >
                        <option value="">All Sources</option>
                        <option v-for="source in filterOptions.utm_sources" :key="source" :value="source">
                            {{ source }}
                        </option>
                    </select>
                </div>

                <div class="flex flex-col gap-1 w-[160px]">
                    <label class="text-[10px] uppercase font-bold text-zinc-500 dark:text-zinc-400">Campaign</label>
                    <select
                        v-model="filterCampaign"
                        @change="applyFilter"
                        class="h-8 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-[12px] px-2 py-0"
                    >
                        <option value="">All Campaigns</option>
                        <option v-for="campaign in filterOptions.utm_campaigns" :key="campaign" :value="campaign">
                            {{ campaign }}
                        </option>
                    </select>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-[12px]">
                        <thead>
                            <tr class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-800">
                                <th class="px-4 py-2 text-left font-medium min-w-[200px]">{{ groupColumnTitle }}</th>
                                <th class="px-4 py-2 text-right font-medium">Total Clicks</th>
                                <th class="px-4 py-2 text-right font-medium text-rose-600">Bot Suspected</th>
                                <th class="px-4 py-2 text-right font-medium">Unique IPs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, idx) in report.items"
                                :key="idx"
                                class="border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                            >
                                <td class="px-4 py-2 font-medium text-zinc-800 dark:text-zinc-200">
                                    <template v-if="activeGroupBy === 'date'">
                                        {{ formatDateTime(row.grouped_by, true) }}
                                    </template>
                                    <template v-else-if="activeGroupBy === 'tracking_link_id'">
                                        <!-- Note: Real implementation would resolve trackingLink relationships from DTO -->
                                        Link #{{ row.grouped_by }}
                                    </template>
                                    <template v-else>
                                        {{ row.grouped_by }}
                                    </template>
                                </td>
                                <td class="px-4 py-2 text-right text-zinc-900 dark:text-zinc-100 font-mono text-[13px]">
                                    {{ number(row.total_clicks) }}
                                </td>
                                <td class="px-4 py-2 text-right text-rose-600 dark:text-rose-400 font-mono text-[13px]">
                                    {{ number(row.bot_clicks) }}
                                </td>
                                <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300 font-mono text-[13px]">
                                    {{ number(row.unique_ips) }}
                                </td>
                            </tr>
                            <tr v-if="report.items.length === 0">
                                <td colspan="8" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                    No click rows found for current filter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-800 flex items-center justify-between text-[12px] text-zinc-600 dark:text-zinc-400">
                    <div>
                        Showing {{ report.pagination.from || 0 }}-{{ report.pagination.to || 0 }}
                        of {{ report.pagination.total || 0 }}
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            as="button"
                            :href="pageUrl((report.pagination.current_page || 1) - 1)"
                            :disabled="!hasPrev"
                            class="h-8 px-3 rounded-md border border-zinc-200 dark:border-zinc-700"
                            :class="!hasPrev ? 'opacity-50 cursor-not-allowed' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800'"
                        >
                            Prev
                        </Link>
                        <span class="text-zinc-700 dark:text-zinc-300">
                            Page {{ report.pagination.current_page || 1 }} / {{ report.pagination.last_page || 1 }}
                        </span>
                        <Link
                            as="button"
                            :href="pageUrl((report.pagination.current_page || 1) + 1)"
                            :disabled="!hasNext"
                            class="h-8 px-3 rounded-md border border-zinc-200 dark:border-zinc-700"
                            :class="!hasNext ? 'opacity-50 cursor-not-allowed' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800'"
                        >
                            Next
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>

<script setup>
import AppShell from '@/Layouts/AppShell.vue'
import ClicksSubnav from './Partials/ClicksSubnav.vue'
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
    report: {
        type: Object,
        default: () => ({
            items: [],
            pagination: {
                current_page: 1,
                per_page: 20,
                total: 0,
                last_page: 1,
                from: null,
                to: null,
                has_more_pages: false,
            },
        }),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    filterOptions: {
        type: Object,
        default: () => ({
            utm_sources: [],
            utm_campaigns: [],
            devices: [],
            group_bys: ['date']
        })
    }
})

import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

const activeGroupBy = ref(props.filters?.group_by || 'date')
const filterSource = ref(props.filters?.utm_sources?.[0] || '')
const filterCampaign = ref(props.filters?.utm_campaigns?.[0] || '')

const groupColumnTitle = computed(() => {
    const map = {
        'date': 'Date',
        'tracking_link_id': 'Tracking Link',
        'utm_source': 'UTM Source',
        'utm_campaign': 'Campaign',
        'device_type': 'Device Type',
    }
    return map[activeGroupBy.value] || 'Group'
})

function applyFilter() {
    const next = new URLSearchParams()
    Object.entries(props.filters || {}).forEach(([key, value]) => {
        if (!['group_by', 'page', 'utm_sources', 'utm_campaigns'].includes(key)) {
            if (value !== null && value !== undefined && `${value}` !== '') {
                next.set(key, `${value}`)
            }
        }
    })
    
    next.set('group_by', activeGroupBy.value)
    
    if (filterSource.value) {
        next.set('utm_sources[0]', filterSource.value)
    }
    if (filterCampaign.value) {
        next.set('utm_campaigns[0]', filterCampaign.value)
    }
    
    router.get(`/analytics/clicks/report?${next.toString()}`)
}

const botRows = computed(() => {
    // Re-calculated from aggregation
    return props.report.items.reduce((sum, row) => sum + (Number(row.bot_clicks) || 0), 0)
})
const unattributedRows = computed(() => props.report.items.filter((row) => row.attribution_status === 'unattributed').length)
const hasPrev = computed(() => (props.report.pagination.current_page || 1) > 1)
const hasNext = computed(() => Boolean(props.report.pagination.has_more_pages))

function number(value) {
    return Number(value || 0).toLocaleString()
}

function formatDateTime(value, dateOnly = false) {
    if (!value) return '-'
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return value
    return dateOnly ? date.toLocaleDateString() : `${date.toLocaleDateString()} ${date.toLocaleTimeString()}`
}

function pageUrl(page) {
    const next = new URLSearchParams()
    Object.entries(props.filters || {}).forEach(([key, value]) => {
        if (value !== null && value !== undefined && `${value}` !== '') {
            next.set(key, `${value}`)
        }
    })
    next.set('page', `${Math.max(1, Number(page || 1))}`)
    return `/clicks/report?${next.toString()}`
}
</script>
