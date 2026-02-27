<template>
    <div class="flex h-screen w-full" :class="{ dark: isDark }">
        <!-- Brand Panel (left) -->
        <div class="hidden lg:flex flex-col justify-between"
             style="width: 640px; background-color: var(--color-primary-500); padding: 64px 56px; flex-shrink: 0;">
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-lg bg-white/20 flex items-center justify-center">
                    <span class="text-white text-sm font-bold">A</span>
                </div>
                <span class="text-white text-[22px] font-bold">Affentra</span>
            </div>
            <div class="flex flex-col gap-6">
                <h1 class="text-white text-4xl font-bold leading-tight">
                    Quản lý Affiliate<br>thông minh hơn.
                </h1>
                <p class="text-white/80 text-base leading-relaxed" style="max-width: 420px;">
                    Theo dõi link, clicks, đơn hàng và hoa hồng Shopee — tất cả trong một nền tảng.
                </p>
            </div>
            <p class="text-white/60 text-[13px]">© 2026 Affentra. All rights reserved.</p>
        </div>

        <!-- Form Panel -->
        <div class="flex flex-1 items-center justify-center" style="background-color: var(--surface-1);">
            <div style="width: 380px;">
                <div class="mb-8">
                    <h2 class="text-2xl font-bold mb-1" style="color: var(--text-primary)">Đặt lại mật khẩu</h2>
                    <p class="text-sm" style="color: var(--text-secondary)">
                        Nhập email và chúng tôi sẽ gửi link đặt lại mật khẩu.
                    </p>
                </div>

                <!-- Success -->
                <div v-if="status" class="mb-4 px-4 py-3 rounded-lg text-sm"
                     style="background: var(--success-bg); color: var(--success-text);">
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="flex flex-col gap-5">
                    <div>
                        <label for="email" class="af-label">Email</label>
                        <input id="email" v-model="form.email" type="email" class="af-input"
                               :class="{ error: errors.email }" placeholder="you@affentra.com" required />
                        <p v-if="errors.email" class="mt-1 text-xs" style="color: var(--color-danger);">
                            {{ errors.email }}
                        </p>
                    </div>
                    <button type="submit" class="af-btn-primary w-full" :disabled="form.processing">
                        <span v-if="form.processing">Đang gửi…</span>
                        <span v-else>Gửi link đặt lại</span>
                    </button>
                    <Link :href="route('login')" class="text-sm text-center hover:underline"
                          style="color: var(--text-secondary)">
                        ← Quay lại đăng nhập
                    </Link>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

const page   = usePage();
const isDark = ref(false);

onMounted(() => { isDark.value = document.documentElement.classList.contains('dark'); });

const status = computed(() => page.props.flash?.status || null);
const errors = computed(() => page.props.errors || {});

const form = useForm({ email: '' });
function submit() {
    form.post(route('password.email'));
}
</script>
