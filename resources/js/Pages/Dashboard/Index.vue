<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <!-- Header row -->
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">
                        Trang  /  Dashboard
                    </p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Dashboard</h1>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Period selector -->
                    <select v-model="period" @change="loadSummary"
                            class="af-input h-9 w-auto text-sm pr-8">
                        <option value="7days">7 ngày</option>
                        <option value="30days">30 ngày</option>
                        <option value="90days">90 ngày</option>
                    </select>
                    <a href="#" class="af-btn-primary text-sm h-9 px-4">
                        <i class="ph ph-plus text-sm"></i>
                        Tạo Link
                    </a>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                <div class="af-kpi-card">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Clicks</p>
                    <p class="text-2xl font-semibold" style="color: var(--text-primary)">
                        {{ fmtNum(summary.clicks) }}
                    </p>
                </div>
                <div class="af-kpi-card">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Hiển thị Miles</p>
                    <p class="text-2xl font-semibold" style="color: var(--text-primary)">
                        {{ fmtNum(summary.orders) }}
                    </p>
                </div>
                <div class="af-kpi-card">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Đơn hàng</p>
                    <p class="text-2xl font-semibold" style="color: var(--text-primary)">
                        {{ fmtNum(summary.orders) }}
                    </p>
                </div>
                <div class="af-kpi-card">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Approved</p>
                    <p class="text-2xl font-semibold" style="color: var(--text-primary)">
                        {{ fmtNum(summary.approved) }}
                    </p>
                </div>
                <div class="af-kpi-card">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Commission</p>
                    <p class="text-2xl font-semibold" style="color: var(--color-primary-500)">
                        ₫{{ fmtMoney(summary.commission) }}
                    </p>
                </div>
            </div>

            <!-- Charts row -->
            <div class="grid grid-cols-2 gap-3" style="min-height: 260px;">
                <div class="af-surface p-4 flex flex-col gap-2">
                    <div class="flex justify-between items-center">
                        <p class="text-sm font-medium" style="color: var(--text-primary)">Doanh thu hoa hồng (VNĐ)</p>
                    </div>
                    <div class="flex-1 mt-2">
                        <LineChart v-if="summary.daily && summary.daily.length" 
                            :series="commissionSeries" 
                            :categories="chartCategories" 
                            :colors="['#10B981']" 
                            height="240" />
                        <div v-else class="flex h-full items-center justify-center text-sm" style="color: var(--text-muted)">
                            Không có dữ liệu
                        </div>
                    </div>
                </div>
                <div class="af-surface p-4 flex flex-col gap-2">
                    <div class="flex justify-between items-center">
                        <p class="text-sm font-medium" style="color: var(--text-primary)">Tương tác & Chuyển đổi</p>
                    </div>
                    <div class="flex-1 mt-2">
                        <LineChart v-if="summary.daily && summary.daily.length" 
                            :series="kpiSeries" 
                            :categories="chartCategories" 
                            height="240" />
                        <div v-else class="flex h-full items-center justify-center text-sm" style="color: var(--text-muted)">
                            Không có dữ liệu
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alerts Panel -->
            <div class="af-surface p-4 flex flex-col gap-2">
                <div class="flex justify-between items-center mb-1">
                    <p class="text-sm font-semibold" style="color: var(--text-primary)">Alerts</p>
                    <span class="text-xs px-2 py-0.5 rounded-full"
                          style="background: var(--danger-bg); color: var(--danger-text)">
                        {{ alerts.length }} mới
                    </span>
                </div>
                <div v-if="alerts.length === 0" class="text-sm py-2" style="color: var(--text-muted)">
                    Không có cảnh báo.
                </div>
                <div v-for="(alert, i) in alerts" :key="i"
                     class="text-sm py-2 px-3 rounded-md"
                     :style="alertStyle(alert.type)">
                    {{ alert.message }}
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="flex gap-3">
                <a href="#" class="af-btn-outline text-sm">
                    <i class="ph ph-link"></i> Tạo Tracking Link
                </a>
                <a href="#" class="af-btn-outline text-sm">
                    <i class="ph ph-upload-simple"></i> Import CSV
                </a>
                <a href="#" class="af-btn-outline text-sm">
                    <i class="ph ph-money"></i> Tạo Payout
                </a>
            </div>
        </div>
    </AppShell>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import LineChart from '@/Components/Charts/LineChart.vue';

const props = defineProps({
    summary: {
        type: Object,
        default: () => ({ period: '30days', clicks: 0, orders: 0, approved: 0, commission: 0, daily: [] }),
    },
});

const period = ref(props.summary?.period || '30days');

const commissionSeries = computed(() => {
    if (!props.summary.daily) return [];
    return [{
        name: 'Hoa hồng',
        data: props.summary.daily.map(d => parseFloat(d.commission) || 0)
    }];
});

const kpiSeries = computed(() => {
    if (!props.summary.daily) return [];
    return [
        { name: 'Clicks', data: props.summary.daily.map(d => parseInt(d.clicks) || 0) },
        { name: 'Đơn hàng', data: props.summary.daily.map(d => parseInt(d.orders) || 0) },
        { name: 'Đã duyệt', data: props.summary.daily.map(d => parseInt(d.approved) || 0) }
    ];
});

const chartCategories = computed(() => {
    if (!props.summary.daily) return [];
    return props.summary.daily.map(d => {
        if (!d.date) return '';
        const parts = d.date.split('-'); 
        return parts.length >= 3 ? `${parts[2]}/${parts[1]}` : d.date;
    });
});


// Placeholder alerts — Phase 2 will come from API
const alerts = ref([
    { type: 'danger',  message: 'Tài khoản Shopee Affiliate đang hết hạn — gia hạn trước 30/01.' },
    { type: 'warning', message: 'Campaign "H2 2026" còn 2 ngày nữa kết thúc. Kiểm tra lại mục tiêu.' },
    { type: 'info',    message: 'Bắt đầu đồng bộ dữ liệu từ Shopee Affiliate — hoàn tất lúc 14:30.' },
]);

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
    router.get(route('dashboard'), { period: period.value }, { preserveState: true, replace: true });
}
</script>
