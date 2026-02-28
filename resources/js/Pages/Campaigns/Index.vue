<template>
    <AppShell>
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div class="flex flex-col gap-1">
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">
                        Campaigns
                    </h1>
                    <p class="text-[13px] text-zinc-500 dark:text-zinc-400">
                        Đồng bộ và quản lý chiến dịch Shopee để dùng chung cho tracking, click và đơn hàng.
                    </p>
                </div>

                <div class="flex items-center gap-2 flex-wrap justify-end">
                    <div class="relative">
                        <Search
                            :size="13"
                            class="af-input-search-icon absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400"
                        />
                        <input
                            v-model="searchInput"
                            type="text"
                            placeholder="Tìm campaign theo tên hoặc ID..."
                            class="af-input af-input-search h-9 text-sm w-64"
                            @keyup.enter="applyFilters"
                        />
                    </div>

                    <select
                        v-model="statusFilter"
                        class="af-input h-9 text-sm"
                        style="width: 160px"
                        @change="applyFilters"
                    >
                        <option value="">Tất cả trạng thái</option>
                        <option
                            v-for="(label, value) in $page.props.constants?.campaign_statuses || {}"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>

                    <button
                        @click="syncCampaigns"
                        class="h-9 px-4 rounded-lg text-sm font-medium border transition-colors flex items-center gap-1.5"
                        :class="canSyncCampaigns
                            ? 'border-zinc-200 bg-white text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800'
                            : 'border-zinc-200 bg-zinc-100 text-zinc-400 cursor-not-allowed dark:border-zinc-800 dark:bg-zinc-800 dark:text-zinc-500'"
                        :disabled="syncingCampaigns || !canSyncCampaigns"
                        :title="canSyncCampaigns ? 'Đồng bộ campaign từ Shopee' : 'Cần có kết nối Shopee active để đồng bộ'"
                    >
                        <RefreshCw :size="14" :class="{ 'animate-spin': syncingCampaigns }" />
                        {{ syncingCampaigns ? 'Đang đồng bộ...' : 'Đồng bộ từ Shopee' }}
                    </button>

                    <button
                        @click="showCreate = true"
                        class="af-btn-primary text-sm h-9 px-4 flex items-center gap-1.5"
                    >
                        <Plus :size="14" />
                        Thêm campaign
                    </button>
                </div>
            </div>

            <div
                class="rounded-lg border px-4 py-3 text-[13px] font-medium flex items-center gap-2"
                :class="canSyncCampaigns
                    ? 'bg-indigo-50 border-indigo-200 text-indigo-700 dark:bg-indigo-500/10 dark:border-indigo-500/30 dark:text-indigo-300'
                    : 'bg-amber-50 border-amber-200 text-amber-700 dark:bg-amber-500/10 dark:border-amber-500/30 dark:text-amber-300'"
            >
                <i class="ph" :class="canSyncCampaigns ? 'ph-check-circle' : 'ph-warning-circle'"></i>
                <span v-if="canSyncCampaigns">
                    Kết nối Shopee sẵn sàng.
                    <span v-if="props.campaignSync?.last_synced_at">
                        Lần sync campaign gần nhất: {{ formatDateTime(props.campaignSync.last_synced_at) }}.
                    </span>
                    <span v-else>
                        Chưa từng sync campaign.
                    </span>
                </span>
                <span v-else>
                    Chưa có kết nối Shopee khả dụng để đồng bộ campaign.
                </span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="af-surface p-4 flex flex-col gap-1">
                    <p class="text-xs text-zinc-500">Tổng campaign</p>
                    <p class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                        {{ fmtNum(campaignItems.length) }}
                    </p>
                </div>
                <div class="af-surface p-4 flex flex-col gap-1">
                    <p class="text-xs text-zinc-500">Đang hoạt động</p>
                    <p class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                        {{ fmtNum(activeCount) }}
                    </p>
                </div>
                <div class="af-surface p-4 flex flex-col gap-1">
                    <p class="text-xs text-zinc-500">Impressions</p>
                    <p class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                        {{ fmtNum(totalImpressions) }}
                    </p>
                </div>
                <div class="af-surface p-4 flex flex-col gap-1">
                    <p class="text-xs text-zinc-500">Clicks</p>
                    <p class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                        {{ fmtNum(totalClicks) }}
                    </p>
                </div>
            </div>

            <div class="af-surface overflow-x-auto">
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-800">
                            <th class="text-left px-4 py-3 font-medium text-zinc-500">Campaign</th>
                            <th class="text-left px-4 py-3 font-medium text-zinc-500">Trạng thái</th>
                            <th class="text-left px-4 py-3 font-medium text-zinc-500">Thời gian</th>
                            <th class="text-right px-4 py-3 font-medium text-zinc-500">Impressions</th>
                            <th class="text-right px-4 py-3 font-medium text-zinc-500">Clicks</th>
                            <th class="text-left px-4 py-3 font-medium text-zinc-500">Nguồn</th>
                            <th class="text-left px-4 py-3 font-medium text-zinc-500">Cập nhật</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!campaignItems.length">
                            <td colspan="7" class="text-center py-12 text-zinc-500">
                                <div class="flex flex-col items-center gap-2">
                                    <Megaphone :size="28" class="text-zinc-400" />
                                    <p>Chưa có campaign nào.</p>
                                    <button
                                        @click="showCreate = true"
                                        class="af-btn-primary text-sm h-8 px-3"
                                    >
                                        Tạo campaign đầu tiên
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr
                            v-for="campaign in campaignItems"
                            :key="campaign.id"
                            class="border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 transition-colors"
                        >
                            <td class="px-4 py-3 min-w-[280px]">
                                <div class="flex flex-col gap-1">
                                    <a
                                        v-if="campaign.campaign_url"
                                        :href="campaign.campaign_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-medium text-indigo-600 hover:underline dark:text-indigo-400 inline-flex items-center gap-1"
                                    >
                                        {{ campaign.name }}
                                        <i class="ph ph-arrow-square-out text-xs"></i>
                                    </a>
                                    <span v-else class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ campaign.name }}
                                    </span>

                                    <div class="text-xs text-zinc-500 flex items-center gap-1.5">
                                        <span v-if="campaign.external_id">ID: {{ campaign.external_id }}</span>
                                        <span v-if="campaign.external_id && campaign.platform">·</span>
                                        <span>{{ campaign.platform }}</span>
                                    </div>

                                    <p
                                        v-if="campaign.description"
                                        class="text-xs text-zinc-500 truncate max-w-[420px]"
                                    >
                                        {{ campaign.description }}
                                    </p>
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold"
                                    :class="statusBadgeClass(campaign.status)"
                                >
                                    {{ $page.props.constants?.campaign_statuses?.[campaign.status] || campaign.status }}
                                </span>
                            </td>

                            <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">
                                {{ formatDateRange(campaign) }}
                            </td>

                            <td class="px-4 py-3 text-right font-medium text-zinc-900 dark:text-zinc-100">
                                {{ fmtNum(campaign.impressions) }}
                            </td>

                            <td class="px-4 py-3 text-right font-medium text-zinc-900 dark:text-zinc-100">
                                {{ fmtNum(campaign.clicks) }}
                            </td>

                            <td class="px-4 py-3 text-xs">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-full font-medium"
                                    :class="campaign.source === 'shopee_sync'
                                        ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300'
                                        : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'"
                                >
                                    {{ campaign.source === 'shopee_sync' ? 'Shopee Sync' : 'Manual' }}
                                </span>
                            </td>

                            <td class="px-4 py-3 text-xs text-zinc-500">
                                {{ formatDateTime(campaign.synced_at || campaign.updated_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="props.campaigns?.last_page > 1"
                class="flex items-center justify-between"
            >
                <p class="text-xs text-zinc-500">
                    Trang {{ props.campaigns.current_page }} / {{ props.campaigns.last_page }}
                </p>
                <div class="flex items-center gap-2">
                    <button
                        class="h-8 px-3 rounded-md border border-zinc-200 text-sm text-zinc-700 bg-white hover:bg-zinc-50 disabled:opacity-50 disabled:cursor-not-allowed dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        :disabled="props.campaigns.current_page <= 1"
                        @click="goToPage(props.campaigns.current_page - 1)"
                    >
                        Trước
                    </button>
                    <button
                        class="h-8 px-3 rounded-md border border-zinc-200 text-sm text-zinc-700 bg-white hover:bg-zinc-50 disabled:opacity-50 disabled:cursor-not-allowed dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        :disabled="props.campaigns.current_page >= props.campaigns.last_page"
                        @click="goToPage(props.campaigns.current_page + 1)"
                    >
                        Sau
                    </button>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="showCreate"
                class="fixed inset-0 z-50 flex items-center justify-center"
                style="background: rgba(0, 0, 0, 0.4)"
            >
                <div
                    class="af-surface w-full max-w-md mx-4 p-6"
                    style="border: 1px solid var(--border)"
                >
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">
                            Tạo campaign
                        </h2>
                        <button @click="showCreate = false">
                            <X :size="16" class="text-zinc-500" />
                        </button>
                    </div>

                    <form class="flex flex-col gap-4" @submit.prevent="submitCreate">
                        <div>
                            <label class="af-label">
                                Tên campaign <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="af-input"
                                required
                                placeholder="Shopee 3.3 Mega Sale"
                            />
                        </div>

                        <div>
                            <label class="af-label">Mục tiêu doanh thu (đ)</label>
                            <input
                                v-model="form.goal_amount"
                                type="number"
                                min="0"
                                step="1000"
                                class="af-input"
                                placeholder="10000000"
                            />
                        </div>

                        <div>
                            <label class="af-label">Platform</label>
                            <select v-model="form.platform" class="af-input">
                                <option
                                    v-for="(label, value) in $page.props.constants?.platforms || {}"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="af-label">Ngày bắt đầu</label>
                                <input
                                    v-model="form.date_start"
                                    type="date"
                                    class="af-input"
                                />
                            </div>
                            <div>
                                <label class="af-label">Ngày kết thúc</label>
                                <input
                                    v-model="form.date_end"
                                    type="date"
                                    class="af-input"
                                />
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 pt-2">
                            <button
                                type="button"
                                @click="showCreate = false"
                                class="af-btn-outline text-sm h-9 px-4"
                            >
                                Huỷ
                            </button>
                            <button
                                type="submit"
                                class="af-btn-primary text-sm h-9 px-4"
                                :disabled="creating"
                            >
                                {{ creating ? 'Đang tạo...' : 'Tạo campaign' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppShell>
</template>

<script setup>
import axios from 'axios';
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Megaphone, Plus, RefreshCw, Search, X } from 'lucide-vue-next';
import AppShell from '@/Layouts/AppShell.vue';

const props = defineProps({
    campaigns: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
    campaignSync: { type: Object, default: () => ({}) },
});

const showCreate = ref(false);
const creating = ref(false);
const syncingCampaigns = ref(false);

const searchInput = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');

const form = ref({
    name: '',
    goal_amount: '',
    platform: 'shopee',
    date_start: '',
    date_end: '',
});

const campaignItems = computed(() => {
    return Array.isArray(props.campaigns?.data) ? props.campaigns.data : [];
});

const canSyncCampaigns = computed(() => Boolean(props.campaignSync?.available));

const activeCount = computed(() => {
    return campaignItems.value.filter((item) => item.status === 'active').length;
});

const totalImpressions = computed(() => {
    return campaignItems.value.reduce((sum, item) => sum + Number(item.impressions || 0), 0);
});

const totalClicks = computed(() => {
    return campaignItems.value.reduce((sum, item) => sum + Number(item.clicks || 0), 0);
});

function statusBadgeClass(status) {
    if (status === 'active') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300';
    if (status === 'paused') return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300';
    return 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300';
}

function fmtNum(value) {
    return Number(value || 0).toLocaleString('vi-VN');
}

function formatDateRange(campaign) {
    if (!campaign?.date_start && !campaign?.date_end) return '--';
    if (campaign?.date_start && campaign?.date_end) {
        return `${formatDate(campaign.date_start)} - ${formatDate(campaign.date_end)}`;
    }
    return campaign?.date_start ? `Từ ${formatDate(campaign.date_start)}` : `Đến ${formatDate(campaign.date_end)}`;
}

function formatDate(value) {
    if (!value) return '--';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '--';
    return date.toLocaleDateString('vi-VN');
}

function formatDateTime(value) {
    if (!value) return '--';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '--';
    return date.toLocaleString('vi-VN', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
}

function applyFilters(page = 1) {
    router.get(route('campaigns.index'), {
        search: searchInput.value || undefined,
        status: statusFilter.value || undefined,
        page,
    }, {
        replace: true,
        preserveScroll: true,
        preserveState: true,
    });
}

function goToPage(page) {
    applyFilters(page);
}

async function syncCampaigns() {
    if (!canSyncCampaigns.value || syncingCampaigns.value) return;

    syncingCampaigns.value = true;
    try {
        const response = await axios.post(route('api.campaigns.sync'));
        if (response.data?.ok) {
            router.reload({
                only: ['campaigns', 'campaignSync', 'filters'],
                preserveScroll: true,
            });
        } else {
            alert(response.data?.message || 'Không thể đồng bộ campaign.');
        }
    } catch (error) {
        alert(error.response?.data?.message || 'Không thể đồng bộ campaign.');
    } finally {
        syncingCampaigns.value = false;
    }
}

async function submitCreate() {
    creating.value = true;
    try {
        const response = await fetch(route('api.campaigns.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify(form.value),
        });

        const payload = await response.json();
        if (payload?.ok) {
            showCreate.value = false;
            form.value = {
                name: '',
                goal_amount: '',
                platform: 'shopee',
                date_start: '',
                date_end: '',
            };
            router.reload({ only: ['campaigns', 'filters'], preserveScroll: true });
        } else {
            alert(payload?.message || 'Không thể tạo campaign.');
        }
    } catch (error) {
        alert(error?.message || 'Không thể tạo campaign.');
    } finally {
        creating.value = false;
    }
}
</script>
