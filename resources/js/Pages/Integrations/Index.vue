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
                <component :is="calloutConfig.icon" :size="16" :class="calloutConfig.iconClass" />
                <span class="text-[13px] font-medium" :class="calloutConfig.textClass">
                    {{ calloutConfig.message }}
                </span>
            </div>

            <div
                v-if="uiFeedback"
                class="flex items-start justify-between gap-3 px-4 py-3 rounded-lg border"
                :class="feedbackClasses(uiFeedback.type)"
            >
                <div class="flex items-start gap-2">
                    <component :is="feedbackIcon(uiFeedback.type)" :size="15" class="mt-0.5 shrink-0" />
                    <div class="text-[13px]">
                        <p class="font-medium">{{ uiFeedback.message }}</p>
                        <p v-if="uiFeedback.details" class="mt-0.5 opacity-80 whitespace-pre-line text-[12px]">{{ uiFeedback.details }}</p>
                    </div>
                </div>
                <button
                    type="button"
                    class="text-[12px] opacity-70 hover:opacity-100"
                    @click="uiFeedback = null"
                >
                    Đóng
                </button>
            </div>

            <div v-if="isEmpty" class="flex flex-col gap-4">
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 p-6 flex flex-col gap-4">
                    <div class="flex items-center gap-3">
                        <div class="h-11 w-11 rounded-xl bg-indigo-100 dark:bg-indigo-500/20 flex items-center justify-center">
                            <Plug2 :size="20" class="text-indigo-700 dark:text-indigo-300" />
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
                                <component :is="platform.icon" :size="14" class="text-white" />
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
                        <Clock :size="16" class="text-zinc-400 dark:text-zinc-500" />
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
                                <component :is="getPlatform(connection.platform).icon" :size="20" class="text-white" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <div class="flex items-center gap-2">
                                    <h3 class="font-semibold text-[15px] text-zinc-900 dark:text-zinc-100">
                                        {{ connection.label || getPlatform(connection.platform).name }}
                                    </h3>
                                    <span
                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold"
                                        :class="connectionStatusBadgeClass(connection.status)"
                                    >
                                        <component :is="connectionStatusIcon(connection.status)" :size="10" />
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

                        <div class="flex items-center gap-2 font-medium">
                            <!-- Method Switcher Dropdown -->
                            <div class="relative">
                                <button
                                    @click.stop="activeDropdownId = activeDropdownId === connection.id ? null : connection.id"
                                    class="h-8 px-2.5 rounded-md border border-zinc-200 bg-white text-zinc-700 text-[11px] font-medium hover:bg-zinc-50 transition-colors dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800 flex items-center gap-1.5 min-w-[115px] justify-center active:scale-95 shadow-sm"
                                >
                                    <span class="text-[10px] text-zinc-400 font-normal uppercase mr-0.5">Mode:</span>
                                    <span class="uppercase font-bold tracking-tight">
                                        {{ connection.method === 'open_api' ? 'API' : (connection.method === 'cookie' ? 'Cookie' : 'Portal') }}
                                    </span>
                                    <ChevronDown :size="10" class="opacity-60 ml-0.5" />
                                </button>
                                <div 
                                    v-if="activeDropdownId === connection.id" 
                                    class="absolute left-0 mt-1 w-44 origin-top-left rounded-lg bg-white dark:bg-zinc-900 shadow-xl ring-1 ring-black/5 dark:ring-white/5 focus:outline-none z-30 border border-zinc-200 dark:border-zinc-800 py-1"
                                >
                                    <button 
                                        v-for="m in ['open_api', 'cookie', 'portal_export']" 
                                        :key="m"
                                        @click.stop="switchMethod(connection, m); activeDropdownId = null"
                                        class="flex items-center justify-between w-full px-3 py-2 text-[12px] text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                        :class="connection.method === m ? 'bg-indigo-50/50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 font-bold' : ''"
                                    >
                                            <span class="flex items-center gap-2">
                                            <Globe v-if="m === 'open_api'" :size="13" class="opacity-70" />
                                            <Cookie v-if="m === 'cookie'" :size="13" class="opacity-70" />
                                            <FileSpreadsheet v-if="m === 'portal_export'" :size="13" class="opacity-70" />
                                            {{ m === 'open_api' ? 'Open API' : (m === 'cookie' ? 'Cookie' : 'Portal Export') }}
                                        </span>
                                        <CheckCircle2 v-if="(m === 'open_api' && connection.has_open_api) || (m === 'cookie' && connection.has_cookie)" :size="10" class="text-emerald-500" />
                                    </button>
                                </div>
                            </div>

                            <button
                                @click="secondaryAction(connection)"
                                class="inline-flex items-center justify-center h-8 px-3 rounded-md border border-zinc-200 bg-white text-zinc-700 text-[12px] font-medium hover:bg-zinc-50 transition-colors dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                :disabled="testingConnection === connection.id"
                            >
                                <Loader2 v-if="testingConnection === connection.id" :size="12" class="animate-spin mr-1" />
                                <span>{{ secondaryActionLabel(connection) }}</span>
                            </button>
                            <button
                                @click="triggerSync(connection)"
                                :disabled="syncingConnection === connection.id"
                                class="inline-flex items-center justify-center h-8 px-3 rounded-md text-white text-[12px] font-medium transition-colors"
                                :class="connection.status === 'error'
                                    ? 'bg-zinc-900 hover:bg-black dark:bg-zinc-700 dark:hover:bg-zinc-600'
                                    : 'bg-indigo-600 hover:bg-indigo-700'"
                            >
                                <Loader2 v-if="syncingConnection === connection.id" :size="12" class="animate-spin mr-1" />
                                <span>{{ syncingConnection === connection.id ? 'Đang sync...' : primaryActionLabel(connection) }}</span>
                            </button>
                            <button
                                @click="openConfigModal(getPlatform(connection.platform), connection)"
                                class="h-8 px-3 rounded-md border border-zinc-200 text-zinc-600 bg-white hover:bg-zinc-50 transition-colors dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800 flex items-center gap-1.5 text-[12px] font-medium"
                            >
                                <Settings :size="13" />
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
                            <h4 class="text-[13px] font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ connection.method === 'portal_export' ? 'Lịch sử upload gần đây' : 'Lịch sử đồng bộ gần đây' }}
                            </h4>
                            <div class="flex items-center gap-2">
                                <span class="text-[12px] text-zinc-500 dark:text-zinc-400">Filter:</span>
                                <select 
                                    :value="syncFilters[connection.id] || 'all'"
                                    @change="syncFilters[connection.id] = $event.target.value"
                                    class="h-7 px-2 text-[11px] font-medium rounded border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                >
                                    <option value="all">Tất cả Sync</option>
                                    <option value="manual">Đơn & Click</option>
                                    <option value="payment_sync">Finance</option>
                                    <option value="campaign_sync">Campaigns</option>
                                </select>
                                <button
                                    type="button"
                                    class="text-[12px] text-indigo-600 dark:text-indigo-400 font-medium hover:underline flex items-center gap-1 shrink-0"
                                    @click="openHistoryModal(connection)"
                                >
                                </button>
                            </div>
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
                                        <td class="py-2.5 px-4 text-zinc-600 dark:text-zinc-400 w-[80px]">
                                            {{ 
                                                run.type === 'manual' ? 'Orders & Clicks' : 
                                                run.type === 'payment_sync' ? 'Finance' : 
                                                run.type === 'campaign_sync' ? 'Campaigns' : 
                                                run.type === 'auto_sync' ? 'Auto' : run.type 
                                            }}
                                        </td>
                                        <td class="py-2.5 px-4 w-[130px]">
                                            <div class="flex items-center gap-1.5 font-medium" :class="runStatusClass(run.status)">
                                                <component :is="runStatusIcon(run.status)" :size="12" :class="[runStatusClass(run.status), ['pending', 'processing'].includes(run.status) ? 'animate-spin' : '']" />
                                                <span>{{ runStatusLabel(run.status) }}</span>
                                            </div>
                                        </td>
                                        <td class="py-2.5 px-4 text-zinc-600 dark:text-zinc-400 text-right font-medium w-[150px]">
                                            <template v-if="isPortalUploadRun(run, connection)">
                                                <div class="leading-tight text-right flex flex-col items-end">
                                                    <div class="text-[11px] font-semibold truncate w-[130px]" :title="run.details?.modules?.portal_export?.original_filename || 'Unknown file'">
                                                        {{ run.details?.modules?.portal_export?.original_filename || '—' }}
                                                    </div>
                                                    <div class="text-[10px] opacity-70 mt-0.5 uppercase tracking-wide">
                                                        {{ run.details?.modules?.portal_export?.selected_type || '—' }}
                                                    </div>
                                                </div>
                                            </template>
                                            <template v-else>
                                                <div class="leading-tight">
                                                    <div class="text-[11px]">API {{ run.records_fetched ?? 0 }}</div>
                                                    <div class="text-[11px]">Thêm/Cập nhật {{ run.records_upserted ?? 0 }}</div>
                                                    <div class="text-[11px]" :class="Number(run.records_failed ?? 0) > 0 ? 'text-red-500 dark:text-red-400' : 'text-zinc-500 dark:text-zinc-400'">
                                                        Bỏ qua/Lỗi {{ run.records_failed ?? 0 }}
                                                    </div>
                                                </div>
                                            </template>
                                        </td>
                                        <td class="py-2.5 px-4 text-right w-[70px]">
                                            <button
                                                @click="openRunDetailsDrawer(connection, run, getRecentRuns(connection))"
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


        <Teleport to="body">
            <DrawerHistory
                :isOpen="isRunDetailsOpen"
                :selectedRun="selectedDetailedRun"
                :runs="selectedDetailedRuns"
                :connectionInfo="selectedDetailedPlatform"
                @close="closeRunDetailsDrawer"
            />
        </Teleport>

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
    </AppShell>
</template>

<script setup>
import { computed, ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    ShoppingBag, ShoppingCart, PlaySquare, Plug2, Globe, Cookie, FileSpreadsheet,
    AlertTriangle, Info, CheckCircle, CheckCircle2, PauseCircle, MinusCircle,
    ArrowRight, Settings, Loader2, Clock,
} from 'lucide-vue-next';
import AppShell from '@/Layouts/AppShell.vue';
import DrawerHistory from './Partials/DrawerHistory.vue';
import DrawerConfig from './Partials/DrawerConfig.vue';
import ModalUpload from './Partials/ModalUpload.vue';
import { useDialog } from '@/Composables/useDialog';

const syncFilters = ref({});

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

const allConnections = computed(() => Array.isArray(connectionsState.value) ? connectionsState.value : []);
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
            icon: AlertTriangle,
            iconClass: 'text-amber-700 dark:text-amber-400',
            textClass: 'text-amber-800 dark:text-amber-300',
            wrapperClass: 'bg-amber-50 border-amber-300 dark:bg-amber-500/10 dark:border-amber-500/30',
            message: 'Chưa có kết nối nào. Bắt đầu với Shopee để hệ thống có thể đồng bộ click, đơn và hoa hồng.',
        };
    }

    if (isSingle.value) {
        return {
            icon: Info,
            iconClass: 'text-indigo-700 dark:text-indigo-300',
            textClass: 'text-indigo-800 dark:text-indigo-200',
            wrapperClass: 'bg-indigo-50 border-indigo-200 dark:bg-indigo-500/10 dark:border-indigo-500/30',
            message: 'Bạn đang có 1 kết nối hoạt động. Thiết lập thêm kết nối để dự phòng và so sánh hiệu suất.',
        };
    }

    if (issueConnections.value.length > 0) {
        return {
            icon: CheckCircle,
            iconClass: 'text-emerald-700 dark:text-emerald-300',
            textClass: 'text-emerald-800 dark:text-emerald-200',
            wrapperClass: 'bg-emerald-50 border-emerald-200 dark:bg-emerald-500/10 dark:border-emerald-500/30',
            message: `Đang có ${connectionCount.value} kết nối. ${activeConnections.value.length} hoạt động, ${issueConnections.value.length} kết nối cần xử lý.`,
        };
    }

    return {
        icon: CheckCircle,
        iconClass: 'text-emerald-700 dark:text-emerald-300',
        textClass: 'text-emerald-800 dark:text-emerald-200',
        wrapperClass: 'bg-emerald-50 border-emerald-200 dark:bg-emerald-500/10 dark:border-emerald-500/30',
        message: `Tất cả ${connectionCount.value} kết nối đang hoạt động ổn định.`,
    };
});

const syncingAll = ref(false);
const testingConnection = ref(null);
const syncingConnection = ref(null);
const activeDropdownId = ref(null);
const uiFeedback = ref(null);

const closeDropdownOnClick = () => {
    activeDropdownId.value = null;
};

onMounted(() => {
    window.addEventListener('click', closeDropdownOnClick);
});

onBeforeUnmount(() => {
    window.removeEventListener('click', closeDropdownOnClick);
});

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
const { confirmDialog } = useDialog();

function connectionStatusPriority(status) {
    if (status === 'active') return 0;
    if (status === 'error') return 1;
    if (status === 'inactive') return 2;
    return 3;
}

function getPlatform(platformId) {
    return platformConfigs.value.find((platform) => platform.id === platformId)
        || { id: platformId, name: platformId, icon: Plug2, color: '#71717A', supported: false };
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
    if (status === 'active') return CheckCircle;
    if (status === 'error') return AlertTriangle;
    if (status === 'inactive') return PauseCircle;
    return MinusCircle;
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
    
    if (connection.sync_interval === 'daily') {
        const time = connection.sync_time || '00:00';
        return `Tự động (Hàng ngày lúc ${time})`;
    }
    
    const intervalMap = {
        '15m': '15 phút',
        '1h': '1 tiếng',
        '3h': '3 tiếng',
        '8h': '8 tiếng',
    };
    
    const interval = connection.sync_interval || '15m';
    const label = intervalMap[interval] || '15 phút';
    
    return `Tự động (${label})`;
}

function backfillLabel(connection) {
    const days = Number(connection?.backfill_days_effective);
    if (!Number.isFinite(days) || days <= 0) return '--';
    return `${days} ngày`;
}

function runStatusLabel(status) {
    if (status === 'pending') return 'Queued';
    if (status === 'processing') return 'Processing';
    if (status === 'completed') return 'Completed';
    if (status === 'completed_with_warnings') return 'Completed (Warnings)';
    if (status === 'failed_auth') return 'Failed Auth';
    if (status === 'rate_limited') return 'Rate Limited';
    if (String(status).startsWith('failed')) return 'Failed';
    if (String(status).startsWith('skipped')) return 'Skipped';
    return status || 'Unknown';
}

function runStatusClass(status) {
    if (status === 'pending' || status === 'processing') return 'text-indigo-600 dark:text-indigo-400';
    if (status === 'completed') return 'text-emerald-600 dark:text-emerald-400';
    if (status === 'completed_with_warnings') return 'text-amber-600 dark:text-amber-400';
    if (status === 'rate_limited') return 'text-amber-600 dark:text-amber-400';
    if (String(status).startsWith('failed')) return 'text-red-500 dark:text-red-400';
    return 'text-zinc-600 dark:text-zinc-300';
}

function runStatusIcon(status) {
    if (status === 'pending' || status === 'processing') return Loader2;
    if (status === 'completed') return CheckCircle;
    if (status === 'completed_with_warnings') return AlertTriangle;
    if (status === 'rate_limited') return AlertTriangle;
    if (String(status).startsWith('failed')) return AlertTriangle;
    return Clock;
}

function isPortalUploadRun(run, connection) {
    // Only return true if the run explicitly contains portal_export details.
    // Relying on connection.method would hide historical API syncs.
    return !!run?.details?.modules?.portal_export;
}

function runActionLabel(status) {
    if (status === 'pending' || status === 'processing') return 'Đang chạy';
    return 'Chi tiết';
}

function feedbackClasses(type) {
    if (type === 'success') return 'bg-emerald-50 border-emerald-200 text-emerald-800 dark:bg-emerald-500/10 dark:border-emerald-500/30 dark:text-emerald-200';
    if (type === 'error') return 'bg-red-50 border-red-200 text-red-800 dark:bg-red-500/10 dark:border-red-500/30 dark:text-red-200';
    if (type === 'warning') return 'bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-500/10 dark:border-amber-500/30 dark:text-amber-200';
    return 'bg-indigo-50 border-indigo-200 text-indigo-800 dark:bg-indigo-500/10 dark:border-indigo-500/30 dark:text-indigo-200';
}

function feedbackIcon(type) {
    if (type === 'success') return CheckCircle;
    if (type === 'error') return AlertTriangle;
    if (type === 'warning') return AlertTriangle;
    return Info;
}

function showFeedback(type, message, details = '') {
    uiFeedback.value = { type, message, details };
}

function normalizeRunPayload(run) {
    return {
        ...run,
        records_upserted: run.records_upserted ?? run.records_inserted ?? 0,
        records_failed: run.records_failed ?? 0,
        finished_at: run.finished_at ?? run.completed_at ?? null,
    };
}

function updateConnectionRuns(connectionId, runs) {
    const index = allConnections.value.findIndex((item) => item.id === connectionId);
    if (index < 0) return;

    const normalizedRuns = (Array.isArray(runs) ? runs : []).map(normalizeRunPayload);
    const next = [...allConnections.value];
    const current = { ...next[index] };

    current.recent_sync_runs = normalizedRuns.slice(0, 5);
    current.sync_runs_count = Math.max(
        Number(current.sync_runs_count || 0),
        normalizedRuns.length,
    );

    if (normalizedRuns.length > 0) {
        // Prefer the first non-subsidiary run (non-payment_sync) for status derivation
        const primaryRun = normalizedRuns.find((r) => r.type !== 'payment_sync') || normalizedRuns[0];
        current.last_sync_status = primaryRun.status;
        current.last_sync_at = primaryRun.started_at;
    }

    next[index] = current;
    connectionsState.value = next;
}

function patchConnection(connectionId, payload) {
    const index = allConnections.value.findIndex((item) => item.id === connectionId);
    if (index < 0) return;

    const next = [...allConnections.value];
    next[index] = { ...next[index], ...payload };
    connectionsState.value = next;
}

async function fetchSyncHistory(connectionId, limit = 20) {
    const response = await axios.get(route('api.integrations.history', connectionId), {
        params: { limit },
    });

    return Array.isArray(response.data?.data) ? response.data.data.map(normalizeRunPayload) : [];
}

function isRunFinal(status) {
    return !['pending', 'processing'].includes(String(status));
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function pollAllConnectionRuns(connectionId, timeoutMs = 180000) {
    const startedAt = Date.now();

    while (Date.now() - startedAt < timeoutMs) {
        const runs = await fetchSyncHistory(connectionId, 20);
        updateConnectionRuns(connectionId, runs);

        // Check if there are any runs still processing
        const isStillRunning = runs.some(run => !isRunFinal(run.status));
        
        if (!isStillRunning) {
            // Return top 3 most recent runs that were likely triggered by this sync
            return runs.slice(0, 3);
        }

        await sleep(2000);
    }

    return null;
}

async function pollManySyncRuns(runTargets, timeoutMs = 180000) {
    const pending = new Map();
    for (const item of runTargets) {
        pending.set(Number(item.connectionId), Number(item.runId));
    }

    const finalRuns = [];
    const startedAt = Date.now();

    while (pending.size > 0 && Date.now() - startedAt < timeoutMs) {
        for (const [connectionId, runId] of [...pending.entries()]) {
            try {
                const runs = await fetchSyncHistory(connectionId, 20);
                updateConnectionRuns(connectionId, runs);
                const run = runs.find((item) => Number(item.id) === Number(runId));
                if (run && isRunFinal(run.status)) {
                    finalRuns.push({ connectionId, run });
                    pending.delete(connectionId);
                }
            } catch (error) {
                console.error('Poll sync all failed for connection', connectionId, error);
            }
        }

        if (pending.size > 0) {
            await sleep(2000);
        }
    }

    return {
        finalRuns,
        pending: [...pending.entries()].map(([connectionId, runId]) => ({ connectionId, runId })),
    };
}

function getRecentRuns(connection) {
    const runs = Array.isArray(connection.recent_sync_runs) ? connection.recent_sync_runs : [];
    const filter = syncFilters.value[connection.id] || 'all';
    
    let filtered = runs;
    if (filter !== 'all') {
        filtered = runs.filter((r) => r.type === filter);
    }
    
    return filtered.slice(0, 5).map(normalizeRunPayload);
}

function showGuide() {
    showFeedback('info', 'Hướng dẫn chi tiết sẽ được cập nhật trong docs nội bộ.');
}

function connectShopeeNow() {
    const shopee = platformConfigs.value.find((platform) => platform.id === 'shopee' && platform.supported);
    if (!shopee) {
        showFeedback('error', 'Shopee hiện chưa khả dụng để kết nối.');
        return;
    }
    openConfigModal(shopee, null);
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
    }, 300);
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
        router.reload({ only: ['connections'], preserveScroll: true, preserveState: true });
    } catch (error) {
        showFeedback('error', error.response?.data?.message || 'Không thể xóa kết nối.');
    }
}

function openRunDetailsDrawer(connection, run, runs = []) {
    const platformId = connection?.platform;
    const platform = getPlatform(platformId);
    
    selectedDetailedPlatform.value = {
        ...platform,
        label: connection?.label || platform.name
    };
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
        const runs = await fetchSyncHistory(connection.id, 100);
        updateConnectionRuns(connection.id, runs);
        if (!runs.length) {
            showFeedback('info', 'Chưa có lịch sử đồng bộ cho kết nối này.');
            return;
        }
        
        // Find the first failed run in history to show first
        const failedRun = runs.find(r => String(r.status).startsWith('failed'));
        openRunDetailsDrawer(connection, failedRun || runs[0], runs);
    } catch (error) {
        console.error('Load history failed', error);
        const fallbackRuns = getRecentRuns(connection);
        if (fallbackRuns.length) {
            const failedFallback = fallbackRuns.find(r => String(r.status).startsWith('failed'));
            openRunDetailsDrawer(connection, failedFallback || fallbackRuns[0], fallbackRuns);
            return;
        }

        showFeedback('error', 'Không thể tải lịch sử đồng bộ.');
    }
}

function secondaryActionLabel(connection) {
    if (connection.method === 'portal_export') return 'Upload Data';
    return 'Test kết nối';
}

function primaryActionLabel(connection) {
    if (connection.status === 'error') return 'Retry Sync';
    if (connection.method === 'portal_export') return 'Upload History';
    return 'Đồng bộ ngay';
}

function secondaryAction(connection) {
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
        const valid = response.data?.ok && response.data?.data?.valid === true;
        const checks = response.data?.data?.checks || {};
        const message = response.data?.message || (valid ? 'Connection valid.' : 'Connection failed.');

        if (valid) {
            patchConnection(connection.id, {
                status: 'active',
                last_sync_status: 'completed',
                last_error: null,
                last_error_at: null,
            });

            const failed = Object.entries(checks)
                .filter(([, check]) => check?.ok === false)
                .map(([name, check]) => `${name}: ${check?.message || 'failed'}`);
            if (failed.length > 0) {
                showFeedback('warning', 'Kết nối dùng được (partial).', `${message}\n${failed.join('\n')}`);
            } else {
                showFeedback('success', 'Test kết nối thành công.', message);
            }
        } else {
            const details = Object.entries(checks)
                .map(([name, check]) => `${name}: ${check?.message || 'failed'}`)
                .join('\n');
            showFeedback('error', `Test kết nối thất bại: ${message}`, details);
        }
    } catch (error) {
        showFeedback('error', `Lỗi: ${error.response?.data?.message || 'Lỗi hệ thống.'}`);
    } finally {
        testingConnection.value = null;
    }
}

async function switchMethod(connection, newMethod) {
    if (connection.method === newMethod) return;

    if (newMethod === 'open_api' && !connection.has_open_api) {
        const confirmed = await confirmDialog({
            variant: 'warning',
            title: 'Open API chưa được cấu hình',
            description: 'Bạn cần nhập App ID/App Secret trước khi chuyển sang mode Open API.',
            confirmText: 'Mở cấu hình',
            cancelText: 'Để sau',
        });
        if (confirmed) {
            openConfigModal(getPlatform(connection.platform), connection);
        }
        return;
    }
    
    if (newMethod === 'cookie' && !connection.has_cookie) {
        const confirmed = await confirmDialog({
            variant: 'warning',
            title: 'Cookie chưa được cấu hình',
            description: 'Bạn cần dán cookie hợp lệ trước khi chuyển sang mode Cookie.',
            confirmText: 'Mở cấu hình',
            cancelText: 'Để sau',
        });
        if (confirmed) {
            openConfigModal(getPlatform(connection.platform), connection);
        }
        return;
    }

    try {
        const payload = { method: newMethod };
        const hasExistingCreds = (newMethod === 'portal_export' || 
                                 (newMethod === 'cookie' && connection.has_cookie) || 
                                 (newMethod === 'open_api' && connection.has_open_api));
        
        if (hasExistingCreds) {
            payload.status = 'active';
        }

        await axios.patch(route('api.integrations.update', connection.id), payload);
        
        // Reload only relevant data
        router.reload({ only: ['connections'] });
    } catch (error) {
        console.error('Switch method failed', error);
        showFeedback('error', 'Không thể chuyển đổi phương thức. Vui lòng thử lại.');
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
        showFeedback('info', `Đang xếp hàng đồng bộ cho ${connection.label || getPlatform(connection.platform).name}...`);
        const response = await axios.post(route('api.integrations.sync', connection.id));
        const runId = response.data?.data?.sync_run_id;

        if (!runId) {
            router.reload({ only: ['connections'] });
            showFeedback('success', 'Đã kích hoạt đồng bộ.');
            return;
        }

        const pendingRun = normalizeRunPayload({
            id: runId,
            status: 'pending',
            type: 'manual',
            started_at: new Date().toISOString(),
            records_fetched: 0,
            records_upserted: 0,
            records_failed: 0,
            error_message: 'Queued for execution.',
        });
        updateConnectionRuns(connection.id, [pendingRun, ...getRecentRuns(connection)]);

        const finalRuns = await pollAllConnectionRuns(connection.id, 180000);
        if (!finalRuns || finalRuns.length === 0) {
            showFeedback(
                'warning',
                'Đồng bộ đã được kích hoạt nhưng chưa có kết quả cuối cùng.',
                'Tiến trình đang chạy ngầm. Bạn có thể bấm "Xem tất cả" để theo dõi thêm.',
            );
            return;
        }

        // Aggregate stats across all recent runs
        let totalFetched = 0;
        let totalUpserted = 0;
        let totalFailed = 0;
        let hasError = false;
        let hasWarning = false;

        for (const run of finalRuns) {
            totalFetched += Number(run.records_fetched || 0);
            totalUpserted += Number(run.records_upserted || 0);
            totalFailed += Number(run.records_failed || 0);
            
            if (String(run.status).startsWith('failed')) hasError = true;
            if (run.status === 'completed_with_warnings' || run.status === 'rate_limited') hasWarning = true;
        }

        const detailLines = [
            `API lấy về: ${totalFetched}`,
            `Thêm/Cập nhật: ${totalUpserted}`,
            `Bỏ qua/Lỗi: ${totalFailed}`,
        ];

        if (hasError) {
            showFeedback('error', 'Đồng bộ hoàn tất nhưng có job thất bại.', detailLines.join('\n'));
        } else if (hasWarning) {
            showFeedback('warning', 'Đồng bộ hoàn tất có cảnh báo.', detailLines.join('\n'));
        } else {
            showFeedback('success', 'Đồng bộ hoàn tất.', detailLines.join('\n'));
        }

        router.reload({ only: ['connections'], preserveState: true, preserveScroll: true });
    } catch (error) {
        console.error('Trigger sync failed', error);
        showFeedback('error', 'Có lỗi khi kích hoạt đồng bộ.', error.response?.data?.message || '');
    } finally {
        syncingConnection.value = null;
    }
}

async function syncAllConnections() {
    if (syncAllDisabled.value) return;

    const targets = allConnections.value.filter((connection) => ['active', 'error'].includes(connection.status));
    if (!targets.length) {
        showFeedback('info', 'Không có kết nối nào sẵn sàng để đồng bộ.');
        return;
    }

    syncingAll.value = true;
    let queued = 0;
    let queueFailed = 0;
    const runTargets = [];

    for (const connection of targets) {
        try {
            const response = await axios.post(route('api.integrations.sync', connection.id));
            const runId = response.data?.data?.sync_run_id;
            queued += 1;
            if (runId) {
                runTargets.push({ connectionId: connection.id, runId });
                const pendingRun = normalizeRunPayload({
                    id: runId,
                    status: 'pending',
                    type: 'manual',
                    started_at: new Date().toISOString(),
                    records_fetched: 0,
                    records_upserted: 0,
                    records_failed: 0,
                    error_message: 'Queued for execution.',
                });
                updateConnectionRuns(connection.id, [pendingRun, ...getRecentRuns(connection)]);
            }
        } catch {
            queueFailed += 1;
        }
    }

    if (!runTargets.length) {
        syncingAll.value = false;
        router.reload({ only: ['connections'] });

        if (queueFailed > 0) {
            showFeedback('warning', `Đã kích hoạt ${queued} kết nối, ${queueFailed} kết nối lỗi khi xếp hàng.`);
            return;
        }

        showFeedback('success', `Đã kích hoạt đồng bộ cho ${queued} kết nối.`);
        return;
    }

    showFeedback('info', `Đã xếp hàng ${queued} kết nối. Đang theo dõi tiến trình...`);
    const polled = await pollManySyncRuns(runTargets, 180000);

    const finalRuns = polled.finalRuns.map((entry) => entry.run);
    const completed = finalRuns.filter((run) => run.status === 'completed').length;
    const warnings = finalRuns.filter((run) => run.status === 'completed_with_warnings').length;
    const failed = finalRuns.filter((run) => String(run.status).startsWith('failed')).length;
    const stillRunning = polled.pending.length;

    const totals = finalRuns.reduce((acc, run) => {
        acc.fetched += Number(run.records_fetched || 0);
        acc.upserted += Number(run.records_upserted || 0);
        acc.failed += Number(run.records_failed || 0);
        return acc;
    }, { fetched: 0, upserted: 0, failed: 0 });

    syncingAll.value = false;
    router.reload({ only: ['connections'], preserveState: true, preserveScroll: true });

    const detailLines = [
        `Completed: ${completed}`,
        `Completed with warnings: ${warnings}`,
        `Failed: ${failed}`,
        `Still running: ${stillRunning}`,
        `API fetched: ${totals.fetched}`,
        `Upserted/Updated: ${totals.upserted}`,
        `Skipped/Failed records: ${totals.failed}`,
    ];

    if (failed > 0 || queueFailed > 0) {
        showFeedback('error', 'Sync all hoàn tất nhưng có kết nối thất bại.', detailLines.join('\n'));
        return;
    }

    if (warnings > 0 || stillRunning > 0) {
        showFeedback('warning', 'Sync all hoàn tất với cảnh báo.', detailLines.join('\n'));
        return;
    }

    showFeedback('success', 'Sync all hoàn tất thành công.', detailLines.join('\n'));
}
</script>
