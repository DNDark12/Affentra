<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Tài chính / Lô đối soát thanh toán</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Lô đối soát thanh toán</h1>
                </div>
                <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('finance.index'))">
                    Quay lại Tài chính
                </button>
            </div>

            <div class="af-surface p-4 flex flex-col gap-3">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary)">Tạo lô mới (Nháp)</h2>
                <p class="text-xs" style="color: var(--text-muted)">Chọn payout chưa gán batch để gom nhóm đối soát.</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="af-surface p-3 border border-[var(--border)] max-h-72 overflow-y-auto">
                        <div v-if="!unbatchedPayouts.length" class="text-xs py-3" style="color: var(--text-muted)">
                            Không có payout chưa gán batch.
                        </div>
                        <label
                            v-for="item in unbatchedPayouts"
                            :key="item.id"
                            class="flex items-start gap-2 py-2 border-b border-[var(--border)] last:border-b-0 text-sm cursor-pointer"
                        >
                            <input
                                :value="item.id"
                                v-model="selectedPayoutIds"
                                type="checkbox"
                                class="mt-1"
                            />
                            <div class="min-w-0">
                                <p class="font-medium" style="color: var(--text-primary)">
                                    {{ item.payout_id }} · {{ fmtMoney(item.amount) }}
                                </p>
                                <p class="text-xs truncate" style="color: var(--text-muted)">
                                    {{ item.user?.name || '—' }} · {{ fmtDateTime(item.payout_at) }}
                                </p>
                            </div>
                        </label>
                    </div>

                    <div class="flex flex-col gap-3">
                        <textarea
                            v-model="note"
                            class="af-input min-h-28 text-sm"
                            placeholder="Ghi chú batch (tuỳ chọn)"
                        ></textarea>
                        <div class="text-sm" style="color: var(--text-secondary)">
                            Đã chọn: <span class="font-semibold" style="color: var(--text-primary)">{{ selectedPayoutIds.length }}</span>
                        </div>
                        <button
                            class="af-btn-primary text-sm h-9 px-4 w-fit"
                            :disabled="isCreating || selectedPayoutIds.length === 0"
                            @click="createBatch"
                        >
                            {{ isCreating ? 'Đang tạo...' : 'Tạo Batch' }}
                        </button>
                        <p v-if="createMessage" class="text-xs" style="color: var(--text-muted)">{{ createMessage }}</p>
                    </div>
                </div>
            </div>

            <div class="af-surface p-4 flex items-center gap-2 flex-wrap">
                <input
                    v-model="batchNoFilter"
                    class="af-input h-9 text-sm"
                    placeholder="Tìm theo batch_no"
                    @keyup.enter="applyFilters"
                />
                <select v-model="statusFilter" class="af-input h-9 text-sm" @change="applyFilters">
                    <option value="">Tất cả trạng thái</option>
                    <option value="draft">Nháp</option>
                    <option value="finalized">Đã chốt</option>
                    <option value="exported">Đã xuất</option>
                </select>
                <button class="af-btn-outline text-sm h-9 px-3" @click="applyFilters">Lọc</button>
            </div>

            <div class="af-surface overflow-x-auto">
                <table class="w-full text-sm border-collapse whitespace-nowrap">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                            <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Mã lô</th>
                            <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Trạng thái</th>
                            <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Số payout</th>
                            <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Tổng tiền</th>
                            <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Tạo lúc</th>
                            <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!batches.data?.length">
                            <td colspan="6" class="text-center py-10" style="color: var(--text-muted)">Chưa có payout batch nào.</td>
                        </tr>
                        <tr
                            v-for="batch in batches.data"
                            :key="batch.id"
                            style="border-bottom: 1px solid var(--border)"
                            class="hover:bg-[var(--surface-2)] transition-colors cursor-pointer"
                            @click="router.visit(route('finance.payout-batches.show', batch.id))"
                        >
                            <td class="px-4 py-3 font-semibold" style="color: var(--text-primary)">{{ batch.batch_no }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded-full" :style="statusStyle(batch.status)">{{ statusLabel(batch.status) }}</span>
                            </td>
                            <td class="px-4 py-3 text-right">{{ batch.payout_count }}</td>
                            <td class="px-4 py-3 text-right">{{ fmtMoney(batch.total_amount) }}</td>
                            <td class="px-4 py-3 text-right">{{ fmtDateTime(batch.created_at) }}</td>
                            <td class="px-4 py-3 text-right">
                                <button class="af-btn-outline text-xs h-7 px-2" @click.stop="router.visit(route('finance.payout-batches.show', batch.id))">
                                    Chi tiết
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>

<script setup>
import axios from 'axios';
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';

const props = defineProps({
    batches: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
    unbatchedPayouts: { type: Array, default: () => [] },
});

const batchNoFilter = ref(props.filters?.batch_no || '');
const statusFilter = ref(props.filters?.status || '');
const selectedPayoutIds = ref([]);
const note = ref('');
const createMessage = ref('');
const isCreating = ref(false);

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

function applyFilters() {
    router.get(route('finance.payout-batches.index'), {
        batch_no: batchNoFilter.value || undefined,
        status: statusFilter.value || undefined,
        page: undefined,
    }, {
        replace: true,
        preserveScroll: true,
        preserveState: true,
        only: ['batches', 'filters', 'unbatchedPayouts'],
    });
}

async function createBatch() {
    if (isCreating.value || selectedPayoutIds.value.length === 0) return;

    isCreating.value = true;
    createMessage.value = '';

    try {
        const response = await axios.post(route('api.payout-batches.store'), {
            payout_ids: selectedPayoutIds.value,
            note: note.value || null,
        });

        if (response.data?.ok) {
            const payload = response.data.data;
            createMessage.value = response.data.message || 'Đã tạo batch.';
            router.visit(route('finance.payout-batches.show', payload.id));
            return;
        }

        createMessage.value = response.data?.message || 'Không thể tạo batch.';
    } catch (error) {
        createMessage.value = error.response?.data?.message || 'Không thể tạo batch.';
    } finally {
        isCreating.value = false;
    }
}
</script>
