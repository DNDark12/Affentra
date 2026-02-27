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
                        :style="payoutForm.is_payout_ready ? payoutReadyStyle : payoutPendingStyle"
                    >
                        {{ payoutForm.is_payout_ready ? 'Sẵn sàng payout' : 'Chờ xét duyệt bank' }}
                    </span>
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
            </section>
        </div>
    </AppShell>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
    payout: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const activeTab = ref('settings');

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
});

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
        },
    });
}
</script>
