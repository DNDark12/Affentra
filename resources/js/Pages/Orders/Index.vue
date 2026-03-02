<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Đơn hàng</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Orders & Commissions</h1>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted)">Hiển thị {{ orders.total }} đơn hàng</p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex gap-1 p-1 rounded-md" style="background: var(--surface-2); border: 1px solid var(--border)">
                        <button v-for="p in periods" :key="p.value"
                            @click="applyFilter('period', p.value)"
                            class="text-xs px-2.5 py-1 rounded font-medium transition-all"
                            :style="(filters.period || '30days') === p.value
                                ? { background: 'var(--surface-1)', color: 'var(--text-primary)' }
                                : { color: 'var(--text-secondary)' }"
                        >{{ p.label }}</button>
                    </div>
                    
                    <button @click="showImportModal = true" class="af-btn-primary text-sm h-9 px-4 flex items-center gap-1.5" v-if="page.props.auth.user.role !== 'ctv'">
                        <Upload :size="14" />
                        Import CSV
                    </button>
                </div>
            </div>

            <!-- KPI Row -->
            <div class="grid grid-cols-4 gap-4">
                <div class="af-surface p-4">
                    <p class="text-xs mb-1 font-medium" style="color: var(--text-muted)">Tổng đơn hàng</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">{{ stats?.total_orders?.toLocaleString() || 0 }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs mb-1 font-medium" style="color: var(--text-muted)">Đơn đã duyệt</p>
                    <p class="text-2xl font-bold" style="color: var(--success-text)">{{ stats?.approved_orders?.toLocaleString() || 0 }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs mb-1 font-medium" style="color: var(--text-muted)">Tổng giá trị hàng</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">{{ fmtMoney(stats?.total_amount) }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs mb-1 font-medium" style="color: var(--text-muted)">Tổng hoa hồng (tạm tính)</p>
                    <p class="text-2xl font-bold" style="color: var(--color-primary-500)">{{ fmtMoney(stats?.total_commission) }}</p>
                </div>
            </div>

            <!-- Filters & Search -->
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="flex gap-2">
                    <select v-model="filterStatus" @change="applyFilter('status', filterStatus)" class="af-input h-9 text-sm" style="width: 150px">
                        <option value="">Tất cả trạng thái</option>
                        <option value="pending">Chờ duyệt</option>
                        <option value="approved">Đã duyệt</option>
                        <option value="rejected">Từ chối</option>
                    </select>

                    <select v-model="filterPlatform" @change="applyFilter('platform', filterPlatform)" class="af-input h-9 text-sm" style="width: 150px">
                        <option value="">Tất cả sàn</option>
                        <option value="shopee">Shopee</option>
                        <option value="lazada">Lazada</option>
                        <option value="tiktok">TikTok</option>
                    </select>
                </div>
                <div class="relative">
                    <Search :size="13" class="af-input-search-icon absolute left-3 top-1/2 -translate-y-1/2" style="color: var(--text-muted)" />
                    <input type="text" v-model="searchQuery" @keyup.enter="applyFilter('search', searchQuery)" placeholder="Mã đơn / ID gốc / shop / sản phẩm..." class="af-input af-input-search h-9 text-sm w-80" />
                </div>
            </div>

            <!-- Table -->
            <div class="af-surface overflow-x-auto">
                <table class="w-full text-sm border-collapse whitespace-nowrap">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                            <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Sàn & Mã Đơn</th>
                            <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Shop</th>
                            <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Sản phẩm</th>
                            <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Nguồn Link</th>
                            <th class="text-left px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">CTV Sở hữu</th>
                            <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Giá Trị</th>
                            <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Hoa Hồng (Chi tiết)</th>
                            <th class="text-center px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Trạng Thái</th>
                            <th class="text-center px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Thanh Toán</th>
                            <th class="text-right px-4 py-3 font-medium text-xs" style="color: var(--text-muted)">Thời gian</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!orders.data?.length">
                            <td colspan="10" class="text-center py-16" style="color: var(--text-muted)">
                                <div class="flex flex-col items-center gap-3">
                                    <ShoppingCart :size="32" style="color: var(--text-muted)" />
                                    <p>Không tìm thấy đơn hàng nào.</p>
                                    <p class="text-xs" v-if="page.props.auth.user.role !== 'ctv'">Nhấn "Import CSV" để tải lên báo cáo đối soát từ hệ thống affiliate.</p>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="o in orders.data" :key="o.id"
                            style="border-bottom: 1px solid var(--border)"
                            class="hover:bg-[var(--surface-2)] transition-colors">
                            
                            <td class="px-4 py-3">
                                <span class="capitalize text-xs font-semibold px-1.5 py-0.5 rounded mr-2" 
                                      :style="platformStyle(o.platform)">{{ o.platform }}</span>
                                <span class="font-mono text-xs font-medium" style="color: var(--text-primary)">{{ o.order_code }}</span>
                                <p v-if="o.external_order_id" class="text-xs font-mono mt-1" style="color: var(--text-muted)">
                                    ID gốc: {{ o.external_order_id }}
                                </p>
                            </td>

                            <td class="px-4 py-3">
                                <p class="text-sm font-medium" style="color: var(--text-primary)">{{ o.shop_name || '—' }}</p>
                                <p v-if="o.shop_id" class="text-xs" style="color: var(--text-muted)">Shop ID: {{ o.shop_id }}</p>
                            </td>

                            <td class="px-4 py-3">
                                <a
                                    v-if="o.product_link"
                                    :href="o.product_link"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-sm font-medium max-w-[280px] truncate block hover:underline"
                                    style="color: var(--color-primary-500)"
                                >
                                    {{ o.product_name || '—' }}
                                </a>
                                <p v-else class="text-sm font-medium max-w-[280px] truncate" style="color: var(--text-primary)">
                                    {{ o.product_name || '—' }}
                                </p>
                                <p class="text-xs mt-0.5" style="color: var(--text-muted)">
                                    Item: {{ o.product_id || '—' }} · Model: {{ o.product_model_id || '—' }}
                                </p>
                                <div class="text-xs mt-1">
                                    <span style="color: var(--text-muted)">SL: {{ o.product_quantity ?? '—' }}</span>
                                </div>
                            </td>
                            
                            <td class="px-4 py-3">
                                <div v-if="o.tracking_link_id">
                                    <p class="text-sm font-medium" style="color: var(--text-primary)">Link #{{ o.tracking_link_id }}</p>
                                    <p v-if="attributionReason(o)" class="text-xs mt-0.5" style="color: var(--text-muted)">
                                        {{ attributionReason(o) }}
                                    </p>
                                    <p v-if="attributionDetail(o)" class="text-xs mt-0.5 font-mono" style="color: var(--text-muted)">
                                        {{ attributionDetail(o) }}
                                    </p>
                                </div>
                                <div v-else>
                                    <span class="text-xs" style="color: var(--text-muted)">{{ sourceLinkLabel(o) }}</span>
                                    <p v-if="attributionReason(o)" class="text-xs mt-0.5" style="color: var(--text-muted)">
                                        {{ attributionReason(o) }}
                                    </p>
                                    <p v-if="attributionDetail(o)" class="text-xs mt-0.5 font-mono" style="color: var(--text-muted)">
                                        {{ attributionDetail(o) }}
                                    </p>
                                </div>
                            </td>
                            
                            <td class="px-4 py-3">
                                <p class="text-sm" style="color: var(--text-primary)">User ID: {{ o.user_id }}</p>
                            </td>
                            
                            <td class="px-4 py-3 text-right font-medium" style="color: var(--text-primary)">
                                <p class="font-semibold">{{ fmtMoney(o.order_amount) }}</p>
                                <p
                                    v-if="Number(o.listed_amount || 0) > 0 && Number(o.listed_amount || 0) !== Number(o.order_amount || 0)"
                                    class="text-xs mt-1"
                                    style="color: var(--text-muted)"
                                >
                                    Giá SP: {{ fmtMoney(o.listed_amount) }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <p class="font-semibold" style="color: var(--color-primary-500)">{{ fmtMoney(o.commission) }}</p>
                                <div class="text-xs mt-1" style="color: var(--text-muted)">
                                    <p>Shop: {{ fmtMoney(o.commission_platform) }}</p>
                                    <p>Xtra: {{ fmtMoney(o.commission_brand) }}</p>
                                    <p v-if="Number(o.commission_other || 0) > 0">Khác: {{ fmtMoney(o.commission_other) }}</p>
                                </div>
                            </td>
                            
                            <td class="px-4 py-3 text-center">
                                <span class="text-xs font-medium px-2 py-0.5 rounded-full" :style="orderStatusStyle(o.status)">
                                    {{ statusLabel(o.status) }}
                                </span>
                            </td>
                            
                            <td class="px-4 py-3 text-center">
                                <span v-if="o.payout_status === 'paid' || o.paid_at" class="text-xs font-medium px-2 py-0.5 rounded-full bg-green-500/10 text-green-500 flex items-center justify-center gap-1 w-max mx-auto">
                                    <Check :size="12" /> Đã chi trả
                                </span>
                                <span v-else-if="o.payout_status === 'processing'" class="text-xs font-medium px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-600 flex items-center justify-center gap-1 w-max mx-auto">
                                    Đang đối soát
                                </span>
                                <span v-else class="text-xs font-medium px-2 py-0.5 rounded-full" style="background: var(--surface-2); color: var(--text-muted)">
                                    Chưa thanh toán
                                </span>
                                <p v-if="o.paid_at" class="text-[11px] mt-1" style="color: var(--text-muted)">
                                    {{ formatDateTimeSeconds(o.paid_at) }}
                                </p>
                            </td>

                            <td class="px-4 py-3 text-right text-xs">
                                <p style="color: var(--text-primary)">Đặt: {{ formatDateTimeSeconds(o.ordered_at) }}</p>
                                <p style="color: var(--text-muted)">Click: {{ formatDateTimeSeconds(o.click_at) }}</p>
                                <p style="color: var(--text-muted)">Hoàn tất: {{ formatDateTimeSeconds(o.completed_at) }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="orders.last_page > 1" class="flex items-center justify-between text-xs mt-2" style="color: var(--text-muted)">
                <span>Hiển thị {{ orders.from }}–{{ orders.to }} / {{ orders.total }} đơn hàng</span>
                <div class="flex gap-1">
                    <button v-for="p in paginationLinks" :key="p.label"
                        @click="p.url && router.get(p.url, {}, { preserveState: true })"
                        class="px-2.5 h-7 rounded text-xs transition-colors"
                        :class="[
                            p.active ? 'bg-[var(--color-primary-500)] text-white' : 'text-[var(--text-secondary)] hover:bg-[var(--surface-2)]',
                            !p.url ? 'opacity-50 cursor-not-allowed' : ''
                        ]"
                        v-html="p.label"
                    ></button>
                </div>
            </div>

        </div>

        <!-- Import Modal -->
        <div v-if="showImportModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
            <div class="af-surface rounded-xl shadow-2xl w-full max-w-md mx-auto flex flex-col overflow-hidden" style="border: 1px solid var(--border)">
                <div class="px-6 py-4 flex items-center justify-between" style="border-bottom: 1px solid var(--border)">
                    <h3 class="font-bold text-lg" style="color: var(--text-primary)">Import Đơn Hàng từ CSV</h3>
                    <button @click="closeImportModal" class="p-1 rounded-md hover:bg-[var(--surface-2)] transition-colors">
                        <X :size="18" style="color: var(--text-muted)" />
                    </button>
                </div>
                
                <div class="px-6 py-6 flex flex-col gap-5">
                    <div v-if="!importProgress">
                        <div class="flex flex-col gap-1.5 mb-4">
                            <label class="text-xs font-medium" style="color: var(--text-secondary)">Chọn Nền Tảng Sàn</label>
                            <select v-model="importForm.platform" class="af-input text-sm">
                                <option value="shopee">Shopee Affiliate</option>
                                <option value="lazada">Lazada Affiliate</option>
                                <option value="tiktok">TikTok Shop Creator</option>
                            </select>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="text-xs font-medium" style="color: var(--text-secondary)">File CSV báo cáo</label>
                            <label class="flex flex-col items-center justify-center w-full h-32 border-2 border-dashed rounded-lg cursor-pointer transition-colors"
                                :style="importForm.file ? { borderColor: 'var(--color-primary-500)', background: 'var(--surface-2)' } : { borderColor: 'var(--border)', background: 'var(--surface-1)' }">
                                <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                    <Upload :size="24" :style="{ color: importForm.file ? 'var(--color-primary-500)' : 'var(--text-muted)', marginBottom: '8px' }" />
                                    <p class="mb-2 text-sm" style="color: var(--text-primary)">
                                        <span class="font-semibold">{{ importForm.file ? importForm.file.name : 'Click để chọn file CSV' }}</span>
                                    </p>
                                    <p class="text-xs" style="color: var(--text-muted)">MAX. 10MB</p>
                                </div>
                                <input id="dropzone-file" type="file" accept=".csv" class="hidden" @change="handleFileChange" />
                            </label>
                            <div v-if="importError" class="text-xs mt-1" style="color: var(--danger-text)">{{ importError }}</div>
                        </div>
                    </div>

                    <!-- Progress View -->
                    <div v-else class="flex flex-col items-center justify-center py-6 text-center">
                        <Loader2 v-if="['pending', 'running'].includes(importProgress.status)" :size="36" class="animate-spin mb-4" style="color: var(--color-primary-500)" />
                        <CheckCircle2 v-else-if="importProgress.status === 'completed'" :size="36" class="mb-4" style="color: var(--success-text)" />
                        <AlertCircle v-else-if="importProgress.status === 'failed'" :size="36" class="mb-4" style="color: var(--danger-text)" />

                        <h4 class="text-lg font-bold mb-1" style="color: var(--text-primary)">
                            Trạng thái: <span class="capitalize">{{ importProgress.status }}</span>
                        </h4>
                        
                        <div class="w-full text-left my-4 af-surface p-3 rounded-lg text-sm flex flex-col gap-2" style="border: 1px solid var(--border)">
                            <div class="flex justify-between">
                                <span style="color: var(--text-muted)">Tổng số dòng:</span>
                                <span class="font-mono">{{ importProgress.records_fetched }}</span>
                            </div>
                            <div class="flex justify-between" style="color: var(--success-text)">
                                <span>Thành công (Upsert):</span>
                                <span class="font-mono">{{ importProgress.records_upserted }}</span>
                            </div>
                            <div class="flex justify-between" style="color: var(--danger-text)">
                                <span>Lỗi / Bỏ qua:</span>
                                <span class="font-mono">{{ importProgress.records_failed }}</span>
                            </div>
                        </div>

                        <p v-if="importProgress.error_message" class="text-xs text-left w-full p-2 bg-red-500/10 text-red-500 rounded font-mono">
                            {{ importProgress.error_message }}
                        </p>
                    </div>
                </div>

                <div class="px-6 py-4 flex justify-end gap-3" style="border-top: 1px solid var(--border); background: var(--surface-2)">
                    <button v-if="!importProgress" @click="closeImportModal" class="af-btn-outline text-sm px-4 h-9">Hủy</button>
                    <button v-if="!importProgress" @click="submitImport" :disabled="!importForm.file || isImporting" class="af-btn-primary text-sm px-4 h-9 flex items-center justify-center min-w-[100px]">
                        <Loader2 v-if="isImporting" :size="16" class="animate-spin" />
                        <span v-else>Bắt đầu Import</span>
                    </button>

                    <button v-if="importProgress" @click="finishImport" class="af-btn-primary text-sm px-4 h-9">
                        {{ ['pending', 'running'].includes(importProgress.status) ? 'Chạy nền & Đóng' : 'Hoàn thành' }}
                    </button>
                </div>
            </div>
        </div>
        
    </AppShell>
</template>

<script setup>
import { ref, computed, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Upload, Search, ShoppingCart, Check, X, Loader2, CheckCircle2, AlertCircle } from 'lucide-vue-next';
import AppShell from '@/Layouts/AppShell.vue';
import axios from 'axios';

const props = defineProps({
    orders:  { type: Object, default: () => ({ data: [], total: 0, current_page: 1, last_page: 1, links: [] }) },
    stats:   { type: Object, default: () => ({ total_orders: 0, approved_orders: 0, total_amount: 0, total_commission: 0 }) },
    filters: { type: Object, default: () => ({}) },
});

const page = usePage();

// Filters State
const filterStatus   = ref(props.filters.status || '');
const filterPlatform = ref(props.filters.platform || '');
const searchQuery    = ref(props.filters.search || '');

const periods = [
    { label: '7 Ngày qua', value: '7days' },
    { label: '30 Ngày qua', value: '30days' },
    { label: '90 Ngày qua', value: '90days' },
];

function applyFilter(key, value) {
    const f = { ...props.filters, [key]: value };
    // Clear empty
    Object.keys(f).forEach(k => { if (!f[k]) delete f[k] });
    router.get(route('orders.index'), f, { preserveState: true, replace: true });
}

// Import Modal State
const showImportModal = ref(false);
const isImporting = ref(false);
const importError = ref('');
const importForm = ref({
    platform: 'shopee',
    file: null,
});

const importProgress = ref(null);
let pollingInterval = null;

function handleFileChange(e) {
    const file = e.target.files[0];
    if (!file) {
        importForm.value.file = null;
        return;
    }
    
    if (file.type !== 'text/csv' && !file.name.endsWith('.csv')) {
        importError.value = 'Chỉ chấp nhận file định dạng CSV.';
        importForm.value.file = null;
        return;
    }
    
    if (file.size > 10 * 1024 * 1024) {
        importError.value = 'Kích thước file vượt quá giới hạn 10MB.';
        importForm.value.file = null;
        return;
    }
    
    importError.value = '';
    importForm.value.file = file;
}

function closeImportModal() {
    showImportModal.value = false;
    importForm.value.file = null;
    importError.value = '';
}

async function submitImport() {
    if (!importForm.value.file) return;
    
    isImporting.value = true;
    importError.value = '';
    
    const formData = new FormData();
    formData.append('file', importForm.value.file);
    formData.append('platform', importForm.value.platform);
    
    try {
        const response = await axios.post(route('api.orders.import'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        });
        
        if (response.data.ok) {
            startPolling(response.data.data.sync_run_id);
        }
    } catch (err) {
        importError.value = err.response?.data?.message || 'Có lỗi xảy ra khi upload. Vui lòng thử lại.';
        isImporting.value = false;
    }
}

function startPolling(syncRunId) {
    importProgress.value = {
        status: 'pending',
        records_fetched: 0,
        records_upserted: 0,
        records_failed: 0,
    };
    
    pollingInterval = setInterval(async () => {
        try {
            const res = await axios.get(route('api.orders.import.status', syncRunId));
            if (res.data.ok) {
                importProgress.value = res.data.data;
                
                if (['completed', 'failed'].includes(res.data.data.status)) {
                    clearInterval(pollingInterval);
                    isImporting.value = false;
                }
            }
        } catch (e) {
            console.error('Lỗi khi cập nhật trạng thái', e);
        }
    }, 2000);
}

function finishImport() {
    const isDone = ['completed', 'failed'].includes(importProgress.value?.status);
    
    if (pollingInterval) clearInterval(pollingInterval);
    importProgress.value = null;
    isImporting.value = false;
    closeImportModal();
    
    // Refresh page data if completed
    if (isDone) {
        router.reload({ only: ['orders', 'stats'] });
    }
}

onUnmounted(() => {
    if (pollingInterval) clearInterval(pollingInterval);
});

// Formatters
const paginationLinks = computed(() => {
    // Exclude 'Previous' and 'Next' string labels mapping to &laquo; etc if they are standard Laravel
    return props.orders.links || [];
});

function fmtMoney(v) {
    if (v === null || v === undefined) return '—';
    return Number(v).toLocaleString('vi-VN') + ' đ';
}

function formatDateTimeSeconds(ds) {
    if (!ds) return '—';
    try {
        const d = new Date(ds);
        return d.toLocaleString('vi-VN', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        });
    } catch (e) {
        return ds;
    }
}

function attributionSource(order) {
    return order?.source_meta?.attribution_source || null;
}

function attributionReason(order) {
    const source = attributionSource(order);
    const labels = {
        sub_id: 'Khớp theo Sub ID',
        product_key: 'Khớp theo sản phẩm',
        auto_link: 'Tự tạo link từ dữ liệu order',
        missing_sub_id: 'Shopee không trả Sub ID',
        ambiguous_product: 'Trùng nhiều link cùng sản phẩm',
        unmatched_product: 'Không tìm thấy link theo sản phẩm',
        direct: 'Không có tín hiệu attribution từ Shopee',
    };

    return labels[source] || null;
}

function attributionDetail(order) {
    const detail = order?.source_meta?.attribution_detail;
    if (!detail) return null;
    return `Key: ${detail}`;
}

function sourceLinkLabel(order) {
    if (attributionSource(order) === 'ambiguous_product') {
        return 'Không xác định';
    }

    return 'Trực tiếp';
}

function statusLabel(s) {
    const m = { pending: 'Chờ duyệt', approved: 'Đã duyệt', rejected: 'Từ chối' };
    return m[s] || s;
}

function orderStatusStyle(s) {
    const m = {
        approved: { background: 'var(--success-bg)', color: 'var(--success-text)' },
        pending:  { background: 'var(--warning-bg)', color: 'var(--warning-text)' },
        rejected: { background: 'var(--danger-bg)',  color: 'var(--danger-text)' },
    };
    return m[s] ?? { background: 'var(--surface-2)', color: 'var(--text-muted)' };
}

function platformStyle(p) {
    const m = {
        shopee: { background: '#f53d2d20', color: '#f53d2d' },
        lazada: { background: '#0f146d20', color: '#0f146d' },
        tiktok: { background: '#00000020', color: '#111111' }, // Default tiktok
    };
    return m[p] ?? { background: 'var(--surface-2)', color: 'var(--text-muted)' };
}
</script>
