<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Cảnh báo / Quy tắc</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Quy tắc cảnh báo</h1>
                </div>
                <div class="flex items-center gap-2">
                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('alerts.index'))">
                        Trung tâm cảnh báo
                    </button>
                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('alerts.templates'))">
                        Template tin nhắn
                    </button>
                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('alerts.telegram-config'))">
                        Cấu hình Telegram
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="af-surface p-4">
                    <p class="text-xs" style="color: var(--text-muted)">Đang bật</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">{{ summary.active || 0 }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs" style="color: var(--text-muted)">Tổng quy tắc</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">{{ summary.total || 0 }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs" style="color: var(--text-muted)">Sự cố đang mở</p>
                    <p class="text-2xl font-bold" style="color: var(--danger-text)">{{ summary.open_incidents || 0 }}</p>
                </div>
            </div>

            <div class="af-surface p-4 flex flex-col gap-3">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary)">Tạo quy tắc mới</h2>
                <div class="flex items-center gap-2">
                    <select v-model="channelFilter" class="af-input h-9 text-sm w-56" @change="applyChannelFilter">
                        <option value="">Tất cả kênh</option>
                        <option value="in_app">Chỉ in-app</option>
                        <option value="telegram">Chỉ Telegram</option>
                        <option value="both">In-app + Telegram</option>
                    </select>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-6 gap-2">
                    <input v-model="createForm.name" class="af-input h-9 text-sm md:col-span-2" placeholder="Tên quy tắc">
                    <select v-model="createForm.metric" class="af-input h-9 text-sm">
                        <option value="">Chỉ số</option>
                        <option v-for="metric in metric_options" :key="metric.value" :value="metric.value">
                            {{ metric.label }}
                        </option>
                    </select>
                    <select v-model="createForm.operator" class="af-input h-9 text-sm">
                        <option value="">Toán tử</option>
                        <option v-for="operator in operator_options" :key="operator" :value="operator">{{ operator }}</option>
                    </select>
                    <input v-model.number="createForm.threshold" class="af-input h-9 text-sm" placeholder="Ngưỡng" type="number" step="0.0001">
                    <select v-model="createForm.channel" class="af-input h-9 text-sm">
                        <option v-for="channel in channel_options" :key="channel" :value="channel">
                            {{ channel }}
                        </option>
                    </select>
                </div>
                <div class="flex items-center justify-between">
                    <p class="text-xs" style="color: var(--text-muted)">
                        Telegram:
                        {{ telegram.enabled ? 'Đang bật' : 'Đang tắt' }} ·
                        {{ telegram.has_token ? 'Có bot token' : 'Thiếu bot token' }} ·
                        {{ hasAnyTelegramTarget ? 'Có đích gửi' : 'Thiếu đích gửi' }}
                    </p>
                    <button class="af-btn-primary text-sm h-9 px-3" :disabled="isCreating" @click="createRule">
                        {{ isCreating ? 'Đang tạo...' : 'Tạo quy tắc' }}
                    </button>
                </div>
            </div>

            <div class="af-surface overflow-x-auto">
                <table class="w-full text-sm border-collapse whitespace-nowrap">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                            <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted)">Tên</th>
                            <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted)">Chỉ số</th>
                            <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted)">Điều kiện</th>
                            <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted)">Kênh</th>
                            <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted)">Trạng thái</th>
                            <th class="text-right px-4 py-3 text-xs font-medium" style="color: var(--text-muted)">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!localRules.length">
                            <td colspan="6" class="text-center py-12" style="color: var(--text-muted)">Chưa có quy tắc cảnh báo.</td>
                        </tr>
                        <tr v-for="rule in localRules" :key="rule.id" style="border-bottom: 1px solid var(--border)">
                            <td class="px-4 py-3 w-80">
                                <input v-model="rule.name" class="af-input h-8 text-sm">
                            </td>
                            <td class="px-4 py-3 w-72">
                                <select v-model="rule.metric" class="af-input h-8 text-sm">
                                    <option v-for="metric in metric_options" :key="metric.value" :value="metric.value">
                                        {{ metric.label }}
                                    </option>
                                </select>
                            </td>
                            <td class="px-4 py-3 w-64">
                                <div class="flex items-center gap-2">
                                    <select v-model="rule.operator" class="af-input h-8 text-sm w-20">
                                        <option v-for="operator in operator_options" :key="operator" :value="operator">{{ operator }}</option>
                                    </select>
                                    <input v-model.number="rule.threshold" class="af-input h-8 text-sm w-full" type="number" step="0.0001">
                                </div>
                            </td>
                            <td class="px-4 py-3 w-48">
                                <select v-model="rule.channel" class="af-input h-8 text-sm">
                                    <option v-for="channel in channel_options" :key="channel" :value="channel">{{ channel }}</option>
                                </select>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded-full" :style="rule.is_active ? activeStyle : inactiveStyle">
                                    {{ rule.is_active ? 'Đang bật' : 'Tạm dừng' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <button class="af-btn-outline text-xs h-7 px-2" @click="toggleRule(rule)">
                                        {{ rule.is_active ? 'Tạm dừng' : 'Kích hoạt' }}
                                    </button>
                                    <button class="af-btn-outline text-xs h-7 px-2" @click="updateRule(rule)">
                                        Lưu
                                    </button>
                                    <button class="af-btn-outline text-xs h-7 px-2" @click="deleteRule(rule)">
                                        Xóa
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
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import { useToast } from '@/Composables/useToast';
import { useDialog } from '@/Composables/useDialog';

const props = defineProps({
    rules: { type: Array, default: () => [] },
    metric_options: { type: Array, default: () => [] },
    operator_options: { type: Array, default: () => [] },
    channel_options: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({ channel: null }) },
    telegram: { type: Object, default: () => ({ enabled: false, has_chat_id: false, has_group_id: false, has_token: false }) },
});

const toast = useToast();
const { confirmDialog } = useDialog();
const isCreating = ref(false);
const localRules = ref(props.rules.map((rule) => ({ ...rule })));
const createForm = ref({
    name: '',
    metric: '',
    operator: '>=',
    threshold: 1,
    channel: 'in_app',
});

const activeStyle = { background: 'var(--success-bg)', color: 'var(--success-text)' };
const inactiveStyle = { background: 'var(--surface-2)', color: 'var(--text-muted)' };
const hasAnyTelegramTarget = computed(() => !!props.telegram?.has_group_id || !!props.telegram?.has_chat_id);
const channelFilter = ref(props.filters?.channel || '');

function applyChannelFilter() {
    router.get(route('alerts.rules'), {
        channel: channelFilter.value || undefined,
    }, {
        replace: true,
        preserveState: true,
        preserveScroll: true,
        only: ['rules', 'summary', 'filters', 'alerts'],
    });
}

async function createRule() {
    if (isCreating.value) return;

    isCreating.value = true;
    try {
        const response = await axios.post(route('api.alerts.rules.store'), createForm.value);
        if (!response.data?.ok) {
            throw new Error(response.data?.message || 'Không thể tạo quy tắc.');
        }
        toast.success(response.data?.message || 'Đã tạo quy tắc cảnh báo.');
        router.reload({ only: ['rules', 'summary', 'alerts'] });
        createForm.value = { name: '', metric: '', operator: '>=', threshold: 1, channel: 'in_app' };
    } catch (error) {
        toast.error(error.response?.data?.message || error.message || 'Không thể tạo quy tắc.');
    } finally {
        isCreating.value = false;
    }
}

async function updateRule(rule) {
    try {
        const response = await axios.patch(route('api.alerts.rules.update', rule.id), {
            name: rule.name,
            metric: rule.metric,
            operator: rule.operator,
            threshold: rule.threshold,
            channel: rule.channel,
            is_active: rule.is_active,
        });
        if (response.data?.ok) {
            toast.success(response.data?.message || 'Đã cập nhật quy tắc.');
            router.reload({ only: ['rules', 'summary', 'alerts'] });
        } else {
            throw new Error(response.data?.message || 'Không thể cập nhật quy tắc.');
        }
    } catch (error) {
        toast.error(error.response?.data?.message || error.message || 'Không thể cập nhật quy tắc.');
    }
}

async function toggleRule(rule) {
    try {
        const response = await axios.post(route('api.alerts.rules.toggle', rule.id), {
            is_active: !rule.is_active,
        });
        if (response.data?.ok) {
            rule.is_active = !rule.is_active;
            toast.success(response.data?.message || 'Đã cập nhật trạng thái quy tắc.');
            router.reload({ only: ['summary', 'alerts'] });
        } else {
            throw new Error(response.data?.message || 'Không thể cập nhật trạng thái.');
        }
    } catch (error) {
        toast.error(error.response?.data?.message || error.message || 'Không thể cập nhật trạng thái.');
    }
}

async function deleteRule(rule) {
    const confirmed = await confirmDialog({
        title: 'Xóa quy tắc cảnh báo?',
        description: `Quy tắc "${rule.name}" sẽ bị xóa vĩnh viễn.`,
        confirmText: 'Xóa quy tắc',
        cancelText: 'Hủy',
        variant: 'danger',
    });
    if (!confirmed) return;

    try {
        const response = await axios.delete(route('api.alerts.rules.destroy', rule.id));
        if (response.data?.ok) {
            toast.success(response.data?.message || 'Đã xóa quy tắc.');
            localRules.value = localRules.value.filter((item) => item.id !== rule.id);
            router.reload({ only: ['summary', 'alerts'] });
        } else {
            throw new Error(response.data?.message || 'Không thể xóa quy tắc.');
        }
    } catch (error) {
        toast.error(error.response?.data?.message || error.message || 'Không thể xóa quy tắc.');
    }
}
</script>
