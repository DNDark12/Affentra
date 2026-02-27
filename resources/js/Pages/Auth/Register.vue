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
                    Chào mừng bạn<br />đến với Affentra.
                </h1>
                <p class="text-white/80 text-base leading-relaxed" style="max-width: 420px;">
                    Đăng ký tài khoản để bắt đầu chạy Affiliate, theo dõi đơn hàng, click và nhận hoa hồng rõ ràng minh bạch.
                </p>
            </div>

            <p class="text-white/60 text-[13px]">© 2026 Affentra. All rights reserved.</p>
        </div>

        <div class="flex flex-1 items-center justify-center overflow-y-auto" style="background-color: var(--surface-1);">
            <div style="width: 380px; padding: 2rem 0;">
                <div class="mb-8">
                    <h2 class="text-2xl font-bold mb-1" style="color: var(--text-primary)">Đăng ký tài khoản</h2>
                    <p class="text-sm" style="color: var(--text-secondary)">
                        Tài khoản đăng ký sẽ ở trạng thái chờ Admin duyệt.
                    </p>
                </div>

                <div
                    v-if="flashError"
                    class="mb-4 px-4 py-3 rounded-lg text-sm"
                    style="background: var(--danger-bg); color: var(--danger-text);"
                >
                    {{ flashError }}
                </div>

                <form @submit.prevent="submit" class="flex flex-col gap-5">
                    <div>
                        <label for="name" class="af-label">Họ tên</label>
                        <input id="name" v-model="form.name" type="text" class="af-input" :class="{ error: errors.name }" required autofocus />
                        <p v-if="errors.name" class="mt-1 text-xs" style="color: var(--color-danger);">{{ errors.name }}</p>
                    </div>

                    <div>
                        <label for="email" class="af-label">Email</label>
                        <input id="email" v-model="form.email" type="email" class="af-input" :class="{ error: errors.email }" required />
                        <p v-if="errors.email" class="mt-1 text-xs" style="color: var(--color-danger);">{{ errors.email }}</p>
                    </div>

                    <div>
                        <label for="password" class="af-label">Mật khẩu</label>
                        <input id="password" v-model="form.password" type="password" class="af-input" :class="{ error: errors.password }" required />
                        <p v-if="errors.password" class="mt-1 text-xs" style="color: var(--color-danger);">{{ errors.password }}</p>
                    </div>

                    <div>
                        <label for="password_confirmation" class="af-label">Xác nhận mật khẩu</label>
                        <input id="password_confirmation" v-model="form.password_confirmation" type="password" class="af-input" required />
                    </div>

                    <button type="submit" class="af-btn-primary w-full" :disabled="form.processing">
                        <span v-if="form.processing">Đang đăng ký...</span>
                        <span v-else>Đăng ký</span>
                    </button>
                </form>

                <div class="my-6 relative flex items-center">
                    <div class="flex-grow border-t border-zinc-200 dark:border-zinc-800"></div>
                    <span class="flex-shrink-0 mx-4 text-xs text-zinc-400">HOẶC DÙNG GOOGLE</span>
                    <div class="flex-grow border-t border-zinc-200 dark:border-zinc-800"></div>
                </div>

                <a
                    :href="route('auth.google.redirect')"
                    class="af-btn-outline w-full h-11 text-sm flex items-center justify-center gap-3"
                >
                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.2-1.4 3.6-5.5 3.6-3.3 0-6-2.8-6-6.2s2.7-6.2 6-6.2c1.9 0 3.2.8 3.9 1.5l2.7-2.6C16.8 2.5 14.6 1.6 12 1.6 6.7 1.6 2.5 6 2.5 11.5S6.7 21.4 12 21.4c6.9 0 9.3-4.9 9.3-7.5 0-.5-.1-.9-.1-1.3H12z"/>
                        <path fill="#34A853" d="M3.7 7.3l3.2 2.4c.9-2 2.8-3.4 5.1-3.4 1.9 0 3.2.8 3.9 1.5l2.7-2.6C16.8 2.5 14.6 1.6 12 1.6 8.4 1.6 5.2 3.8 3.7 7.3z"/>
                        <path fill="#FBBC05" d="M12 21.4c2.5 0 4.7-.8 6.2-2.3l-2.9-2.4c-.8.6-1.9 1-3.3 1-2.4 0-4.4-1.6-5.1-3.8l-3.2 2.4c1.5 3.6 4.8 5.1 8.3 5.1z"/>
                        <path fill="#4285F4" d="M21.3 13.9c0-.5-.1-.9-.1-1.3H12v3.9h5.5c-.3 1.3-1.1 2.4-2.2 3.1l2.9 2.4c1.7-1.6 2.8-4 2.8-7.1z"/>
                    </svg>
                    Đăng ký bằng Google
                </a>

                <p class="text-sm text-center mt-6" style="color: var(--text-secondary)">
                    Đã có tài khoản?
                    <Link :href="route('login')" class="hover:underline font-medium" style="color: var(--color-primary-500);">Đăng nhập</Link>
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue';
import { usePage, Link, useForm } from '@inertiajs/vue3';

const page = usePage();
const isDark = ref(false);

onMounted(() => {
    isDark.value = document.documentElement.classList.contains('dark');
});

const flashError = computed(() => page.props.flash?.error || null);
const errors = computed(() => page.props.errors || {});

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(route('register.post'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>
