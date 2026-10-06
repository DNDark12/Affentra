<template>
    <AppShell>
        <div class="flex flex-col gap-5 p-6">
            <div class="flex flex-col gap-1">
                <p class="text-xs" style="color: var(--text-muted)">Quản lý / Integrations</p>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight" style="color: var(--text-primary)">Kết nối nền tảng</h1>
                        <p class="mt-1 text-sm" style="color: var(--text-secondary)">
                            Quản lý nhiều kết nối theo dạng card. Mở chi tiết để xem lịch sử và thao tác nâng cao.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="af-btn-outline text-sm h-9 px-3"
                            :disabled="syncAllDisabled"
                            @click="syncAllConnections"
                        >
                            <Loader2 v-if="syncingAll" :size="14" class="animate-spin" />
                            <span v-else>Đồng bộ tất cả</span>
                        </button>
                        <button
                            type="button"
                            class="af-btn-primary text-sm h-9 px-4 inline-flex items-center gap-1.5"
                            @click="openNewConnectionModal"
                        >
                            <Plus :size="14" />
                            {{ addConnectionLabel }}
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-if="uiFeedback"
                class="flex items-start justify-between gap-3 rounded-lg border px-4 py-3"
                :class="feedbackClasses(uiFeedback.type)"
            >
                <div class="flex items-start gap-2">
                    <component :is="feedbackIcon(uiFeedback.type)" :size="15" class="mt-0.5 shrink-0" />
                    <div class="text-[13px]">
                        <p class="font-medium">{{ uiFeedback.message }}</p>
                        <p v-if="uiFeedback.details" class="mt-0.5 text-[12px] opacity-80 whitespace-pre-line">{{ uiFeedback.details }}</p>
                    </div>
                </div>
                <button type="button" class="text-[12px] opacity-70 hover:opacity-100" @click="uiFeedback = null">Đóng</button>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="stat in summaryStats"
                    :key="stat.key"
                    class="af-surface p-4"
                >
                    <p class="text-xs uppercase tracking-wide" style="color: var(--text-muted)">{{ stat.label }}</p>
                    <p class="mt-1 text-2xl font-semibold tracking-tight" :style="{ color: stat.valueColor }">{{ stat.value }}</p>
                    <p class="mt-1 text-xs" style="color: var(--text-secondary)">{{ stat.note }}</p>
                </div>
            </div>

            <div class="af-surface p-4 grid gap-3 xl:grid-cols-12 xl:items-center">
                <div class="relative xl:col-span-5">
                    <Search :size="14" class="absolute left-3 top-1/2 -translate-y-1/2" style="color: var(--text-muted)" />
                    <input
                        v-model.trim="searchKeyword"
                        type="text"
                        class="af-input af-input-search h-10 text-sm"
                        placeholder="Tìm theo label, nền tảng, method..."
                    />
                </div>

                <select v-model="statusFilter" class="af-input h-10 text-sm xl:col-span-2" data-no-tom-select="1">
                    <option value="all">Tất cả trạng thái</option>
                    <option value="active">Đang hoạt động</option>
                    <option value="error">Cần xử lý</option>
                    <option value="inactive">Tạm dừng</option>
                    <option value="disabled">Đã vô hiệu</option>
                    <option value="expired">Hết hạn</option>
                </select>

                <select v-model="methodFilter" class="af-input h-10 text-sm xl:col-span-2" data-no-tom-select="1">
                    <option value="all">Tất cả phương thức</option>
                    <option value="open_api">Open API</option>
                    <option value="cookie">Cookie</option>
                    <option value="portal_export">Portal Export</option>
                </select>

                <select v-model="sortBy" class="af-input h-10 text-sm xl:col-span-3" data-no-tom-select="1">
                    <option value="status_priority">Ưu tiên trạng thái</option>
                    <option value="updated_desc">Sync gần nhất (mới nhất)</option>
                    <option value="updated_asc">Sync gần nhất (cũ nhất)</option>
                    <option value="label_asc">Tên kết nối (A-Z)</option>
                    <option value="label_desc">Tên kết nối (Z-A)</option>
                </select>
            </div>

            <div v-if="filteredConnections.length === 0" class="af-surface p-8 text-center">
                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl" style="background: var(--surface-2)">
                    <Plug2 :size="20" style="color: var(--text-muted)" />
                </div>
                <p class="text-sm font-medium" style="color: var(--text-primary)">
                    {{ allConnections.length === 0 ? 'Chưa có kết nối nào' : 'Không có kết quả phù hợp' }}
                </p>
                <p class="mt-1 text-xs" style="color: var(--text-muted)">
                    {{ allConnections.length === 0
                        ? 'Thêm kết nối đầu tiên để bắt đầu đồng bộ dữ liệu affiliate.'
                        : 'Thử thay đổi bộ lọc hoặc từ khóa tìm kiếm.' }}
                </p>
                <button
                    v-if="allConnections.length === 0"
                    type="button"
                    class="af-btn-primary mt-4 h-9 px-4 text-sm"
                    @click="openNewConnectionModal"
                >
                    Kết nối đầu tiên
                </button>
            </div>

            <div v-else class="flex flex-col gap-3">
                <div
                    v-if="allConnections.length === 1"
                    class="af-surface p-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
                >
                    <div>
                        <p class="text-sm font-semibold" style="color: var(--text-primary)">Thêm kết nối thứ 2 để mở rộng nguồn dữ liệu</p>
                        <p class="text-xs mt-1" style="color: var(--text-secondary)">
                            Kết nối thêm nền tảng hoặc account phụ để đối soát và tránh gián đoạn sync.
                        </p>
                    </div>
                    <button type="button" class="af-btn-primary h-9 px-4 text-sm" @click="openNewConnectionModal">
                        Thêm kết nối
                    </button>
                </div>

                <div class="flex items-center justify-between text-xs px-1" style="color: var(--text-secondary)">
                    <span>Hiển thị {{ paginatedConnections.length }} / {{ filteredConnections.length }} kết nối</span>
                    <span>Trang {{ currentPage }} / {{ totalPages }}</span>
                </div>

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="connection in paginatedConnections"
                    :key="connection.id"
                    class="af-surface p-5 flex h-full flex-col gap-4 transition-shadow hover:shadow-md"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3 min-w-0">
                            <div
                                class="h-10 w-10 rounded-xl flex items-center justify-center shrink-0 text-white"
                                :style="{ backgroundColor: getPlatform(connection.platform).color }"
                            >
                                <component :is="getPlatform(connection.platform).icon" :size="18" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold truncate" style="color: var(--text-primary)">
                                    {{ connection.label || getPlatform(connection.platform).name }}
                                </p>
                                <p class="text-xs mt-0.5" style="color: var(--text-muted)">
                                    {{ getPlatform(connection.platform).name }}
                                </p>
                                <div class="mt-2 flex items-center gap-1.5 flex-wrap">
                                    <span class="text-[11px] px-2 py-0.5 rounded-full font-medium" :class="statusPillClass(effectiveConnectionStatus(connection))">
                                        {{ statusLabel(effectiveConnectionStatus(connection)) }}
                                    </span>
                                    <span class="text-[11px] px-2 py-0.5 rounded-full font-medium" :class="methodPillClass(connection.method)">
                                        {{ methodLabel(connection.method) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="integration-card-setting-btn h-8 w-8 rounded-lg border flex items-center justify-center transition-colors cursor-pointer"
                            style="border-color: var(--border); color: var(--text-secondary)"
                            @click.stop="openConfigModal(getPlatform(connection.platform), connection)"
                            title="Cấu hình"
                        >
                            <Settings :size="14" />
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-3 text-xs">
                        <div class="rounded-lg p-2" style="background: var(--surface-2)">
                            <p style="color: var(--text-muted)">Lần sync gần nhất</p>
                            <p class="mt-1 font-medium" style="color: var(--text-primary)">{{ timeAgo(connection.last_sync_at) }}</p>
                        </div>
                        <div class="rounded-lg p-2" style="background: var(--surface-2)">
                            <p style="color: var(--text-muted)">Chế độ sync</p>
                            <p class="mt-1 font-medium" style="color: var(--text-primary)">{{ nextRunLabel(connection) }}</p>
                        </div>
                        <div class="rounded-lg p-2" style="background: var(--surface-2)">
                            <p style="color: var(--text-muted)">Backfill</p>
                            <p class="mt-1 font-medium" style="color: var(--text-primary)">{{ backfillLabel(connection) }}</p>
                        </div>
                        <div class="rounded-lg p-2" style="background: var(--surface-2)">
                            <p style="color: var(--text-muted)">Sync status</p>
                            <p class="mt-1 font-medium" style="color: var(--text-primary)">{{ runStatusLabel(connection.last_sync_status) }}</p>
                        </div>
                    </div>

                    <div class="min-h-[50px]">
                        <div
                            v-if="connection.last_error"
                            class="integration-card-error rounded-lg border px-3 py-2 text-xs"
                            style="border-color: color-mix(in srgb, var(--color-danger) 40%, var(--border)); background: color-mix(in srgb, var(--danger-bg) 70%, transparent); color: var(--danger-text)"
                        >
                            {{ truncate(connection.last_error, 180) }}
                        </div>
                    </div>

                    <div class="mt-auto pt-2 border-t flex items-center gap-2" style="border-color: var(--border)">
                        <button
                            type="button"
                            class="af-btn-outline h-8 px-3 text-xs flex-1 flex items-center justify-center gap-1"
                            @click.stop="openDetail(connection)"
                        >
                            <ArrowRight :size="12" />
                            Chi tiết
                        </button>

                        <button
                            type="button"
                            class="af-btn-outline h-8 px-3 text-xs"
                            :disabled="testingConnection === connection.id"
                            @click.stop="secondaryAction(connection)"
                        >
                            <Loader2 v-if="testingConnection === connection.id" :size="12" class="animate-spin" />
                            <span v-else>{{ secondaryActionLabel(connection) }}</span>
                        </button>

                        <button
                            type="button"
                            class="af-btn-primary h-8 px-3 text-xs"
                            :disabled="syncingConnection === connection.id"
                            @click.stop="triggerSync(connection)"
                        >
                            <Loader2 v-if="syncingConnection === connection.id" :size="12" class="animate-spin" />
                            <span v-else>{{ primaryActionLabel(connection) }}</span>
                        </button>
                    </div>
                </div>
                </div>

                <div v-if="totalPages > 1" class="af-surface p-3 flex items-center justify-between gap-3">
                    <button
                        type="button"
                        class="af-btn-outline h-8 px-3 text-xs"
                        :disabled="currentPage <= 1"
                        @click="goToPage(currentPage - 1)"
                    >
                        Trang trước
                    </button>
                    <p class="text-xs" style="color: var(--text-secondary)">
                        Trang {{ currentPage }} / {{ totalPages }}
                    </p>
                    <button
                        type="button"
                        class="af-btn-outline h-8 px-3 text-xs"
                        :disabled="currentPage >= totalPages"
                        @click="goToPage(currentPage + 1)"
                    >
                        Trang sau
                    </button>
                </div>
            </div>

            <Teleport to="body">
                <DrawerConfig
                    :isOpen="isConfigOpen"
                    :platforms="platformConfigs"
                    :allowedMethods="allowedMethods"
                    :editConnection="editConnection"
                    @close="closeConfigModal"
                    @delete="deleteConnection"
                />
            </Teleport>

            <Teleport to="body">
                <ModalUpload
                    :isOpen="isUploadOpen"
                    :connection="uploadConnection"
                    @close="closeUploadModal"
                />
            </Teleport>
        </div>
    </AppShell>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    ShoppingBag,
    ShoppingCart,
    PlaySquare,
    Plug2,
    ArrowRight,
    Settings,
    Loader2,
    AlertTriangle,
    CheckCircle2,
    Clock,
    Search,
    Plus,
} from 'lucide-vue-next';
import AppShell from '@/Layouts/AppShell.vue';
import DrawerConfig from './Partials/DrawerConfig.vue';
import ModalUpload from './Partials/ModalUpload.vue';
import { useDialog } from '@/Composables/useDialog';

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

const { confirmDialog } = useDialog();

function cloneConnections(input) {
    return JSON.parse(JSON.stringify(Array.isArray(input) ? input : []));
}

const platformCatalog = [
    { id: 'shopee', name: 'Shopee Vietnam', icon: ShoppingBag, color: '#EE4D2D' },
    { id: 'lazada', name: 'Lazada Affiliate', icon: ShoppingCart, color: '#0B1AA5' },
    { id: 'tiktok', name: 'TikTok Shop', icon: PlaySquare, color: '#111827' },
];

const platformConfigs = computed(() => {
    return platformCatalog.map((platform) => ({
        ...platform,
        supported: props.supportedPlatforms.includes(platform.id),
    }));
});

const allowedMethods = computed(() => Array.isArray(props.allowedMethods) ? props.allowedMethods : ['open_api', 'portal_export']);

const connectionsState = ref(cloneConnections(props.connections));
watch(
    () => props.connections,
    (next) => {
        connectionsState.value = cloneConnections(next);
    },
    { deep: true },
);

const searchKeyword = ref('');
const statusFilter = ref('all');
const methodFilter = ref('all');
const sortBy = ref('status_priority');
const currentPage = ref(1);
const PAGE_SIZE = 9;

const syncingAll = ref(false);
const syncingConnection = ref(null);
const testingConnection = ref(null);

const isConfigOpen = ref(false);
const editConnection = ref(null);

const isUploadOpen = ref(false);
const uploadConnection = ref(null);

const uiFeedback = ref(null);

const allConnections = computed(() => Array.isArray(connectionsState.value) ? connectionsState.value : []);

const filteredConnections = computed(() => {
    const keyword = searchKeyword.value.trim().toLowerCase();

    return allConnections.value.filter((connection) => {
        const effectiveStatus = effectiveConnectionStatus(connection);

        if (statusFilter.value !== 'all' && effectiveStatus !== statusFilter.value) {
            return false;
        }

        if (methodFilter.value !== 'all' && connection.method !== methodFilter.value) {
            return false;
        }

        if (!keyword) {
            return true;
        }

        const platformName = getPlatform(connection.platform).name.toLowerCase();
        const haystack = [
            connection.label,
            connection.platform,
            platformName,
            connection.method,
            connection.status,
            effectiveStatus,
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        return haystack.includes(keyword);
    });
});

const sortedConnections = computed(() => {
    const items = [...filteredConnections.value];

    if (sortBy.value === 'updated_desc') {
        return items.sort((left, right) => lastSyncTimestamp(right) - lastSyncTimestamp(left));
    }

    if (sortBy.value === 'updated_asc') {
        return items.sort((left, right) => lastSyncTimestamp(left) - lastSyncTimestamp(right));
    }

    if (sortBy.value === 'label_asc') {
        return items.sort((left, right) => displayLabel(left).localeCompare(displayLabel(right), 'vi', { sensitivity: 'base' }));
    }

    if (sortBy.value === 'label_desc') {
        return items.sort((left, right) => displayLabel(right).localeCompare(displayLabel(left), 'vi', { sensitivity: 'base' }));
    }

    return items.sort((left, right) => {
        const statusDiff = statusPriority(effectiveConnectionStatus(left)) - statusPriority(effectiveConnectionStatus(right));
        if (statusDiff !== 0) return statusDiff;
        return lastSyncTimestamp(right) - lastSyncTimestamp(left);
    });
});

const totalPages = computed(() => {
    if (sortedConnections.value.length === 0) return 1;
    return Math.max(1, Math.ceil(sortedConnections.value.length / PAGE_SIZE));
});

const paginatedConnections = computed(() => {
    const start = (currentPage.value - 1) * PAGE_SIZE;
    return sortedConnections.value.slice(start, start + PAGE_SIZE);
});

const eligibleSyncConnections = computed(() => {
    return allConnections.value.filter((connection) => {
        if (connection.method === 'portal_export') return false;

        return ['active', 'error'].includes(connection.status);
    });
});

const syncAllDisabled = computed(() => syncingAll.value || eligibleSyncConnections.value.length === 0);
const addConnectionLabel = computed(() => allConnections.value.length === 0 ? 'Kết nối đầu tiên' : 'Thêm kết nối');
const summaryStats = computed(() => {
    const total = allConnections.value.length;
    const active = allConnections.value.filter((connection) => !hasConnectionIssue(connection)).length;
    const attention = allConnections.value.filter((connection) => hasConnectionIssue(connection)).length;
    const scheduled = allConnections.value.filter((connection) => connection.sync_mode === 'scheduled').length;

    return [
        {
            key: 'total',
            label: 'Tổng kết nối',
            value: total,
            valueColor: 'var(--text-primary)',
            note: 'Số kết nối hiện có trong hệ thống',
        },
        {
            key: 'active',
            label: 'Đang hoạt động',
            value: active,
            valueColor: 'var(--success-text)',
            note: 'Kết nối có thể sync ngay',
        },
        {
            key: 'attention',
            label: 'Cần xử lý',
            value: attention,
            valueColor: 'var(--warning-text)',
            note: 'Có lỗi trạng thái hoặc lỗi đồng bộ gần nhất',
        },
        {
            key: 'scheduled',
            label: 'Auto sync',
            value: scheduled,
            valueColor: 'var(--color-info)',
            note: 'Kết nối có lịch chạy định kỳ',
        },
    ];
});

watch(
    [searchKeyword, statusFilter, methodFilter, sortBy],
    () => {
        currentPage.value = 1;
    },
);

watch(
    totalPages,
    (nextTotal) => {
        if (currentPage.value > nextTotal) {
            currentPage.value = nextTotal;
        }
    },
);

function goToPage(page) {
    if (!Number.isFinite(page)) return;
    if (page < 1 || page > totalPages.value) return;
    currentPage.value = page;
}

function getPlatform(platformId) {
    return platformConfigs.value.find((platform) => platform.id === platformId)
        || { id: platformId, name: platformId, icon: Plug2, color: '#71717A', supported: false };
}

function statusLabel(status) {
    if (status === 'active') return 'Active';
    if (status === 'error') return 'Cần xử lý';
    if (status === 'inactive') return 'Paused';
    if (status === 'disabled') return 'Disabled';
    if (status === 'expired') return 'Expired';
    return status || 'Unknown';
}

function statusPillClass(status) {
    if (status === 'active') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300';
    if (status === 'error') return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300';
    if (status === 'inactive') return 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300';
    return 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300';
}

function methodLabel(method) {
    if (method === 'open_api') return 'Open API';
    if (method === 'cookie') return 'Cookie';
    if (method === 'portal_export') return 'Portal';
    return method || '--';
}

function methodPillClass(method) {
    if (method === 'open_api') return 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300';
    if (method === 'cookie') return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300';
    if (method === 'portal_export') return 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-300';
    return 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300';
}

function runStatusLabel(status) {
    if (status === 'pending') return 'Queued';
    if (status === 'processing') return 'Processing';
    if (status === 'completed') return 'Completed';
    if (status === 'completed_with_warnings') return 'Warnings';
    if (status === 'failed_auth') return 'Failed Auth';
    if (status === 'rate_limited') return 'Rate Limited';
    if (String(status || '').startsWith('failed')) return 'Failed';
    if (String(status || '').startsWith('skipped')) return 'Skipped';
    return status || 'Unknown';
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

function nextRunLabel(connection) {
    if (connection.sync_mode !== 'scheduled' || hasConnectionIssue(connection)) return 'Manual';

    if (connection.sync_interval === 'daily') {
        const time = connection.sync_time || '00:00';
        return `Daily ${time}`;
    }

    const intervalMap = {
        '15m': '15 phút',
        '1h': '1 tiếng',
        '3h': '3 tiếng',
        '8h': '8 tiếng',
    };

    const interval = connection.sync_interval || '15m';

    return intervalMap[interval] || interval;
}

function backfillLabel(connection) {
    const days = Number(connection?.backfill_days_effective);
    if (!Number.isFinite(days) || days <= 0) return '--';

    return `${days} ngày`;
}

function feedbackClasses(type) {
    if (type === 'success') return 'bg-emerald-50 border-emerald-200 text-emerald-800 dark:bg-emerald-500/10 dark:border-emerald-500/30 dark:text-emerald-200';
    if (type === 'error') return 'bg-red-50 border-red-200 text-red-800 dark:bg-red-500/10 dark:border-red-500/30 dark:text-red-200';
    if (type === 'warning') return 'bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-500/10 dark:border-amber-500/30 dark:text-amber-200';
    return 'bg-indigo-50 border-indigo-200 text-indigo-800 dark:bg-indigo-500/10 dark:border-indigo-500/30 dark:text-indigo-200';
}

function feedbackIcon(type) {
    if (type === 'success') return CheckCircle2;
    if (type === 'error') return AlertTriangle;
    if (type === 'warning') return AlertTriangle;
    return Clock;
}

function showFeedback(type, message, details = '') {
    uiFeedback.value = { type, message, details };
}

function truncate(value, maxLength = 140) {
    if (!value || value.length <= maxLength) return value;
    return `${value.slice(0, maxLength).trimEnd()}...`;
}

function statusPriority(status) {
    if (status === 'active') return 0;
    if (status === 'error') return 1;
    if (status === 'inactive') return 2;
    if (status === 'disabled') return 3;
    if (status === 'expired') return 4;
    return 5;
}

function displayLabel(connection) {
    return String(connection?.label || getPlatform(connection?.platform).name || '').trim();
}

function lastSyncTimestamp(connection) {
    const value = connection?.last_sync_at;
    if (!value) return 0;
    const timestamp = new Date(value).getTime();
    return Number.isFinite(timestamp) ? timestamp : 0;
}

function hasConnectionIssue(connection) {
    if (!connection) return false;
    if (connection.status !== 'active') return true;
    if (String(connection.last_error || '').trim() !== '') return true;

    const lastSyncStatus = String(connection.last_sync_status || '');
    if (lastSyncStatus.startsWith('failed')) return true;
    if (lastSyncStatus === 'rate_limited') return true;
    if (lastSyncStatus === 'completed_with_warnings') return true;

    return false;
}

function effectiveConnectionStatus(connection) {
    if (hasConnectionIssue(connection)) return 'error';
    return connection?.status || 'unknown';
}

function patchConnection(connectionId, payload) {
    const index = allConnections.value.findIndex((item) => item.id === connectionId);
    if (index < 0) return;

    const next = [...allConnections.value];
    next[index] = { ...next[index], ...payload };
    connectionsState.value = next;
}

function openDetail(connection) {
    router.visit(route('integrations.show', connection.id));
}

function openNewConnectionModal() {
    const firstSupported = platformConfigs.value.find((platform) => platform.supported);
    if (!firstSupported) {
        showFeedback('error', 'Hiện chưa có nền tảng nào khả dụng để kết nối.');
        return;
    }

    openConfigModal(firstSupported, null);
}

function openConfigModal(platform, existingConnection) {
    if (!existingConnection && !platform?.supported) {
        showFeedback('error', 'Nền tảng này chưa được hỗ trợ kết nối API.');
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
    }, 200);
}

function primaryActionLabel(connection) {
    if (connection.method === 'portal_export') return 'Upload';
    return 'Sync ngay';
}

function secondaryActionLabel(connection) {
    if (connection.method === 'portal_export') return 'Upload';
    return 'Test';
}

function secondaryAction(connection) {
    if (connection.method === 'portal_export') {
        openUploadModal(connection);
        return;
    }

    void testConnection(connection);
}

async function syncAllConnections() {
    if (syncAllDisabled.value) return;

    const targets = eligibleSyncConnections.value;
    syncingAll.value = true;

    try {
        const results = await Promise.allSettled(
            targets.map((connection) => axios.post(route('api.integrations.sync', connection.id))),
        );

        const successCount = results.filter((item) => item.status === 'fulfilled').length;
        const failedCount = results.length - successCount;

        showFeedback(
            failedCount > 0 ? 'warning' : 'success',
            `Đã gửi ${successCount}/${results.length} lệnh đồng bộ.`,
            failedCount > 0 ? `${failedCount} kết nối chưa gửi được, vui lòng kiểm tra từng card.` : '',
        );

        await router.reload({ only: ['connections'], preserveScroll: true, preserveState: true });
    } catch (error) {
        showFeedback('error', 'Không thể đồng bộ hàng loạt.', error.response?.data?.message || 'Vui lòng thử lại.');
    } finally {
        syncingAll.value = false;
    }
}

async function triggerSync(connection) {
    if (connection.method === 'portal_export') {
        openUploadModal(connection);
        return;
    }

    syncingConnection.value = connection.id;

    try {
        const response = await axios.post(route('api.integrations.sync', connection.id));
        showFeedback('success', response.data?.message || 'Đã đưa sync vào queue.');

        patchConnection(connection.id, {
            last_sync_status: 'pending',
            status: connection.status === 'error' ? 'active' : connection.status,
        });

        await router.reload({ only: ['connections'], preserveScroll: true, preserveState: true });
    } catch (error) {
        showFeedback('error', 'Không thể bắt đầu đồng bộ.', error.response?.data?.message || 'Vui lòng thử lại.');
    } finally {
        syncingConnection.value = null;
    }
}

async function testConnection(connection) {
    testingConnection.value = connection.id;

    try {
        const response = await axios.post(route('api.integrations.test', connection.id));
        const valid = !!response.data?.data?.valid;

        if (valid) {
            showFeedback('success', response.data?.message || 'Kết nối hợp lệ.');
        } else {
            showFeedback('warning', response.data?.message || 'Kết nối chưa hợp lệ.');
        }

        await router.reload({ only: ['connections'], preserveScroll: true, preserveState: true });
    } catch (error) {
        showFeedback('error', 'Test kết nối thất bại.', error.response?.data?.message || 'Vui lòng thử lại.');
    } finally {
        testingConnection.value = null;
    }
}

async function deleteConnection(connection) {
    const confirmed = await confirmDialog({
        variant: 'danger',
        title: 'Xóa kết nối?',
        description: 'Kết nối sẽ bị xóa khỏi hệ thống. Dữ liệu lịch sử đã đồng bộ sẽ không bị xóa.',
        confirmText: 'Xóa kết nối',
        cancelText: 'Giữ lại',
    });

    if (!confirmed) return;

    try {
        await axios.delete(route('api.integrations.destroy', connection.id));
        closeConfigModal();
        showFeedback('success', 'Đã xóa kết nối thành công.');
        await router.reload({ only: ['connections'], preserveScroll: true, preserveState: true });
    } catch (error) {
        showFeedback('error', 'Không thể xóa kết nối.', error.response?.data?.message || 'Vui lòng thử lại.');
    }
}
</script>

<style scoped>
.integration-card-setting-btn {
    cursor: pointer;
}

.integration-card-setting-btn:hover {
    background: color-mix(in srgb, var(--surface-2) 80%, var(--surface));
    border-color: color-mix(in srgb, var(--border) 55%, var(--text-primary));
    color: var(--text-primary) !important;
}

.integration-card-error {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
