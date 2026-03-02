<template>
    <div v-if="isOpen" class="fixed inset-0 z-50 flex justify-end">
        <!-- Backdrop -->
        <transition enter-active-class="transition-opacity ease-linear duration-300"
                    enter-from-class="opacity-0" enter-to-class="opacity-100"
                    leave-active-class="transition-opacity ease-linear duration-300"
                    leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="close"></div>
        </transition>

        <!-- Slide Slide-over panel -->
        <transition enter-active-class="transform transition ease-in-out duration-300"
                    enter-from-class="translate-x-full" enter-to-class="translate-x-0"
                    leave-active-class="transform transition ease-in-out duration-300"
                    leave-from-class="translate-x-0" leave-to-class="translate-x-full">
            <div class="relative w-[560px] max-w-full flex shadow-2xl h-full bg-white dark:bg-zinc-900 border-l border-zinc-200 dark:border-zinc-800">
                <div class="flex flex-col h-full w-full" v-if="activeRun">
                    
                    <!-- Header -->
                    <div class="px-6 py-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                        <div class="flex flex-col gap-1">
                            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Chi tiết đồng bộ</h2>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-[13px] text-zinc-500 dark:text-zinc-400">
                                    Chạy lúc {{ formatTime(activeRun.started_at) }}
                                </span>
                                <div class="px-2 py-0.5 rounded-full flex items-center gap-1.5"
                                     :class="statusBadgeClass(activeRun.status)">
                                    <i :class="statusIcon(activeRun.status)" class="text-[12px]"></i>
                                    <span class="text-[11px] font-semibold uppercase tracking-wide">{{ activeRun.status }}</span>
                                </div>
                            </div>
                        </div>
                        <button @click="close" class="h-8 w-8 flex items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800 text-zinc-500 hover:text-zinc-700 transition-colors">
                            <i class="ph ph-x"></i>
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 overflow-y-auto p-6 flex flex-col gap-6">
                        <!-- Metrics Grid -->
                        <div class="grid grid-cols-3 gap-4">
                            <!-- Fetched -->
                            <div class="bg-zinc-50 dark:bg-zinc-800/60 rounded-lg p-4 flex flex-col gap-1 border border-zinc-100 dark:border-zinc-700/50">
                                <span class="text-[12px] text-zinc-500 dark:text-zinc-400 font-medium tracking-wide">Bản ghi API lấy về</span>
                                <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">{{ activeRun.records_fetched ?? '-' }}</span>
                            </div>
                            <!-- Inserted -->
                            <div class="bg-zinc-50 dark:bg-zinc-800/60 rounded-lg p-4 flex flex-col gap-1 border border-zinc-100 dark:border-zinc-700/50">
                                <span class="text-[12px] text-zinc-500 dark:text-zinc-400 font-medium tracking-wide">Đã thêm/cập nhật</span>
                                <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">{{ activeRun.records_upserted ?? '-' }}</span>
                            </div>
                            <!-- Failed -->
                            <div class="bg-zinc-50 dark:bg-zinc-800/60 rounded-lg p-4 flex flex-col gap-1 border border-zinc-100 dark:border-zinc-700/50">
                                <span class="text-[12px] text-zinc-500 dark:text-zinc-400 font-medium tracking-wide">Bản ghi bỏ qua/lỗi</span>
                                <span class="text-xl font-bold" :class="Number(activeRun.records_failed || 0) > 0 ? 'text-red-600' : 'text-zinc-900 dark:text-zinc-100'">
                                    {{ activeRun.records_failed ?? 0 }}
                                </span>
                            </div>
                        </div>

                        <p class="text-[12px] text-zinc-500 dark:text-zinc-400">
                            Chỉ số trên là tổng bản ghi đồng bộ (Orders + Clicks hoặc Finance records), không phải số sản phẩm trong Tracking Link.
                        </p>

                        <div v-if="moduleRows.length" class="flex flex-col gap-3">
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Chi tiết theo module</h3>
                            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700/80 bg-white dark:bg-zinc-900 overflow-hidden">
                                <table class="w-full text-[12px]">
                                    <thead class="bg-zinc-50 dark:bg-zinc-800/70 border-b border-zinc-200 dark:border-zinc-700">
                                        <tr>
                                            <th class="text-left px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">Module</th>
                                            <th class="text-left px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">Status</th>
                                            <th class="text-right px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">Fetched</th>
                                            <th class="text-right px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">Upserted/Updated</th>
                                            <th class="text-right px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">Failed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="(module, index) in moduleRows"
                                            :key="module.key"
                                            :class="{ 'border-b border-zinc-100 dark:border-zinc-800': index < moduleRows.length - 1 }"
                                        >
                                            <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ module.label }}</td>
                                            <td class="px-3 py-2">
                                                <span
                                                    class="px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wide"
                                                    :class="moduleStatusClass(module.status)"
                                                >
                                                    {{ module.status || 'n/a' }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-right text-zinc-700 dark:text-zinc-300 tabular-nums">{{ module.fetched }}</td>
                                            <td class="px-3 py-2 text-right text-zinc-700 dark:text-zinc-300 tabular-nums">{{ module.upserted }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums" :class="Number(module.failed) > 0 ? 'text-red-500 dark:text-red-400' : 'text-zinc-700 dark:text-zinc-300'">
                                                {{ module.failed }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Details List -->
                        <div class="flex flex-col gap-3">
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Chi tiết Execution</h3>
                            
                            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700/80 bg-white dark:bg-zinc-900 overflow-hidden text-[13px]">
                                <!-- Row: Platform -->
                                <div class="flex justify-between items-center px-4 py-3 border-b border-zinc-100 dark:border-zinc-800">
                                    <span class="text-zinc-500 dark:text-zinc-400">Nền tảng:</span>
                                    <div class="flex items-center gap-1.5 font-medium text-zinc-900 dark:text-zinc-100">
                                        <i class="ph ph-plugs-connected text-indigo-500"></i>
                                        <span>{{ connectionInfo?.label || connectionInfo?.name || connectionInfo?.platform || 'Unknown' }}</span>
                                    </div>
                                </div>
                                <!-- Row: Sync ID -->
                                <div class="flex justify-between items-center px-4 py-3 border-b border-zinc-100 dark:border-zinc-800">
                                    <span class="text-zinc-500 dark:text-zinc-400">ID Yêu cầu:</span>
                                    <span class="font-mono text-zinc-900 dark:text-zinc-100 bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 rounded text-[12px]">{{ activeRun.id }}</span>
                                </div>
                                <!-- Row: End Time -->
                                <div class="flex justify-between items-center px-4 py-3 border-b border-zinc-100 dark:border-zinc-800" v-if="activeRun.finished_at">
                                    <span class="text-zinc-500 dark:text-zinc-400">Đã xong lúc:</span>
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ formatTime(activeRun.finished_at) }}</span>
                                </div>
                                
                                <!-- Log Output -->
                                <div class="flex flex-col gap-2 p-4 bg-zinc-50 dark:bg-zinc-800/50">
                                    <span class="text-zinc-500 dark:text-zinc-400 font-medium">Output Log</span>
                                    <div class="bg-zinc-900 rounded-md p-3 max-h-[250px] overflow-y-auto">
                                        <pre class="text-[12px] font-mono whitespace-pre-wrap rounded leading-relaxed" 
                                             :class="logTextClass(activeRun)"
                                        >{{ activeRun.error_message || "Execution completed normally. No system errors recorded." }}</pre>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                    
                </div>
            </div>
        </transition>
    </div>
</template>

<script setup>
import { computed, watch } from 'vue';

const props = defineProps({
    isOpen: Boolean,
    selectedRun: Object,     // Single history record
    runs: {
        type: Array,
        default: () => [],
    },
    connectionInfo: Object,  // The parent connection item for display context
});

const emit = defineEmits(['close']);

function close() {
    emit('close');
}

const displayedRuns = computed(() => {
    if (Array.isArray(props.runs) && props.runs.length) {
        return props.runs;
    }

    return props.selectedRun ? [props.selectedRun] : [];
});

const activeRun = computed(() => {
    if (!displayedRuns.value.length) {
        return null;
    }

    if (props.selectedRun?.id) {
        const selected = displayedRuns.value.find((run) => run.id === props.selectedRun.id);
        if (selected) return selected;
    }

    return displayedRuns.value[0];
});

const moduleRows = computed(() => {
    const details = activeRun.value?.details;
    const modules = details?.modules;
    if (!modules || typeof modules !== 'object') {
        return [];
    }

    const labels = {
        orders: 'Orders',
        clicks: 'Clicks',
        campaign: 'Campaign',
        finance_billing: 'Finance Billing',
        finance_payout: 'Finance Payout',
        finance_service_fee: 'Finance Service Fee',
        finance_order_reconcile: 'Finance Reconcile Orders',
        finance: 'Finance',
    };

    return Object.entries(modules).map(([key, value]) => {
        const item = (value && typeof value === 'object') ? value : {};
        return {
            key,
            label: labels[key] || key,
            status: item.status || 'ok',
            fetched: item.fetched ?? 0,
            upserted: item.upserted ?? item.updated ?? 0,
            failed: item.failed ?? 0,
        };
    });
});

watch(() => props.isOpen, (val) => {
    if (val) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
});

function formatTime(isoString) {
    if (!isoString) return '--';
    const d = new Date(isoString);
    return d.toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' });
}

function statusBadgeClass(status) {
    if (status === 'completed') return 'bg-emerald-100/80 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400';
    if (String(status).startsWith('failed')) return 'bg-red-100/80 text-red-700 dark:bg-red-500/10 dark:text-red-400';
    return 'bg-amber-100/80 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400';
}

function statusIcon(status) {
    if (status === 'completed') return 'ph-check-circle-fill text-emerald-600 dark:text-emerald-500';
    if (String(status).startsWith('failed')) return 'ph-warning-circle-fill text-red-600 dark:text-red-500';
    return 'ph-spinner-gap animate-spin text-amber-600 dark:text-amber-500';
}

function moduleStatusClass(status) {
    const normalized = String(status || '').toLowerCase();
    if (normalized === 'ok' || normalized === 'completed') {
        return 'bg-emerald-100/80 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400';
    }
    if (normalized === 'partial' || normalized === 'warning') {
        return 'bg-amber-100/80 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400';
    }
    if (normalized === 'failed' || normalized === 'error') {
        return 'bg-red-100/80 text-red-700 dark:bg-red-500/10 dark:text-red-400';
    }

    return 'bg-zinc-100/80 text-zinc-700 dark:bg-zinc-700/40 dark:text-zinc-300';
}

function logTextClass(run) {
    if (String(run?.status).startsWith('failed')) return 'text-red-400';
    if (run?.status === 'completed_with_warnings') return 'text-amber-300';
    if (run?.status === 'completed') return 'text-emerald-300';
    return 'text-emerald-400';
}
</script>
