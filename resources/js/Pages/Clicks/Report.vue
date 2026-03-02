<template>
    <AppShell>
        <Head title="Click Report" />

        <div class="flex flex-col gap-6 p-6">
            <div class="flex flex-col gap-2">
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">
                    Click Report
                </h1>
                <p class="text-[13px] text-zinc-500 dark:text-zinc-400">
                    Raw click rows from synchronized sources.
                </p>
                <ClicksSubnav />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4">
                    <div class="text-[11px] text-zinc-500 dark:text-zinc-400">Rows (total)</div>
                    <div class="text-[22px] font-bold text-zinc-900 dark:text-zinc-100 mt-1">
                        {{ number(report.pagination.total) }}
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4">
                    <div class="text-[11px] text-zinc-500 dark:text-zinc-400">Rows (page)</div>
                    <div class="text-[22px] font-bold text-zinc-900 dark:text-zinc-100 mt-1">
                        {{ number(report.items.length) }}
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4">
                    <div class="text-[11px] text-zinc-500 dark:text-zinc-400">Bot Suspected (page)</div>
                    <div class="text-[22px] font-bold text-rose-600 dark:text-rose-400 mt-1">
                        {{ number(botRows) }}
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4">
                    <div class="text-[11px] text-zinc-500 dark:text-zinc-400">Unattributed (page)</div>
                    <div class="text-[22px] font-bold text-amber-600 dark:text-amber-400 mt-1">
                        {{ number(unattributedRows) }}
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-[12px]">
                        <thead>
                            <tr class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-800">
                                <th class="px-4 py-2.5 text-left font-medium">ID</th>
                                <th class="px-4 py-2.5 text-left font-medium">Time</th>
                                <th class="px-4 py-2.5 text-left font-medium">Sub ID</th>
                                <th class="px-4 py-2.5 text-left font-medium">Campaign</th>
                                <th class="px-4 py-2.5 text-left font-medium">Referrer Domain</th>
                                <th class="px-4 py-2.5 text-left font-medium">IP</th>
                                <th class="px-4 py-2.5 text-left font-medium">Attribution</th>
                                <th class="px-4 py-2.5 text-left font-medium">Risk</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in report.items"
                                :key="row.id"
                                class="border-b border-zinc-100 dark:border-zinc-800"
                            >
                                <td class="px-4 py-2.5 font-mono text-zinc-700 dark:text-zinc-300">{{ row.id }}</td>
                                <td class="px-4 py-2.5 text-zinc-900 dark:text-zinc-100">{{ formatDateTime(row.created_at) }}</td>
                                <td class="px-4 py-2.5 text-zinc-700 dark:text-zinc-300">{{ row.sub_id || '-' }}</td>
                                <td class="px-4 py-2.5 text-zinc-700 dark:text-zinc-300">
                                    {{ row.tracking_link?.campaign?.name || 'Unattributed' }}
                                </td>
                                <td class="px-4 py-2.5 text-zinc-700 dark:text-zinc-300">{{ row.referer_domain || '-' }}</td>
                                <td class="px-4 py-2.5 text-zinc-700 dark:text-zinc-300 font-mono">{{ row.ip || '-' }}</td>
                                <td class="px-4 py-2.5">
                                    <span
                                        class="px-2 py-0.5 rounded text-[10px] font-medium"
                                        :class="row.attribution_status === 'matched'
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400'
                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400'"
                                    >
                                        {{ row.attribution_status || '-' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span
                                        class="px-2 py-0.5 rounded text-[10px] font-medium"
                                        :class="row.is_bot
                                            ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-400'
                                            : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-500/20 dark:text-zinc-300'"
                                    >
                                        {{ row.is_bot ? (row.bot_reason || 'bot') : 'normal' }}
                                    </span>
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
})

const botRows = computed(() => props.report.items.filter((row) => row.is_bot).length)
const unattributedRows = computed(() => props.report.items.filter((row) => row.attribution_status === 'unattributed').length)
const hasPrev = computed(() => (props.report.pagination.current_page || 1) > 1)
const hasNext = computed(() => Boolean(props.report.pagination.has_more_pages))

function number(value) {
    return Number(value || 0).toLocaleString()
}

function formatDateTime(value) {
    if (!value) return '-'
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return value
    return `${date.toLocaleDateString()} ${date.toLocaleTimeString()}`
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
