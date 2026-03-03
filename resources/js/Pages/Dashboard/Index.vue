<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Dashboard</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Dashboard</h1>
                </div>
                <select
                    v-model="period"
                    class="af-input h-9 text-sm pr-8"
                    style="width: 140px"
                    @change="loadSummary"
                >
                        <option value="7days">7 ngày</option>
                        <option value="30days">30 ngày</option>
                        <option value="90days">90 ngày</option>
                </select>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3">
                <div class="af-kpi-card">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Clicks</p>
                    <p class="text-2xl font-semibold" style="color: var(--text-primary)">
                        {{ fmtNum(summary.clicks) }}
                    </p>
                </div>
                <div class="af-kpi-card">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Đơn hàng</p>
                    <p class="text-2xl font-semibold" style="color: var(--text-primary)">
                        {{ fmtNum(summary.orders) }}
                    </p>
                </div>
                <div class="af-kpi-card">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Đã duyệt</p>
                    <p class="text-2xl font-semibold" style="color: var(--text-primary)">
                        {{ fmtNum(summary.approved) }}
                    </p>
                </div>
                <div class="af-kpi-card">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Hoa hồng</p>
                    <p class="text-2xl font-semibold" style="color: var(--text-primary)">
                        ₫{{ fmtMoney(summary.commission) }}
                    </p>
                </div>
                <div class="af-kpi-card">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Link hoạt động</p>
                    <p class="text-2xl font-semibold" style="color: var(--text-primary)">
                        {{ fmtNum(summary.active_links) }}
                    </p>
                </div>
                <div class="af-kpi-card">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Campaign hoạt động</p>
                    <p class="text-2xl font-semibold" style="color: var(--text-primary)">
                        {{ fmtNum(summary.active_campaigns) }}
                    </p>
                </div>
                <div class="af-kpi-card">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Đơn chưa gán link</p>
                    <p class="text-2xl font-semibold" style="color: var(--danger-text)">
                        {{ fmtNum(summary.unattributed_orders) }}
                    </p>
                </div>
                <div class="af-kpi-card">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Hoa hồng chờ thanh toán</p>
                    <p class="text-2xl font-semibold" style="color: var(--color-primary-500)">
                        ₫{{ fmtMoney(summary.pending_commission) }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-3" style="min-height: 260px;">
                <div class="af-surface p-4 flex flex-col gap-2">
                    <p class="text-sm font-medium" style="color: var(--text-primary)">Hoa hồng đã duyệt theo ngày (VNĐ)</p>
                    <div class="flex-1 mt-2">
                        <LineChart
                            v-if="dailyRows.length"
                            :series="commissionSeries"
                            :categories="chartCategories"
                            :colors="['#10B981']"
                            height="240"
                        />
                        <div v-else class="flex h-full items-center justify-center text-sm" style="color: var(--text-muted)">
                            Không có dữ liệu
                        </div>
                    </div>
                </div>
                <div class="af-surface p-4 flex flex-col gap-2">
                    <p class="text-sm font-medium" style="color: var(--text-primary)">Clicks / Đơn hàng / Duyệt theo ngày</p>
                    <div class="flex-1 mt-2">
                        <LineChart
                            v-if="dailyRows.length"
                            :series="kpiSeries"
                            :categories="chartCategories"
                            height="240"
                        />
                        <div v-else class="flex h-full items-center justify-center text-sm" style="color: var(--text-muted)">
                            Không có dữ liệu
                        </div>
                    </div>
                </div>
            </div>

            <div class="af-surface p-4 flex flex-col gap-2">
                <div class="flex justify-between items-center mb-1">
                    <p class="text-sm font-semibold" style="color: var(--text-primary)">Cảnh báo đang mở</p>
                    <span
                        class="text-xs px-2 py-0.5 rounded-full"
                        style="background: var(--danger-bg); color: var(--danger-text)"
                    >
                        {{ fmtNum(summary.open_alerts_count) }} mở / {{ fmtNum(summary.unseen_alerts_count) }} chưa xem
                    </span>
                </div>
                <div v-if="alerts.length === 0" class="text-sm py-2" style="color: var(--text-muted)">
                    Không có cảnh báo.
                </div>
                <div
                    v-for="alert in alerts"
                    :key="alert.id"
                    class="text-sm py-2 px-3 rounded-md"
                    :style="alertStyle(alert.severity)"
                >
                    {{ alert.message }}
                </div>
                <div v-if="alerts.length" class="pt-1">
                    <button class="af-btn-outline text-sm h-8 px-3" @click="router.visit(route('alerts.index'))">
                        Xem trung tâm cảnh báo
                    </button>
                </div>
            </div>
        </div>
    </AppShell>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import LineChart from '@/Components/Charts/LineChart.vue';

const props = defineProps({
    summary: {
        type: Object,
        default: () => ({
            period: '30days',
            clicks: 0,
            orders: 0,
            approved: 0,
            commission: 0,
            active_links: 0,
            active_campaigns: 0,
            unattributed_orders: 0,
            pending_commission: 0,
            paid_commission: 0,
            open_alerts_count: 0,
            unseen_alerts_count: 0,
            alerts: [],
            daily: [],
        }),
    },
});

const period = ref(props.summary?.period || '30days');
watch(() => props.summary?.period, (next) => {
    if (typeof next === 'string' && next.length) {
        period.value = next;
    }
});

const dailyRows = computed(() => Array.isArray(props.summary?.daily) ? props.summary.daily : []);

const commissionSeries = computed(() => {
    return [{
        name: 'Hoa hồng',
        data: dailyRows.value.map((d) => parseFloat(d.commission) || 0),
    }];
});

const kpiSeries = computed(() => {
    return [
        { name: 'Clicks', data: dailyRows.value.map((d) => parseInt(d.clicks, 10) || 0) },
        { name: 'Đơn hàng', data: dailyRows.value.map((d) => parseInt(d.orders, 10) || 0) },
        { name: 'Đã duyệt', data: dailyRows.value.map((d) => parseInt(d.approved, 10) || 0) },
    ];
});

const chartCategories = computed(() => {
    return dailyRows.value.map((d) => {
        if (!d.date) return '';
        const parts = String(d.date).split('-');
        return parts.length >= 3 ? `${parts[2]}/${parts[1]}` : d.date;
    });
});

const alerts = computed(() => Array.isArray(props.summary?.alerts) ? props.summary.alerts : []);

function alertStyle(type) {
    const map = {
        danger:  { background: 'var(--danger-bg)',  color: 'var(--danger-text)' },
        warning: { background: 'var(--warning-bg)', color: 'var(--warning-text)' },
        info:    { background: 'var(--surface-2)',  color: 'var(--text-secondary)' },
    };
    return map[type] || map.info;
}

function fmtNum(n) {
    return Number(n || 0).toLocaleString('vi-VN');
}

function fmtMoney(n) {
    return Number(n || 0).toLocaleString('vi-VN', { maximumFractionDigits: 0 });
}

function loadSummary() {
    router.get(
        route('dashboard'),
        { period: period.value },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['summary'],
        },
    );
}
</script>
