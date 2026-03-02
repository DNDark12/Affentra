<template>
    <AppShell>
        <div class="flex flex-col gap-6" style="padding: 24px;">
            <!-- Top Controls: Header + Connection Selector -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 flex items-center justify-center">
                            <i class="ph ph-compass text-lg"></i>
                        </div>
                        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white tracking-tight">Khám phá sản phẩm</h1>
                    </div>
                    <p class="text-[14px] text-zinc-500 dark:text-zinc-400">
                        Tìm sản phẩm hoa hồng cao và tạo link affiliate. Kết quả được cache 30 phút.
                    </p>
                </div>

                <!-- Platform Selector -->
                <div class="flex items-center gap-3">
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Tài khoản kết nối:</span>
                    <select v-model="selectedConnectionId" class="h-10 pl-3 pr-8 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-sm font-medium focus:ring-1 focus:ring-indigo-500 min-w-[200px]">
                        <option v-if="!activeConnections.length" value="" disabled>Chưa có kết nối nào</option>
                        <option v-for="conn in activeConnections" :key="conn.id" :value="conn.id">
                            {{ formatPlatformName(conn.platform) }} - {{ maskAppId(conn.app_id) }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Search Panel -->
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl p-5 shadow-sm flex flex-col gap-4">
                <form @submit.prevent="performSearch" class="flex flex-col md:flex-row items-stretch md:items-center gap-3">
                    <div class="relative flex-1">
                        <i class="ph ph-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-zinc-400 text-lg"></i>
                        <input type="text" v-model="searchUrl" 
                               placeholder="Dán link sản phẩm Shopee hoặc nhập Item ID..." 
                               class="w-full h-12 pl-12 pr-[120px] rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/30 text-[15px] focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-all text-zinc-900 dark:text-zinc-100 font-medium" />
                        
                        <button type="submit" :disabled="!searchUrl || isSearching || !selectedConnectionId" class="absolute right-1.5 top-1.5 bottom-1.5 px-6 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-1.5 shadow-sm">
                            <i v-if="isSearching" class="ph ph-spinner animate-spin text-lg"></i>
                            <span v-else>Tìm kiếm</span>
                        </button>
                    </div>
                </form>

                <!-- Quick Filters / Tags -->
                <div class="flex items-center gap-3 text-sm">
                    <span class="text-zinc-500 dark:text-zinc-400">Lọc nhanh:</span>
                    <button class="px-3 py-1.5 rounded-full bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 font-medium transition-colors cursor-not-allowed opacity-50">Hoa hồng cao</button>
                    <button class="px-3 py-1.5 rounded-full bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 font-medium transition-colors cursor-not-allowed opacity-50">Giá thấp</button>
                    <button class="px-3 py-1.5 rounded-full bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 font-medium transition-colors cursor-not-allowed opacity-50">Phổ biến</button>
                </div>
            </div>

            <div v-if="activeConnections.length === 0" class="px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[14px]">
                Chưa có kết nối Shopee ở trạng thái Active. Hãy cấu hình trong màn Integrations trước khi tìm offer.
            </div>

            <!-- Error State -->
            <div v-if="errorMsg" class="px-4 py-3 rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 text-red-700 dark:text-red-400 text-[14px] flex items-center gap-2">
                <i class="ph ph-warning-circle text-lg"></i>
                <span>{{ errorMsg }}</span>
            </div>

            <!-- Empty / Initial State -->
            <div v-if="!isSearching && !hasSearched && !errorMsg" class="py-16 flex flex-col items-center justify-center text-zinc-500 dark:text-zinc-400">
                <i class="ph ph-magnifying-glass text-6xl opacity-20 mb-4"></i>
                <p class="text-base font-medium">Nhập link hoặc ID sản phẩm để bắt đầu</p>
                <p class="text-sm mt-1 opacity-70">Hiện chỉ hỗ trợ nền tảng Shopee Vietnam kết nối qua API.</p>
            </div>

            <!-- Searching Skeleton -->
            <div v-if="isSearching" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
                <div v-for="i in 4" :key="i" class="animate-pulse bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl p-4 flex flex-col gap-4">
                    <div class="aspect-square bg-zinc-200 dark:bg-zinc-800 rounded-lg"></div>
                    <div class="h-4 bg-zinc-200 dark:bg-zinc-800 rounded w-3/4"></div>
                    <div class="h-4 bg-zinc-200 dark:bg-zinc-800 rounded w-1/2"></div>
                    <div class="mt-auto flex gap-2 pt-2">
                        <div class="h-9 bg-zinc-200 dark:bg-zinc-800 rounded flex-1"></div>
                        <div class="h-9 bg-zinc-200 dark:bg-zinc-800 rounded flex-1"></div>
                    </div>
                </div>
            </div>

            <!-- Results Grid -->
            <div v-if="!isSearching && hasSearched && !errorMsg" class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        {{ searchResults.length }} kết quả
                        <span v-if="searchResults.length === 0" class="font-normal text-zinc-500">(Không tìm thấy sản phẩm hợp lệ)</span>
                    </h3>
                    <!-- Toggles for view mode could go here -->
                </div>

                <div v-if="searchResults.length > 0" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
                    <!-- Offer Card -->
                    <div v-for="offer in searchResults" :key="offer.item_id" class="group bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 hover:border-indigo-300 dark:hover:border-indigo-700/50 rounded-xl overflow-hidden shadow-sm hover:shadow transition-all flex flex-col relative">
                        <!-- Red Commission Badge on top left corner -->
                        <div v-if="offer.commission_rate" class="absolute top-0 left-0 bg-[#ee4d2d] text-white text-[11px] font-bold px-2.5 py-1 rounded-br-xl z-20 shadow-sm">
                            Hoa hồng {{ offer.commission_rate }}%
                        </div>
                        
                        <!-- Image Container with Mall Badge overlay -->
                        <div class="aspect-square relative overflow-hidden bg-zinc-100 dark:bg-zinc-800 border-b border-zinc-100 dark:border-zinc-800 shrink-0">
                            <!-- Mall Badge -->
                            <div class="absolute top-8 left-2 bg-emerald-50 text-emerald-600 border border-emerald-200 shadow-sm text-[10px] font-bold px-1.5 py-0.5 rounded flex items-center gap-1 z-10 uppercase tracking-wide">
                                <i class="ph ph-check-circle-fill"></i> Shopee Mall
                            </div>
                            <img v-if="offer.image_url" :src="offer.image_url" alt="Product Image" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                            <div v-else class="w-full h-full flex items-center justify-center text-zinc-400">
                                <i class="ph ph-image text-3xl"></i>
                            </div>
                        </div>

                        <!-- Card Body -->
                        <div class="p-4 flex flex-col flex-1 gap-1">
                            <h3 class="text-[13px] font-medium leading-[1.3] text-zinc-900 dark:text-zinc-100 line-clamp-2 h-[34px] mb-1" :title="offer.item_name">
                                {{ offer.item_name }}
                            </h3>
                            
                            <div class="flex items-end gap-2 mt-auto">
                                <span class="text-[15px] font-bold text-[#ee4d2d] tracking-tight">{{ formatCurrency(offer.price) }}</span>
                                <span class="text-[11px] text-zinc-400 dark:text-zinc-500 pb-0.5">Đã bán 13k+</span>
                            </div>

                            <div class="flex items-center justify-between mt-2 pt-2 border-t border-zinc-100 border-dashed dark:border-zinc-800/80">
                                <span class="text-[11px] text-zinc-500">EST. Hoa hồng</span>
                                <span class="text-[13px] font-bold text-[#ee4d2d]">
                                    +{{ formatCurrency(offer.estimated_commission || (offer.price * (offer.commission_rate||0) / 100)) }}
                                </span>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 mt-4">
                                <button @click="quickCopy(offer)" class="flex-1 h-8 rounded-lg border border-zinc-200 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 text-[12px] font-semibold flex items-center justify-center gap-1.5 transition-colors">
                                    <i class="ph ph-copy"></i> Copy Link
                                </button>
                                <button @click="openGetLinkModal(offer)" class="flex-1 h-8 rounded-lg bg-indigo-50 border border-indigo-100 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-500/10 dark:border-indigo-500/20 dark:text-indigo-400 dark:hover:bg-indigo-500/20 text-[12px] font-semibold flex items-center justify-center gap-1.5 transition-colors">
                                    <i class="ph ph-link"></i> Lấy Link Động
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>

        <!-- Dynamic Drawer for Advanced Linking -->
        <DrawerGetLink 
            :isOpen="isGetLinkOpen"
            :offer="selectedOffer"
            :connectionId="selectedConnectionId ? Number(selectedConnectionId) : null"
            @close="closeGetLinkModal"
        />

    </AppShell>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell.vue';
import DrawerGetLink from './Partials/DrawerGetLink.vue';

const props = defineProps({
    connections: {
        type: Array,
        default: () => [],
    }
});

// Select the first valid active connection to default to
const activeConnections = computed(() => {
    return props.connections.filter((connection) => connection.status === 'active' && connection.platform === 'shopee');
});
const selectedConnectionId = ref(activeConnections.value.length ? String(activeConnections.value[0].id) : '');

function formatPlatformName(p) {
    if (p === 'shopee') return 'Shopee Vietnam';
    return p;
}

function maskAppId(appId) {
    if (!appId) return '';
    return '*'.repeat(Math.max(appId.length - 4, 3)) + appId.slice(-4);
}

function formatCurrency(amount) {
    if (!amount && amount !== 0) return '';
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(amount);
}

// ----------------------------------------------------
// Search State
// ----------------------------------------------------
const searchUrl = ref('');
const isSearching = ref(false);
const hasSearched = ref(false);
const errorMsg = ref('');
const searchResults = ref([]); // Arrays in case API returns multiple based on shop link search

async function performSearch() {
    if (!searchUrl.value || !selectedConnectionId.value) return;
    
    isSearching.value = true;
    errorMsg.value = '';
    hasSearched.value = true;
    searchResults.value = [];

    try {
        const res = await axios.get(route('api.offers.search'), {
            params: {
                connection_id: Number(selectedConnectionId.value),
                ...buildSearchParams(searchUrl.value),
            },
        });

        if (res.data?.ok) {
            const nodes = Array.isArray(res.data?.data?.nodes) ? res.data.data.nodes : [];
            searchResults.value = nodes
                .map(normalizeOffer)
                .filter((offer) => offer.item_id && offer.item_url);
        } else {
            errorMsg.value = res.data?.message || 'Không tìm thấy sản phẩm. Vui lòng kiểm tra lại URL hoặc ID.';
        }
    } catch (e) {
        errorMsg.value = e.response?.data?.message || 'Có lỗi hệ thống xảy ra khi tìm dữ liệu Offer.';
    } finally {
        isSearching.value = false;
    }
}

// ----------------------------------------------------
// Link Generation State
// ----------------------------------------------------
const isGetLinkOpen = ref(false);
const selectedOffer = ref(null);

function openGetLinkModal(offer) {
    selectedOffer.value = offer;
    isGetLinkOpen.value = true;
}

function closeGetLinkModal() {
    isGetLinkOpen.value = false;
    setTimeout(() => {
        selectedOffer.value = null;
    }, 300);
}

// Quick copy creates standard clean link with no external sub-ids, via simple API request
async function quickCopy(offer) {
    try {
        const payload = {
            connection_id: Number(selectedConnectionId.value),
            offer_link: offer.item_url,
            item_id: offer.item_id,
            product_name: offer.item_name,
            shop_id: offer.shop_id ?? undefined,
            commission_rate: offer.commission_rate ?? undefined,
            image_url: offer.image_url ?? undefined,
        };
        const res = await axios.post(route('api.offers.getLink'), payload);
        if (res.data?.ok) {
            const link = res.data?.data?.track_url || res.data?.data?.destination_url;
            if (!link) {
                throw new Error('No link returned from API.');
            }
            navigator.clipboard.writeText(link).then(() => {
                alert('Copied link: ' + link);
            });
        }
    } catch (e) {
        alert('Có lỗi khi tạo link nhanh.');
    }
}

function buildSearchParams(rawInput) {
    const input = String(rawInput || '').trim();

    if (!input) {
        return {};
    }

    if (/^\d+$/.test(input)) {
        return { itemId: Number(input) };
    }

    try {
        const parsedUrl = new URL(input);
        const pathMatch = parsedUrl.pathname.match(/-i\.(\d+)\.(\d+)$/);
        if (pathMatch) {
            return {
                shopId: Number(pathMatch[1]),
                itemId: Number(pathMatch[2]),
            };
        }

        const itemIdParam = parsedUrl.searchParams.get('itemid') || parsedUrl.searchParams.get('item_id');
        const shopIdParam = parsedUrl.searchParams.get('shopid') || parsedUrl.searchParams.get('shop_id');

        if (itemIdParam && /^\d+$/.test(itemIdParam)) {
            return {
                itemId: Number(itemIdParam),
                ...(shopIdParam && /^\d+$/.test(shopIdParam) ? { shopId: Number(shopIdParam) } : {}),
            };
        }
    } catch {
        // Fallback to keyword search.
    }

    return { keyword: input };
}

function normalizeOffer(node) {
    const minPrice = Number(node.priceMin ?? 0);
    const maxPrice = Number(node.priceMax ?? 0);

    return {
        item_id: String(node.itemId ?? ''),
        item_name: node.productName ?? 'Sản phẩm',
        item_url: node.offerLink ?? node.productLink ?? '',
        image_url: node.imageUrl ?? null,
        price: minPrice || maxPrice,
        commission_rate: Number(node.commissionRate ?? 0),
        shop_id: node.shopId ? String(node.shopId) : null,
    };
}
</script>
