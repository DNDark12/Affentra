<template>
    <aside class="af-sidebar flex flex-col h-full" style="padding: 24px 16px;">
        <!-- Top: Logo + Nav -->
        <div class="flex flex-col gap-6 flex-1">
            <!-- Logo -->
            <div class="flex items-center gap-2.5 px-2">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center"
                     style="background-color: var(--color-primary-500);">
                    <span class="text-white text-sm font-bold">A</span>
                </div>
                <span class="text-base font-semibold" style="color: var(--text-primary)">Affentra</span>
            </div>

            <!-- Nav -->
            <nav class="flex flex-col gap-0.5">
                <Link
                    v-for="item in navigation"
                    :key="item.route"
                    :href="routeExists(item.route) ? route(item.route) : '#'"
                    class="af-nav-item"
                    :class="{ active: isActive(item.route) }"
                >
                    <component :is="item.icon" :size="16" :stroke-width="1.75" />
                    <span>{{ item.label }}</span>
                </Link>
            </nav>
        </div>

        <!-- Bottom -->
        <div class="flex flex-col gap-0.5">
            <Link :href="route('profile.edit')" class="af-nav-item">
                <Settings :size="16" :stroke-width="1.75" />
                <span>Cài đặt</span>
            </Link>
            <a href="#" class="af-nav-item">
                <HelpCircle :size="16" :stroke-width="1.75" />
                <span>Trợ giúp</span>
            </a>
            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="af-nav-item w-full text-left"
            >
                <LogOut :size="16" :stroke-width="1.75" />
                <span>Đăng xuất</span>
            </Link>
        </div>
    </aside>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import {
    Settings,
    HelpCircle,
    LogOut,
} from 'lucide-vue-next';

defineProps({
    navigation: {
        type: Array,
        default: () => [],
    },
});

function isActive(routeName) {
    try {
        if (route().current(routeName)) {
            return true;
        }

        if (routeName.endsWith('.index')) {
            const section = routeName.split('.')[0];
            return route().current(`${section}.*`);
        }

        return false;
    } catch {
        return false;
    }
}

function routeExists(routeName) {
    try { route(routeName); return true; } catch { return false; }
}
</script>
