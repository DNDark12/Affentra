<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Tài chính / Lô đối soát / Chi tiết</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">{{ batch.batch_no }}</h1>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-xs px-2 py-0.5 rounded-full" :style="statusStyle(batch.status)">{{ statusLabel(batch.status) }}</span>
                        <span class="text-xs" style="color: var(--text-muted)">
                            {{ batch.creator?.name || '—' }} · {{ fmtDateTime(batch.created_at) }}
                        </span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Finalize button: only for draft -->
                    <button
                        v-if="batch.status === 'draft'"
                        class="af-btn-primary text-sm h-9 px-4"
                        :disabled="isFinalizing"
                        @click="finalizeBatch"
                    >
                        {{ isFinalizing ? 'Đang chốt...' : 'Chốt batch' }}
                    </button>

                    <!-- Export dropdown: only for finalized/exported -->
                    <div v-if="batch.status === 'finalized' || batch.status === 'exported'" class="relative" ref="exportDropdownRef">
                        <button
                            class="af-btn-primary text-sm h-9 px-4 flex items-center gap-1"
                            :disabled="isExporting"
                            @click="showExportMenu = !showExportMenu"
                        >
                            {{ isExporting ? 'Đang xuất...' : 'Xuất CSV' }}
                            <span class="ml-1">▾</span>
                        </button>
                        <div
                            v-if="showExportMenu"
                            class="absolute right-0 mt-1 w-48 rounded shadow-lg z-20"
                            style="background: var(--surface-1); border: 1px solid var(--border);"
                        >
                            <button
                                v-for="fmt in exportFormats"
                                :key="fmt.value"
                                class="w-full text-left px-4 py-2 text-sm hover:bg-[var(--surface-2)] transition-colors"
                                style="color: var(--text-primary)"
                                @click="exportBatch(fmt.value)"
                            >
                                {{ fmt.label }}
                            </button>
                        </div>
                    </div>

                    <!-- Delete button: only for draft -->
                    <button
                        v-if="batch.status === 'draft'"
                        class="af-btn-danger text-sm h-9 px-3"
                        :disabled="isDeleting"
                        @click="deleteBatch"
                    >
                        {{ isDeleting ? 'Đang xóa...' : 'Xóa batch' }}
                    </button>

                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('finance.payout-batches.index'))">
                        Quay lại danh sách
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="af-surface p-4">
                    <p class="text-xs" style="color: var(--text-muted)">Tổng tiền</p>
                    <p class="text-2xl font-bold mt-1" style="color: var(--text-primary)">{{ fmtMoney(batch.total_amount) }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs" style="color: var(--text-muted)">Số payout</p>
                    <p class="text-2xl font-bold mt-1" style="color: var(--text-primary)">{{ batch.payout_count }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs" style="color: var(--text-muted)">Chốt lúc</p>
                    <p class="text-base font-semibold mt-1" style="color: var(--text-primary)">{{ fmtDateTime(batch.finalized_at) }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs" style="color: var(--text-muted)">Người chốt</p>
                    <p class="text-base font-semibold mt-1" style="color: var(--text-primary)">{{ batch.finalizer?.name || '—' }}</p>
                </div>
            </div>

            <div v-if="batch.exported_at" class="af-surface p-3 flex items-center gap-2 text-xs" style="border: 1px solid var(--border)">
                <span style="color: var(--text-muted)">Đã xuất lần cuối:</span>
                <span style="color: var(--text-primary)">{{ fmtDateTime(batch.exported_at) }}</span>
            </div>

            <div v-if="batch.note" class="af-surface p-4">
                <p class="text-xs mb-1" style="color: var(--text-muted)">Ghi chú</p>
                <p class="text-sm" style="color: var(--text-primary)">{{ batch.note }}</p>
            </div>

            <div class="af-surface overflow-x-auto">
                <table class="w-full text-sm border-collapse whitespace-nowrap">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                            <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Payout ID</th>
                            <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Partner</th>
                            <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Trạng thái</th>
                            <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Số tiền</th>
                            <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Thời gian payout</th>
                            <th v-if="batch.status === 'draft'" class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!localPayouts.length">
                            <td colspan="6" class="text-center py-10" style="color: var(--text-muted)">Batch chưa có payout.</td>
                        </tr>
                        <tr v-for="item in localPayouts" :key="item.id" style="border-bottom: 1px solid var(--border)">
                            <td class="px-4 py-3 font-medium" style="color: var(--text-primary)">{{ item.payout_id }}</td>
                            <td class="px-4 py-3">
                                <p style="color: var(--text-primary)">{{ item.user?.name || '—' }}</p>
                                <p class="text-xs" style="color: var(--text-muted)">{{ item.user?.email || '' }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded-full" :style="payoutStatusStyle(item.status)">
                                    {{ payoutStatusLabel(item.status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">{{ fmtMoney(item.amount) }}</td>
                            <td class="px-4 py-3 text-right">{{ fmtDateTime(item.payout_at) }}</td>
                            <td v-if="batch.status === 'draft'" class="px-4 py-3 text-right">
                                <button
                                    class="text-xs px-2 py-1 rounded"
                                    style="background: var(--danger-bg); color: var(--danger-text)"
                                    :disabled="removingPayoutId === item.id"
                                    @click="removePayout(item.id)"
                                >
                                    {{ removingPayoutId === item.id ? '...' : 'Xóa' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="message" class="text-xs" :style="{ color: messageIsError ? 'var(--danger-text)' : 'var(--text-muted)' }">{{ message }}</p>
        </div>
    </AppShell>
</template>

<script setup>
import axios from 'axios';
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';

const props = defineProps({
    batch: { type: Object, required: true },
    payouts: { type: Array, default: () => [] },
});

const isFinalizing = ref(false);
const isExporting = ref(false);
const isDeleting = ref(false);
const removingPayoutId = ref(null);
const showExportMenu = ref(false);
const message = ref('');
const messageIsError = ref(false);
const exportDropdownRef = ref(null);

// Local copy of payouts so we can remove rows immediately (optimistic)
const localPayouts = ref([...props.payouts]);

const exportFormats = [
    { value: 'generic',     label: 'Generic (Tổng quát)' },
    { value: 'vietcombank', label: 'Vietcombank (VCB)' },
    { value: 'techcombank', label: 'Techcombank (TCB)' },
];

function setMessage(text, isError = false) {
    message.value = text;
    messageIsError.value = isError;
}

function fmtMoney(value) {
    return Number(value || 0).toLocaleString('vi-VN') + ' đ';
}

function fmtDateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('vi-VN');
}

function statusStyle(status) {
    const key = String(status || '').toLowerCase();
    if (key === 'draft') return { background: 'var(--warning-bg)', color: 'var(--warning-text)' };
    if (key === 'finalized') return { background: 'var(--success-bg)', color: 'var(--success-text)' };
    if (key === 'exported') return { background: 'var(--color-primary-500)20', color: 'var(--color-primary-500)' };
    return { background: 'var(--surface-2)', color: 'var(--text-muted)' };
}

function statusLabel(status) {
    const key = String(status || '').toLowerCase();
    if (key === 'draft') return 'Nháp';
    if (key === 'finalized') return 'Đã chốt';
    if (key === 'exported') return 'Đã xuất';
    return status || '—';
}

function normalizePayoutStatus(value) {
    const raw = String(value ?? '').trim().toLowerCase();
    if (['2', '3', '6', 'paid', 'settled', 'completed', 'success', 'done'].includes(raw)) return 'paid';
    if (['0', '1', 'pending', 'processing', 'review', 'created', 'init'].includes(raw)) return 'pending';
    if (['-1', '4', '5', 'failed', 'rejected', 'cancelled', 'canceled', 'closed'].includes(raw)) return 'failed';
    return raw || 'pending';
}

function payoutStatusStyle(value) {
    const key = normalizePayoutStatus(value);
    if (key === 'paid') return { background: 'var(--success-bg)', color: 'var(--success-text)' };
    if (key === 'pending') return { background: 'var(--warning-bg)', color: 'var(--warning-text)' };
    if (key === 'failed') return { background: 'var(--danger-bg)', color: 'var(--danger-text)' };
    return { background: 'var(--surface-2)', color: 'var(--text-muted)' };
}

function payoutStatusLabel(value) {
    const key = normalizePayoutStatus(value);
    if (key === 'paid') return 'Đã thanh toán';
    if (key === 'pending') return 'Đang xử lý';
    if (key === 'failed') return 'Không thanh toán';
    return String(value ?? '—');
}

async function finalizeBatch() {
    if (isFinalizing.value || props.batch.status !== 'draft') return;
    isFinalizing.value = true;
    setMessage('');
    try {
        const response = await axios.post(route('api.payout-batches.finalize', props.batch.id));
        setMessage(response.data?.message || 'Đã chốt lô đối soát.');
        router.reload({ only: ['batch', 'payouts'] });
    } catch (error) {
        setMessage(error.response?.data?.message || 'Không thể chốt batch.', true);
    } finally {
        isFinalizing.value = false;
    }
}

function exportBatch(format) {
    showExportMenu.value = false;
    if (isExporting.value) return;
    isExporting.value = true;
    setMessage('');

    const url = route('api.payout-batches.export', props.batch.id) + '?format=' + format;
    const link = document.createElement('a');
    link.href = url;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    // Give server a moment to respond, then reload to pick up status change
    setTimeout(() => {
        isExporting.value = false;
        router.reload({ only: ['batch'] });
    }, 1500);
}

async function deleteBatch() {
    if (isDeleting.value || props.batch.status !== 'draft') return;
    if (!confirm('Xóa batch này? Tất cả payout sẽ được giải phóng.')) return;

    isDeleting.value = true;
    setMessage('');
    try {
        await axios.delete(route('api.payout-batches.destroy', props.batch.id));
        router.visit(route('finance.payout-batches.index'));
    } catch (error) {
        setMessage(error.response?.data?.message || 'Không thể xóa batch.', true);
        isDeleting.value = false;
    }
}

async function removePayout(payoutId) {
    if (removingPayoutId.value !== null) return;
    removingPayoutId.value = payoutId;
    setMessage('');
    try {
        const response = await axios.delete(
            route('api.payout-batches.remove-payout', { payoutBatch: props.batch.id, payout: payoutId })
        );
        // Optimistic remove
        localPayouts.value = localPayouts.value.filter(p => p.id !== payoutId);
        setMessage(response.data?.message || 'Đã xóa payout khỏi batch.');
        router.reload({ only: ['batch'] });
    } catch (error) {
        setMessage(error.response?.data?.message || 'Không thể xóa payout.', true);
    } finally {
        removingPayoutId.value = null;
    }
}

// Close export dropdown when clicking outside
function handleClickOutside(event) {
    if (exportDropdownRef.value && !exportDropdownRef.value.contains(event.target)) {
        showExportMenu.value = false;
    }
}

onMounted(() => document.addEventListener('click', handleClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', handleClickOutside));
</script>
