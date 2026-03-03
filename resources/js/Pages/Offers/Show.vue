<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Ưu đãi / Chi tiết</p>
                    <h1 class="text-2xl font-bold line-clamp-2" style="color: var(--text-primary)">{{ offer.item_name }}</h1>
                    <p class="text-xs mt-1" style="color: var(--text-muted)">
                        Mã SP: {{ offer.item_id }} · Mã Shop: {{ offer.shop_id || '—' }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button class="af-btn-outline text-sm h-9 px-3" @click="router.visit(route('offers.index'))">
                        Quay lại
                    </button>
                    <button class="af-btn-primary text-sm h-9 px-4" @click="isGetLinkOpen = true">
                        Tạo Link Động
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="af-surface p-4 lg:col-span-2 flex flex-col gap-4">
                    <div class="flex gap-4">
                        <div class="w-28 h-28 rounded-lg border border-[var(--border)] overflow-hidden bg-[var(--surface-2)] shrink-0">
                            <img v-if="offer.image_url" :src="offer.image_url" alt="Ảnh sản phẩm" class="w-full h-full object-cover">
                            <div v-else class="w-full h-full flex items-center justify-center text-xs" style="color: var(--text-muted)">Không có ảnh</div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <a
                                v-if="offer.item_url"
                                :href="offer.item_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-sm font-semibold hover:underline"
                                style="color: var(--color-primary-500)"
                            >
                                Mở sản phẩm trên Shopee
                            </a>
                            <p class="text-sm mt-2" style="color: var(--text-secondary)">
                                {{ offer.shop_name || 'Shopee Shop' }} · {{ offer.shop_type || '—' }}
                            </p>
                            <div class="flex items-center gap-4 mt-3 text-sm">
                                <span style="color: var(--text-muted)">Giá:</span>
                                <span class="font-semibold" style="color: var(--text-primary)">{{ fmtMoney(offer.price) }}</span>
                            </div>
                            <div class="flex items-center gap-4 mt-1 text-sm">
                                <span style="color: var(--text-muted)">Hoa hồng:</span>
                                <span class="font-semibold" style="color: var(--success-text)">{{ fmtRate(offer.commission_rate) }}</span>
                            </div>
                            <div class="flex items-center gap-4 mt-1 text-sm">
                                <span style="color: var(--text-muted)">Ước tính:</span>
                                <span class="font-semibold" style="color: var(--color-primary-500)">{{ fmtMoney(offer.estimated_commission) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 flex-wrap">
                        <button class="af-btn-outline text-sm h-9 px-3" @click="copySourceLink">
                            Sao chép link sản phẩm
                        </button>
                        <button class="af-btn-outline text-sm h-9 px-3" :disabled="isCreatingQuickLink" @click="createQuickLink">
                            {{ isCreatingQuickLink ? 'Đang tạo...' : 'Tạo link nhanh' }}
                        </button>
                    </div>

                    <div v-if="quickLink" class="rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-2)">
                        <p class="text-xs mb-1" style="color: var(--text-muted)">Link theo dõi</p>
                        <div class="flex items-center gap-2">
                            <input
                                class="af-input h-9 text-sm flex-1"
                                readonly
                                :value="quickLink"
                            >
                            <button class="af-btn-primary text-xs h-9 px-3" @click="copyText(quickLink)">Sao chép</button>
                        </div>
                    </div>
                </div>

                <div class="af-surface p-4 flex flex-col gap-3">
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary)">Chỉ số ưu đãi</h2>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Giá thấp nhất</p>
                            <p class="font-medium" style="color: var(--text-primary)">{{ fmtMoney(offer.price_min) }}</p>
                        </div>
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Giá cao nhất</p>
                            <p class="font-medium" style="color: var(--text-primary)">{{ fmtMoney(offer.price_max) }}</p>
                        </div>
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Đã bán</p>
                            <p class="font-medium" style="color: var(--text-primary)">{{ fmtInt(offer.sales) }}</p>
                        </div>
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Đánh giá</p>
                            <p class="font-medium" style="color: var(--text-primary)">{{ offer.rating_star ?? '—' }}</p>
                        </div>
                    </div>

                    <div>
                        <p class="text-xs mb-2" style="color: var(--text-muted)">Bậc hoa hồng</p>
                        <div v-if="offer.commission_tiers?.length" class="flex flex-col gap-2">
                            <div
                                v-for="tier in offer.commission_tiers"
                                :key="tier.name"
                                class="flex items-center justify-between rounded border px-2.5 py-2 text-sm"
                                style="border-color: var(--border)"
                            >
                                <span style="color: var(--text-secondary)">{{ tier.name }}</span>
                                <span class="font-medium" style="color: var(--text-primary)">{{ fmtRate(tier.rate) }}</span>
                            </div>
                        </div>
                        <p v-else class="text-sm" style="color: var(--text-muted)">Không có dữ liệu tier.</p>
                    </div>

                    <div class="text-xs" style="color: var(--text-muted)">
                        <p>Kết nối: {{ connectionName }}</p>
                        <p>Hiệu lực: {{ fmtDateTime(offer.period_start_at) }} → {{ fmtDateTime(offer.period_end_at) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <DrawerGetLink
            :isOpen="isGetLinkOpen"
            :offer="offer"
            :connectionId="connectionId"
            @close="isGetLinkOpen = false"
        />
    </AppShell>
</template>

<script setup>
import axios from 'axios';
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import DrawerGetLink from './Partials/DrawerGetLink.vue';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
    offer: { type: Object, required: true },
    connectionId: { type: Number, required: true },
    connections: { type: Array, default: () => [] },
});

const toast = useToast();
const isGetLinkOpen = ref(false);
const isCreatingQuickLink = ref(false);
const quickLink = ref('');

const connectionName = computed(() => {
    const match = props.connections.find((connection) => Number(connection.id) === Number(props.connectionId));
    if (!match) return `#${props.connectionId}`;
    return `${match.platform} · ${match.app_id || 'cookie'}`;
});

function fmtMoney(value) {
    if (value === null || value === undefined) return '—';
    return Number(value).toLocaleString('vi-VN') + ' đ';
}

function fmtInt(value) {
    if (value === null || value === undefined) return '—';
    return Number(value).toLocaleString('vi-VN');
}

function fmtRate(value) {
    if (value === null || value === undefined) return '—';
    return `${Number(value).toLocaleString('vi-VN')}%`;
}

function fmtDateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('vi-VN');
}

async function copyText(value) {
    if (!value) return;
    await navigator.clipboard.writeText(value);
    toast.success('Đã sao chép.');
}

async function copySourceLink() {
    if (!props.offer.item_url) return;
    await copyText(props.offer.item_url);
}

async function createQuickLink() {
    if (isCreatingQuickLink.value) return;

    isCreatingQuickLink.value = true;
    quickLink.value = '';

    try {
        const response = await axios.post(route('api.offers.getLink'), {
            connection_id: props.connectionId,
            offer_link: props.offer.item_url,
            item_id: props.offer.item_id,
            shop_id: props.offer.shop_id || undefined,
            product_name: props.offer.item_name,
            commission_rate: props.offer.commission_rate || undefined,
            image_url: props.offer.image_url || undefined,
        });

        if (response.data?.ok) {
            quickLink.value = response.data?.data?.track_url || response.data?.data?.destination_url || '';
            if (quickLink.value) {
                toast.success('Đã tạo tracking link.');
            }
        } else {
            toast.error(response.data?.message || 'Không thể tạo link.');
        }
    } catch (error) {
        toast.error(error.response?.data?.message || 'Không thể tạo link.');
    } finally {
        isCreatingQuickLink.value = false;
    }
}
</script>
