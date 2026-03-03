<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Cảnh báo / Template tin nhắn</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Template tin nhắn cảnh báo</h1>
                    <p class="text-sm mt-1" style="color: var(--text-muted)">
                        Mỗi chỉ số có 2 template riêng: In-app (hệ thống) và Telegram.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('alerts.index'))">
                        Trung tâm cảnh báo
                    </button>
                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('alerts.rules'))">
                        Quy tắc cảnh báo
                    </button>
                    <button class="af-btn-primary text-sm h-9 px-3" :disabled="isSavingAll" @click="saveAllTemplates">
                        {{ isSavingAll ? 'Đang lưu...' : 'Lưu tất cả template' }}
                    </button>
                </div>
            </div>

            <div class="af-surface p-4 flex flex-col gap-3">
                <div class="flex items-center gap-3 flex-wrap">
                    <div class="w-full md:w-[360px]" ref="metricDropdownRef">
                        <p class="text-xs font-medium mb-1.5" style="color: var(--text-secondary)">Chỉ số</p>
                        <div class="relative">
                            <button
                                type="button"
                                class="af-input h-10 text-sm w-full text-left flex items-center justify-between"
                                @click="showMetricDropdown = !showMetricDropdown"
                            >
                                <span style="color: var(--text-primary)">{{ activeMetricLabel }}</span>
                                <span style="color: var(--text-muted)">▾</span>
                            </button>
                            <div
                                v-if="showMetricDropdown"
                                class="absolute top-[44px] left-0 right-0 rounded-lg border shadow-lg z-20 overflow-hidden"
                                style="border-color: var(--border); background: var(--surface-1)"
                            >
                                <div class="p-2 border-b" style="border-color: var(--border)">
                                    <input
                                        v-model="metricSearch"
                                        type="text"
                                        class="af-input h-8 text-sm w-full"
                                        placeholder="Search metric..."
                                    >
                                </div>
                                <div class="max-h-56 overflow-y-auto">
                                    <button
                                        v-for="metric in filteredMetrics"
                                        :key="metric.value"
                                        type="button"
                                        class="w-full text-left px-3 py-2.5 text-sm transition"
                                        :style="dropdownItemStyle(metric.value)"
                                        @click="selectMetric(metric.value)"
                                    >
                                        {{ metric.label }}
                                    </button>
                                    <p
                                        v-if="!filteredMetrics.length"
                                        class="px-3 py-3 text-sm"
                                        style="color: var(--text-muted)"
                                    >
                                        Không có chỉ số phù hợp.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 md:ml-auto">
                    </div>

                    <div class="flex items-center gap-2">
                        <button class="af-btn-outline text-sm h-9 px-3" :disabled="isRestoring" @click="restoreDefaultMetric">
                            {{ isRestoring ? 'Đang khôi phục...' : 'Khôi phục mặc định' }}
                        </button>
                        <button class="af-btn-primary text-sm h-9 px-3" :disabled="isSavingMetric" @click="saveActiveMetric">
                            {{ isSavingMetric ? 'Đang lưu...' : 'Lưu chỉ số này' }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-4">
                <div class="xl:col-span-4 flex flex-col gap-4">
                    <div class="af-surface p-4">
                        <p class="text-sm font-semibold mb-3" style="color: var(--text-primary)">Danh sách chỉ số templates</p>
                        <div class="flex flex-col gap-2">
                            <button
                                v-for="metric in metric_options"
                                :key="metric.value"
                                type="button"
                                class="w-full rounded-lg border px-3 py-2.5 text-left transition"
                                :style="metricItemStyle(metric.value)"
                                @click="selectMetric(metric.value)"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-medium" style="color: var(--text-primary)">{{ metric.label }}</p>
                                    <span
                                        v-if="isMetricDirty(metric.value)"
                                        class="text-[11px] px-2 py-0.5 rounded-full"
                                        style="background: var(--warning-bg); color: var(--warning-text)"
                                    >
                                        Chưa lưu
                                    </span>
                                </div>
                                <div class="mt-1.5 flex items-center gap-2">
                                    <span class="text-[11px] px-2 py-0.5 rounded-full" :style="templateStateBadgeStyle(metric.value)">
                                        In-app
                                    </span>
                                    <span class="text-[11px] px-2 py-0.5 rounded-full" :style="templateStateBadgeStyle(metric.value)">
                                        Telegram
                                    </span>
                                </div>
                            </button>
                        </div>
                    </div>

                    <div class="af-surface p-4">
                        <p class="text-sm font-semibold mb-3" style="color: var(--text-primary)">Biến dữ liệu dùng chung</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="variable in template_variables"
                                :key="variable.token"
                                type="button"
                                class="text-xs px-2.5 py-1 rounded-full border"
                                style="border-color: var(--border); color: var(--text-secondary); background: var(--surface-2)"
                                @click="insertToken(variable.token)"
                            >
                                {{ variable.token }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="xl:col-span-8">
                    <div class="af-surface p-4 flex flex-col gap-4">
                        <p class="text-sm font-semibold" style="color: var(--text-primary)">
                            Soạn nội dung template · {{ activeMetricLabel }}
                        </p>

                        <div id="in-app-editor" class="flex flex-col gap-1.5">
                            <label class="text-xs font-medium" style="color: var(--text-secondary)">
                                Tin nhắn hệ thống (In-app)
                            </label>
                            <textarea
                                ref="inAppEditorRef"
                                v-model="activeMetricTemplate.in_app_template"
                                rows="5"
                                class="af-input text-sm w-full"
                                style="line-height: 1.5"
                                @focus="activeChannelTab = 'in_app'"
                            ></textarea>
                        </div>

                        <div id="telegram-editor" class="flex flex-col gap-1.5">
                            <label class="text-xs font-medium" style="color: var(--text-secondary)">
                                Tin nhắn Telegram
                            </label>
                            <textarea
                                ref="telegramEditorRef"
                                v-model="activeMetricTemplate.telegram_template"
                                rows="6"
                                class="af-input text-sm w-full"
                                style="line-height: 1.5"
                                @focus="activeChannelTab = 'telegram'"
                            ></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="af-surface p-4 flex flex-col gap-3">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm font-semibold" style="color: var(--text-primary)">Preview khi rule kích hoạt</p>
                    <button class="af-btn-primary text-sm h-9 px-3" :disabled="isSendingTelegramTest" @click="sendTelegramTest">
                        {{ isSendingTelegramTest ? 'Đang gửi...' : 'Gửi test Telegram' }}
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-2)">
                        <p class="text-xs font-medium mb-2" style="color: var(--text-secondary)">Preview In-app</p>
                        <p class="text-sm whitespace-pre-line" style="color: var(--text-primary)">{{ previewInApp }}</p>
                    </div>
                    <div class="rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-2)">
                        <p class="text-xs font-medium mb-2" style="color: var(--text-secondary)">Preview Telegram</p>
                        <p class="text-sm whitespace-pre-line" style="color: var(--text-primary)">{{ previewTelegram }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>

<script setup>
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import { useToast } from '@/Composables/useToast';
import { useDialog } from '@/Composables/useDialog';

const props = defineProps({
    metric_options: { type: Array, default: () => [] },
    template_variables: { type: Array, default: () => [] },
    templates: { type: Object, default: () => ({}) },
    active_metric: { type: String, default: '' },
});

const toast = useToast();
const { confirmDialog } = useDialog();

const metricDropdownRef = ref(null);
const inAppEditorRef = ref(null);
const telegramEditorRef = ref(null);

const showMetricDropdown = ref(false);
const metricSearch = ref('');
const isSavingMetric = ref(false);
const isSavingAll = ref(false);
const isRestoring = ref(false);
const isSendingTelegramTest = ref(false);
const activeChannelTab = ref('in_app');

const activeMetricKey = ref(props.active_metric || props.metric_options?.[0]?.value || '');
const initialTemplates = ref(normalizeTemplates(props.templates, props.metric_options));
const localTemplates = ref(JSON.parse(JSON.stringify(initialTemplates.value)));

const activeMetricTemplate = computed(() => {
    return localTemplates.value[activeMetricKey.value] || {
        in_app_template: '',
        telegram_template: '',
        is_custom: false,
        updated_at: null,
    };
});

const filteredMetrics = computed(() => {
    const query = metricSearch.value.trim().toLowerCase();
    if (!query) return props.metric_options;
    return props.metric_options.filter((item) => String(item.label || '').toLowerCase().includes(query));
});

const activeMetricLabel = computed(() => {
    return props.metric_options.find((item) => item.value === activeMetricKey.value)?.label || activeMetricKey.value;
});

const previewVariables = computed(() => ({
    metric_label: activeMetricLabel.value,
    triggered_value: '5',
    operator: '>=',
    threshold: '3',
    detected_at: new Date().toLocaleString('vi-VN'),
    connection_name: 'Shopee Test Connection',
}));

const previewInApp = computed(() => renderTemplate(activeMetricTemplate.value.in_app_template, previewVariables.value));
const previewTelegram = computed(() => renderTemplate(activeMetricTemplate.value.telegram_template, previewVariables.value));

function normalizeTemplates(rawTemplates, metricOptions) {
    const defaults = {};
    for (const metric of metricOptions) {
        const value = rawTemplates?.[metric.value] || {};
        defaults[metric.value] = {
            in_app_template: String(value.in_app_template || ''),
            telegram_template: String(value.telegram_template || ''),
            is_custom: !!value.is_custom,
            updated_at: value.updated_at || null,
        };
    }

    return defaults;
}

function renderTemplate(template, variables) {
    const source = String(template || '');
    return source.replace(/\{([a-zA-Z0-9_]+)\}/g, (_, key) => variables[key] ?? `{${key}}`);
}

function isMetricDirty(metricKey) {
    const local = localTemplates.value?.[metricKey];
    const baseline = initialTemplates.value?.[metricKey];
    if (!local || !baseline) return false;

    return local.in_app_template !== baseline.in_app_template
        || local.telegram_template !== baseline.telegram_template;
}

function dropdownItemStyle(metricKey) {
    if (metricKey === activeMetricKey.value) {
        return {
            background: 'var(--surface-2)',
            color: 'var(--text-primary)',
        };
    }
    return {
        color: 'var(--text-secondary)',
    };
}

function metricItemStyle(metricKey) {
    const active = metricKey === activeMetricKey.value;
    return {
        borderColor: active ? 'var(--color-primary-500)' : 'var(--border)',
        background: active ? 'var(--surface-2)' : 'var(--surface-1)',
    };
}

function templateStateBadgeStyle(metricKey) {
    const isCustom = !!initialTemplates.value?.[metricKey]?.is_custom;
    return isCustom
        ? { background: 'var(--success-bg)', color: 'var(--success-text)' }
        : { background: 'var(--surface-3)', color: 'var(--text-muted)' };
}

function channelTabStyle(channel) {
    if (activeChannelTab.value !== channel) {
        return {};
    }

    return {
        borderColor: 'var(--color-primary-500)',
        color: 'var(--color-primary-500)',
        background: 'var(--surface-2)',
    };
}

function switchChannelTab(channel) {
    activeChannelTab.value = channel;
    if (channel === 'in_app') {
        inAppEditorRef.value?.focus();
        inAppEditorRef.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        return;
    }

    telegramEditorRef.value?.focus();
    telegramEditorRef.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function selectMetric(nextMetric) {
    if (!nextMetric || nextMetric === activeMetricKey.value) {
        showMetricDropdown.value = false;
        return;
    }

    if (isMetricDirty(activeMetricKey.value)) {
        const saveBeforeSwitch = await confirmDialog({
            title: 'Lưu thay đổi trước khi đổi chỉ số?',
            description: 'Bạn đang chỉnh sửa template chưa lưu.',
            confirmText: 'Lưu & chuyển',
            cancelText: 'Tiếp tục',
        });

        if (saveBeforeSwitch) {
            const saved = await saveActiveMetric(false);
            if (!saved) return;
        } else {
            const discard = await confirmDialog({
                variant: 'warning',
                title: 'Bỏ thay đổi hiện tại?',
                description: 'Các thay đổi chưa lưu của chỉ số hiện tại sẽ bị mất.',
                confirmText: 'Bỏ thay đổi',
                cancelText: 'Ở lại chỉnh sửa',
            });

            if (!discard) return;

            localTemplates.value[activeMetricKey.value] = {
                ...initialTemplates.value[activeMetricKey.value],
            };
        }
    }

    activeMetricKey.value = nextMetric;
    metricSearch.value = '';
    showMetricDropdown.value = false;
}

function insertToken(token) {
    const key = activeChannelTab.value === 'telegram' ? 'telegram_template' : 'in_app_template';
    const targetRef = activeChannelTab.value === 'telegram' ? telegramEditorRef.value : inAppEditorRef.value;
    const content = localTemplates.value[activeMetricKey.value][key];

    if (!targetRef || typeof targetRef.selectionStart !== 'number') {
        localTemplates.value[activeMetricKey.value][key] = `${content}${content ? ' ' : ''}${token}`;
        return;
    }

    const start = targetRef.selectionStart;
    const end = targetRef.selectionEnd;
    const next = `${content.slice(0, start)}${token}${content.slice(end)}`;
    localTemplates.value[activeMetricKey.value][key] = next;

    requestAnimationFrame(() => {
        targetRef.focus();
        const cursor = start + token.length;
        targetRef.setSelectionRange(cursor, cursor);
    });
}

async function saveActiveMetric(showSuccessToast = true) {
    if (isSavingMetric.value) return false;

    if (!isMetricDirty(activeMetricKey.value)) {
        if (showSuccessToast) {
            toast.info('Không có thay đổi cần lưu cho chỉ số hiện tại.');
        }
        return true;
    }

    isSavingMetric.value = true;
    try {
        const metric = activeMetricKey.value;
        const payload = {
            in_app_template: localTemplates.value[metric].in_app_template,
            telegram_template: localTemplates.value[metric].telegram_template,
        };

        const response = await axios.put(route('api.alerts.templates.update', metric), payload);
        if (!response.data?.ok) {
            throw new Error(response.data?.message || 'Không thể lưu template.');
        }

        const nextTemplates = normalizeTemplates(response.data?.data?.templates || {}, props.metric_options);
        initialTemplates.value[metric] = { ...nextTemplates[metric] };
        localTemplates.value[metric] = { ...nextTemplates[metric] };

        if (showSuccessToast) {
            toast.success(response.data?.message || 'Đã lưu template cho chỉ số.');
        }
        return true;
    } catch (error) {
        toast.error(error?.response?.data?.message || error?.message || 'Không thể lưu template.');
        return false;
    } finally {
        isSavingMetric.value = false;
    }
}

async function saveAllTemplates() {
    if (isSavingAll.value) return;

    const dirtyMetrics = props.metric_options
        .map((item) => item.value)
        .filter((metric) => isMetricDirty(metric));

    if (!dirtyMetrics.length) {
        toast.info('Không có thay đổi cần lưu.');
        return;
    }

    isSavingAll.value = true;
    try {
        const payload = {
            templates: dirtyMetrics.map((metric) => ({
                metric,
                in_app_template: localTemplates.value[metric].in_app_template,
                telegram_template: localTemplates.value[metric].telegram_template,
            })),
        };

        const response = await axios.put(route('api.alerts.templates.updateAll'), payload);
        if (!response.data?.ok) {
            throw new Error(response.data?.message || 'Không thể lưu toàn bộ template.');
        }

        const nextTemplates = normalizeTemplates(response.data?.data?.templates || {}, props.metric_options);
        initialTemplates.value = JSON.parse(JSON.stringify(nextTemplates));
        localTemplates.value = JSON.parse(JSON.stringify(nextTemplates));
        toast.success(response.data?.message || 'Đã lưu toàn bộ template.');
    } catch (error) {
        toast.error(error?.response?.data?.message || error?.message || 'Không thể lưu toàn bộ template.');
    } finally {
        isSavingAll.value = false;
    }
}

async function restoreDefaultMetric() {
    if (isRestoring.value) return;

    const confirmed = await confirmDialog({
        variant: 'warning',
        title: 'Khôi phục template mặc định?',
        description: 'Template tùy chỉnh của chỉ số hiện tại sẽ bị thay thế.',
        confirmText: 'Khôi phục',
        cancelText: 'Hủy',
    });
    if (!confirmed) return;

    isRestoring.value = true;
    try {
        const metric = activeMetricKey.value;
        const response = await axios.post(route('api.alerts.templates.restore', metric));
        if (!response.data?.ok) {
            throw new Error(response.data?.message || 'Không thể khôi phục template.');
        }

        const nextTemplates = normalizeTemplates(response.data?.data?.templates || {}, props.metric_options);
        initialTemplates.value[metric] = { ...nextTemplates[metric] };
        localTemplates.value[metric] = { ...nextTemplates[metric] };
        toast.success(response.data?.message || 'Đã khôi phục template mặc định.');
    } catch (error) {
        toast.error(error?.response?.data?.message || error?.message || 'Không thể khôi phục template.');
    } finally {
        isRestoring.value = false;
    }
}

async function sendTelegramTest() {
    if (isSendingTelegramTest.value) return;

    isSendingTelegramTest.value = true;
    try {
        const response = await axios.post(route('api.alerts.telegram-config.test'), {
            message: previewTelegram.value,
        });
        if (!response.data?.ok) {
            throw new Error(response.data?.message || 'Gửi test Telegram thất bại.');
        }
        toast.success(response.data?.message || 'Đã gửi tin nhắn test Telegram.');
    } catch (error) {
        toast.error(error?.response?.data?.message || error?.message || 'Gửi test Telegram thất bại.');
    } finally {
        isSendingTelegramTest.value = false;
    }
}

function handleOutsideClick(event) {
    const root = metricDropdownRef.value;
    if (!root || root.contains(event.target)) {
        return;
    }

    showMetricDropdown.value = false;
}

onMounted(() => {
    document.addEventListener('mousedown', handleOutsideClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', handleOutsideClick);
});
</script>

