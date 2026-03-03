<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Cảnh báo / Cấu hình Telegram</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Cấu hình Telegram bot cá nhân</h1>
                </div>
                <div class="flex items-center gap-2">
                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('alerts.index'))">
                        Trung tâm cảnh báo
                    </button>
                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('alerts.templates'))">
                        Template tin nhắn
                    </button>
                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('alerts.rules'))">
                        Quy tắc cảnh báo
                    </button>
                </div>
            </div>

            <div class="af-surface p-4 md:p-5 flex flex-col gap-3">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <p class="text-sm font-semibold" style="color: var(--text-primary)">Trạng thái kết nối</p>
                        <p class="text-xs mt-1" style="color: var(--text-muted)">
                            Mỗi user cấu hình bot riêng. Dữ liệu token được mã hóa trong DB.
                        </p>
                    </div>
                    <span
                        class="text-xs px-2.5 py-1 rounded-full font-medium"
                        :style="connectionBadgeStyle"
                    >
                        {{ connectionLabel }}
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-2)">
                        <p class="text-xs mb-1" style="color: var(--text-muted)">Bot token</p>
                        <p class="text-sm font-medium break-all" style="color: var(--text-primary)">
                            {{ telegramState.masked_bot_token || 'Chưa cấu hình' }}
                        </p>
                    </div>
                    <div class="rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-2)">
                        <p class="text-xs mb-1" style="color: var(--text-muted)">Group id (ưu tiên)</p>
                        <p class="text-sm font-medium break-all" style="color: var(--text-primary)">
                            {{ telegramState.group_id || 'Không có (sẽ gửi chat cá nhân)' }}
                        </p>
                    </div>
                    <div class="rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-2)">
                        <p class="text-xs mb-1" style="color: var(--text-muted)">Test gần nhất</p>
                        <p class="text-sm font-medium" style="color: var(--text-primary)">
                            {{ formatTestStatus(telegramState.last_test_status) }}
                        </p>
                        <p class="text-xs mt-1" style="color: var(--text-muted)">
                            {{ fmtDateTime(telegramState.last_test_at) }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <div class="af-surface p-4 xl:col-span-2 flex flex-col gap-4">
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary)">Cập nhật kết nối Telegram</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-xs font-medium" style="color: var(--text-secondary)">Bot token</label>
                            <input
                                v-model="form.bot_token"
                                type="password"
                                autocomplete="off"
                                class="af-input h-10 text-sm"
                                placeholder="123456789:AA..."
                            >
                            <p class="text-[11px]" style="color: var(--text-muted)">
                                {{ telegramState.has_token ? 'Để trống nếu không đổi token.' : 'Nhập bot token từ @BotFather để bắt đầu.' }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="text-xs font-medium" style="color: var(--text-secondary)">Group id (optional)</label>
                            <input
                                v-model="form.group_id"
                                type="text"
                                class="af-input h-10 text-sm"
                                placeholder="-100xxxxxxxxxx hoặc @channel"
                            >
                            <p class="text-[11px]" style="color: var(--text-muted)">
                                Nếu để trống, hệ thống sẽ tự gửi về chat cá nhân gần nhất từ Telegram updates.
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="flex items-center gap-2 rounded-lg border p-3 cursor-pointer" style="border-color: var(--border); background: var(--surface-2)">
                            <input v-model="form.is_enabled" type="checkbox" class="rounded border-zinc-300 text-indigo-600">
                            <span class="text-sm" style="color: var(--text-primary)">Bật gửi cảnh báo Telegram</span>
                        </label>
                        <label class="flex items-center gap-2 rounded-lg border p-3 cursor-pointer" style="border-color: var(--border); background: var(--surface-2)">
                            <input v-model="form.fallback_to_in_app" type="checkbox" class="rounded border-zinc-300 text-indigo-600">
                            <span class="text-sm" style="color: var(--text-primary)">Fallback về in-app nếu Telegram lỗi</span>
                        </label>
                    </div>

                    <div class="flex items-center gap-2 flex-wrap">
                        <button class="af-btn-primary text-sm h-9 px-4" :disabled="isSaving" @click="saveConfig">
                            {{ isSaving ? 'Đang lưu...' : 'Lưu cấu hình' }}
                        </button>
                        <button class="af-btn-outline text-sm h-9 px-4" :disabled="isTesting" @click="testConfig">
                            {{ isTesting ? 'Đang test...' : 'Test kết nối' }}
                        </button>
                        <button class="af-btn-outline text-sm h-9 px-4" :disabled="isDeleting || !telegramState.has_token" @click="deleteConfig">
                            {{ isDeleting ? 'Đang xóa...' : 'Xóa cấu hình' }}
                        </button>
                    </div>
                </div>

                <div class="af-surface p-4 flex flex-col gap-3">
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary)">Hướng dẫn lấy group/chat id</h2>
                    <ol class="text-xs leading-5 list-decimal pl-4" style="color: var(--text-muted)">
                        <li>Nhắn tin trước cho bot của bạn trên Telegram.</li>
                        <li>Muốn gửi vào group: thêm bot vào group và lấy <code>group id</code></li>
                        <li>Bạn có thể dán trực tiếp link kiểu <code>web.telegram.org/a/#-xxxxx</code>, hệ thống sẽ tự chuẩn hóa.</li>
                        <li>Nếu không nhập group id, hệ thống sẽ tự lấy chat cá nhân gần nhất.</li>
                    </ol>

                    <div class="rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-2)">
                        <p class="text-xs font-medium mb-1" style="color: var(--text-secondary)">Test nhanh</p>
                        <input
                            v-model="testPayload.bot_token"
                            type="password"
                            autocomplete="off"
                            class="af-input h-9 text-sm mb-2"
                            placeholder="Bot token test (optional, để trống = dùng token đã lưu)"
                        >
                        <input
                            v-model="testPayload.group_id"
                            type="text"
                            class="af-input h-9 text-sm mb-2"
                            placeholder="Group id test (optional, để trống = dùng group/chat đã lưu)"
                        >
                        <textarea
                            v-model="testPayload.message"
                            class="af-input text-sm w-full"
                            rows="3"
                            placeholder="Nội dung test (optional)"
                        ></textarea>
                        <button
                            class="af-btn-primary text-sm h-8 px-3 mt-2"
                            :disabled="isTesting"
                            @click="testConfig"
                        >
                            {{ isTesting ? 'Đang gửi...' : 'Gửi tin nhắn test' }}
                        </button>
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
import { useDialog } from '@/Composables/useDialog';

const props = defineProps({
    telegram: {
        type: Object,
        default: () => ({
            enabled: false,
            has_chat_id: false,
            has_group_id: false,
            has_token: false,
            masked_bot_token: null,
            default_chat_id: null,
            group_id: null,
            fallback_to_in_app: true,
            last_test_status: null,
            last_test_error: null,
            last_test_at: null,
            verified_at: null,
        }),
    },
    summary: { type: Object, default: () => ({}) },
});

const toast = useToast();
const { confirmDialog } = useDialog();

const isSaving = ref(false);
const isTesting = ref(false);
const isDeleting = ref(false);

const telegramState = ref({ ...props.telegram });

const form = ref({
    bot_token: '',
    group_id: telegramState.value.group_id || '',
    is_enabled: !!telegramState.value.enabled,
    fallback_to_in_app: telegramState.value.fallback_to_in_app !== false,
});

const testPayload = ref({
    bot_token: '',
    group_id: '',
    message: '',
});

const hasAnyTarget = computed(() => !!telegramState.value.has_group_id || !!telegramState.value.has_chat_id);

const connectionLabel = computed(() => {
    if (!telegramState.value.has_token) return 'Chưa cấu hình';
    if (!form.value.is_enabled) return 'Đã lưu nhưng đang tắt';
    if (!hasAnyTarget.value) return 'Thiếu đích gửi';
    if (telegramState.value.last_test_status === 'failed') return 'Cần test lại';
    return 'Đang hoạt động';
});

const connectionBadgeStyle = computed(() => {
    if (!telegramState.value.has_token) {
        return { background: 'var(--warning-bg)', color: 'var(--warning-text)' };
    }
    if (!form.value.is_enabled) {
        return { background: 'var(--surface-2)', color: 'var(--text-muted)' };
    }
    if (!hasAnyTarget.value) {
        return { background: 'var(--warning-bg)', color: 'var(--warning-text)' };
    }
    if (telegramState.value.last_test_status === 'failed') {
        return { background: 'var(--danger-bg)', color: 'var(--danger-text)' };
    }
    return { background: 'var(--success-bg)', color: 'var(--success-text)' };
});

function applyTelegramState(nextState) {
    if (!nextState || typeof nextState !== 'object') {
        return;
    }

    telegramState.value = {
        ...telegramState.value,
        ...nextState,
    };

    form.value.group_id = telegramState.value.group_id || '';
    form.value.is_enabled = !!telegramState.value.enabled;
    form.value.fallback_to_in_app = telegramState.value.fallback_to_in_app !== false;
}

function extractError(error) {
    const payload = error?.response?.data || {};
    const errorCode = payload?.errors?.error_code || payload?.data?.error_code || '';
    const message = payload?.message || error?.message || 'Có lỗi xảy ra.';

    if (payload?.errors?.telegram) {
        applyTelegramState(payload.errors.telegram);
    }

    if (payload?.data?.telegram) {
        applyTelegramState(payload.data.telegram);
    }

    return {
        message: classifyErrorMessage(errorCode, message),
    };
}

async function saveConfig() {
    if (isSaving.value) return;

    isSaving.value = true;
    try {
        const payload = {
            group_id: (form.value.group_id || '').trim(),
            is_enabled: !!form.value.is_enabled,
            fallback_to_in_app: !!form.value.fallback_to_in_app,
        };

        if ((form.value.bot_token || '').trim() !== '') {
            payload.bot_token = form.value.bot_token.trim();
        }

        const response = await axios.put(route('api.alerts.telegram-config.update'), payload);

        if (!response.data?.ok) {
            throw new Error(response.data?.message || 'Không thể lưu cấu hình Telegram.');
        }

        applyTelegramState(response.data?.data?.telegram);
        form.value.bot_token = '';
        toast.success(response.data?.message || 'Đã lưu cấu hình Telegram.');
    } catch (error) {
        const extracted = extractError(error);
        toast.error(extracted.message);
    } finally {
        isSaving.value = false;
    }
}

async function testConfig() {
    if (isTesting.value) return;

    isTesting.value = true;
    try {
        const effectiveBotToken = (testPayload.value.bot_token || form.value.bot_token || '').trim();
        const effectiveGroupId = (testPayload.value.group_id || form.value.group_id || '').trim();
        const effectiveMessage = (testPayload.value.message || '').trim();

        const payload = {};
        if (effectiveBotToken !== '') {
            payload.bot_token = effectiveBotToken;
        }
        if (effectiveGroupId !== '') {
            payload.group_id = effectiveGroupId;
        }
        if (effectiveMessage !== '') {
            payload.message = effectiveMessage;
        }

        const response = await axios.post(route('api.alerts.telegram-config.test'), payload);

        if (!response.data?.ok) {
            throw new Error(response.data?.message || 'Test Telegram thất bại.');
        }

        applyTelegramState(response.data?.data?.telegram);
        toast.success(response.data?.message || 'Test Telegram thành công.');
    } catch (error) {
        const extracted = extractError(error);
        toast.error(extracted.message);
    } finally {
        isTesting.value = false;
    }
}

async function deleteConfig() {
    if (isDeleting.value) return;

    const confirmed = await confirmDialog({
        variant: 'danger',
        title: 'Xóa cấu hình Telegram',
        description: 'Hệ thống sẽ ngừng gửi Telegram cho tài khoản này. Bạn có chắc chắn?',
        confirmText: 'Xóa cấu hình',
        cancelText: 'Hủy',
    });

    if (!confirmed) return;

    isDeleting.value = true;
    try {
        const response = await axios.delete(route('api.alerts.telegram-config.delete'));
        if (!response.data?.ok) {
            throw new Error(response.data?.message || 'Không thể xóa cấu hình Telegram.');
        }

        applyTelegramState(response.data?.data?.telegram);
        form.value.bot_token = '';
        form.value.group_id = '';
        form.value.is_enabled = false;
        form.value.fallback_to_in_app = true;
        testPayload.value.bot_token = '';
        testPayload.value.group_id = '';
        testPayload.value.message = '';

        toast.success(response.data?.message || 'Đã xóa cấu hình Telegram.');
    } catch (error) {
        const extracted = extractError(error);
        toast.error(extracted.message);
    } finally {
        isDeleting.value = false;
    }
}

function classifyErrorMessage(errorCode, fallbackMessage) {
    if (errorCode === 'token_invalid') return 'Bot token Telegram không hợp lệ. Vui lòng kiểm tra lại.';
    if (errorCode === 'chat_not_found') return 'Không tìm thấy chat/group hợp lệ. Hãy nhắn bot trước hoặc nhập đúng group id.';
    if (errorCode === 'bot_blocked') return 'Bot đang bị chặn ở chat đích. Vui lòng mở chặn và test lại.';
    if (errorCode === 'network_timeout') return 'Kết nối Telegram bị timeout. Vui lòng thử lại sau.';
    if (errorCode === 'network_error') return 'Lỗi mạng khi gọi Telegram API. Vui lòng thử lại.';
    if (errorCode === 'not_configured') return 'Chưa có bot token. Vui lòng nhập token và lưu cấu hình trước.';
    return fallbackMessage || 'Test Telegram thất bại.';
}

function fmtDateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('vi-VN');
}

function formatTestStatus(status) {
    if (status === 'success') return 'Thành công';
    if (status === 'failed') return 'Thất bại';
    return 'Chưa test';
}
</script>
