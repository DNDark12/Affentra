<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Campaigns</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Campaigns</h1>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted)">Quản lý các chiến dịch với goals, Rules và Actions</p>
                </div>
                <div class="flex items-center gap-2">
                    <button class="af-btn-outline text-sm h-9 px-4 flex items-center gap-1.5">
                        <Archive :size="14" />
                        Bulk Archive
                    </button>
                    <button @click="showCreate = true" class="af-btn-primary text-sm h-9 px-4 flex items-center gap-1.5">
                        <Plus :size="14" />
                        Add Campaign
                    </button>
                </div>
            </div>

            <!-- KPI Row -->
            <div class="grid grid-cols-4 gap-4">
                <div class="af-surface p-4 flex flex-col gap-1">
                    <p class="text-xs" style="color: var(--text-muted)">Active</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">24</p>
                </div>
                <div class="af-surface p-4 flex flex-col gap-1">
                    <p class="text-xs" style="color: var(--text-muted)">Approved Commission</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">đ182.4M</p>
                </div>
                <div class="af-surface p-4 flex flex-col gap-1">
                    <p class="text-xs" style="color: var(--text-muted)">ROAS</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">4.8x</p>
                </div>
                <div class="af-surface p-4 flex flex-col gap-1">
                    <p class="text-xs" style="color: var(--text-muted)">Ending in 7d</p>
                    <p class="text-2xl font-bold" style="color: var(--text-primary)">6</p>
                </div>
            </div>

            <!-- Tabs + Search -->
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="flex gap-1 p-1 rounded-lg" style="background: var(--surface-2)">
                    <button v-for="tab in tabs" :key="tab.value"
                        @click="activeTab = tab.value"
                        class="text-xs px-3 py-1.5 rounded-md font-medium transition-all"
                        :style="activeTab === tab.value
                            ? { background: 'var(--surface-1)', color: 'var(--text-primary)', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }
                            : { color: 'var(--text-secondary)' }"
                    >{{ tab.label }}</button>
                </div>
                <button class="af-btn-outline text-sm h-9 px-3 flex items-center gap-1.5">
                    <Filter :size="13" />
                    Filter
                </button>
            </div>

            <!-- Table -->
            <div class="af-surface overflow-hidden">
                <p class="text-xs font-semibold px-4 py-2.5" style="color: var(--text-muted); border-bottom: 1px solid var(--border); background: var(--surface-2)">
                    Campaign List
                </p>
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border)">
                            <th class="text-left px-4 py-2.5 font-medium" style="color: var(--text-muted)">Campaign Name / Goal</th>
                            <th class="text-left px-4 py-2.5 font-medium" style="color: var(--text-muted)">Status</th>
                            <th class="text-right px-4 py-2.5 font-medium" style="color: var(--text-muted)">Links</th>
                            <th class="text-right px-4 py-2.5 font-medium" style="color: var(--text-muted)">Clicks</th>
                            <th class="text-right px-4 py-2.5 font-medium" style="color: var(--text-muted)">Commission</th>
                            <th class="px-4 py-2.5 font-medium" style="color: var(--text-muted); width: 80px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!campaigns.data?.length">
                            <td colspan="6" class="text-center py-12" style="color: var(--text-muted)">
                                <div class="flex flex-col items-center gap-3">
                                    <Megaphone :size="32" style="color: var(--text-muted)" />
                                    <p>Chưa có campaign nào.</p>
                                    <button @click="showCreate = true" class="af-btn-primary text-sm h-8 px-3">Tạo campaign đầu tiên</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="c in campaigns.data" :key="c.id"
                            style="border-bottom: 1px solid var(--border)"
                            class="hover:bg-[var(--surface-2)] transition-colors">
                            <td class="px-4 py-3">
                                <p class="font-medium text-sm" style="color: var(--text-primary)">{{ c.name }}</p>
                                <p class="text-xs" style="color: var(--text-muted)">{{ c.goal ?? '–' }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs font-medium px-2 py-0.5 rounded-full" :style="statusStyle(c.status)">
                                    {{ $page.props.constants?.campaign_statuses?.[c.status] || c.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right" style="color: var(--text-primary)">{{ c.links_count ?? 0 }}</td>
                            <td class="px-4 py-3 text-right" style="color: var(--text-primary)">{{ fmtNum(c.clicks_total) }}</td>
                            <td class="px-4 py-3 text-right font-semibold" style="color: var(--text-primary)">₫0</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1 justify-end">
                                    <button class="w-7 h-7 rounded flex items-center justify-center hover:bg-[var(--surface-2)]">
                                        <Pencil :size="13" style="color: var(--text-secondary)" />
                                    </button>
                                    <button class="w-7 h-7 rounded flex items-center justify-center hover:bg-[var(--danger-bg)]">
                                        <Archive :size="13" style="color: var(--danger-text)" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create Modal -->
        <Teleport to="body">
            <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center" style="background: rgba(0,0,0,0.4)">
                <div class="af-surface w-full max-w-md" style="padding: 24px; margin: 16px">
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-base font-semibold" style="color: var(--text-primary)">Tạo Campaign</h2>
                        <button @click="showCreate = false"><X :size="16" style="color: var(--text-muted)" /></button>
                    </div>
                    <form @submit.prevent="submitCreate" class="flex flex-col gap-4">
                        <div>
                            <label class="af-label">Tên Campaign <span style="color: var(--color-danger)">*</span></label>
                            <input v-model="form.name" type="text" class="af-input" required placeholder="Shopee 9.9 Sale" />
                        </div>
                        <div>
                            <label class="af-label">Goal</label>
                            <input v-model="form.goal" type="text" class="af-input" placeholder="ví dụ: 1000 đơn / tháng" />
                        </div>
                        <div>
                            <label class="af-label">Platform</label>
                            <select v-model="form.platform" class="af-input">
                                <option v-for="(lbl, val) in $page.props.constants?.platforms || {}" :key="val" :value="val">
                                    {{ lbl }}
                                </option>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="af-label">Start Date</label>
                                <input v-model="form.start_date" type="date" class="af-input" />
                            </div>
                            <div>
                                <label class="af-label">End Date</label>
                                <input v-model="form.end_date" type="date" class="af-input" />
                            </div>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" @click="showCreate = false" class="af-btn-outline text-sm h-9 px-4">Huỷ</button>
                            <button type="submit" class="af-btn-primary text-sm h-9 px-4" :disabled="creating">
                                {{ creating ? 'Đang tạo…' : 'Tạo Campaign' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppShell>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Plus, Archive, Filter, Pencil, X, Megaphone } from 'lucide-vue-next';
import AppShell from '@/Layouts/AppShell.vue';

const props = defineProps({
    campaigns: { type: Object, default: () => ({ data: [] }) },
    filters:   { type: Object, default: () => ({}) },
});

const activeTab = ref('all');
const showCreate = ref(false);
const creating   = ref(false);
const form       = ref({ name: '', goal: '', platform: 'shopee', start_date: '', end_date: '' });

const tabs = [
    { label: 'Currently Active', value: 'all' },
    { label: 'Planned / Group', value: 'planned' },
    { label: 'Find & Add Campaign', value: 'find' },
    { label: 'Add Campaign', value: 'add' },
];

function fmtNum(n) { return Number(n || 0).toLocaleString('vi-VN'); }
function statusStyle(s) {
    const m = {
        active:   { background: 'var(--success-bg)', color: 'var(--success-text)' },
        paused:   { background: 'var(--warning-bg)', color: 'var(--warning-text)' },
        ended:    { background: 'var(--surface-2)',  color: 'var(--text-muted)' },
        archived: { background: 'var(--surface-2)',  color: 'var(--text-muted)' },
    };
    return m[s] ?? m.archived;
}
async function submitCreate() {
    creating.value = true;
    try {
        const res = await fetch(route('api.campaigns.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify(form.value),
        });
        const json = await res.json();
        if (json.ok) { showCreate.value = false; router.reload({ only: ['campaigns'] }); }
    } finally { creating.value = false; }
}
</script>
