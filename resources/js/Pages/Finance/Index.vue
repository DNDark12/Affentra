<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Tài chính</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Finance & Settlements</h1>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted)">Quản lý dòng tiền và đối soát hoa hồng</p>
                </div>
                <div class="flex items-center gap-2">
                    <span
                        v-if="sync?.is_running"
                        class="inline-flex items-center h-9 px-3 rounded-full text-xs font-semibold"
                        style="background: var(--warning-bg); color: var(--warning-text)"
                    >
                        Sync đang chạy...
                    </span>
                    <button @click="triggerSync" :disabled="isSyncing" class="af-btn-primary text-sm h-9 px-4 flex items-center gap-1.5">
                        <RefreshCw :size="14" :class="{ 'animate-spin': isSyncing }" />
                        Đồng bộ ngay
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="af-surface p-4 flex flex-col gap-1">
                    <p class="text-xs" style="color: var(--text-muted)">Unpaid Balance</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">{{ fmtMoney(summary.unpaid_balance) }}</p>
                </div>
                <div class="af-surface p-4 flex flex-col gap-1">
                    <p class="text-xs" style="color: var(--text-muted)">Total Earned</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">{{ fmtMoney(summary.total_earned) }}</p>
                </div>
                <div class="af-surface p-4 flex flex-col gap-1">
                    <p class="text-xs" style="color: var(--text-muted)">Total Paid</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">{{ fmtMoney(summary.total_paid) }}</p>
                </div>
            </div>

            <div class="flex items-center justify-between gap-2 flex-wrap">
                <div class="flex items-center gap-2 flex-wrap">
                    <input v-model="dateFrom" type="date" class="af-input h-9 text-sm" @change="applyFilters" />
                    <input v-model="dateTo" type="date" class="af-input h-9 text-sm" @change="applyFilters" />
                    <button class="af-btn-outline text-sm h-9 px-3" @click="applyFilters">Apply</button>
                </div>
                <p v-if="syncMessage" class="text-xs" style="color: var(--text-muted)">{{ syncMessage }}</p>
            </div>

            <!-- Tabs -->
            <div class="flex border-b border-[var(--border)] gap-8">
                <button 
                    v-for="tab in tabs" 
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="pb-3 text-sm font-medium transition-all relative"
                    :style="activeTab === tab.id ? { color: 'var(--color-primary-500)' } : { color: 'var(--text-secondary)' }"
                >
                    {{ tab.label }}
                    <div v-if="activeTab === tab.id" class="absolute bottom-0 left-0 right-0 h-0.5 bg-[var(--color-primary-500)]"></div>
                </button>
            </div>

            <!-- Billings Tab -->
            <div v-if="activeTab === 'billings'" class="flex flex-col gap-4">
                <div class="af-surface overflow-x-auto">
                    <table class="w-full text-sm border-collapse whitespace-nowrap">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                                <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Sàn & Mã Hóa Đơn</th>
                                <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Kỳ Thanh Toán</th>
                                <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Tổng Hoa Hồng</th>
                                <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Phí Dịch Vụ</th>
                                <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Thực Nhận</th>
                                <th class="text-center px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Trạng Thái</th>
                                <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Cập nhật</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!billings.data?.length">
                                <td colspan="7" class="text-center py-16" style="color: var(--text-muted)">
                                    <div class="flex flex-col items-center gap-3">
                                        <FileText :size="32" style="color: var(--text-muted)" />
                                        <p>Không tìm thấy dữ liệu hóa đơn nào.</p>
                                    </div>
                                </td>
                            </tr>
                            <tr v-for="b in billings.data" :key="b.id"
                                style="border-bottom: 1px solid var(--border)"
                                class="hover:bg-[var(--surface-2)] transition-colors">
                                <td class="px-4 py-3">
                                    <span class="capitalize text-xs font-semibold px-1.5 py-0.5 rounded mr-2" 
                                          :style="platformStyle(b.platform)">{{ b.platform }}</span>
                                    <span class="font-mono text-xs font-medium" style="color: var(--text-primary)">{{ b.billing_id }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    {{ formatDate(b.period_start) }} - {{ formatDate(b.period_end) }}
                                </td>
                                <td class="px-4 py-3 text-right font-medium">{{ fmtMoney(b.total_commission) }}</td>
                                <td class="px-4 py-3 text-right text-red-500">-{{ fmtMoney(b.service_fee) }}</td>
                                <td class="px-4 py-3 text-right font-bold" style="color: var(--color-primary-500)">{{ fmtMoney(b.net_amount) }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="text-xs font-medium px-2 py-0.5 rounded-full" :style="billingStatusStyle(b.status)">
                                        {{ billingStatusLabel(b.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-xs" style="color: var(--text-muted)">
                                    {{ formatDateTime(b.updated_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Payouts Tab -->
            <div v-if="activeTab === 'payouts'" class="flex flex-col gap-4">
                <div class="af-surface overflow-x-auto">
                    <table class="w-full text-sm border-collapse whitespace-nowrap">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                                <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">ID Chi Trả</th>
                                <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Ngân Hàng</th>
                                <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Số Tiền</th>
                                <th class="text-center px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Trạng Thái</th>
                                <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Thời Gian</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!payouts.data?.length">
                                <td colspan="5" class="text-center py-16" style="color: var(--text-muted)">
                                    <div class="flex flex-col items-center gap-3">
                                        <Wallet :size="32" style="color: var(--text-muted)" />
                                        <p>Không tìm thấy lịch sử chi trả nào.</p>
                                    </div>
                                </td>
                            </tr>
                            <tr v-for="p in payouts.data" :key="p.id"
                                style="border-bottom: 1px solid var(--border)"
                                class="hover:bg-[var(--surface-2)] transition-colors">
                                <td class="px-4 py-3 font-mono text-xs">{{ p.payout_id }}</td>
                                <td class="px-4 py-3">
                                    <p class="text-sm font-medium">{{ p.bank_name || '—' }}</p>
                                    <p class="text-xs text-[var(--text-muted)]">{{ p.account_number_masked }}</p>
                                </td>
                                <td class="px-4 py-3 text-right font-bold" style="color: var(--success-text)">{{ fmtMoney(p.amount) }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-green-500/10 text-green-500">
                                        {{ p.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-xs" style="color: var(--text-muted)">
                                    {{ formatDateTime(p.payout_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </AppShell>
</template>

<script setup>
import axios from 'axios';
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { RefreshCw, FileText, Wallet } from 'lucide-vue-next';
import AppShell from '@/Layouts/AppShell.vue';

const props = defineProps({
    billings: { type: Object, default: () => ({ data: [] }) },
    payouts:  { type: Object, default: () => ({ data: [] }) },
    summary:  { type: Object, default: () => ({ unpaid_balance: 0, total_earned: 0, total_paid: 0 }) },
    filters: { type: Object, default: () => ({}) },
    sync: { type: Object, default: () => ({ is_running: false }) },
});

const activeTab = ref('billings');
const isSyncing = ref(false);
const syncMessage = ref('');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');

const tabs = [
    { id: 'billings', label: 'Hóa Đơn (Billings)' },
    { id: 'payouts', label: 'Chi Trả (Payouts)' },
];

async function triggerSync() {
    if (isSyncing.value) return;
    isSyncing.value = true;
    syncMessage.value = '';

    try {
        const response = await axios.post(route('api.finance.sync'));
        if (response.data?.ok) {
            syncMessage.value = response.data?.message || 'Đang đồng bộ dữ liệu tài chính...';
            setTimeout(() => {
                router.reload({
                    only: ['billings', 'payouts', 'summary', 'filters', 'sync'],
                    preserveScroll: true,
                });
            }, 1800);
            return;
        }

        syncMessage.value = response.data?.message || 'Không thể đồng bộ dữ liệu.';
    } catch (error) {
        const message = error.response?.data?.message || 'Không thể đồng bộ dữ liệu.';
        syncMessage.value = message;
    } finally {
        isSyncing.value = false;
    }
}

function applyFilters() {
    router.get(route('finance.index'), {
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        billings_page: undefined,
        payouts_page: undefined,
    }, {
        replace: true,
        preserveScroll: true,
        preserveState: true,
        only: ['billings', 'payouts', 'summary', 'filters', 'sync'],
    });
}

function fmtMoney(v) {
    if (v === null || v === undefined) return '0 đ';
    return Number(v).toLocaleString('vi-VN') + ' đ';
}

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('vi-VN');
}

function formatDateTime(d) {
    if (!d) return '—';
    return new Date(d).toLocaleString('vi-VN');
}

function platformStyle(p) {
    const m = {
        shopee: { background: '#f53d2d20', color: '#f53d2d' },
    };
    return m[p] ?? { background: 'var(--surface-2)', color: 'var(--text-muted)' };
}

function billingStatusStyle(s) {
    const key = normalizeBillingStatus(s);
    const m = {
        paid:    { background: 'var(--success-bg)', color: 'var(--success-text)' },
        settled: { background: 'var(--color-primary-500)20', color: 'var(--color-primary-500)' },
        pending: { background: 'var(--warning-bg)', color: 'var(--warning-text)' },
        failed:  { background: 'var(--danger-bg)', color: 'var(--danger-text)' },
    };
    return m[key] ?? { background: 'var(--surface-2)', color: 'var(--text-muted)' };
}

function normalizeBillingStatus(value) {
    const raw = String(value ?? '').trim().toLowerCase();
    if (['2', '3', '6', 'paid', 'settled', 'completed', 'success', 'đã thanh toán'].includes(raw)) return 'paid';
    if (['0', '1', 'pending', 'processing', 'review'].includes(raw)) return 'pending';
    if (['-1', 'failed', 'rejected', 'cancelled', 'unpaid'].includes(raw)) return 'failed';
    return raw || 'pending';
}

function billingStatusLabel(value) {
    const key = normalizeBillingStatus(value);
    if (key === 'paid') return 'Đã thanh toán';
    if (key === 'pending') return 'Đang xử lý';
    if (key === 'failed') return 'Không thanh toán';
    return String(value ?? '—');
}
</script>
