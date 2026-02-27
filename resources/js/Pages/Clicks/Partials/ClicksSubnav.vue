<template>
    <div class="flex items-center gap-2 mb-6 border-b border-zinc-200 dark:border-zinc-800 pb-4">
        <Link
            href="/clicks"
            class="h-8 px-3 rounded-lg text-xs font-medium flex items-center transition-colors"
            :class="isActive('/clicks') ? 'bg-indigo-50 border border-indigo-200 text-indigo-700 dark:bg-indigo-500/20 dark:border-indigo-500/30 dark:text-indigo-300' : 'bg-white border border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:bg-zinc-900 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800'"
        >
            Analytics Overview
        </Link>
        <Link
            href="/clicks/report"
            class="h-8 px-3 rounded-lg text-xs font-medium flex items-center transition-colors"
            :class="isActive('/clicks/report') ? 'bg-indigo-50 border border-indigo-200 text-indigo-700 dark:bg-indigo-500/20 dark:border-indigo-500/30 dark:text-indigo-300' : 'bg-white border border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:bg-zinc-900 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800'"
        >
            Click Report
        </Link>
        <Link
            href="/clicks/conversion"
            class="h-8 px-3 rounded-lg text-xs font-medium flex items-center transition-colors"
            :class="isActive('/clicks/conversion') ? 'bg-indigo-50 border border-indigo-200 text-indigo-700 dark:bg-indigo-500/20 dark:border-indigo-500/30 dark:text-indigo-300' : 'bg-white border border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:bg-zinc-900 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800'"
        >
            Conversion Report
        </Link>
    </div>
</template>

<script setup>
import { Link, usePage } from '@inertiajs/vue3'

const page = usePage()

function isActive(path) {
    // Basic active state check based on exact path match
    return page.url.split('?')[0] === path
}
</script>
