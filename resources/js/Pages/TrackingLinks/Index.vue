<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Tracking Links</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Tracking Links</h1>
                    <p class="text-xs" style="color: var(--text-muted)">Quản lý link hiệu suất, theo dõi click và chuyển đổi theo quyền truy cập.</p>
                </div>
                <button @click="openCreate" class="af-btn-primary text-sm h-9 px-4 flex items-center gap-1.5">
                    <Plus :size="14" />
                    Tạo Link
                </button>
            </div>

            <div
                v-if="Number(summary.unattributed_clicks || 0) > 0 || Number(summary.unattributed_orders || 0) > 0"
                class="af-surface px-3 py-2"
                style="border-color: var(--warning-text); background: var(--warning-bg)"
            >
                <p class="text-xs font-semibold" style="color: var(--warning-text)">
                    Có dữ liệu chưa được gắn đúng tracking link.
                </p>
                <p class="text-xs mt-0.5" style="color: var(--text-secondary)">
                    Click chưa gắn link: {{ fmtNum(summary.unattributed_clicks || 0) }} ·
                    Đơn chưa gắn link: {{ fmtNum(summary.unattributed_orders || 0) }}
                </p>
                <p v-if="unattributedClickReasonText" class="text-xs mt-0.5" style="color: var(--text-secondary)">
                    Nguyên nhân click: {{ unattributedClickReasonText }}
                </p>
                <p v-if="unattributedOrderReasonText" class="text-xs mt-0.5" style="color: var(--text-secondary)">
                    Nguyên nhân đơn: {{ unattributedOrderReasonText }}
                </p>
            </div>

            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-3 flex-wrap">
                    <div class="flex gap-1 p-1 rounded-lg" style="background: var(--surface-2)">
                        <button
                            v-for="tab in statusTabs"
                            :key="tab.value"
                            @click="setStatusTab(tab.value)"
                            class="text-xs px-3 py-1.5 rounded-md font-medium transition-all"
                            :style="activeStatusTab === tab.value
                                ? { background: 'var(--surface-1)', color: 'var(--text-primary)', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }
                                : { color: 'var(--text-secondary)' }"
                        >
                            {{ tab.label }}
                        </button>
                    </div>

                    <div class="flex items-center gap-2">
                        <Filter :size="13" style="color: var(--text-muted)" />
                        <select v-model="activePreset" @change="setPreset" class="af-input h-9 text-sm" style="width: 185px;">
                            <option value="">Preset lọc nhanh</option>
                            <option value="top_performing">Top performing</option>
                            <option value="underperforming">Underperforming</option>
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <select v-model="sortBy" @change="applySorting" class="af-input h-9 text-sm" style="width: 165px;">
                            <option value="created_at">Sort: Created</option>
                            <option value="clicks_count">Sort: Clicks</option>
                            <option value="orders_count">Sort: Orders</option>
                        </select>
                        <select v-model="sortDirection" @change="applySorting" class="af-input h-9 text-sm" style="width: 100px;">
                            <option value="desc">Desc</option>
                            <option value="asc">Asc</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <div class="relative">
                        <Search :size="14" class="af-input-search-icon absolute left-3.5 top-1/2 -translate-y-1/2" style="color: var(--text-muted)" />
                        <input
                            v-model="searchQuery"
                            @input="debouncedSearch"
                            type="text"
                            placeholder="Tìm theo link, short code, source..."
                            class="af-input af-input-search h-9 text-sm w-64"
                        />
                    </div>

                    <div class="flex items-center gap-2">
                        <input v-model="dateFrom" @click="$event.target.showPicker?.()" @change="applyDateRange" type="date" class="af-input af-input-date h-9 text-sm" />
                        <span class="text-xs" style="color: var(--text-muted)">→</span>
                        <input v-model="dateTo" @click="$event.target.showPicker?.()" @change="applyDateRange" type="date" class="af-input af-input-date h-9 text-sm" />
                    </div>

                    <div class="relative">
                        <button @click="showColumnsMenu = !showColumnsMenu" class="af-btn-outline h-9 px-3 text-sm flex items-center gap-1.5">
                            <Columns3 :size="14" />
                            Columns
                        </button>
                        <div v-if="showColumnsMenu" class="absolute right-0 mt-2 af-surface z-20 p-2 w-48" style="box-shadow: var(--shadow-sm)">
                            <label
                                v-for="col in allColumns"
                                :key="col.id"
                                class="flex items-center gap-2 px-2 py-1.5 rounded cursor-pointer hover:bg-[var(--surface-2)]"
                            >
                                <input type="checkbox" :checked="hasColumn(col.id)" @change="toggleColumn(col.id)" />
                                <span class="text-xs" style="color: var(--text-primary)">{{ col.label }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="af-surface overflow-x-auto">
                <table class="w-full text-sm border-collapse min-w-[980px]">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                            <th v-if="hasColumn('link')" class="text-left px-4 py-2.5 font-medium" style="color: var(--text-muted); width: 34%">Link / Destination</th>
                            <th v-if="hasColumn('campaign')" class="text-left px-4 py-2.5 font-medium" style="color: var(--text-muted)">Campaign</th>
                            <th v-if="hasColumn('status')" class="text-left px-4 py-2.5 font-medium" style="color: var(--text-muted)">Status</th>
                            <th v-if="hasColumn('clicks')" class="text-right px-4 py-2.5 font-medium" style="color: var(--text-muted)">Clicks</th>
                            <th v-if="hasColumn('orders')" class="text-right px-4 py-2.5 font-medium" style="color: var(--text-muted)">Orders</th>
                            <th v-if="hasColumn('commission')" class="text-right px-4 py-2.5 font-medium" style="color: var(--text-muted)">Commission</th>
                            <th v-if="hasColumn('cr')" class="text-right px-4 py-2.5 font-medium" style="color: var(--text-muted)">Order Rate</th>
                            <th v-if="hasColumn('created')" class="text-right px-4 py-2.5 font-medium" style="color: var(--text-muted)">Created</th>
                            <th class="px-4 py-2.5 font-medium" style="color: var(--text-muted); width: 220px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!links.data?.length">
                            <td :colspan="visibleColumnCount + 1" class="text-center py-12" style="color: var(--text-muted)">
                                <div class="flex flex-col items-center gap-3">
                                    <Link2 :size="32" style="color: var(--text-muted)" />
                                    <p>No links created yet.</p>
                                    <button @click="openCreate" class="af-btn-primary text-sm h-8 px-3">Create first tracking link</button>
                                </div>
                            </td>
                        </tr>

                        <tr
                            v-for="link in links.data"
                            :key="link.id"
                            style="border-bottom: 1px solid var(--border); height: 48px"
                            class="hover:bg-[var(--surface-2)] transition-colors"
                        >
                            <td v-if="hasColumn('link')" class="px-4 py-3">
                                <div class="flex flex-col gap-0.5">
                                    <button @click="openDetail(link)" class="font-mono text-xs font-semibold text-left" style="color: var(--color-primary-500)">
                                        go.affentra/{{ link.short_code }}
                                    </button>
                                    <span class="text-xs truncate max-w-[320px]" style="color: var(--text-muted)" :title="link.destination_url">
                                        {{ link.destination_url }}
                                    </span>
                                </div>
                            </td>

                            <td v-if="hasColumn('campaign')" class="px-4 py-3 text-xs" style="color: var(--text-secondary)">
                                {{ link.campaign?.name || 'No campaign' }}
                            </td>

                            <td v-if="hasColumn('status')" class="px-4 py-3">
                                <span class="text-xs font-medium px-2 py-0.5 rounded-full" :style="statusStyle(link.status)">
                                    {{ statusLabel(link.status) }}
                                </span>
                            </td>

                            <td v-if="hasColumn('clicks')" class="px-4 py-3 text-right font-medium tabular-nums" style="color: var(--text-primary)">
                                {{ fmtNum(metricClicks(link)) }}
                            </td>

                            <td v-if="hasColumn('orders')" class="px-4 py-3 text-right tabular-nums" style="color: var(--text-primary)">
                                {{ fmtNum(metricOrders(link)) }}
                            </td>

                            <td v-if="hasColumn('commission')" class="px-4 py-3 text-right tabular-nums font-semibold" style="color: var(--color-primary-500)">
                                {{ fmtCurrency(metricCommission(link)) }}
                            </td>

                            <td v-if="hasColumn('cr')" class="px-4 py-3 text-right text-xs tabular-nums"
                                :style="{ color: convRate(link) === '—' ? 'var(--text-muted)' : (Number(convRate(link)) >= 3 ? 'var(--success-text)' : 'var(--text-secondary)') }">
                                {{ convRate(link) === '—' ? '—' : `${convRate(link)}%` }}
                            </td>

                            <td v-if="hasColumn('created')" class="px-4 py-3 text-right text-xs" style="color: var(--text-muted)">
                                {{ formatDate(link.created_at) }}
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1 justify-end">
                                    <button @click="openDetail(link)" class="w-7 h-7 rounded flex items-center justify-center hover:bg-[var(--surface-2)]" title="View detail">
                                        <Eye :size="13" style="color: var(--text-secondary)" />
                                    </button>
                                    <button @click="copyLink(link)" class="w-7 h-7 rounded flex items-center justify-center hover:bg-[var(--surface-2)]" title="Copy link">
                                        <Copy :size="13" style="color: var(--text-secondary)" />
                                    </button>
                                    <button @click="refreshProduct(link)" :disabled="actionLoadingId === link.id" class="w-7 h-7 rounded flex items-center justify-center hover:bg-[var(--surface-2)]" title="Refresh Product Info">
                                        <Loader2
                                            v-if="actionLoadingId === link.id && currentAction === 'refresh'"
                                            :size="13"
                                            class="animate-spin"
                                            style="color: var(--text-secondary)"
                                        />
                                        <RefreshCw
                                            v-else
                                            :size="13"
                                            style="color: var(--text-secondary)"
                                        />
                                    </button>
                                    <button @click="createAIContent(link)" class="w-7 h-7 rounded flex items-center justify-center hover:bg-[var(--surface-2)]" title="Create AI Content">
                                        <Sparkles :size="13" style="color: var(--color-primary-500)" />
                                    </button>
                                    <button
                                        v-if="link.status !== 'archived'"
                                        @click="toggleStatus(link)"
                                        :disabled="actionLoadingId === link.id"
                                        class="w-7 h-7 rounded flex items-center justify-center hover:bg-[var(--surface-2)]"
                                        :title="link.status === 'active' ? 'Pause' : 'Resume'"
                                    >
                                        <Pause v-if="link.status === 'active'" :size="13" style="color: var(--warning-text)" />
                                        <Play v-else :size="13" style="color: var(--success-text)" />
                                    </button>
                                    <button @click="openEdit(link)" class="w-7 h-7 rounded flex items-center justify-center hover:bg-[var(--surface-2)]" title="Edit">
                                        <Pencil :size="13" style="color: var(--text-secondary)" />
                                    </button>
                                    <button
                                        @click="archiveLink(link)"
                                        :disabled="actionLoadingId === link.id || link.status === 'archived'"
                                        class="w-7 h-7 rounded flex items-center justify-center hover:bg-[var(--danger-bg)]"
                                        title="Archive"
                                    >
                                        <Archive :size="13" style="color: var(--danger-text)" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>

                    <tfoot v-if="links.data?.length">
                        <tr style="border-top: 2px solid var(--border); background: var(--surface-2)">
                            <td class="px-4 py-2.5 text-xs font-semibold" style="color: var(--text-secondary)">
                                Total {{ links.total }} links
                            </td>
                            <td v-if="hasColumn('campaign')"></td>
                            <td v-if="hasColumn('status')"></td>
                            <td v-if="hasColumn('clicks')" class="px-4 py-2.5 text-right text-xs font-semibold tabular-nums" style="color: var(--text-primary)">
                                {{ fmtNum(summary.total_clicks) }}
                            </td>
                            <td v-if="hasColumn('orders')" class="px-4 py-2.5 text-right text-xs font-semibold tabular-nums" style="color: var(--text-primary)">
                                {{ fmtNum(summary.total_orders) }}
                            </td>
                            <td v-if="hasColumn('commission')" class="px-4 py-2.5 text-right text-xs font-semibold tabular-nums" style="color: var(--color-primary-500)">
                                {{ fmtCurrency(summary.total_commission) }}
                            </td>
                            <td v-if="hasColumn('cr')" class="px-4 py-2.5 text-right text-xs tabular-nums" style="color: var(--text-secondary)">
                                {{ totalCR === '—' ? '—' : `${totalCR}%` }}
                            </td>
                            <td v-if="hasColumn('created')"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div v-if="links.last_page > 1" class="flex items-center justify-between">
                <p class="text-xs" style="color: var(--text-muted)">
                    Showing {{ links.from }}–{{ links.to }} of {{ links.total }}
                </p>
                <div class="flex items-center gap-1">
                    <button
                        v-for="p in pageRange"
                        :key="p"
                        @click="goToPage(p)"
                        class="w-8 h-8 rounded-md text-sm transition-colors"
                        :style="p === links.current_page
                            ? { background: 'var(--color-primary-500)', color: '#fff' }
                            : { color: 'var(--text-secondary)' }"
                    >{{ p }}</button>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center" style="background: rgba(0,0,0,0.35)">
                <div class="af-surface w-full max-w-md" style="padding: 24px; margin: 16px">
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-base font-semibold" style="color: var(--text-primary)">Tạo Tracking Link</h2>
                        <button @click="showCreate = false" class="w-7 h-7 rounded flex items-center justify-center hover:bg-[var(--surface-2)]">
                            <X :size="16" style="color: var(--text-muted)" />
                        </button>
                    </div>

                    <form @submit.prevent="submitCreate" class="flex flex-col gap-4">
                        <div>
                            <label class="af-label">Destination URL <span style="color: var(--danger-text)">*</span></label>
                            <input v-model="createForm.destination_url" type="url" class="af-input"
                                   :class="{ error: createErrors.destination_url }"
                                   placeholder="https://shopee.vn/product/..." required />
                            <p v-if="createErrors.destination_url" class="mt-1 text-xs" style="color: var(--danger-text)">
                                {{ createErrors.destination_url[0] }}
                            </p>
                        </div>

                        <div>
                            <label class="af-label">Short code (optional)</label>
                            <input v-model="createForm.short_code" type="text" class="af-input"
                                   :class="{ error: createErrors.short_code }"
                                   placeholder="abc12345 (auto nếu để trống)" maxlength="20" />
                            <p v-if="createErrors.short_code" class="mt-1 text-xs" style="color: var(--danger-text)">
                                {{ createErrors.short_code[0] }}
                            </p>
                        </div>

                        <div>
                            <label class="af-label">Campaign (optional)</label>
                            <select v-model="createForm.campaign_id" class="af-input" :class="{ error: createErrors.campaign_id }">
                                <option value="">-- No Campaign --</option>
                                <option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <p v-if="createErrors.campaign_id" class="mt-1 text-xs" style="color: var(--danger-text)">
                                {{ createErrors.campaign_id[0] }}
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="af-label">Source</label>
                                <input v-model="createForm.source" type="text" class="af-input" placeholder="facebook" />
                            </div>
                            <div>
                                <label class="af-label">Channel</label>
                                <input v-model="createForm.channel" type="text" class="af-input" placeholder="story" />
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" @click="showCreate = false" class="af-btn-outline text-sm h-9 px-4">Huỷ</button>
                            <button type="submit" class="af-btn-primary text-sm h-9 px-4" :disabled="creating">
                                <span v-if="creating">Đang tạo…</span>
                                <span v-else>Tạo Link</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="showEdit" class="fixed inset-0 z-50 flex items-center justify-center" style="background: rgba(0,0,0,0.35)">
                <div class="af-surface w-full max-w-md" style="padding: 24px; margin: 16px">
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-base font-semibold" style="color: var(--text-primary)">Chỉnh sửa Tracking Link</h2>
                        <button @click="showEdit = false" class="w-7 h-7 rounded flex items-center justify-center hover:bg-[var(--surface-2)]">
                            <X :size="16" style="color: var(--text-muted)" />
                        </button>
                    </div>

                    <form @submit.prevent="submitEdit" class="flex flex-col gap-4">
                        <div>
                            <label class="af-label">Destination URL <span style="color: var(--danger-text)">*</span></label>
                            <input v-model="editForm.destination_url" type="url" class="af-input" :class="{ error: editErrors.destination_url }" required />
                            <p v-if="editErrors.destination_url" class="mt-1 text-xs" style="color: var(--danger-text)">
                                {{ editErrors.destination_url[0] }}
                            </p>
                        </div>

                        <div>
                            <label class="af-label">Campaign (optional)</label>
                            <select v-model="editForm.campaign_id" class="af-input" :class="{ error: editErrors.campaign_id }">
                                <option value="">-- No Campaign --</option>
                                <option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <p v-if="editErrors.campaign_id" class="mt-1 text-xs" style="color: var(--danger-text)">
                                {{ editErrors.campaign_id[0] }}
                            </p>
                        </div>

                        <div>
                            <label class="af-label">Status</label>
                            <select v-model="editForm.status" class="af-input" :class="{ error: editErrors.status }">
                                <option value="active">Active</option>
                                <option value="paused">Paused</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="af-label">Source</label>
                                <input v-model="editForm.source" type="text" class="af-input" />
                            </div>
                            <div>
                                <label class="af-label">Channel</label>
                                <input v-model="editForm.channel" type="text" class="af-input" />
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" @click="showEdit = false" class="af-btn-outline text-sm h-9 px-4">Huỷ</button>
                            <button type="submit" class="af-btn-primary text-sm h-9 px-4" :disabled="editing">
                                <span v-if="editing">Đang lưu…</span>
                                <span v-else>Lưu thay đổi</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppShell>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    Archive,
    Columns3,
    Copy,
    Eye,
    Filter,
    Link2,
    Pause,
    Pencil,
    Play,
    Plus,
    Loader2,
    RefreshCw,
    Search,
    Sparkles,
    X,
} from 'lucide-vue-next';
import AppShell from '@/Layouts/AppShell.vue';
import { useDialog } from '@/Composables/useDialog';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
    links: { type: Object, default: () => ({ data: [], total: 0, current_page: 1, last_page: 1, from: 0, to: 0 }) },
    campaigns: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    summary: {
        type: Object,
        default: () => ({
            total_clicks: 0,
            total_orders: 0,
            total_commission: 0,
            total_approved: 0,
            unattributed_clicks: 0,
            unattributed_click_reasons: [],
            unattributed_orders: 0,
            unattributed_order_reasons: [],
        }),
    },
});

const page = usePage();
const { confirmDialog } = useDialog();
const toast = useToast();

const showCreate = ref(false);
const showEdit = ref(false);
const showColumnsMenu = ref(false);
const creating = ref(false);
const editing = ref(false);
const actionLoadingId = ref(null);
const editId = ref(null);
const currentAction = ref(null);

const createErrors = ref({});
const editErrors = ref({});

const searchQuery = ref(props.filters.search || '');
const activeStatusTab = ref(props.filters.status || 'all');
const activePreset = ref(props.filters.preset || '');
const dateFrom = ref(props.filters.date_from || '');
const dateTo = ref(props.filters.date_to || '');
const sortBy = ref(props.filters.sort || 'created_at');
const sortDirection = ref(props.filters.direction || 'desc');

const createForm = ref({
    destination_url: '',
    short_code: '',
    source: '',
    channel: '',
    sub_id: '',
    campaign_id: '',
});

const editForm = ref({
    destination_url: '',
    source: '',
    channel: '',
    status: 'active',
    campaign_id: '',
});

const statusTabs = [
    { label: 'All', value: 'all' },
    { label: 'Active', value: 'active' },
    { label: 'Paused', value: 'paused' },
    { label: 'Archived', value: 'archived' },
];

const allColumns = [
    { id: 'link', label: 'Link / Destination' },
    { id: 'campaign', label: 'Campaign' },
    { id: 'status', label: 'Status' },
    { id: 'clicks', label: 'Clicks' },
    { id: 'orders', label: 'Orders' },
    { id: 'commission', label: 'Commission' },
    { id: 'cr', label: 'Order Rate' },
    { id: 'created', label: 'Created At' },
];

const columnsStorageKey = 'affentra.links.columns.v1';
const visibleColumns = ref(loadColumns());

const visibleColumnCount = computed(() => visibleColumns.value.length);
const totalCR = computed(() => {
    return formatOrderRate(
        Number(props.summary.total_orders || 0),
        Number(props.summary.total_clicks || 0),
    );
});
const unattributedClickReasonText = computed(() => {
    const reasons = Array.isArray(props.summary.unattributed_click_reasons)
        ? props.summary.unattributed_click_reasons
        : [];
    if (!reasons.length) return '';
    return reasons
        .slice(0, 3)
        .map((item) => `${item.label || item.reason || 'Không xác định'} (${fmtNum(item.count || 0)})`)
        .join(' · ');
});
const unattributedOrderReasonText = computed(() => {
    const reasons = Array.isArray(props.summary.unattributed_order_reasons)
        ? props.summary.unattributed_order_reasons
        : [];
    if (!reasons.length) return '';
    return reasons
        .slice(0, 3)
        .map((item) => `${item.label || item.reason || 'Không xác định'} (${fmtNum(item.count || 0)})`)
        .join(' · ');
});
const pageRange = computed(() => Array.from({ length: props.links.last_page || 1 }, (_, i) => i + 1));

watch(() => props.filters, (nextFilters) => {
    searchQuery.value = nextFilters.search || '';
    activeStatusTab.value = nextFilters.status || 'all';
    activePreset.value = nextFilters.preset || '';
    dateFrom.value = nextFilters.date_from || '';
    dateTo.value = nextFilters.date_to || '';
    sortBy.value = nextFilters.sort || 'created_at';
    sortDirection.value = nextFilters.direction || 'desc';
}, { deep: true });

function loadColumns() {
    const fallback = allColumns.map((column) => column.id);

    try {
        const raw = localStorage.getItem(columnsStorageKey);
        if (!raw) {
            return fallback;
        }

        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            return fallback;
        }

        const allowed = new Set(fallback);
        const normalized = parsed.filter((id) => allowed.has(id));

        return normalized.length ? normalized : fallback;
    } catch {
        return fallback;
    }
}

function hasColumn(columnId) {
    return visibleColumns.value.includes(columnId);
}

function toggleColumn(columnId) {
    if (hasColumn(columnId)) {
        if (visibleColumns.value.length === 1) {
            return;
        }
        visibleColumns.value = visibleColumns.value.filter((id) => id !== columnId);
    } else {
        visibleColumns.value = [...visibleColumns.value, columnId];
    }

    localStorage.setItem(columnsStorageKey, JSON.stringify(visibleColumns.value));
}

function statusLabel(status) {
    return page.props.constants?.link_statuses?.[status] ?? status;
}

function statusStyle(status) {
    const map = {
        active: { background: 'var(--success-bg)', color: 'var(--success-text)' },
        paused: { background: 'var(--warning-bg)', color: 'var(--warning-text)' },
        archived: { background: 'var(--surface-2)', color: 'var(--text-muted)' },
    };

    return map[status] || map.archived;
}

function fmtNum(value) {
    return Number(value || 0).toLocaleString('vi-VN');
}

function fmtCurrency(value) {
    return new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND',
        maximumFractionDigits: 0,
    }).format(Number(value || 0));
}

function metricClicks(link) {
    return Number(link.metrics_clicks ?? link.clicks_count ?? 0);
}

function metricOrders(link) {
    return Number(link.metrics_orders ?? link.orders_count ?? 0);
}

function metricCommission(link) {
    return Number(link.metrics_commission ?? 0);
}

function convRate(link) {
    return formatOrderRate(metricOrders(link), metricClicks(link));
}

function formatOrderRate(orders, clicks) {
    const normalizedOrders = Number(orders || 0);
    const normalizedClicks = Number(clicks || 0);

    if (!Number.isFinite(normalizedOrders) || !Number.isFinite(normalizedClicks)) {
        return '0.0';
    }

    if (normalizedClicks <= 0) {
        return normalizedOrders > 0 ? '—' : '0.0';
    }

    // One click can fan out to multiple item rows; keep Order Rate bounded for UX readability.
    const rawRate = (normalizedOrders / normalizedClicks) * 100;
    const boundedRate = Math.min(Math.max(rawRate, 0), 100);

    return boundedRate.toFixed(1);
}

function formatDate(value) {
    if (!value) {
        return '--';
    }

    return new Intl.DateTimeFormat('vi-VN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(value));
}

function setStatusTab(value) {
    activeStatusTab.value = value;
    navigateWithFilters({ status: value === 'all' ? undefined : value, page: 1 });
}

function setPreset() {
    navigateWithFilters({ preset: activePreset.value || undefined, page: 1 });
}

function applyDateRange() {
    navigateWithFilters({
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        page: 1,
    });
}

function applySorting() {
    navigateWithFilters({
        sort: sortBy.value || 'created_at',
        direction: sortDirection.value || 'desc',
        page: 1,
    });
}

let searchDebounceTimer = null;
function debouncedSearch() {
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => {
        navigateWithFilters({ search: searchQuery.value || undefined, page: 1 });
    }, 350);
}

function goToPage(pageNumber) {
    navigateWithFilters({ page: pageNumber });
}

function navigateWithFilters(partial) {
    const merged = {
        ...props.filters,
        ...partial,
    };

    const cleaned = {};
    for (const [key, value] of Object.entries(merged)) {
        if (value !== undefined && value !== null && value !== '') {
            cleaned[key] = value;
        }
    }

    router.get(route('links.index'), cleaned, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['links', 'filters', 'summary'],
    });
}

function openCreate() {
    createForm.value = {
        destination_url: '',
        short_code: '',
        source: '',
        channel: '',
        sub_id: '',
    };
    createErrors.value = {};
    showCreate.value = true;
}

function openEdit(link) {
    editId.value = link.id;
    editForm.value = {
        destination_url: link.destination_url || '',
        source: link.source || '',
        channel: link.channel || '',
        status: link.status || 'active',
        campaign_id: link.campaign_id || '',
    };
    editErrors.value = {};
    showEdit.value = true;
}

function openDetail(link) {
    router.visit(route('links.show', link.id));
}

async function apiRequest(url, options = {}) {
    const headers = {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]')?.content || '',
        ...(options.headers || {}),
    };

    if (options.body && !headers['Content-Type']) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(url, {
        ...options,
        headers,
    });

    const payload = await response.json();

    if (!response.ok || !payload.ok) {
        const error = new Error(payload.message || 'Request failed.');
        error.payload = payload;
        throw error;
    }

    return payload;
}

async function submitCreate() {
    creating.value = true;
    createErrors.value = {};

    try {
        const response = await apiRequest(route('api.links.store'), {
            method: 'POST',
            body: JSON.stringify(createForm.value),
        });

        showCreate.value = false;
        toast.success(response.message || 'Đã tạo tracking link.');
        router.reload({ only: ['links', 'summary'] });
    } catch (error) {
        createErrors.value = error.payload?.errors || {};
        toast.error(error.message || 'Không thể tạo tracking link.');
    } finally {
        creating.value = false;
    }
}

async function submitEdit() {
    if (!editId.value) {
        return;
    }

    editing.value = true;
    editErrors.value = {};

    try {
        await apiRequest(route('api.links.update', editId.value), {
            method: 'PATCH',
            body: JSON.stringify(editForm.value),
        });

        showEdit.value = false;
        toast.success('Đã cập nhật tracking link.');
        router.reload({ only: ['links', 'summary'] });
    } catch (error) {
        editErrors.value = error.payload?.errors || {};
        toast.error(error.message || 'Không thể cập nhật tracking link.');
    } finally {
        editing.value = false;
    }
}

async function refreshProduct(link) {
    actionLoadingId.value = link.id;
    currentAction.value = 'refresh';
    try {
        const response = await apiRequest(route('api.links.refresh-product', link.id), {
            method: 'POST',
        });
        toast.success(response.message || 'Đã làm mới thông tin sản phẩm.');
        router.reload({ only: ['links', 'summary'] });
    } catch (error) {
        toast.error(error.message || 'Không thể làm mới thông tin sản phẩm.');
    } finally {
        actionLoadingId.value = null;
        currentAction.value = null;
    }
}

function createAIContent(link) {
    router.visit(route('ai.content.index', { link_id: link.id }));
}

function copyLink(link) {
    const url = window.location.origin + route('redirect', link.short_code);
    navigator.clipboard.writeText(url)
        .then(() => {
            toast.success('Đã copy link tracking.');
        })
        .catch(() => {
            toast.error('Không thể copy link.');
        });
}

async function toggleStatus(link) {
    if (link.status === 'archived') {
        return;
    }

    actionLoadingId.value = link.id;

    try {
        const nextStatus = link.status === 'active' ? 'paused' : 'active';
        await apiRequest(route('api.links.update', link.id), {
            method: 'PATCH',
            body: JSON.stringify({ status: nextStatus }),
        });

        toast.success(nextStatus === 'paused' ? 'Đã tạm dừng link.' : 'Đã bật lại link.');
        router.reload({ only: ['links', 'summary'] });
    } catch (error) {
        toast.error(error.message || 'Không thể cập nhật trạng thái link.');
    } finally {
        actionLoadingId.value = null;
    }
}

async function archiveLink(link) {
    if (link.status === 'archived') {
        return;
    }

    const confirmed = await confirmDialog({
        variant: 'warning',
        title: 'Archive tracking link?',
        description: `Link ${link.short_code} sẽ chuyển sang trạng thái lưu trữ và không còn nhận click mới.`,
        confirmText: 'Archive',
        cancelText: 'Hủy',
    });

    if (!confirmed) {
        return;
    }

    actionLoadingId.value = link.id;

    try {
        await apiRequest(route('api.links.archive', link.id), {
            method: 'PATCH',
        });

        toast.success('Đã lưu trữ tracking link.');
        router.reload({ only: ['links', 'summary'] });
    } catch (error) {
        toast.error(error.message || 'Không thể lưu trữ tracking link.');
    } finally {
        actionLoadingId.value = null;
    }
}

</script>
