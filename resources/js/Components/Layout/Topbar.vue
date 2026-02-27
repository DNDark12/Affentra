<template>
    <header class="af-topbar flex items-center justify-between px-6 sticky top-0 z-20">
        <!-- Breadcrumb -->
        <div class="flex items-center gap-2" style="color: var(--text-secondary)">
            <span class="text-sm font-medium">{{ pageTitle }}</span>
        </div>

        <!-- Right -->
        <div class="flex items-center gap-3">
            <span class="text-xs px-2 py-1 rounded-md font-medium"
                  style="background: var(--surface-2); color: var(--text-secondary)">
                Platform: Shopee
            </span>

            <button class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--surface-2)] transition-colors"
                    aria-label="Notifications">
                <Bell :size="16" :stroke-width="1.75" style="color: var(--text-secondary)" />
            </button>

            <button @click="toggleTheme"
                    class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--surface-2)] transition-colors"
                    aria-label="Toggle theme">
                <Sun  v-if="isDark" :size="16" :stroke-width="1.75" style="color: var(--text-secondary)" />
                <Moon v-else        :size="16" :stroke-width="1.75" style="color: var(--text-secondary)" />
            </button>

            <button class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold text-white"
                    style="background-color: var(--color-primary-500)">
                {{ initials }}
            </button>
        </div>
    </header>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Bell, Sun, Moon } from 'lucide-vue-next';

const page   = usePage();
const user   = computed(() => page.props.auth?.user);
const isDark = ref(false);

onMounted(() => {
    isDark.value = document.documentElement.classList.contains('dark');
});

const pageTitle = computed(() =>
    page.props.ziggy?.location?.split('/').filter(Boolean).pop() || 'Dashboard'
);

const initials = computed(() =>
    (user.value?.name || 'U').split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase()
);

function toggleTheme() {
    isDark.value = !isDark.value;
    document.documentElement.classList.toggle('dark', isDark.value);
    document.cookie = `theme=${isDark.value ? 'dark' : 'light'};path=/;max-age=31536000`;
}
</script>
