<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Cảnh báo / Sự cố</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Sự cố #{{ incident.id }}</h1>
                </div>
                <div class="flex items-center gap-2">
                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('alerts.index'))">
                        Quay lại trung tâm cảnh báo
                    </button>
                    <button
                        v-if="!incident.resolved_at"
                        class="af-btn-primary text-sm h-9 px-3"
                        @click="resolveIncident"
                    >
                        Đã xử lý
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="af-surface p-4 lg:col-span-2 flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <span class="text-xs px-2 py-0.5 rounded-full" :style="statusStyle">{{ statusLabel }}</span>
                        <span class="text-xs" style="color: var(--text-muted)">
                            {{ fmtDateTime(incident.created_at) }}
                        </span>
                    </div>
                    <p class="text-sm leading-6" style="color: var(--text-primary)">{{ incident.message }}</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Quy tắc</p>
                            <p class="text-sm font-medium" style="color: var(--text-primary)">{{ rule?.name || `Quy tắc #${incident.alert_rule_id}` }}</p>
                        </div>
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Giá trị ghi nhận</p>
                            <p class="text-sm font-medium" style="color: var(--text-primary)">{{ formatValue(incident.triggered_value) }}</p>
                        </div>
                    </div>
                    <div class="rounded border p-3 text-xs" style="border-color: var(--border); background: var(--surface-2)">
                        <p class="mb-1 font-semibold" style="color: var(--text-secondary)">Ngữ cảnh</p>
                        <pre class="whitespace-pre-wrap break-all" style="color: var(--text-muted)">{{ JSON.stringify(incident.context || {}, null, 2) }}</pre>
                    </div>

                    <div class="rounded border p-3 flex flex-col gap-2" style="border-color: var(--border)">
                        <p class="text-sm font-semibold" style="color: var(--text-primary)">Ghi chú xử lý</p>
                        <textarea
                            v-model="commentDraft"
                            class="w-full rounded-lg border px-3 py-2 text-sm min-h-[88px] outline-none focus:ring-2"
                            style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary); box-shadow: 0 0 0 0 var(--ring);"
                            placeholder="Nhập ghi chú xử lý sự cố..."
                        />
                        <div class="flex items-center justify-between">
                            <p class="text-xs" style="color: var(--text-muted)">
                                {{ commentDraft.length }}/1000
                            </p>
                            <div class="flex items-center gap-2">
                                <button
                                    class="af-btn-outline text-sm h-8 px-3"
                                    :disabled="!canSubmitComment"
                                    @click="saveComment"
                                >
                                    Lưu comment
                                </button>
                                <button
                                    v-if="!incident.resolved_at"
                                    class="af-btn-primary text-sm h-8 px-3"
                                    @click="resolveIncident"
                                >
                                    Đã xử lý + lưu comment
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="af-surface p-4 flex flex-col gap-2">
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary)">Thông tin quy tắc</h2>
                    <p class="text-xs" style="color: var(--text-muted)">Chỉ số</p>
                    <p class="text-sm" style="color: var(--text-primary)">{{ rule?.metric_label || rule?.metric || '—' }}</p>
                    <p class="text-xs mt-2" style="color: var(--text-muted)">Điều kiện</p>
                    <p class="text-sm" style="color: var(--text-primary)">
                        {{ rule?.operator || '—' }} {{ formatValue(rule?.threshold) }}
                    </p>
                    <p class="text-xs mt-2" style="color: var(--text-muted)">Kênh</p>
                    <p class="text-sm" style="color: var(--text-primary)">{{ rule?.channel || 'in_app' }}</p>
                    <p class="text-xs mt-2" style="color: var(--text-muted)">Đã xem lúc</p>
                    <p class="text-sm" style="color: var(--text-primary)">{{ fmtDateTime(incident.seen_at) }}</p>
                    <p class="text-xs mt-2" style="color: var(--text-muted)">Đã xử lý lúc</p>
                    <p class="text-sm" style="color: var(--text-primary)">{{ fmtDateTime(incident.resolved_at) }}</p>
                </div>
            </div>

            <div class="af-surface p-4">
                <h2 class="text-sm font-semibold mb-2" style="color: var(--text-primary)">Lịch sử cùng quy tắc</h2>
                <div v-if="!timeline.length" class="text-sm" style="color: var(--text-muted)">Không có dữ liệu timeline.</div>
                <div v-else class="flex flex-col">
                    <div v-for="row in timeline" :key="row.id" class="py-2 border-b last:border-b-0" style="border-color: var(--border)">
                        <div class="flex items-center justify-between">
                            <p class="text-sm" style="color: var(--text-primary)">#{{ row.id }} · {{ row.message }}</p>
                            <span class="text-xs" style="color: var(--text-muted)">{{ fmtDateTime(row.created_at) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="af-surface p-4">
                <h2 class="text-sm font-semibold mb-2" style="color: var(--text-primary)">Nhật ký comment</h2>
                <div v-if="!comments.length" class="text-sm" style="color: var(--text-muted)">
                    Chưa có comment nào.
                </div>
                <div v-else class="flex flex-col gap-2">
                    <div
                        v-for="comment in comments"
                        :key="comment.id"
                        class="rounded border p-3"
                        style="border-color: var(--border); background: var(--surface-2)"
                    >
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <p class="text-sm font-medium" style="color: var(--text-primary)">
                                {{ comment.user_name || `User #${comment.user_id}` }}
                            </p>
                            <p class="text-xs" style="color: var(--text-muted)">
                                {{ fmtDateTime(comment.created_at) }}
                            </p>
                        </div>
                        <p class="text-sm whitespace-pre-wrap break-words" style="color: var(--text-secondary)">
                            {{ comment.comment }}
                        </p>
                    </div>
                </div>
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

const props = defineProps({
    incident: { type: Object, required: true },
    rule: { type: Object, default: null },
    timeline: { type: Array, default: () => [] },
});

const toast = useToast();
const commentDraft = ref('');

const statusLabel = computed(() => {
    if (props.incident.resolved_at) return 'Đã xử lý';
    if (!props.incident.seen_at) return 'Chưa xem';
    return 'Đang mở';
});

const statusStyle = computed(() => {
    if (props.incident.resolved_at) return { background: 'var(--success-bg)', color: 'var(--success-text)' };
    if (!props.incident.seen_at) return { background: 'var(--danger-bg)', color: 'var(--danger-text)' };
    return { background: 'var(--warning-bg)', color: 'var(--warning-text)' };
});

const comments = computed(() =>
    Array.isArray(props.incident?.context?.comments) ? props.incident.context.comments : []
);

const canSubmitComment = computed(() => commentDraft.value.trim().length >= 2 && commentDraft.value.trim().length <= 1000);

async function resolveIncident() {
    try {
        const response = await axios.post(route('api.alerts.incidents.resolve', props.incident.id), {
            comment: commentDraft.value.trim() || undefined,
        });
        if (response.data?.ok) {
            toast.success(response.data?.message || 'Đã đánh dấu đã xử lý.');
            commentDraft.value = '';
            router.reload({ only: ['incident', 'timeline', 'alerts'] });
        }
    } catch (error) {
        toast.error(error.response?.data?.message || 'Không thể xử lý sự cố.');
    }
}

async function saveComment() {
    if (!canSubmitComment.value) {
        toast.error('Comment cần từ 2 đến 1000 ký tự.');
        return;
    }

    try {
        const response = await axios.post(route('api.alerts.incidents.comment', props.incident.id), {
            comment: commentDraft.value.trim(),
        });
        if (response.data?.ok) {
            toast.success(response.data?.message || 'Đã lưu ghi chú xử lý.');
            commentDraft.value = '';
            router.reload({ only: ['incident', 'timeline', 'alerts'] });
        }
    } catch (error) {
        toast.error(error.response?.data?.message || 'Không thể lưu comment.');
    }
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
