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
                Platform: {{ platformLabel }}
            </span>

            <button class="relative w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--surface-2)] transition-colors"
                    @click="goAlerts"
                    aria-label="Notifications">
                <Bell :size="16" :stroke-width="1.75" style="color: var(--text-secondary)" />
                <span
                    v-if="alertSummary.unseen_count > 0"
                    class="absolute -top-1 -right-1 min-w-4 h-4 px-1 rounded-full text-[10px] font-semibold flex items-center justify-center text-white"
                    style="background: var(--danger-text)"
                >
                    {{ alertSummary.unseen_count > 9 ? '9+' : alertSummary.unseen_count }}
                </span>
            </button>

            <button @click="toggleTheme"
                    class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--surface-2)] transition-colors"
                    aria-label="Toggle theme">
                <Sun  v-if="isDark" :size="16" :stroke-width="1.75" style="color: var(--text-secondary)" />
                <Moon v-else        :size="16" :stroke-width="1.75" style="color: var(--text-secondary)" />
            </button>

            <Link
                    :href="route('profile.edit')"
                    class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold text-white"
                    title="Hồ sơ tài khoản"
                    style="background-color: var(--color-primary-500)">
                {{ initials }}
            </Link>
        </div>
    </header>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell, Sun, Moon } from 'lucide-vue-next';
import { applyTheme, getCurrentTheme, resolveThemePreference } from '@/Utils/theme';

const page   = usePage();
const user   = computed(() => page.props.auth?.user);
const isDark = ref(false);

onMounted(() => {
    const initialTheme = resolveThemePreference();
    applyTheme(initialTheme);
    isDark.value = getCurrentTheme() === 'dark';
});

const ROUTE_TITLES = {
    'dashboard':                   'Dashboard',
    'integrations.index':          'Tích hợp kết nối',
    'links.index':                 'Tracking Links',
    'links.show':                  'Chi tiết Link',
    'offers.index':                'Offers',
    'offers.show':                 'Chi tiết Offer',
    'campaigns.index':             'Campaigns',
    'orders.index':                'Orders',
    'clicks.index':                'Click Analytics',
    'clicks.report':               'Báo cáo Click',
    'clicks.conversion':           'Tỷ lệ chuyển đổi',
    'partners.index':              'Partners',
    'partners.show':               'Chi tiết Partner',
    'finance.index':               'Finance',
    'finance.payout-batches.index':'Payout Batches',
    'finance.payout-batches.show': 'Chi tiết Payout Batch',
    'alerts.index':                'Cảnh báo',
    'alerts.rules':                'Quy tắc cảnh báo',
    'alerts.templates':            'Mẫu thông báo',
    'alerts.telegram-config':      'Cấu hình Telegram',
    'alerts.incidents.show':       'Chi tiết sự cố',
    'profile.edit':                'Hồ sơ tài khoản',
    'settings.ai':                 'AI Provider',
};

const pageTitle = computed(() => {
    const currentRoute = route().current();
    if (currentRoute && ROUTE_TITLES[currentRoute]) {
        return ROUTE_TITLES[currentRoute];
    }
    // Fallback: capitalise last URL segment
    const seg = page.props.ziggy?.location?.split('/').filter(Boolean).pop() || 'Dashboard';
    return seg.charAt(0).toUpperCase() + seg.slice(1).replace(/-/g, ' ');
});

const alertSummary = computed(() => page.props.alerts || { unseen_count: 0 });
const platformLabel = computed(() => page.props.ui?.current_platform_label || 'Shopee');

const initials = computed(() =>
    (user.value?.name || 'U').split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase()
);

function toggleTheme() {
    const nextTheme = isDark.value ? 'light' : 'dark';
    applyTheme(nextTheme);
    isDark.value = getCurrentTheme() === 'dark';
}

function goAlerts() {
    router.visit(route('alerts.index'));
}

</script>
