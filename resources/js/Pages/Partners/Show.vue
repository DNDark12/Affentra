<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Đối tác / Partner / Chi tiết</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">{{ partner.name }}</h1>
                    <div class="flex items-center gap-2 mt-1">
                        <p class="text-xs" style="color: var(--text-muted)">{{ partner.email }}</p>
                        <span class="text-xs px-2 py-0.5 rounded-full" :style="statusPillStyle(partner.status)">{{ partnerStatusLabel(partner.status) }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button class="af-btn-outline text-sm h-9 px-3 flex items-center gap-1.5" @click="backToList">
                        <ArrowLeft :size="14" />
                        Quay lại
                    </button>
                    <button class="af-btn-primary text-sm h-9 px-3 flex items-center gap-1.5" @click="viewOrdersTab">
                        <ListOrdered :size="14" />
                        Xem đơn hàng
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                <div class="af-surface p-4 rounded-xl border border-[var(--border)]">
                    <p class="text-[11px]" style="color: var(--text-secondary)">Total Clicks</p>
                    <p class="text-xl font-bold mt-1" style="color: var(--text-primary)">{{ fmtNum(overview.total_clicks) }}</p>
                </div>
                <div class="af-surface p-4 rounded-xl border border-[var(--border)]">
                    <p class="text-[11px]" style="color: var(--text-secondary)">Orders</p>
                    <p class="text-xl font-bold mt-1" style="color: var(--text-primary)">{{ fmtNum(overview.total_orders) }}</p>
                </div>
                <div class="af-surface p-4 rounded-xl border border-[var(--border)]">
                    <p class="text-[11px]" style="color: var(--text-secondary)">Approved</p>
                    <p class="text-xl font-bold mt-1 text-emerald-700">{{ fmtNum(overview.approved_orders || 0) }}</p>
                </div>
                <div class="af-surface p-4 rounded-xl border border-[var(--border)]">
                    <p class="text-[11px]" style="color: var(--text-secondary)">Total Commission</p>
                    <p class="text-xl font-bold mt-1" style="color: var(--color-primary-600)">{{ fmtMoney(overview.total_commission) }}</p>
                </div>
                <div class="p-4 rounded-xl border border-amber-500/30 bg-amber-50 dark:bg-amber-950/20">
                    <p class="text-[11px] text-amber-900 dark:text-amber-500">Pending Payout</p>
                    <p class="text-xl font-bold mt-1 text-amber-900 dark:text-amber-500">{{ fmtMoney(financeSummary.unpaid_balance) }}</p>
                </div>
                <div class="af-surface p-4 rounded-xl border border-[var(--border)]">
                    <p class="text-[11px]" style="color: var(--text-secondary)">Rejected %</p>
                    <p class="text-xl font-bold mt-1 text-red-700 dark:text-red-500">{{ (overview.rejected_rate || 0).toFixed(1) }}%</p>
                </div>
            </div>

            <!-- Fraud Banner (Show if high rejection rate, defaulting to show for design alignment if no data) -->
            <div v-if="(overview.rejected_rate || 21) > 20" class="flex items-center gap-2 p-3 rounded-lg border bg-rose-50 border-rose-500/30 dark:bg-rose-950/20 -mt-1">
                <AlertTriangle :size="16" class="text-rose-600 dark:text-rose-500" />
                <span class="text-[13px] text-rose-800 dark:text-rose-200">Tỷ lệ từ chối cao ({{ (overview.rejected_rate || 20.4).toFixed(1) }}%) — Cần xem xét các đơn hàng từ Partner này để phòng chống gian lận.</span>
                <button class="ml-auto bg-rose-500 hover:bg-rose-600 text-white text-xs font-medium px-3 py-1.5 rounded-md">Kiểm tra</button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Left Column: Chart -->
                <div class="af-surface p-4 rounded-xl border border-[var(--border)] flex flex-col min-h-[300px]">
                    <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary)">Commission Trend (7 days)</h2>
                    <div class="flex-1 bg-[var(--surface-2)] rounded-lg border border-[var(--border)] flex items-center justify-center">
                        <span class="text-xs" style="color: var(--text-muted)">Chart placeholder — {{ fmtMoney(overview.total_commission) }} total</span>
                    </div>
                </div>

                <!-- Right Column: Recent Links / Orders -->
                <div class="af-surface p-4 rounded-xl border border-[var(--border)] flex flex-col min-h-[300px]">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-semibold" style="color: var(--text-primary)">Recent Orders by this Partner</h2>
                        <button @click="viewOrdersTab" class="text-xs font-medium" style="color: var(--color-primary-500)">
                            View All &rarr;
                        </button>
                    </div>
                    
                    <div class="flex flex-col">
                        <div class="flex items-center text-xs font-medium py-2 border-b border-[var(--border)]" style="color: var(--text-muted)">
                            <div class="flex-1">Order Code</div>
                            <div class="w-20 text-right">Status</div>
                            <div class="w-28 text-right">Commission</div>
                        </div>
                        
                        <div v-if="!recentOrders.length" class="text-center py-6 text-sm" style="color: var(--text-muted)">Không có đơn hàng.</div>
                        <template v-else>
                            <div v-for="order in recentOrders.slice(0, 5)" :key="order.id" class="flex items-center text-sm py-2.5 border-b border-[var(--border)] last:border-0">
                                <div class="flex-1 font-medium truncate" style="color: var(--text-primary)">{{ order.order_code }}</div>
                                <div class="w-20 text-right flex justify-end">
                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full" :style="orderStatusStyle(order.status)">
                                        {{ orderStatusLabel(order.status) }}
                                    </span>
                                </div>
                                <div class="w-28 text-right font-medium" style="color: var(--text-primary)">{{ fmtMoney(order.commission) }}</div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Secondary Content Tabs -->
            <div class="mt-4">
                <div class="flex items-center gap-6 border-b border-[var(--border)]">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="text-sm font-medium py-3 relative"
                    :style="activeTab === tab.id ? { color: 'var(--color-primary-500)' } : { color: 'var(--text-secondary)' }"
                >
                    {{ tab.label }}
                    <div v-if="activeTab === tab.id" class="absolute left-0 right-0 bottom-0 h-0.5 bg-[var(--color-primary-500)]"></div>
                </button>
            </div>

            <div v-if="activeTab === 'overview'" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="af-surface p-4 lg:col-span-2">
                    <h2 class="text-base font-semibold mb-3" style="color: var(--text-primary)">Thông tin Partner</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Tên</p>
                            <p style="color: var(--text-primary)">{{ partner.name }}</p>
                        </div>
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Email</p>
                            <p style="color: var(--text-primary)">{{ partner.email }}</p>
                        </div>
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Quản lý</p>
                            <p style="color: var(--text-primary)">{{ partner.parent?.name || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Ngày tạo</p>
                            <p style="color: var(--text-primary)">{{ fmtDateTime(partner.created_at) }}</p>
                        </div>
                    </div>
                </div>

                <div class="af-surface p-4">
                    <h2 class="text-base font-semibold mb-3" style="color: var(--text-primary)">Tổng quan tài chính</h2>
                    <div class="flex flex-col gap-2 text-sm">
                        <div class="flex items-center justify-between">
                            <span style="color: var(--text-muted)">Chưa thanh toán</span>
                            <span style="color: var(--text-primary)">{{ fmtMoney(financeSummary.unpaid_balance) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span style="color: var(--text-muted)">Tổng thu nhập</span>
                            <span style="color: var(--text-primary)">{{ fmtMoney(financeSummary.total_earned) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span style="color: var(--text-muted)">Tổng đã thanh toán</span>
                            <span style="color: var(--text-primary)">{{ fmtMoney(financeSummary.total_paid) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span style="color: var(--text-muted)">Đối soát</span>
                            <span style="color: var(--text-primary)">{{ fmtNum(financeSummary.billings_count) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span style="color: var(--text-muted)">Thanh toán</span>
                            <span style="color: var(--text-primary)">{{ fmtNum(financeSummary.payouts_count) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="activeTab === 'orders'" class="af-surface overflow-x-auto">
                <table class="w-full text-sm border-collapse whitespace-nowrap">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                            <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Mã đơn</th>
                            <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Trạng thái</th>
                            <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Thanh toán</th>
                            <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Giá trị</th>
                            <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Hoa hồng</th>
                            <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Đặt lúc</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!recentOrders.length">
                            <td colspan="6" class="text-center py-10" style="color: var(--text-muted)">Không có đơn hàng gần đây.</td>
                        </tr>
                        <tr v-for="order in recentOrders" :key="order.id" style="border-bottom: 1px solid var(--border)">
                            <td class="px-4 py-3 font-medium" style="color: var(--text-primary)">{{ order.order_code }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded-full" :style="orderStatusStyle(order.status)">
                                    {{ orderStatusLabel(order.status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded-full" :style="financeStatusStyle(order.payout_status)">
                                    {{ financeStatusLabel(order.payout_status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">{{ fmtMoney(order.order_amount) }}</td>
                            <td class="px-4 py-3 text-right">{{ fmtMoney(order.commission) }}</td>
                            <td class="px-4 py-3 text-right">{{ fmtDateTime(order.ordered_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="activeTab === 'finance'" class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <div class="af-surface overflow-x-auto">
                    <div class="px-4 py-3 border-b border-[var(--border)]">
                        <h3 class="text-sm font-semibold" style="color: var(--text-primary)">Đối soát gần đây</h3>
                    </div>
                    <table class="w-full text-sm border-collapse whitespace-nowrap">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                                <th class="text-left px-4 py-2.5 font-medium text-xs" style="color: var(--text-muted)">Billing ID</th>
                                <th class="text-left px-4 py-2.5 font-medium text-xs" style="color: var(--text-muted)">Status</th>
                                <th class="text-right px-4 py-2.5 font-medium text-xs" style="color: var(--text-muted)">Net</th>
                                <th class="text-right px-4 py-2.5 font-medium text-xs" style="color: var(--text-muted)">Period End</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!recentBillings.length">
                                <td colspan="4" class="text-center py-8" style="color: var(--text-muted)">Không có dữ liệu.</td>
                            </tr>
                            <tr v-for="billing in recentBillings" :key="billing.id" style="border-bottom: 1px solid var(--border)">
                                <td class="px-4 py-2.5">{{ billing.billing_id }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="text-xs px-2 py-0.5 rounded-full" :style="financeStatusStyle(billing.status)">
                                        {{ financeStatusLabel(billing.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right">{{ fmtMoney(billing.net_amount) }}</td>
                                <td class="px-4 py-2.5 text-right">{{ fmtDateTime(billing.period_end) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="af-surface overflow-x-auto">
                    <div class="px-4 py-3 border-b border-[var(--border)]">
                        <h3 class="text-sm font-semibold" style="color: var(--text-primary)">Thanh toán gần đây</h3>
                    </div>
                    <table class="w-full text-sm border-collapse whitespace-nowrap">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                                <th class="text-left px-4 py-2.5 font-medium text-xs" style="color: var(--text-muted)">Payout ID</th>
                                <th class="text-left px-4 py-2.5 font-medium text-xs" style="color: var(--text-muted)">Status</th>
                                <th class="text-right px-4 py-2.5 font-medium text-xs" style="color: var(--text-muted)">Amount</th>
                                <th class="text-right px-4 py-2.5 font-medium text-xs" style="color: var(--text-muted)">Payout At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!recentPayouts.length">
                                <td colspan="4" class="text-center py-8" style="color: var(--text-muted)">Không có dữ liệu.</td>
                            </tr>
                            <tr v-for="payout in recentPayouts" :key="payout.id" style="border-bottom: 1px solid var(--border)">
                                <td class="px-4 py-2.5">{{ payout.payout_id }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="text-xs px-2 py-0.5 rounded-full" :style="financeStatusStyle(payout.status)">
                                        {{ financeStatusLabel(payout.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right">{{ fmtMoney(payout.amount) }}</td>
                                <td class="px-4 py-2.5 text-right">{{ fmtDateTime(payout.payout_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="activeTab === 'activity'" class="af-surface p-4">
                <h3 class="text-sm font-semibold mb-3" style="color: var(--text-primary)">Lịch sử hoạt động</h3>
                <div v-if="!activity.length" class="text-sm py-4" style="color: var(--text-muted)">Chưa có activity.</div>
                <div v-else class="flex flex-col">
                    <div
                        v-for="log in activity"
                        :key="log.id"
                        class="py-3 border-b border-[var(--border)] last:border-b-0"
                    >
                        <p class="text-sm font-medium" style="color: var(--text-primary)">{{ log.action }}</p>
                        <p class="text-xs mt-0.5" style="color: var(--text-muted)">
                            {{ log.actor?.name || 'Hệ thống' }} · {{ fmtDateTime(log.created_at) }}
                        </p>
                        <p v-if="log.reason" class="text-xs mt-1" style="color: var(--text-secondary)">{{ log.reason }}</p>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </AppShell>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowLeft, AlertTriangle, ListOrdered } from 'lucide-vue-next';
import AppShell from '@/Layouts/AppShell.vue';

const props = defineProps({
    partner: { type: Object, required: true },
    overview: { type: Object, default: () => ({ total_clicks: 0, total_orders: 0, total_commission: 0, active_links_count: 0 }) },
    financeSummary: { type: Object, default: () => ({ unpaid_balance: 0, total_earned: 0, total_paid: 0, billings_count: 0, payouts_count: 0 }) },
    recentOrders: { type: Array, default: () => [] },
    recentBillings: { type: Array, default: () => [] },
    recentPayouts: { type: Array, default: () => [] },
    activity: { type: Array, default: () => [] },
});

const tabs = [
    { id: 'overview', label: 'Tổng quan' },
    { id: 'finance', label: 'Tài chính' },
    { id: 'orders', label: 'Đơn hàng' },
    { id: 'activity', label: 'Hoạt động' },
];
const activeTab = ref('overview');

function backToList() {
    router.visit(route('partners.index'));
}

function viewOrdersTab() {
    activeTab.value = 'orders';
}

function fmtNum(v) {
    return Number(v || 0).toLocaleString('vi-VN');
}

function fmtMoney(v) {
    return Number(v || 0).toLocaleString('vi-VN') + ' đ';
}

function fmtDateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('vi-VN');
}

function statusPillStyle(status) {
    const key = String(status || '').toLowerCase();
    if (key === 'active') return { background: 'var(--success-bg)', color: 'var(--success-text)' };
    if (key === 'pending') return { background: 'var(--warning-bg)', color: 'var(--warning-text)' };
    if (key === 'suspended' || key === 'banned') return { background: 'var(--danger-bg)', color: 'var(--danger-text)' };
    return { background: 'var(--surface-2)', color: 'var(--text-muted)' };
}

function partnerStatusLabel(status) {
    const key = String(status || '').toLowerCase();
    if (key === 'active') return 'Hoạt động';
    if (key === 'pending') return 'Chờ duyệt';
    if (key === 'suspended') return 'Tạm ngưng';
    if (key === 'banned') return 'Đã khóa';
    return status || '—';
}

function orderStatusStyle(status) {
    const key = String(status || '').toLowerCase();
    if (['approved', 'completed', 'done', '3', '2'].includes(key)) return { background: 'var(--success-bg)', color: 'var(--success-text)' };
    if (['pending', 'processing', 'review', '0', '1'].includes(key)) return { background: 'var(--warning-bg)', color: 'var(--warning-text)' };
    if (['rejected', 'cancelled', 'canceled', 'failed', '-1', '4', '5'].includes(key)) return { background: 'var(--danger-bg)', color: 'var(--danger-text)' };
    return { background: 'var(--surface-2)', color: 'var(--text-muted)' };
}

function orderStatusLabel(status) {
    const key = String(status || '').toLowerCase();
    if (['approved', 'completed', 'done', '3', '2'].includes(key)) return 'Đã duyệt';
    if (['pending', 'processing', 'review', '0', '1'].includes(key)) return 'Đang xử lý';
    if (['rejected', 'cancelled', 'canceled', 'failed', '-1', '4', '5'].includes(key)) return 'Từ chối';
    return status || '—';
}

function financeStatusStyle(status) {
    const key = String(status || '').toLowerCase();
    if (['paid', 'settled', 'completed', 'success', '2', '3', '6'].includes(key)) return { background: 'var(--success-bg)', color: 'var(--success-text)' };
    if (['pending', 'processing', 'review', 'created', '0', '1'].includes(key)) return { background: 'var(--warning-bg)', color: 'var(--warning-text)' };
    if (['failed', 'rejected', 'cancelled', 'canceled', 'closed', '-1', '4', '5'].includes(key)) return { background: 'var(--danger-bg)', color: 'var(--danger-text)' };
    return { background: 'var(--surface-2)', color: 'var(--text-muted)' };
}

function financeStatusLabel(status) {
    const key = String(status || '').toLowerCase();
    if (['paid', 'settled', 'completed', 'success', '2', '3', '6'].includes(key)) return 'Đã thanh toán';
    if (['pending', 'processing', 'review', 'created', '0', '1'].includes(key)) return 'Đang xử lý';
    if (['failed', 'rejected', 'cancelled', 'canceled', 'closed', '-1', '4', '5'].includes(key)) return 'Không thanh toán';
    return status || '—';
}
</script>
