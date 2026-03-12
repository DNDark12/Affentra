<template>
    <AppShell>
        <Head title="Conversion Report" />

        <div class="flex flex-col gap-6 p-6">
            <div class="flex flex-col gap-2">
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">
                    Conversion Report
                </h1>
                <p class="text-[13px] text-zinc-500 dark:text-zinc-400">
                    Orders attributed from tracked clicks.
                </p>
                <ClicksSubnav />
            </div>

            <div class="flex flex-wrap items-end gap-3 bg-zinc-50 dark:bg-zinc-800/20 p-4 rounded-xl border border-zinc-200 dark:border-zinc-800">
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
                                <th class="px-4 py-2.5 text-left font-medium">Order</th>
                                <th class="px-4 py-2.5 text-left font-medium">Tracking</th>
                                <th class="px-4 py-2.5 text-left font-medium">Status</th>
                                <th class="px-4 py-2.5 text-right font-medium">Order Amount</th>
                                <th class="px-4 py-2.5 text-right font-medium">Commission</th>
                                <th class="px-4 py-2.5 text-left font-medium">Ordered At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in conversion.items"
                                :key="row.id"
                                class="border-b border-zinc-100 dark:border-zinc-800"
                            >
                                <td class="px-4 py-2.5 text-zinc-900 dark:text-zinc-100">
                                    <div class="flex flex-col">
                                        <span class="font-medium">{{ row.order_code || '-' }}</span>
                                        <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ row.external_order_id || '-' }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-zinc-700 dark:text-zinc-300">
                                    <div class="flex flex-col">
                                        <span>{{ row.tracking_link?.short_code || '-' }}</span>
                                        <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ row.tracking_link?.campaign?.name || 'No campaign' }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-medium" :class="statusClass(row.status)">
                                        {{ row.status || '-' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300">{{ money(row.order_amount) }}</td>
                                <td class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300">{{ money(row.commission) }}</td>
                                <td class="px-4 py-2.5 text-zinc-700 dark:text-zinc-300">{{ formatDateTime(row.ordered_at) }}</td>
                            </tr>
                            <tr v-if="conversion.items.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                    No conversion rows found for current filter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-800 flex items-center justify-between text-[12px] text-zinc-600 dark:text-zinc-400">
                    <div>
                        Showing {{ conversion.pagination.from || 0 }}-{{ conversion.pagination.to || 0 }}
                        of {{ conversion.pagination.total || 0 }}
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            as="button"
                            :href="pageUrl((conversion.pagination.current_page || 1) - 1)"
                            :disabled="!hasPrev"
                            class="h-8 px-3 rounded-md border border-zinc-200 dark:border-zinc-700"
                            :class="!hasPrev ? 'opacity-50 cursor-not-allowed' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800'"
                        >
                            Prev
                        </Link>
                        <span class="text-zinc-700 dark:text-zinc-300">
                            Page {{ conversion.pagination.current_page || 1 }} / {{ conversion.pagination.last_page || 1 }}
                        </span>
                        <Link
                            as="button"
                            :href="pageUrl((conversion.pagination.current_page || 1) + 1)"
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
    conversion: {
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
        })
    }
})

import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

const filterSource = ref(props.filters?.utm_sources?.[0] || '')
const filterCampaign = ref(props.filters?.utm_campaigns?.[0] || '')

function applyFilter() {
    const next = new URLSearchParams()
    Object.entries(props.filters || {}).forEach(([key, value]) => {
        if (!['page', 'utm_sources', 'utm_campaigns'].includes(key)) {
            if (value !== null && value !== undefined && `${value}` !== '') {
                next.set(key, `${value}`)
            }
        }
    })
    
    if (filterSource.value) {
        next.set('utm_sources[0]', filterSource.value)
    }
    if (filterCampaign.value) {
        next.set('utm_campaigns[0]', filterCampaign.value)
    }
    
    router.get(`/analytics/clicks/conversion?${next.toString()}`)
}

const approvedCount = computed(() => props.conversion.items.filter((row) => (row.status || '').toLowerCase() === 'approved').length)
const totalOrderAmount = computed(() => props.conversion.items.reduce((sum, row) => sum + Number(row.order_amount || 0), 0))
const totalCommission = computed(() => props.conversion.items.reduce((sum, row) => sum + Number(row.commission || 0), 0))
const hasPrev = computed(() => (props.conversion.pagination.current_page || 1) > 1)
const hasNext = computed(() => Boolean(props.conversion.pagination.has_more_pages))

function number(value) {
    return Number(value || 0).toLocaleString()
}

function money(value) {
    return `VND ${Number(value || 0).toLocaleString()}`
}

function formatDateTime(value) {
    if (!value) return '-'
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return value
    return `${date.toLocaleDateString()} ${date.toLocaleTimeString()}`
}

function statusClass(status) {
    const key = String(status || '').toLowerCase()
    if (key === 'approved') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400'
    if (key === 'pending') return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400'
    if (key === 'rejected') return 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-400'
    return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-500/20 dark:text-zinc-300'
}

function pageUrl(page) {
    const next = new URLSearchParams()
    Object.entries(props.filters || {}).forEach(([key, value]) => {
        if (!['page', 'utm_sources', 'utm_campaigns'].includes(key)) {
            if (value !== null && value !== undefined && `${value}` !== '') {
                next.set(key, `${value}`)
            }
        }
    })
    
    if (filterSource.value) {
        next.set('utm_sources[0]', filterSource.value)
    }
    if (filterCampaign.value) {
        next.set('utm_campaigns[0]', filterCampaign.value)
    }
    
    next.set('page', `${Math.max(1, Number(page || 1))}`)
    return `/analytics/clicks/conversion?${next.toString()}`
}
</script>
