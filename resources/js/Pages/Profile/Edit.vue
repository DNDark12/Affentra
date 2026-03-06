<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <div>
                <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Hồ sơ</p>
                <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Hồ sơ tài khoản</h1>
            </div>

            <div class="af-surface p-1 inline-flex gap-1 w-fit">
                <button
                    type="button"
                    class="px-4 h-9 rounded-md text-sm font-medium transition-colors"
                    :style="tabStyle('settings')"
                    @click="activeTab = 'settings'"
                >
                    Cài đặt
                </button>
                <button
                    type="button"
                    class="px-4 h-9 rounded-md text-sm font-medium transition-colors"
                    :style="tabStyle('payout')"
                    @click="activeTab = 'payout'"
                >
                    Thanh toán
                </button>
            </div>

            <div
                v-if="flashSuccess"
                class="px-4 py-3 rounded-lg text-sm"
                style="background: var(--success-bg); color: var(--success-text);"
            >
                {{ flashSuccess }}
            </div>

            <section v-if="activeTab === 'settings'" class="af-surface p-6 max-w-3xl">
                <h2 class="text-lg font-semibold mb-5" style="color: var(--text-primary)">Thông tin cơ bản</h2>

                <div
                    v-if="!settingsForm.has_password"
                    class="mb-5 px-4 py-3 rounded-lg text-sm"
                    style="background: var(--warning-bg); color: var(--warning-text);"
                >
                    Tài khoản này chưa có mật khẩu nội bộ. Bạn có thể tạo mật khẩu mới ở bên dưới.
                </div>

                <form class="flex flex-col gap-5" @submit.prevent="submitSettings">
                    <div>
                        <label for="name" class="af-label">Họ tên</label>
                        <input id="name" v-model="settingsForm.name" type="text" class="af-input" :class="{ error: errors.name }" required />
                        <p v-if="errors.name" class="mt-1 text-xs" style="color: var(--color-danger);">{{ errors.name }}</p>
                    </div>

                    <div>
                        <label for="email" class="af-label">Email</label>
                        <input id="email" :value="settingsForm.email" type="email" class="af-input" disabled />
                    </div>

                    <div>
                        <label for="phone" class="af-label">Số điện thoại</label>
                        <input id="phone" v-model="settingsForm.phone" type="tel" class="af-input" :class="{ error: errors.phone }" placeholder="0901 234 567" />
                        <p v-if="errors.phone" class="mt-1 text-xs" style="color: var(--color-danger);">{{ errors.phone }}</p>
                    </div>

                    <div>
                        <label for="avatar" class="af-label">Avatar URL</label>
                        <input id="avatar" v-model="settingsForm.avatar" type="url" class="af-input" :class="{ error: errors.avatar }" placeholder="https://..." />
                        <p v-if="errors.avatar" class="mt-1 text-xs" style="color: var(--color-danger);">{{ errors.avatar }}</p>
                    </div>

                    <div class="border-t pt-5" style="border-color: var(--border);">
                        <h3 class="text-sm font-semibold mb-4" style="color: var(--text-primary)">Đổi mật khẩu</h3>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="md:col-span-2" v-if="settingsForm.has_password">
                                <label for="current_password" class="af-label">Mật khẩu hiện tại</label>
                                <input
                                    id="current_password"
                                    v-model="settingsForm.current_password"
                                    type="password"
                                    class="af-input"
                                    :class="{ error: errors.current_password }"
                                />
                                <p v-if="errors.current_password" class="mt-1 text-xs" style="color: var(--color-danger);">
                                    {{ errors.current_password }}
                                </p>
                            </div>

                            <div>
                                <label for="new_password" class="af-label">Mật khẩu mới</label>
                                <input
                                    id="new_password"
                                    v-model="settingsForm.new_password"
                                    type="password"
                                    class="af-input"
                                    :class="{ error: errors.new_password }"
                                />
                                <p v-if="errors.new_password" class="mt-1 text-xs" style="color: var(--color-danger);">{{ errors.new_password }}</p>
                            </div>

                            <div>
                                <label for="new_password_confirmation" class="af-label">Xác nhận mật khẩu mới</label>
                                <input
                                    id="new_password_confirmation"
                                    v-model="settingsForm.new_password_confirmation"
                                    type="password"
                                    class="af-input"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="af-btn-primary" :disabled="settingsForm.processing">
                            <span v-if="settingsForm.processing">Đang lưu…</span>
                            <span v-else>Lưu cài đặt</span>
                        </button>
                    </div>
                </form>
            </section>

            <section v-else class="af-surface p-6 max-w-3xl">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-lg font-semibold" style="color: var(--text-primary)">Thông tin thanh toán</h2>
                    <span
                        class="text-xs px-2 py-1 rounded-md font-medium"
                        :style="payoutReviewBadgeStyle"
                    >
                        {{ payoutReviewBadgeText }}
                    </span>
                </div>

                <div
                    v-if="payoutForm.payout_review_status === 'rejected' && payoutForm.payout_reject_reason"
                    class="mb-4 px-4 py-3 rounded-lg text-sm"
                    style="background: var(--danger-bg); color: var(--danger-text);"
                >
                    Lý do từ chối: {{ payoutForm.payout_reject_reason }}
                </div>

                <form class="flex flex-col gap-5" @submit.prevent="submitPayout">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="bank_code" class="af-label">Mã ngân hàng</label>
                            <input id="bank_code" v-model="payoutForm.bank_code" type="text" class="af-input" :class="{ error: errors.bank_code }" />
                            <p v-if="errors.bank_code" class="mt-1 text-xs" style="color: var(--color-danger);">{{ errors.bank_code }}</p>
                        </div>

                        <div>
                            <label for="bank_name" class="af-label">Tên ngân hàng</label>
                            <input id="bank_name" v-model="payoutForm.bank_name" type="text" class="af-input" :class="{ error: errors.bank_name }" />
                            <p v-if="errors.bank_name" class="mt-1 text-xs" style="color: var(--color-danger);">{{ errors.bank_name }}</p>
                        </div>

                        <div>
                            <label for="bank_account_name" class="af-label">Tên chủ tài khoản</label>
                            <input
                                id="bank_account_name"
                                v-model="payoutForm.bank_account_name"
                                type="text"
                                class="af-input"
                                :class="{ error: errors.bank_account_name }"
                            />
                            <p v-if="errors.bank_account_name" class="mt-1 text-xs" style="color: var(--color-danger);">
                                {{ errors.bank_account_name }}
                            </p>
                        </div>

                        <div>
                            <label for="bank_account_number" class="af-label">Số tài khoản</label>
                            <input
                                id="bank_account_number"
                                v-model="payoutForm.bank_account_number"
                                type="text"
                                class="af-input"
                                :class="{ error: errors.bank_account_number }"
                            />
                            <p v-if="errors.bank_account_number" class="mt-1 text-xs" style="color: var(--color-danger);">
                                {{ errors.bank_account_number }}
                            </p>
                        </div>
                    </div>

                    <div>
                        <label for="tax_id" class="af-label">Mã số thuế (nếu có)</label>
                        <input id="tax_id" v-model="payoutForm.tax_id" type="text" class="af-input" :class="{ error: errors.tax_id }" />
                        <p v-if="errors.tax_id" class="mt-1 text-xs" style="color: var(--color-danger);">{{ errors.tax_id }}</p>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="af-btn-primary" :disabled="payoutForm.processing">
                            <span v-if="payoutForm.processing">Đang lưu…</span>
                            <span v-else>Lưu thông tin payout</span>
                        </button>
                    </div>
                </form>

                <div v-if="approval?.can_review" class="mt-8 border-t pt-5" style="border-color: var(--border);">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Payout Approval Queue</h3>
                        <span class="text-xs" style="color: var(--text-muted);">{{ approval.items?.length || 0 }} pending</span>
                    </div>

                    <div class="af-surface overflow-hidden">
                        <table class="w-full text-sm border-collapse">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                                    <th class="text-left px-4 py-2.5 font-medium" style="color: var(--text-muted)">Partner</th>
                                    <th class="text-left px-4 py-2.5 font-medium" style="color: var(--text-muted)">Ngân hàng</th>
                                    <th class="text-left px-4 py-2.5 font-medium" style="color: var(--text-muted)">Cập nhật</th>
                                    <th class="text-right px-4 py-2.5 font-medium" style="color: var(--text-muted)">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!approval.items?.length">
                                    <td colspan="4" class="px-4 py-8 text-center text-xs" style="color: var(--text-muted)">
                                        Không có hồ sơ payout chờ duyệt.
                                    </td>
                                </tr>
                                <tr
                                    v-for="item in approval.items"
                                    :key="item.user_id"
                                    style="border-bottom: 1px solid var(--border)"
                                    class="hover:bg-[var(--surface-2)] transition-colors"
                                >
                                    <td class="px-4 py-3">
                                        <p class="text-sm font-medium" style="color: var(--text-primary)">{{ item.name }}</p>
                                        <p class="text-xs" style="color: var(--text-muted)">{{ item.email }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-sm" style="color: var(--text-secondary)">
                                        {{ item.bank_name || '—' }} · {{ item.bank_account_name || '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs" style="color: var(--text-muted)">
                                        {{ formatDateTime(item.updated_at) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-2">
                                            <button
                                                type="button"
                                                class="af-btn-outline text-xs h-8 px-3"
                                                :disabled="approvingUserId === item.user_id"
                                                @click="rejectPayout(item.user_id)"
                                            >
                                                Từ chối
                                            </button>
                                            <button
                                                type="button"
                                                class="af-btn-primary text-xs h-8 px-3"
                                                :disabled="approvingUserId === item.user_id"
                                                @click="approvePayout(item.user_id)"
                                            >
                                                Duyệt
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </AppShell>
</template>

<script setup>
import axios from 'axios';
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import { useToast } from '@/Composables/useToast';
import { useDialog } from '@/Composables/useDialog';

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
    payout: {
        type: Object,
        required: true,
    },
    approval: {
        type: Object,
        default: () => ({ can_review: false, items: [] }),
    },
});

const page = usePage();
const activeTab = ref('settings');
const toast = useToast();
const { promptDialog } = useDialog();

const settingsForm = useForm({
    name: props.settings.name || '',
    email: props.settings.email || '',
    avatar: props.settings.avatar || '',
    phone: props.settings.phone || '',
    has_password: props.settings.has_password || false,
    current_password: '',
    new_password: '',
    new_password_confirmation: '',
});

const payoutForm = useForm({
    bank_code: props.payout.bank_code || '',
    bank_name: props.payout.bank_name || '',
    bank_account_name: props.payout.bank_account_name || '',
    bank_account_number: props.payout.bank_account_number || '',
    tax_id: props.payout.tax_id || '',
    is_payout_ready: props.payout.is_payout_ready || false,
    payout_review_status: props.payout.payout_review_status || 'pending',
    payout_reject_reason: props.payout.payout_reject_reason || null,
});
const approvingUserId = ref(null);

const errors = computed(() => page.props.errors || {});
const flashSuccess = computed(() => page.props.flash?.success || null);

const payoutReadyStyle = {
    background: 'var(--success-bg)',
    color: 'var(--success-text)',
};

const payoutPendingStyle = {
    background: 'var(--warning-bg)',
    color: 'var(--warning-text)',
};

const payoutRejectedStyle = {
    background: 'var(--danger-bg)',
    color: 'var(--danger-text)',
};

const payoutReviewBadgeText = computed(() => {
    if (payoutForm.is_payout_ready || payoutForm.payout_review_status === 'approved') return 'Sẵn sàng payout';
    if (payoutForm.payout_review_status === 'rejected') return 'Bị từ chối';
    return 'Chờ xét duyệt bank';
});

const payoutReviewBadgeStyle = computed(() => {
    if (payoutForm.is_payout_ready || payoutForm.payout_review_status === 'approved') return payoutReadyStyle;
    if (payoutForm.payout_review_status === 'rejected') return payoutRejectedStyle;
    return payoutPendingStyle;
});

function tabStyle(name) {
    if (activeTab.value === name) {
        return {
            background: 'var(--color-primary-50)',
            color: 'var(--color-primary-600)',
        };
    }

    return {
        background: 'transparent',
        color: 'var(--text-secondary)',
    };
}

function submitSettings() {
    settingsForm.put(route('profile.settings.update'), {
        onSuccess: () => {
            settingsForm.reset('current_password', 'new_password', 'new_password_confirmation');
        },
    });
}

function submitPayout() {
    payoutForm.put(route('profile.payout.update'), {
        onSuccess: () => {
            payoutForm.is_payout_ready = false;
            payoutForm.payout_review_status = 'pending';
            payoutForm.payout_reject_reason = null;
            router.reload({ only: ['payout', 'approval', 'flash'], preserveScroll: true });
        },
    });
}

function formatDateTime(value) {
    if (!value) return '--';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '--';
    return date.toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' });
}

async function approvePayout(userId) {
    approvingUserId.value = userId;
    try {
        const response = await axios.post(route('api.payout-approvals.approve', userId));
        if (!response.data?.ok) {
            toast.error(response.data?.message || 'Không thể duyệt hồ sơ.');
            return;
        }

        toast.success(response.data?.message || 'Đã duyệt hồ sơ payout.');
        router.reload({ only: ['payout', 'approval', 'flash'], preserveScroll: true });
    } catch (error) {
        toast.error(error.response?.data?.message || 'Không thể duyệt hồ sơ.');
    } finally {
        approvingUserId.value = null;
    }
}

async function rejectPayout(userId) {
    const reason = await promptDialog({
        variant: 'danger',
        title: 'Từ chối hồ sơ payout',
        description: 'Vui lòng nhập lý do từ chối để hệ thống gửi lại cho Partner.',
        confirmText: 'Xác nhận từ chối',
        cancelText: 'Hủy',
        inputLabel: 'Lý do từ chối',
        inputPlaceholder: 'Ví dụ: Sai số tài khoản ngân hàng...',
        inputMinLength: 3,
    });

    if (!reason) {
        return;
    }

    approvingUserId.value = userId;
    try {
        const response = await axios.post(route('api.payout-approvals.reject', userId), {
            reason,
        });
        if (!response.data?.ok) {
            toast.error(response.data?.message || 'Không thể từ chối hồ sơ.');
            return;
        }

        toast.success(response.data?.message || 'Đã từ chối hồ sơ payout.');
        router.reload({ only: ['payout', 'approval', 'flash'], preserveScroll: true });
    } catch (error) {
        toast.error(error.response?.data?.message || 'Không thể từ chối hồ sơ.');
    } finally {
        approvingUserId.value = null;
    }
}
</script>
