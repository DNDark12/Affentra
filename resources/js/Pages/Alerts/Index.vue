<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Cảnh báo</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Trung tâm cảnh báo</h1>
                </div>
                <div class="flex items-center gap-2">
                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('alerts.rules'))">
                        Quy tắc cảnh báo
                    </button>
                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('alerts.templates'))">
                        Template tin nhắn
                    </button>
                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('alerts.telegram-config'))">
                        Cấu hình Telegram
                    </button>
                    <button class="af-btn-primary text-sm h-9 px-3" :disabled="isEvaluating" @click="evaluateNow">
                        {{ isEvaluating ? 'Đang đánh giá...' : 'Đánh giá ngay' }}
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="af-surface p-4">
                    <p class="text-xs" style="color: var(--text-muted)">Đang mở</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">{{ summary.open_count || 0 }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs" style="color: var(--text-muted)">Chưa xem</p>
                    <p class="text-2xl font-bold" style="color: var(--danger-text)">{{ summary.unseen_count || 0 }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs" style="color: var(--text-muted)">Hôm nay</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">{{ summary.today_count || 0 }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs" style="color: var(--text-muted)">Quy tắc đang bật</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">{{ summary.active_rules_count || 0 }}</p>
                </div>
            </div>

            <div class="af-surface p-4 flex items-center gap-2">
                <select v-model="statusFilter" class="af-input h-9 text-sm w-52" @change="applyFilters">
                    <option value="">Tất cả trạng thái</option>
                    <option value="open">Đang mở</option>
                    <option value="unseen">Chưa xem</option>
                    <option value="resolved">Đã xử lý</option>
                </select>
                <p class="text-xs ml-auto" style="color: var(--text-muted)">
                    Đánh giá lúc: {{ fmtDateTime(summary.evaluated_at) }}
                </p>
            </div>

            <div class="af-surface overflow-x-auto">
                <table class="w-full text-sm border-collapse whitespace-nowrap">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                            <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted)">Quy tắc</th>
                            <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted)">Nội dung</th>
                            <th class="text-right px-4 py-3 text-xs font-medium" style="color: var(--text-muted)">Giá trị</th>
                            <th class="text-center px-4 py-3 text-xs font-medium" style="color: var(--text-muted)">Trạng thái</th>
                            <th class="text-right px-4 py-3 text-xs font-medium" style="color: var(--text-muted)">Tạo lúc</th>
                            <th class="text-right px-4 py-3 text-xs font-medium" style="color: var(--text-muted)">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!incidents.data?.length">
                            <td colspan="6" class="py-12 text-center" style="color: var(--text-muted)">Chưa có sự cố cảnh báo nào.</td>
                        </tr>
                        <tr v-for="incident in incidents.data" :key="incident.id" style="border-bottom: 1px solid var(--border)">
                            <td class="px-4 py-3">
                                <p class="font-medium" style="color: var(--text-primary)">{{ incident.rule?.name || `Quy tắc #${incident.alert_rule_id}` }}</p>
                                <p class="text-xs" style="color: var(--text-muted)">{{ incident.rule?.metric_label || incident.rule?.metric || '—' }}</p>
                            </td>
                            <td class="px-4 py-3 max-w-[520px]">
                                <p class="truncate" :title="incident.message" style="color: var(--text-secondary)">{{ incident.message }}</p>
                            </td>
                            <td class="px-4 py-3 text-right">{{ formatValue(incident.triggered_value) }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="text-xs px-2 py-0.5 rounded-full" :style="incidentStatusStyle(incident)">
                                    {{ incidentStatusLabel(incident) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">{{ fmtDateTime(incident.created_at) }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <button class="af-btn-outline text-xs h-7 px-2" @click="router.visit(route('alerts.incidents.show', incident.id))">
                                        Chi tiết
                                    </button>
                                    <button
                                        v-if="!incident.seen_at"
                                        class="af-btn-outline text-xs h-7 px-2"
                                        :disabled="pendingSeenIds.has(incident.id)"
                                        @click="markSeen(incident.id)"
                                    >
                                        {{ pendingSeenIds.has(incident.id) ? 'Đang lưu...' : 'Đã xem' }}
                                    </button>
                                    <button
                                        v-if="!incident.resolved_at"
                                        class="af-btn-outline text-xs h-7 px-2"
                                        :disabled="pendingResolveIds.has(incident.id)"
                                        @click="resolveIncident(incident.id)"
                                    >
                                        {{ pendingResolveIds.has(incident.id) ? 'Đang xử lý...' : 'Đã xử lý' }}
                                    </button>
                                </div>
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
import { useToast } from '@/Composables/useToast';
import { useDialog } from '@/Composables/useDialog';

const props = defineProps({
    incidents: { type: Object, default: () => ({ data: [] }) },
    summary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const toast = useToast();
const { confirmDialog } = useDialog();
const isEvaluating = ref(false);
const statusFilter = ref(props.filters?.status || '');
const pendingSeenIds = ref(new Set());
const pendingResolveIds = ref(new Set());

function applyFilters() {
    router.get(route('alerts.index'), {
        status: statusFilter.value || undefined,
    }, {
        replace: true,
        preserveState: true,
        preserveScroll: true,
        only: ['incidents', 'summary', 'filters'],
    });
}

async function evaluateNow() {
    if (isEvaluating.value) return;
    isEvaluating.value = true;
    try {
        const response = await axios.post(route('api.alerts.evaluate'));
        if (response.data?.ok) {
            toast.success(response.data?.message || 'Đã đánh giá quy tắc cảnh báo.');
            router.reload({ only: ['incidents', 'summary', 'filters', 'alerts'] });
        } else {
            toast.error(response.data?.message || 'Không thể đánh giá quy tắc.');
        }
    } catch (error) {
        toast.error(error.response?.data?.message || 'Không thể đánh giá quy tắc.');
    } finally {
        isEvaluating.value = false;
    }
}

async function markSeen(id) {
    if (pendingSeenIds.value.has(id)) return;
    pendingSeenIds.value.add(id);
    try {
        await axios.post(route('api.alerts.incidents.seen', id));
        toast.success('Đã đánh dấu sự cố là đã xem.');
        router.reload({ only: ['incidents', 'summary', 'alerts'] });
    } catch (error) {
        toast.error(error.response?.data?.message || 'Không thể cập nhật sự cố.');
    } finally {
        pendingSeenIds.value.delete(id);
    }
}

async function resolveIncident(id) {
    const confirmed = await confirmDialog({
        title: 'Đánh dấu đã xử lý?',
        description: 'Sự cố này sẽ được chuyển sang trạng thái đã xử lý.',
        confirmText: 'Đánh dấu đã xử lý',
        cancelText: 'Hủy',
        variant: 'warning',
    });
    if (!confirmed) return;

    if (pendingResolveIds.value.has(id)) return;
    pendingResolveIds.value.add(id);
    try {
        await axios.post(route('api.alerts.incidents.resolve', id));
        toast.success('Đã đánh dấu sự cố là đã xử lý.');
        router.reload({ only: ['incidents', 'summary', 'alerts'] });
    } catch (error) {
        toast.error(error.response?.data?.message || 'Không thể cập nhật sự cố.');
    } finally {
        pendingResolveIds.value.delete(id);
    }
}

function incidentStatusLabel(incident) {
    if (incident.resolved_at) return 'Đã xử lý';
    if (!incident.seen_at) return 'Chưa xem';
    return 'Đang mở';
}

function incidentStatusStyle(incident) {
    if (incident.resolved_at) return { background: 'var(--success-bg)', color: 'var(--success-text)' };
    if (!incident.seen_at) return { background: 'var(--danger-bg)', color: 'var(--danger-text)' };
    return { background: 'var(--warning-bg)', color: 'var(--warning-text)' };
}

function fmtDateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('vi-VN');
}

function formatValue(value) {
    if (value === null || value === undefined) return '—';
    return Number(value).toLocaleString('vi-VN');
}
</script>
