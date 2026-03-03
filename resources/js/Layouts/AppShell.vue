<template>
    <div class="flex h-screen overflow-hidden" :style="{ backgroundColor: 'var(--app-bg)' }">
        <SyncBanner 
            v-if="syncStatus && syncStatus.status !== 'fresh'"
            :status="syncStatus.status" 
            :lastSyncAt="syncStatus.lastSyncAt" 
            :nextSyncIn="syncStatus.nextSyncIn" 
            @sync="handleGlobalSync"
            @retry="handleGlobalSync"
        />
        <Sidebar :navigation="navigation" />
        <div class="flex flex-col flex-1 min-w-0">
            <Topbar />
            <main class="flex-1 overflow-y-auto">
                <div :key="$page.url" class="animate-slide-up">
                    <slot />
                </div>
            </main>
        </div>
        <ToastStack />
        <AppDialog />
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    Link2,
    Tags,
    Rocket,
    TrendingUp,
    ShoppingCart,
    Users,
    Banknote,
    Settings2,
    BellRing,
    Sparkles,
} from 'lucide-vue-next';
import Sidebar from '@/Components/Layout/Sidebar.vue';
import Topbar  from '@/Components/Layout/Topbar.vue';
import SyncBanner from '@/Components/Layout/SyncBanner.vue';
import ToastStack from '@/Components/Feedback/ToastStack.vue';
import AppDialog from '@/Components/Feedback/AppDialog.vue';
import { router } from '@inertiajs/vue3';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const syncStatus = computed(() => page.props.sync_status || { status: 'fresh' });

function handleGlobalSync() {
    router.visit(route('integrations.index'));
}

const navigation = computed(() => {
    const role = user.value?.role;
    const items = [
        { label: 'Dashboard',      icon: LayoutDashboard, route: 'dashboard',         roles: ['owner','leader','ctv'] },
        { label: 'Integrations',   icon: Settings2,       route: 'integrations.index',roles: ['owner','leader'] },
        { label: 'Tracking Links', icon: Link2,           route: 'links.index',       roles: ['owner','leader','ctv'] },
        { label: 'Offers',         icon: Tags,            route: 'offers.index',      roles: ['owner','leader','ctv'] },
        { label: 'Campaigns',      icon: Rocket,          route: 'campaigns.index',   roles: ['owner','leader','ctv'] },
        { label: 'Orders',         icon: ShoppingCart,    route: 'orders.index',      roles: ['owner','leader','ctv'] },
        { label: 'Click Analysis', icon: TrendingUp,      route: 'clicks.index',      roles: ['owner','leader'] },
        { label: 'Partners',       icon: Users,           route: 'partners.index',    roles: ['owner','leader'] },
        { label: 'Finance',        icon: Banknote,        route: 'finance.index',     roles: ['owner'] },
        { label: 'Cảnh báo',       icon: BellRing,        route: 'alerts.index',      roles: ['owner','leader','ctv'] },
        { label: 'AI Content',      icon: Sparkles,        route: 'ai.content.index',  roles: ['owner','leader','ctv'] },
    ];
    return items.filter((item) => {
        if (role && !item.roles.includes(role)) {
            return false;
        }

        try {
            route(item.route);
            return true;
        } catch {
            return false;
        }
    });
});
</script>
