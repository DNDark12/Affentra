<template>
    <AppShell>
        <div class="flex flex-col gap-6 p-6">
            <div class="flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">
                        Kết nối nền tảng
                    </h1>
                    <div class="flex items-center gap-3">
                        <button
                            @click="syncAllConnections"
                            :disabled="syncAllDisabled"
                            class="h-[38px] px-4 rounded-lg border text-[13px] font-medium transition-colors"
                            :class="syncAllDisabled
                                ? 'border-[#E4E4E7] text-[#A1A1AA] bg-[#F4F4F5] cursor-not-allowed dark:border-zinc-700 dark:text-zinc-500 dark:bg-zinc-800'
                                : 'border-[#E4E4E7] text-[#18181B] bg-white hover:bg-[#F4F4F5] dark:border-zinc-700 dark:text-zinc-100 dark:bg-zinc-900 dark:hover:bg-zinc-800'"
                        >
                            {{ syncingAll ? 'Đang xử lý...' : 'Đồng bộ tất cả' }}
                        </button>
                        <button
                            @click="openNewConnectionModal"
                            class="h-[38px] px-4 rounded-lg bg-indigo-600 text-white text-[13px] font-medium hover:bg-indigo-700 shadow-sm transition-colors"
                        >
                            {{ addConnectionLabel }}
                        </button>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <p class="text-[13px] text-zinc-500 dark:text-zinc-400">
                        Quản lý Open API, đồng bộ tự động và lịch sử đồng bộ.
                    </p>
                    <span class="px-2 py-0.5 rounded text-[11px] bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 font-medium leading-none flex items-center h-[20px]">
                        Dữ liệu có thể trễ do sàn cập nhật theo chu kỳ.
                    </span>
                </div>
            </div>

            <div
                class="flex items-center gap-2 px-4 py-3 rounded-lg border"
                :class="calloutConfig.wrapperClass"
            >
                <i :class="['text-lg', calloutConfig.icon, calloutConfig.iconClass]"></i>
                <span class="text-[13px] font-medium" :class="calloutConfig.textClass">
                    {{ calloutConfig.message }}
                </span>
            </div>

            <div v-if="isEmpty" class="flex flex-col gap-4">
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-6 flex flex-col gap-4">
                    <div class="flex items-center gap-3">
                        <div class="h-11 w-11 rounded-xl bg-indigo-100 dark:bg-indigo-500/20 flex items-center justify-center">
                            <i class="ph ph-plugs text-lg text-indigo-700 dark:text-indigo-300"></i>
                        </div>
                        <div class="flex flex-col">
                            <h3 class="text-[16px] font-semibold text-zinc-900 dark:text-zinc-100">
                                Bắt đầu kết nối nền tảng affiliate đầu tiên
                            </h3>
                            <p class="text-[13px] text-zinc-500 dark:text-zinc-400">
                                Kết nối Shopee Open API để tự động đồng bộ dữ liệu click, đơn hàng và hoa hồng.
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            @click="connectShopeeNow"
                            class="h-9 px-4 rounded-lg bg-indigo-600 text-white text-[13px] font-medium hover:bg-indigo-700 transition-colors"
                        >
                            Kết nối Shopee ngay
                        </button>
                        <button
                            @click="showGuide"
                            class="h-9 px-4 rounded-lg border border-zinc-200 bg-white text-zinc-700 text-[13px] font-medium hover:bg-zinc-50 transition-colors dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        >
                            Xem hướng dẫn kết nối
                        </button>
                    </div>

                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="px-3 py-1.5 rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 text-[11px] font-medium">
                            1. Nhập App ID / Secret
                        </span>
                        <span class="px-3 py-1.5 rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 text-[11px] font-medium">
                            2. Test kết nối
                        </span>
                        <span class="px-3 py-1.5 rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 text-[11px] font-medium">
                            3. Bật Auto Sync
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div
                        v-for="platform in platformAvailability"
                        :key="platform.id"
                        class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-2"
                    >
                        <div class="flex items-center gap-2">
                            <div
                                class="h-7 w-7 rounded-lg flex items-center justify-center"
                                :style="{ backgroundColor: platform.color }"
                            >
                                <i :class="['ph text-white text-sm', platform.icon]"></i>
                            </div>
                            <span class="text-[13px] font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ platform.name }}
                            </span>
                        </div>
                        <span
                            class="text-[12px] font-medium"
                            :class="platform.ready ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400 dark:text-zinc-500'"
                        >
                            {{ platform.ready ? 'Sẵn sàng kết nối' : 'Coming soon' }}
                        </span>
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-4 flex flex-col gap-3">
                    <div class="flex items-center justify-between">
                        <h4 class="text-[13px] font-semibold text-zinc-900 dark:text-zinc-100">Lịch sử đồng bộ</h4>
                        <span class="text-[12px] text-zinc-400 dark:text-zinc-500">0 lượt chạy</span>
                    </div>
                    <div class="flex flex-col items-center gap-2 py-6">
                        <i class="ph ph-clock text-zinc-400 dark:text-zinc-500"></i>
                        <p class="text-[13px] font-medium text-zinc-600 dark:text-zinc-300">Chưa có lần đồng bộ nào</p>
                        <p class="text-[12px] text-zinc-400 dark:text-zinc-500">
                            Sau khi kết nối thành công, lịch sử sync sẽ hiển thị tại đây.
                        </p>
                    </div>
                </div>
            </div>

            <div v-else class="flex flex-col gap-4">
                <div
                    v-for="connection in displayedConnections"
                    :key="connection.id"
                    class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 shadow-sm overflow-hidden"
                >
                    <div class="px-5 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-start justify-between">
                        <div class="flex items-start gap-3">
                            <div
                                class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0"
                                :style="{ backgroundColor: getPlatform(connection.platform).color }"
                            >
                                <i :class="['ph text-xl text-white', getPlatform(connection.platform).icon]"></i>
                            </div>
                            <div class="flex flex-col gap-1">
                                <div class="flex items-center gap-2">
                                    <h3 class="font-semibold text-[15px] text-zinc-900 dark:text-zinc-100">
                                        {{ getPlatform(connection.platform).name }}
                                    </h3>
                                    <span
                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold"
                                        :class="connectionStatusBadgeClass(connection.status)"
                                    >
                                        <i :class="connectionStatusIcon(connection.status)"></i>
                                        {{ connectionStatusLabel(connection.status) }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span
                                        v-for="cap in capabilityBadges(connection)"
                                        :key="`${connection.id}-${cap}`"
                                        class="text-[11px] px-2 py-0.5 rounded bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 font-medium"
                                    >
                                        {{ cap }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <button
                                @click="secondaryAction(connection)"
                                class="h-8 px-3 rounded-md border border-zinc-200 bg-white text-zinc-700 text-[12px] font-medium hover:bg-zinc-50 transition-colors dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                :disabled="testingConnection === connection.id"
                            >
                                <i v-if="testingConnection === connection.id" class="ph ph-spinner animate-spin mr-1"></i>
                                {{ secondaryActionLabel(connection) }}
                            </button>
                            <button
                                @click="triggerSync(connection)"
                                :disabled="syncingConnection === connection.id"
                                class="h-8 px-3 rounded-md text-white text-[12px] font-medium transition-colors"
                                :class="connection.status === 'error'
                                    ? 'bg-zinc-900 hover:bg-black dark:bg-zinc-700 dark:hover:bg-zinc-600'
                                    : 'bg-indigo-600 hover:bg-indigo-700'"
                            >
                                <i v-if="syncingConnection === connection.id" class="ph ph-spinner animate-spin mr-1"></i>
                                {{ syncingConnection === connection.id ? 'Đang gọi...' : primaryActionLabel(connection) }}
                            </button>
                            <button
                                @click="openConfigModal(getPlatform(connection.platform), connection)"
                                class="h-8 px-3 rounded-md border border-zinc-200 text-zinc-600 bg-white hover:bg-zinc-50 transition-colors dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800 flex items-center gap-1.5 text-[12px] font-medium"
                            >
                                <i class="ph ph-gear-six"></i>
                                <span>Cấu hình</span>
                            </button>
                        </div>
                    </div>

                    <div class="px-5 py-4 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/70 dark:bg-zinc-800/40">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[11px] text-zinc-500 uppercase tracking-wide font-semibold">Last Synced</span>
                                <span class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">{{ timeAgo(connection.last_sync_at) }}</span>
                            </div>
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[11px] text-zinc-500 uppercase tracking-wide font-semibold">Next Run</span>
                                <span class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">{{ nextRunLabel(connection) }}</span>
                            </div>
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[11px] text-zinc-500 uppercase tracking-wide font-semibold">Backfill</span>
                                <span class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">{{ backfillLabel(connection) }}</span>
                            </div>
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[11px] text-zinc-500 uppercase tracking-wide font-semibold">App ID</span>
                                <span class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100 font-mono">{{ maskAppId(connection.app_id) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="px-5 py-4 flex flex-col gap-3">
                        <div class="flex items-center justify-between">
                            <h4 class="text-[13px] font-semibold text-zinc-900 dark:text-zinc-100">Lịch sử đồng bộ gần đây</h4>
                            <button
                                v-if="connection.sync_runs_count > getRecentRuns(connection).length"
                                type="button"
                                class="text-[12px] text-indigo-600 dark:text-indigo-400 font-medium hover:underline flex items-center gap-1"
                                @click="openHistoryModal(connection)"
                            >
                                {{ connection.status === 'error' ? 'Xem log lỗi' : 'Xem tất cả' }}
                                <i class="ph ph-arrow-right"></i>
                            </button>
                        </div>

                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 overflow-hidden bg-zinc-50/60 dark:bg-zinc-800/30">
                            <div v-if="!getRecentRuns(connection).length" class="text-center py-5 text-[12px] text-zinc-500 dark:text-zinc-400">
                                Chưa có lượt chạy nào.
                            </div>
                            <table v-else class="w-full text-left text-[12px]">
                                <tbody>
                                    <tr
                                        v-for="(run, index) in getRecentRuns(connection)"
                                        :key="run.id"
                                        class="border-zinc-200 dark:border-zinc-800"
                                        :class="{ 'border-b': index < getRecentRuns(connection).length - 1 }"
                                    >
                                        <td class="py-2.5 px-4 text-zinc-600 dark:text-zinc-400 w-[120px]">{{ formatTimeOnly(run.started_at) }}</td>
                                        <td class="py-2.5 px-4 text-zinc-600 dark:text-zinc-400 w-[80px]">{{ run.type === 'manual' ? 'Manual' : 'Auto' }}</td>
                                        <td class="py-2.5 px-4 w-[130px]">
                                            <div class="flex items-center gap-1.5 font-medium" :class="runStatusClass(run.status)">
                                                <i :class="runStatusIcon(run.status)"></i>
                                                <span>{{ runStatusLabel(run.status) }}</span>
                                            </div>
                                        </td>
                                        <td class="py-2.5 px-4 text-zinc-600 dark:text-zinc-400 text-right font-medium w-[100px]">
                                            {{ run.records_upserted ?? 0 }} / {{ run.records_fetched ?? 0 }}
                                        </td>
                                        <td class="py-2.5 px-4 text-right w-[70px]">
                                            <button
                                                @click="openRunDetailsDrawer(getPlatform(connection.platform), run, getRecentRuns(connection))"
                                                class="text-indigo-600 dark:text-indigo-400 hover:underline font-medium"
                                            >
                                                {{ runActionLabel(run.status) }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div
                    v-if="isSingle"
                    class="rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4 flex items-center justify-between"
                >
                    <div class="flex flex-col">
                        <h4 class="text-[14px] font-semibold text-zinc-900 dark:text-zinc-100">
                            Thêm kết nối thứ 2 để mở rộng nguồn dữ liệu
                        </h4>
                        <p class="text-[12px] text-zinc-500 dark:text-zinc-400">
                            Kết nối thêm nền tảng hoặc account phụ để đối soát và tránh gián đoạn sync.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            @click="showGuide"
                            class="h-8 px-3 rounded-md border border-zinc-200 bg-white text-zinc-700 text-[12px] font-medium hover:bg-zinc-50 transition-colors dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        >
                            Xem hướng dẫn
                        </button>
                        <button
                            @click="openNewConnectionModal"
                            class="h-8 px-3 rounded-md bg-indigo-600 text-white text-[12px] font-medium hover:bg-indigo-700 transition-colors"
                        >
                            Thêm kết nối
                        </button>
                    </div>
                </div>
            </div>
        </div>


        <DrawerHistory
            :isOpen="isRunDetailsOpen"
            :selectedRun="selectedDetailedRun"
            :runs="selectedDetailedRuns"
            :connectionInfo="selectedDetailedPlatform"
            @close="closeRunDetailsDrawer"
        />

        <DrawerConfig
            :isOpen="isConfigOpen"
            :platforms="platformConfigs"
            :allowedMethods="allowedMethods"
            :editConnection="editConnection"
            @close="closeConfigModal"
            @delete="deleteConnection"
        />

        <ModalUpload
            :isOpen="isUploadOpen"
            :connection="uploadConnection"
            @close="closeUploadModal"
        />
    </AppShell>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell.vue';
import DrawerHistory from './Partials/DrawerHistory.vue';
import DrawerConfig from './Partials/DrawerConfig.vue';
import ModalUpload from './Partials/ModalUpload.vue';

const props = defineProps({
    connections: {
        type: Array,
        default: () => [],
    },
    supportedPlatforms: {
        type: Array,
        default: () => ['shopee'],
    },
    allowedMethods: {
        type: Array,
        default: () => ['open_api', 'portal_export'],
    },
});

const platformCatalog = [
    { id: 'shopee', name: 'Shopee Vietnam', icon: 'ph-shopping-bag', color: '#EE4D2D' },
    { id: 'lazada', name: 'Lazada Affiliate', icon: 'ph-shopping-cart', color: '#0B1AA5' },
    { id: 'tiktok', name: 'TikTok Shop', icon: 'ph-play-square', color: '#111827' },
];

const platformConfigs = computed(() => {
    return platformCatalog.map((platform) => ({
        ...platform,
        supported: props.supportedPlatforms.includes(platform.id),
    }));
});

const allowedMethods = computed(() => Array.isArray(props.allowedMethods) ? props.allowedMethods : ['open_api', 'portal_export']);

const allConnections = computed(() => Array.isArray(props.connections) ? props.connections : []);
const connectionCount = computed(() => allConnections.value.length);
const isEmpty = computed(() => connectionCount.value === 0);
const isSingle = computed(() => connectionCount.value === 1);

const activeConnections = computed(() => {
    return allConnections.value.filter((connection) => connection.status === 'active');
});

const issueConnections = computed(() => {
    return allConnections.value.filter((connection) => connection.status !== 'active');
});

const displayedConnections = computed(() => {
    return [...allConnections.value].sort((left, right) => connectionStatusPriority(left.status) - connectionStatusPriority(right.status));
});

const addConnectionLabel = computed(() => isEmpty.value ? 'Kết nối đầu tiên' : 'Thêm kết nối');

const platformAvailability = computed(() => {
    return platformConfigs.value.map((platform) => ({
        ...platform,
        ready: platform.id === 'shopee' && platform.supported,
    }));
});

const calloutConfig = computed(() => {
    if (isEmpty.value) {
        return {
            icon: 'ph ph-warning-circle',
            iconClass: 'text-amber-700 dark:text-amber-400',
            textClass: 'text-amber-800 dark:text-amber-300',
            wrapperClass: 'bg-amber-50 border-amber-300 dark:bg-amber-500/10 dark:border-amber-500/30',
            message: 'Chưa có kết nối nào. Bắt đầu với Shopee để hệ thống có thể đồng bộ click, đơn và hoa hồng.',
        };
    }

    if (isSingle.value) {
        return {
            icon: 'ph ph-info',
            iconClass: 'text-indigo-700 dark:text-indigo-300',
            textClass: 'text-indigo-800 dark:text-indigo-200',
            wrapperClass: 'bg-indigo-50 border-indigo-200 dark:bg-indigo-500/10 dark:border-indigo-500/30',
            message: 'Bạn đang có 1 kết nối hoạt động. Thiết lập thêm kết nối để dự phòng và so sánh hiệu suất.',
        };
    }

    if (issueConnections.value.length > 0) {
        return {
            icon: 'ph ph-check-circle',
            iconClass: 'text-emerald-700 dark:text-emerald-300',
            textClass: 'text-emerald-800 dark:text-emerald-200',
            wrapperClass: 'bg-emerald-50 border-emerald-200 dark:bg-emerald-500/10 dark:border-emerald-500/30',
            message: `Đang có ${connectionCount.value} kết nối. ${activeConnections.value.length} hoạt động, ${issueConnections.value.length} kết nối cần xử lý.`,
        };
    }

    return {
        icon: 'ph ph-check-circle',
        iconClass: 'text-emerald-700 dark:text-emerald-300',
        textClass: 'text-emerald-800 dark:text-emerald-200',
        wrapperClass: 'bg-emerald-50 border-emerald-200 dark:bg-emerald-500/10 dark:border-emerald-500/30',
        message: `Tất cả ${connectionCount.value} kết nối đang hoạt động ổn định.`,
    };
});

const syncingAll = ref(false);
const testingConnection = ref(null);
const syncingConnection = ref(null);

const isConfigOpen = ref(false);
const editConnection = ref(null);

const isUploadOpen = ref(false);
const uploadConnection = ref(null);

const isRunDetailsOpen = ref(false);
const selectedDetailedRun = ref(null);
const selectedDetailedRuns = ref([]);
const selectedDetailedPlatform = ref(null);

const syncAllDisabled = computed(() => {
    return syncingAll.value || allConnections.value.length === 0;
});

function connectionStatusPriority(status) {
    if (status === 'active') return 0;
    if (status === 'error') return 1;
    if (status === 'inactive') return 2;
    return 3;
}

function getPlatform(platformId) {
    return platformConfigs.value.find((platform) => platform.id === platformId)
        || { id: platformId, name: platformId, icon: 'ph-plugs-connected', color: '#71717A', supported: false };
}

function connectionStatusLabel(status) {
    if (status === 'active') return 'Active';
    if (status === 'error') return 'Needs Re-auth';
    if (status === 'inactive') return 'Paused';
    if (status === 'disabled') return 'Disabled';
    if (status === 'expired') return 'Expired';
    return status || 'Unknown';
}

function connectionStatusIcon(status) {
    if (status === 'active') return 'ph ph-check-circle';
    if (status === 'error') return 'ph ph-warning-circle';
    if (status === 'inactive') return 'ph ph-pause-circle';
    return 'ph ph-minus-circle';
}

function connectionStatusBadgeClass(status) {
    if (status === 'active') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300';
    if (status === 'error') return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300';
    if (status === 'inactive') return 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300';
    return 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300';
}

function capabilityBadges(connection) {
    if (connection.method === 'portal_export') {
        return ['Manual Upload', 'SubId'];
    }

    if (connection.method === 'cookie') {
        return connection.status === 'error'
            ? ['Manual Sync (Beta)', 'Offers']
            : ['Auto Sync (Beta)', 'Offers'];
    }

    const capabilities = connection.capabilities || {};
    const badges = [];

    if (capabilities.supportsAutoSync ?? true) badges.push(connection.status === 'error' ? 'Manual Sync' : 'Auto Sync');
    if (capabilities.supportsOfferDiscovery ?? true) badges.push('Offers');

    return badges.slice(0, 2);
}

function maskAppId(appId) {
    if (!appId) return '--';
    return '*'.repeat(Math.max(appId.length - 4, 3)) + appId.slice(-4);
}

function timeAgo(isoString) {
    if (!isoString) return 'Chưa từng sync';
    const date = new Date(isoString);
    const minutes = Math.floor((Date.now() - date.getTime()) / 60000);
    if (minutes < 1) return 'Vừa xong';
    if (minutes < 60) return `${minutes} phút trước`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} giờ trước`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days} ngày trước`;
    return date.toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' });
}

function formatTimeOnly(isoString) {
    if (!isoString) return '--';
    return new Date(isoString).toLocaleTimeString('vi-VN', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    });
}

function nextRunLabel(connection) {
    if (connection.sync_mode !== 'scheduled' || connection.status === 'error') return 'Manual';
    return '15 phút nữa';
}

function backfillLabel(connection) {
    const days = Number(connection?.backfill_days_effective);
    if (!Number.isFinite(days) || days <= 0) return '--';
    return `${days} ngày`;
}

function runStatusLabel(status) {
    if (status === 'completed') return 'Completed';
    if (status === 'failed_auth') return 'Failed Auth';
    if (status === 'rate_limited') return 'Rate Limited';
    if (String(status).startsWith('failed')) return 'Failed';
    if (String(status).startsWith('skipped')) return 'Skipped';
    return status || 'Unknown';
}

function runStatusClass(status) {
    if (status === 'completed') return 'text-emerald-600 dark:text-emerald-400';
    if (status === 'rate_limited') return 'text-amber-600 dark:text-amber-400';
    if (String(status).startsWith('failed')) return 'text-red-500 dark:text-red-400';
    return 'text-zinc-600 dark:text-zinc-300';
}

function runStatusIcon(status) {
    if (status === 'completed') return 'ph ph-check-circle';
    if (status === 'rate_limited') return 'ph ph-warning-circle';
    if (String(status).startsWith('failed')) return 'ph ph-warning-circle';
    return 'ph ph-clock-counter-clockwise';
}

function runActionLabel(status) {
    if (String(status).startsWith('failed')) return 'Retry';
    return 'Chi tiết';
}

function normalizeRunPayload(run) {
    return {
        ...run,
        records_upserted: run.records_upserted ?? run.records_inserted ?? 0,
        finished_at: run.finished_at ?? run.completed_at ?? null,
    };
}

function getRecentRuns(connection) {
    const runs = Array.isArray(connection.recent_sync_runs) ? connection.recent_sync_runs : [];
    return runs.slice(0, 5).map(normalizeRunPayload);
}

function showGuide() {
    alert('Hướng dẫn chi tiết sẽ được cập nhật trong docs nội bộ.');
}

function connectShopeeNow() {
    const shopee = platformConfigs.value.find((platform) => platform.id === 'shopee' && platform.supported);
    if (!shopee) {
        alert('Shopee hiện chưa khả dụng để kết nối.');
        return;
    }
    openConfigModal(shopee, null);
}

function openNewConnectionModal() {
    const firstSupported = platformConfigs.value.find((platform) => platform.supported);
    if (!firstSupported) {
        alert('Hiện chưa có nền tảng nào khả dụng để kết nối.');
        return;
    }
    openConfigModal(firstSupported, null);
}

function openConfigModal(platform, existingConnection) {
    if (!existingConnection && !platform?.supported) {
        alert('Nền tảng này chưa được hỗ trợ kết nối API.');
        return;
    }
    editConnection.value = existingConnection;
    isConfigOpen.value = true;
}

function closeConfigModal() {
    isConfigOpen.value = false;
    editConnection.value = null;
}

function openUploadModal(connection) {
    uploadConnection.value = connection;
    isUploadOpen.value = true;
}

function closeUploadModal() {
    isUploadOpen.value = false;
    setTimeout(() => {
        uploadConnection.value = null;
    }, 300);
}

function deleteConnection(connection) {
    if (confirm('Bạn có chắc chắn muốn xóa kết nối này? Dữ liệu lịch sử sẽ không bị xóa.')) {
        router.delete(route('api.integrations.destroy', connection.id), {
            preserveScroll: true,
            onSuccess: () => closeConfigModal(),
        });
    }
}

function openRunDetailsDrawer(platform, run, runs = []) {
    const platformId = platform?.id ?? platform?.platform;
    selectedDetailedPlatform.value = getPlatform(platformId);
    selectedDetailedRun.value = normalizeRunPayload(run);
    selectedDetailedRuns.value = Array.isArray(runs) ? runs.map(normalizeRunPayload) : [];

    if (!selectedDetailedRuns.value.length && selectedDetailedRun.value) {
        selectedDetailedRuns.value = [selectedDetailedRun.value];
    }

    isRunDetailsOpen.value = true;
}

function closeRunDetailsDrawer() {
    isRunDetailsOpen.value = false;
    setTimeout(() => {
        selectedDetailedRun.value = null;
        selectedDetailedRuns.value = [];
        selectedDetailedPlatform.value = null;
    }, 300);
}

async function openHistoryModal(connection) {
    if (!connection) return;

    try {
        const response = await axios.get(route('api.integrations.history', connection.id), {
            params: { limit: 100 },
        });
        const runs = Array.isArray(response.data?.data) ? response.data.data.map(normalizeRunPayload) : [];
        if (!runs.length) {
            alert('Chưa có lịch sử đồng bộ cho kết nối này.');
            return;
        }
        openRunDetailsDrawer(getPlatform(connection.platform), runs[0], runs);
    } catch (error) {
        console.error('Load history failed', error);
        const fallbackRuns = getRecentRuns(connection);
        if (fallbackRuns.length) {
            openRunDetailsDrawer(getPlatform(connection.platform), fallbackRuns[0], fallbackRuns);
            return;
        }

        alert('Không thể tải lịch sử đồng bộ.');
    }
}

function secondaryActionLabel(connection) {
    if (connection.status === 'error') return 'Re-auth';
    if (connection.method === 'portal_export') return 'Upload Data';
    return 'Test kết nối';
}

function primaryActionLabel(connection) {
    if (connection.status === 'error') return 'Retry Sync';
    if (connection.method === 'portal_export') return 'Upload History';
    return 'Sync Now';
}

function secondaryAction(connection) {
    if (connection.status === 'error') {
        openConfigModal(getPlatform(connection.platform), connection);
        return;
    }
    if (connection.method === 'portal_export') {
        openUploadModal(connection);
        return;
    }
    testConnection(connection);
}

async function testConnection(connection) {
    if (!connection) return;

    testingConnection.value = connection.id;
    try {
        const response = await axios.post(route('api.integrations.test', connection.id));
        if (response.data?.ok && response.data?.data?.valid === true) {
            alert('Kết nối thành công!');
        } else {
            alert(`Lỗi: ${response.data?.message || 'Không thể xác thực credentials.'}`);
        }
    } catch (error) {
        alert(`Lỗi: ${error.response?.data?.message || 'Lỗi hệ thống.'}`);
    } finally {
        testingConnection.value = null;
    }
}

async function triggerSync(connection) {
    if (!connection) return;

    if (connection.method === 'portal_export') {
        openHistoryModal(connection);
        return;
    }

    syncingConnection.value = connection.id;
    try {
        await axios.post(route('api.integrations.sync', connection.id));
        router.reload({ only: ['connections'] });
    } catch (error) {
        console.error('Trigger sync failed', error);
        alert('Có lỗi khi kích hoạt đồng bộ.');
    } finally {
        syncingConnection.value = null;
    }
}

async function syncAllConnections() {
    if (syncAllDisabled.value) return;

    const targets = allConnections.value.filter((connection) => ['active', 'error'].includes(connection.status));
    if (!targets.length) {
        alert('Không có kết nối nào sẵn sàng để đồng bộ.');
        return;
    }

    syncingAll.value = true;
    let success = 0;
    let failed = 0;

    for (const connection of targets) {
        try {
            await axios.post(route('api.integrations.sync', connection.id));
            success += 1;
        } catch {
            failed += 1;
        }
    }

    syncingAll.value = false;
    router.reload({ only: ['connections'] });

    if (failed > 0) {
        alert(`Đã kích hoạt ${success} kết nối, ${failed} kết nối gặp lỗi.`);
        return;
    }

    alert(`Đã kích hoạt đồng bộ cho ${success} kết nối.`);
}
</script>
