<template>
    <div class="flex h-screen w-full" :class="{ dark: isDark }">
        <div
            class="hidden lg:flex flex-col justify-between"
            style="width: 640px; background-color: var(--color-primary-500); padding: 64px 56px; flex-shrink: 0;"
        >
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-lg bg-white/20 flex items-center justify-center">
                    <span class="text-white text-sm font-bold">A</span>
                </div>
                <span class="text-white text-[22px] font-bold">Affentra</span>
            </div>
            <div class="flex flex-col gap-6">
                <h1 class="text-white text-4xl font-bold leading-tight">
                    Tạo mật khẩu mới<br />an toàn hơn.
                </h1>
                <p class="text-white/80 text-base leading-relaxed" style="max-width: 420px;">
                    Mật khẩu mới sẽ thay thế mật khẩu cũ và các phiên đăng nhập sẽ được làm mới.
                </p>
            </div>
            <p class="text-white/60 text-[13px]">© 2026 Affentra. All rights reserved.</p>
        </div>

        <div class="flex flex-1 items-center justify-center" style="background-color: var(--surface-1);">
            <div style="width: 380px;">
                <div class="mb-8">
                    <h2 class="text-2xl font-bold mb-1" style="color: var(--text-primary)">Đặt lại mật khẩu</h2>
                    <p class="text-sm" style="color: var(--text-secondary)">
                        Nhập mật khẩu mới cho tài khoản của bạn.
                    </p>
                </div>

                <form @submit.prevent="submit" class="flex flex-col gap-5">
                    <div>
                        <label for="email" class="af-label">Email</label>
                        <input id="email" v-model="form.email" type="email" class="af-input" :class="{ error: errors.email }" required />
                        <p v-if="errors.email" class="mt-1 text-xs" style="color: var(--color-danger);">{{ errors.email }}</p>
                    </div>

                    <div>
                        <label for="password" class="af-label">Mật khẩu mới</label>
                        <input id="password" v-model="form.password" type="password" class="af-input" :class="{ error: errors.password }" required />
                        <p v-if="errors.password" class="mt-1 text-xs" style="color: var(--color-danger);">{{ errors.password }}</p>
                    </div>

                    <div>
                        <label for="password_confirmation" class="af-label">Xác nhận mật khẩu</label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            class="af-input"
                            required
                        />
                    </div>

                    <button type="submit" class="af-btn-primary w-full" :disabled="form.processing">
                        <span v-if="form.processing">Đang lưu…</span>
                        <span v-else>Cập nhật mật khẩu</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    email: {
        type: String,
        default: '',
    },
    token: {
        type: String,
        required: true,
    },
});

const page = usePage();
const isDark = ref(false);

onMounted(() => {
    isDark.value = document.documentElement.classList.contains('dark');
});

const errors = computed(() => page.props.errors || {});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(route('password.update'));
}
</script>
