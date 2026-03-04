<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <!-- Header -->
            <div>
                <p class="text-xs mb-0.5" style="color: var(--text-muted)">Cài đặt / AI Provider</p>
                <h1 class="text-2xl font-bold" style="color: var(--text-primary)">AI Content Generator</h1>
                <p class="mt-1 text-sm" style="color: var(--text-secondary)">
                    Cấu hình AI provider để sử dụng tính năng tạo nội dung tự động. Mỗi tài khoản giữ API key riêng và được mã hóa an toàn.
                </p>
            </div>

            <!-- Provider Grid -->
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <!-- Existing configured providers -->
                <div
                    v-for="setting in userSettings"
                    :key="setting.provider_key"
                    class="af-surface p-5 flex flex-col gap-3"
                >
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div
                                class="w-10 h-10 rounded-xl flex items-center justify-center text-lg font-bold"
                                :style="providerIconStyle(setting.provider_key)"
                            >
                                {{ providerIcon(setting.provider_key) }}
                            </div>
                            <div>
                                <p class="text-sm font-semibold" style="color: var(--text-primary)">{{ setting.label || providerName(setting.provider_key) }}</p>
                                <p class="text-xs" style="color: var(--text-muted)">{{ setting.provider_key }}</p>
                            </div>
                        </div>
                        <span
                            class="text-xs px-2 py-0.5 rounded-full font-medium"
                            :style="setting.is_configured
                                ? 'background: var(--success-bg); color: var(--success-text);'
                                : 'background: var(--warning-bg); color: var(--warning-text);'"
                        >
                            {{ setting.is_configured ? 'Đã cấu hình' : 'Chưa có key' }}
                        </span>
                    </div>

                    <!-- Info rows -->
                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-center justify-between text-xs">
                            <span style="color: var(--text-muted)">Model mặc định</span>
                            <span class="font-mono" style="color: var(--text-secondary)">{{ setting.default_model || '—' }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span style="color: var(--text-muted)">Capabilities</span>
                            <div class="flex gap-1">
                                <span
                                    v-for="cap in setting.capabilities"
                                    :key="cap"
                                    class="px-1.5 py-0.5 rounded text-xs font-medium"
                                    style="background: var(--surface-2); color: var(--text-secondary)"
                                >{{ cap }}</span>
                            </div>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span style="color: var(--text-muted)">Quota/ngày</span>
                            <span style="color: var(--text-secondary)">{{ setting.token_quota_per_day ? setting.token_quota_per_day.toLocaleString() + ' tokens' : 'Mặc định' }}</span>
                        </div>
                    </div>

                    <!-- Enable/disable status + Connection health -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-1.5 text-xs" :style="setting.status === 'enabled' ? 'color: var(--success-text)' : 'color: var(--text-muted)'">
                            <div class="w-1.5 h-1.5 rounded-full" :style="setting.status === 'enabled' ? 'background: var(--success-text)' : 'background: var(--text-muted)'"></div>
                            {{ setting.status === 'enabled' ? 'Đang bật' : 'Đã tắt' }}
                        </div>
                        <!-- Connection test badge -->
                        <span
                            v-if="setting.last_test_status === 'ok'"
                            class="text-xs px-2 py-0.5 rounded-full font-medium flex items-center gap-1"
                            style="background: var(--success-bg); color: var(--success-text);"
                            :title="'Kiểm tra lúc: ' + formatTime(setting.last_tested_at)"
                        >
                            <CheckCircle2 :size="11" />
                            Kết nối OK
                        </span>
                        <span
                            v-else-if="setting.last_test_status === 'error'"
                            class="text-xs px-2 py-0.5 rounded-full font-medium flex items-center gap-1 cursor-help"
                            style="background: var(--danger-bg); color: var(--danger-text);"
                            :title="setting.last_test_error || 'Xem chi tiết lỗi'"
                        >
                            <XCircle :size="11" />
                            Lỗi kết nối
                        </span>
                        <span
                            v-else
                            class="text-xs"
                            style="color: var(--text-muted);"
                        >Chưa kiểm tra</span>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-2 pt-1 border-t" style="border-color: var(--border);">
                        <button
                            type="button"
                            class="af-btn-outline text-xs h-8 px-3 flex-1 flex items-center justify-center gap-1.5"
                            @click="openForm(setting)"
                        >
                            <Pencil :size="12" />
                            Chỉnh sửa
                        </button>
                        <button
                            type="button"
                            class="af-btn-outline text-xs h-8 px-3 flex items-center gap-1"
                            title="Kiểm tra kết nối"
                            :disabled="testing && testingKey === setting.provider_key"
                            @click="testProvider(setting.provider_key)"
                        >
                            <Loader2 v-if="testing && testingKey === setting.provider_key" :size="12" class="animate-spin" />
                            <Plug2 v-else :size="12" />
                        </button>
                        <button
                            type="button"
                            class="af-btn-outline text-xs h-8 px-3 flex items-center gap-1"
                            style="color: var(--color-danger); border-color: var(--color-danger); opacity: 0.8;"
                            title="Xóa provider"
                            @click="deleteProvider(setting.provider_key, setting.label || providerName(setting.provider_key))"
                        >
                            <Trash2 :size="12" />
                        </button>
                    </div>
                </div>

                <!-- Add New Provider Card -->
                <button
                    type="button"
                    class="af-surface p-5 flex flex-col items-center justify-center gap-3 min-h-44 transition-all"
                    style="border: 2px dashed var(--border); background: transparent;"
                    @click="openForm(null)"
                >
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: var(--surface-2);">
                        <Plus :size="24" style="color: var(--text-muted)" />
                    </div>
                    <div class="text-center">
                        <p class="text-sm font-medium" style="color: var(--text-secondary)">Thêm AI Provider</p>
                        <p class="text-xs" style="color: var(--text-muted)">Gemini, OpenAI, Self-hosted…</p>
                    </div>
                </button>
            </div>

            <!-- Registry info section -->
            <section class="af-surface p-5 max-w-2xl">
                <h3 class="text-sm font-semibold mb-3 flex items-center gap-1.5" style="color: var(--text-primary)">
                    <Info :size="14" />
                    Các AI Provider được hỗ trợ
                </h3>
                <div class="grid gap-2 sm:grid-cols-2">
                    <div
                        v-for="(info, key) in registry"
                        :key="key"
                        class="flex items-start gap-2 p-3 rounded-lg"
                        style="background: var(--surface-2);"
                    >
                        <div class="w-7 h-7 rounded-lg flex items-center justify-center text-sm shrink-0" :style="providerIconStyle(key)">{{ providerIcon(key) }}</div>
                        <div>
                            <p class="text-xs font-medium" style="color: var(--text-primary)">{{ info.name }}</p>
                            <div class="flex gap-1 mt-0.5">
                                <span
                                    v-for="cap in info.capabilities"
                                    :key="cap"
                                    class="text-xs px-1 py-0.5 rounded"
                                    style="background: var(--surface); color: var(--text-muted)"
                                >{{ cap }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- ── Add / Edit Modal ─────────────────────────────────────────────── -->
        <teleport to="body">
            <transition
                enter-active-class="transition-all duration-200"
                enter-from-class="opacity-0"
                leave-active-class="transition-all duration-200"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="formOpen"
                    class="fixed inset-0 z-50 flex items-center justify-center p-4"
                    style="background: rgba(0,0,0,0.5); backdrop-filter: blur(4px);"
                    @click.self="closeForm"
                >
                    <div
                        class="af-surface w-full max-w-lg p-6 flex flex-col gap-5"
                        style="max-height: 90vh; overflow-y: auto;"
                    >
                        <!-- Modal header -->
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold" style="color: var(--text-primary)">
                                {{ editMode ? 'Chỉnh sửa Provider' : 'Thêm AI Provider' }}
                            </h2>
                            <button type="button" class="p-1 rounded-md hover:bg-[var(--surface-2)]" @click="closeForm">
                                <X :size="16" style="color: var(--text-muted)" />
                            </button>
                        </div>

                        <!-- Form error -->
                        <div
                            v-if="formError"
                            class="px-4 py-3 rounded-lg text-sm flex items-center gap-2"
                            style="background: var(--danger-bg); color: var(--danger-text);"
                        >
                            <AlertCircle :size="14" class="shrink-0" />
                            {{ formError }}
                        </div>

                        <!-- Test success -->
                        <div
                            v-if="testSuccess"
                            class="px-4 py-3 rounded-lg text-sm flex items-center gap-2"
                            style="background: var(--success-bg); color: var(--success-text);"
                        >
                            <CheckCircle :size="14" class="shrink-0" />
                            {{ testSuccess }}
                        </div>

                        <!-- Provider selector -->
                        <div v-if="!editMode">
                            <label class="af-label">Provider <span style="color:var(--color-danger)">*</span></label>
                            <select v-model="form.provider_key" class="af-input">
                                <option value="">— Chọn provider —</option>
                                <option v-for="(info, key) in registry" :key="key" :value="key">
                                    {{ info.name }}
                                </option>
                            </select>
                        </div>
                        <div v-else>
                            <label class="af-label">Provider</label>
                            <input :value="providerName(form.provider_key)" class="af-input" disabled />
                        </div>

                        <!-- Base URL (for providers with has_base_url) -->
                        <div v-if="selectedProviderInfo?.has_base_url">
                            <label class="af-label">
                                Base URL <span style="color:var(--color-danger)">*</span>
                                <span v-if="selectedProviderInfo?.default_base_url" class="ml-1 text-xs font-normal" style="color: var(--text-muted)">(tự động điền)</span>
                            </label>
                            <input v-model="form.base_url" type="url" class="af-input" :placeholder="selectedProviderInfo?.default_base_url || 'http://127.0.0.1:8045'" />
                            <p v-if="form.provider_key === 'self_hosted'" class="mt-1 text-xs flex items-center gap-1" style="color: var(--text-muted)">
                                <Info :size="11" />
                                Nếu chạy Docker: <code>127.0.0.1</code> sẽ tự động chuyển thành <code>host.docker.internal</code>.
                            </p>
                        </div>

                        <!-- API Format (for Self Hosted) -->
                        <div v-if="form.provider_key === 'self_hosted'">
                            <label class="af-label">Định dạng API <span style="color:var(--color-danger)">*</span></label>
                            <select v-model="form.api_format" class="af-input">
                                <option value="openai">OpenAI Compatible (Ollama, vLLM...)</option>
                                <option value="gemini">Google Gemini API</option>
                            </select>
                        </div>

                        <!-- API Key -->
                        <div>
                            <label class="af-label">
                                API Key
                                <span v-if="editMode" class="ml-1 text-xs font-normal" style="color: var(--text-muted)">(để trống nếu không đổi)</span>
                            </label>
                            <div class="relative">
                                <input
                                    v-model="form.api_key"
                                    :type="showKey ? 'text' : 'password'"
                                    class="af-input pr-10"
                                    placeholder="sk-..."
                                    autocomplete="new-password"
                                />
                                <button
                                    type="button"
                                    class="absolute right-3 inset-y-0 flex items-center"
                                    style="color: var(--text-muted);"
                                    @click="showKey = !showKey"
                                >
                                    <EyeOff v-if="showKey" :size="14" />
                                    <Eye v-else :size="14" />
                                </button>
                            </div>
                        </div>

                        <!-- Default model -->
                        <div>
                            <label class="af-label">Model mặc định</label>
                            <input
                                v-model="form.default_model"
                                type="text"
                                class="af-input"
                                :placeholder="modelPlaceholder"
                            />
                            <p class="mt-1 text-xs" style="color: var(--text-muted)">
                                Gemini: <code>gemini-3.1-flash</code> · OpenAI: <code>gpt-5.3-mini</code> · Seedance: <code>doubao-seedance-2-0</code>
                            </p>
                        </div>

                        <!-- Label -->
                        <div>
                            <label class="af-label">Tên hiển thị (tùy chọn)</label>
                            <input v-model="form.label" type="text" class="af-input" placeholder="Ví dụ: Local Ollama, Gemini Pro…" />
                        </div>

                        <!-- Capabilities -->
                        <div>
                            <label class="af-label">Capabilities</label>
                            <div class="flex gap-4 mt-2">
                                <label v-for="cap in ['text', 'image', 'video']" :key="cap" class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-secondary)">
                                    <input type="checkbox" :value="cap" v-model="form.capabilities" class="rounded" />
                                    {{ cap }}
                                </label>
                            </div>
                        </div>

                        <!-- Status toggle -->
                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                                :style="form.status === 'enabled' ? 'background: var(--color-primary-500)' : 'background: var(--border)'"
                                @click="form.status = form.status === 'enabled' ? 'disabled' : 'enabled'"
                            >
                                <span
                                    class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform"
                                    :style="form.status === 'enabled' ? 'transform: translateX(1.375rem)' : 'transform: translateX(0.25rem)'"
                                ></span>
                            </button>
                            <span class="text-sm" style="color: var(--text-secondary)">
                                {{ form.status === 'enabled' ? 'Đang bật' : 'Đã tắt' }}
                            </span>
                        </div>

                        <!-- Action buttons -->
                        <div class="flex items-center gap-3 pt-2 border-t" style="border-color: var(--border)">
                            <button
                                type="button"
                                class="af-btn-outline text-sm h-9 px-4 flex items-center gap-1.5"
                                :disabled="testing || !form.provider_key"
                                @click="testFormConnection"
                            >
                                <Loader2 v-if="testing" :size="13" class="animate-spin" />
                                <Plug2 v-else :size="13" />
                                Test kết nối
                            </button>
                            <div class="flex-1"></div>
                            <button type="button" class="af-btn-outline text-sm h-9 px-4" @click="closeForm">Hủy</button>
                            <button
                                type="button"
                                class="af-btn-primary text-sm h-9 px-4 flex items-center gap-1.5"
                                :disabled="saving || !form.provider_key"
                                @click="saveProvider"
                            >
                                <Loader2 v-if="saving" :size="13" class="animate-spin" />
                                Lưu
                            </button>
                        </div>
                    </div>
                </div>
            </transition>
        </teleport>
    </AppShell>
</template>

<script setup>
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import { useDialog } from '@/Composables/useDialog';
import { useToast } from '@/Composables/useToast';
import {
    Plus, Pencil, Plug2, Trash2, Loader2,
    Eye, EyeOff, X, CheckCircle, CheckCircle2, XCircle,
    AlertCircle, Info,
} from 'lucide-vue-next';

const { confirmDialog } = useDialog();
const toast = useToast();

const props = defineProps({
    userSettings: { type: Array,  default: () => [] },
    registry:     { type: Object, default: () => ({}) },
});

// ── Form state ────────────────────────────────────────────────────────────────
const formOpen    = ref(false);
const editMode    = ref(false);
const saving      = ref(false);
const testing     = ref(false);
const testingKey  = ref(null);
const showKey     = ref(false);
const formError   = ref(null);
const testSuccess = ref(null);

const defaultForm = () => ({
    provider_key:        '',
    api_key:             '',
    base_url:            '',
    api_format:          'openai',
    default_model:       '',
    label:               '',
    status:              'enabled',
    capabilities:        ['text'],
});

const form = reactive(defaultForm());

function openForm(setting = null) {
    formError.value   = null;
    testSuccess.value = null;
    showKey.value     = false;
    editMode.value    = !!setting;

    if (setting) {
        Object.assign(form, {
            provider_key:  setting.provider_key,
            api_key:       '',
            base_url:      '',
            api_format:    setting.api_format || 'openai',
            default_model: setting.default_model || '',
            label:         setting.label || '',
            status:        setting.status || 'enabled',
            capabilities:  setting.capabilities || ['text'],
        });
    } else {
        Object.assign(form, defaultForm());
    }

    formOpen.value = true;
}

function closeForm() {
    formOpen.value    = false;
    formError.value   = null;
    testSuccess.value = null;
}

// ── Provider meta helpers ─────────────────────────────────────────────────────
const ICONS = {
    gemini:      { icon: 'G', style: 'background: #1a73e820; color: #1a73e8' },
    openai:      { icon: 'O', style: 'background: #10a37f20; color: #10a37f' },
    self_hosted: { icon: '⌂',  style: 'background: #8b5cf620; color: #8b5cf6' },
    anthropic:   { icon: 'A', style: 'background: #d4763020; color: #d47630' },
    seedance:    { icon: 'S', style: 'background: #0ea5e920; color: #0ea5e9' },
};

function providerIcon(key)      { return ICONS[key]?.icon  ?? '?'; }
function providerIconStyle(key) { return ICONS[key]?.style ?? 'background: var(--surface-2); color: var(--text-muted)'; }
function providerName(key)      { return props.registry[key]?.name ?? key; }

const selectedProviderInfo = computed(() => props.registry[form.provider_key] ?? null);

const modelPlaceholder = computed(() => ({
    gemini:      'gemini-3.1-flash',
    openai:      'gpt-5.3-mini',
    self_hosted: 'deepseek-r1:7b',
    anthropic:   'claude-4-sonnet',
    seedance:    'doubao-seedance-2-0',
}[form.provider_key] ?? 'model-name'));

// ── Auto-fill defaults when selecting a NEW provider ──────────────────────────
watch(() => form.provider_key, (newKey) => {
    if (editMode.value || !newKey) return;

    const info = props.registry[newKey];
    if (!info) return;

    // Auto-fill model, base_url, capabilities from registry defaults
    if (info.default_model)    form.default_model = info.default_model;
    if (info.default_base_url) form.base_url      = info.default_base_url;
    if (info.capabilities)     form.capabilities  = [...info.capabilities];
});

// ── Save ──────────────────────────────────────────────────────────────────────
async function saveProvider() {
    if (!form.provider_key) { formError.value = 'Vui lòng chọn provider.'; return; }

    saving.value    = true;
    formError.value = null;

    try {
        const payload = {
            provider_key:  form.provider_key,
            default_model: form.default_model || undefined,
            label:         form.label || undefined,
            status:        form.status,
            capabilities:  form.capabilities,
        };
        if (form.api_key)  payload.api_key  = form.api_key;
        if (form.base_url) payload.base_url = form.base_url;
        if (form.api_format) payload.api_format = form.api_format;

        const res = await axios.post(route('api.ai.settings.upsert'), payload);
        if (!res.data?.ok) throw new Error(res.data?.message ?? 'Lỗi lưu provider');

        toast.success(res.data.message ?? 'Đã lưu provider thành công!');
        closeForm();
        router.reload({ only: ['userSettings'], preserveScroll: true });
    } catch (err) {
        formError.value = err.response?.data?.message
            ?? Object.values(err.response?.data?.errors ?? {})[0]?.[0]
            ?? err.message
            ?? 'Không thể lưu provider.';
    } finally {
        saving.value = false;
    }
}

// ── Misc helpers ─────────────────────────────────────────────────────────────
function formatTime(value) {
    if (!value) return '';
    return new Date(value).toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' });
}

// ── Test from card (uses saved DB credentials) ────────────────────────────────
async function testProvider(providerKey) {
    testing.value    = true;
    testingKey.value = providerKey;

    try {
        const res = await axios.post(route('api.ai.settings.test'), { provider_key: providerKey });
        if (res.data?.ok) {
            toast.success(`Kết nối thành công. Model: ${res.data?.data?.model || providerKey}`);
        } else {
            toast.error(res.data?.message ?? 'Kết nối thất bại.');
        }
        // Reload so the card connection badge refreshes from DB
        router.reload({ only: ['userSettings'], preserveScroll: true });
    } catch (err) {
        toast.error(err.response?.data?.message ?? 'Kết nối thất bại.');
    } finally {
        testing.value    = false;
        testingKey.value = null;
    }
}

// ── Test from form (uses typed credentials, no save) ─────────────────────────
async function testFormConnection() {
    if (!form.provider_key) return;
    testing.value     = true;
    formError.value   = null;
    testSuccess.value = null;

    try {
        const res = await axios.post(route('api.ai.settings.test'), {
            provider_key:  form.provider_key,
            api_key:       form.api_key  || undefined,
            base_url:      form.base_url || undefined,
            default_model: form.default_model || undefined,
            api_format:    form.api_format || undefined,
        });

        if (res.data?.ok) {
            testSuccess.value = `✓ Kết nối thành công! Model: ${res.data?.data?.model}`;
        } else {
            formError.value = res.data?.message ?? 'Kết nối thất bại.';
        }
    } catch (err) {
        formError.value = err.response?.data?.message ?? 'Kết nối thất bại.';
    } finally {
        testing.value = false;
    }
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function deleteProvider(providerKey, label) {
    const confirmed = await confirmDialog({
        variant:     'danger',
        title:       'Xóa AI Provider',
        description: `Bạn chắc chắn muốn xóa "${label || providerKey}"? Thao tác không thể hoàn tác.`,
        confirmText: 'Xóa',
        cancelText:  'Hủy',
    });
    if (!confirmed) return;

    try {
        const res = await axios.delete(route('api.ai.settings.destroy', { key: providerKey }));
        if (!res.data?.ok) throw new Error(res.data?.message ?? 'Lỗi xóa');
        toast.success(res.data.message ?? 'Đã xóa provider.');
        router.reload({ only: ['userSettings'], preserveScroll: true });
    } catch (err) {
        toast.error(err.response?.data?.message ?? 'Không thể xóa.');
    }
}

</script>
