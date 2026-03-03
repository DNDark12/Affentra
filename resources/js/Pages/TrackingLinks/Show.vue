<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Tracking Links / {{ trackingLink.short_code }}</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Link Detail</h1>
                    <p class="text-xs" style="color: var(--text-muted)">Theo dõi hiệu suất, nguồn traffic và lịch sử click cho từng tracking link.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button class="af-btn-outline h-9 px-3 text-sm" @click="router.visit(route('links.index'))">Quay lại danh sách</button>
                    <button class="af-btn-primary h-9 px-3 text-sm flex items-center gap-1" @click="copyTrackUrl">
                        <Copy :size="14" />
                        Copy Track URL
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-4 gap-4">
                <div class="af-surface p-4">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Status</p>
                    <span class="text-xs font-medium px-2 py-0.5 rounded-full" :style="statusStyle(trackingLink.status)">
                        {{ statusLabel(trackingLink.status) }}
                    </span>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Total Clicks</p>
                    <p class="text-2xl font-bold tabular-nums" style="color: var(--text-primary)">{{ fmtNum(trackingLink.clicks_count) }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Total Orders</p>
                    <p class="text-2xl font-bold tabular-nums" style="color: var(--text-primary)">{{ fmtNum(trackingLink.orders_count) }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Order Rate</p>
                    <p class="text-2xl font-bold tabular-nums" style="color: var(--color-primary-500)">{{ conversionRate }}%</p>
                </div>
            </div>

            <div class="grid grid-cols-4 gap-4">
                <div class="af-surface p-4">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Clicks (30d)</p>
                    <p class="text-xl font-semibold tabular-nums" style="color: var(--text-primary)">{{ fmtNum(trackingLink.metrics_clicks_30d) }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Orders (30d)</p>
                    <p class="text-xl font-semibold tabular-nums" style="color: var(--text-primary)">{{ fmtNum(trackingLink.metrics_orders_30d) }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Approved (30d)</p>
                    <p class="text-xl font-semibold tabular-nums" style="color: var(--text-primary)">{{ fmtNum(trackingLink.metrics_approved_30d) }}</p>
                </div>
                <div class="af-surface p-4">
                    <p class="text-xs mb-1" style="color: var(--text-muted)">Commission (30d)</p>
                    <p class="text-xl font-semibold tabular-nums" style="color: var(--color-primary-500)">{{ fmtCurrency(trackingLink.metrics_commission_30d) }}</p>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div class="af-surface p-4 col-span-2">
                    <h2 class="text-base font-semibold mb-3" style="color: var(--text-primary)">Thông tin Link</h2>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Track URL</p>
                            <p class="font-mono break-all" style="color: var(--color-primary-500)">{{ trackingLink.track_url }}</p>
                        </div>
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Destination URL</p>
                            <p class="break-all" style="color: var(--text-primary)">{{ trackingLink.destination_url }}</p>
                        </div>
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Campaign</p>
                            <p style="color: var(--text-primary)">{{ trackingLink.campaign?.name || 'No campaign' }}</p>
                        </div>
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Owner</p>
                            <p style="color: var(--text-primary)">{{ trackingLink.owner?.name || '--' }}</p>
                        </div>
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Source / Channel</p>
                            <p style="color: var(--text-primary)">{{ trackingLink.source || '--' }} / {{ trackingLink.channel || '--' }}</p>
                        </div>
                        <div>
                            <p class="text-xs mb-1" style="color: var(--text-muted)">Created</p>
                            <p style="color: var(--text-primary)">{{ formatDateTime(trackingLink.created_at) }}</p>
                        </div>
                    </div>
                </div>

                <div class="af-surface p-4">
                    <h2 class="text-base font-semibold mb-3" style="color: var(--text-primary)">Clicks 14 ngày gần nhất</h2>
                    <div class="flex flex-col gap-2">
                        <div
                            v-for="point in trackingLink.clicks_series_14d"
                            :key="point.date"
                            class="flex items-center justify-between text-xs p-2 rounded"
                            style="background: var(--surface-2)"
                        >
                            <span style="color: var(--text-secondary)">{{ formatDate(point.date) }}</span>
                            <span class="font-semibold tabular-nums" style="color: var(--text-primary)">{{ fmtNum(point.clicks) }}</span>
                        </div>
                        <p v-if="!trackingLink.clicks_series_14d?.length" class="text-xs" style="color: var(--text-muted)">Chưa có dữ liệu click 14 ngày gần nhất.</p>
                    </div>
                </div>
            </div>

            <div class="af-surface overflow-hidden">
                <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary)">Recent Click Events</h2>
                    <span class="text-xs" style="color: var(--text-muted)">{{ trackingLink.recent_clicks?.length || 0 }} bản ghi</span>
                </div>
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border)">
                            <th class="text-left px-4 py-2 text-xs" style="color: var(--text-muted)">Time</th>
                            <th class="text-left px-4 py-2 text-xs" style="color: var(--text-muted)">Sub ID</th>
                            <th class="text-left px-4 py-2 text-xs" style="color: var(--text-muted)">IP</th>
                            <th class="text-left px-4 py-2 text-xs" style="color: var(--text-muted)">Referrer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!trackingLink.recent_clicks?.length">
                            <td colspan="4" class="text-center py-8 text-xs" style="color: var(--text-muted)">Chưa có click log.</td>
                        </tr>
                        <tr v-for="event in trackingLink.recent_clicks" :key="event.id" style="border-bottom: 1px solid var(--border)">
                            <td class="px-4 py-2 text-xs" style="color: var(--text-secondary)">{{ formatDateTime(event.created_at) }}</td>
                            <td class="px-4 py-2 text-xs font-mono" style="color: var(--text-primary)">{{ event.sub_id || '--' }}</td>
                            <td class="px-4 py-2 text-xs font-mono" style="color: var(--text-primary)">{{ event.ip || '--' }}</td>
                            <td class="px-4 py-2 text-xs truncate max-w-[360px]" style="color: var(--text-secondary)">{{ event.referer || '--' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>

<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Copy } from 'lucide-vue-next';
import AppShell from '@/Layouts/AppShell.vue';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
    trackingLink: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const toast = useToast();

const conversionRate = computed(() => {
    const clicks = Number(props.trackingLink.clicks_count || 0);
    const orders = Number(props.trackingLink.orders_count || 0);

    if (!clicks) {
        return '0.0';
    }

    const normalizedOrders = Math.min(orders, clicks);

    return ((normalizedOrders / clicks) * 100).toFixed(1);
});

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

function formatDateTime(value) {
    if (!value) {
        return '--';
    }

    return new Intl.DateTimeFormat('vi-VN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

function copyTrackUrl() {
    navigator.clipboard.writeText(props.trackingLink.track_url)
        .then(() => {
            toast.success('Đã copy tracking URL.');
        })
        .catch(() => {
            toast.error('Không thể copy tracking URL.');
        });
}
</script>
