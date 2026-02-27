<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Partners / CTV</p>
                    <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Partners / CTV</h1>
                </div>
                <div class="flex items-center gap-2">
                    <div class="relative">
                        <Search :size="13" class="af-input-search-icon absolute left-3 top-1/2 -translate-y-1/2" style="color: var(--text-muted)" />
                        <input type="text" placeholder="Search partners..." class="af-input af-input-search h-9 text-sm w-52" />
                    </div>
                    <!-- Copy Referral Link -->
                    <button @click="copyReferralLink" class="af-btn-outline text-sm h-9 px-4 flex items-center gap-1.5" title="Copy link mời CTV">
                        <LinkIcon :size="14" />
                        Copy Link
                    </button>
                    <button @click="showAdd = true" class="af-btn-primary text-sm h-9 px-4 flex items-center gap-1.5">
                        <UserPlus :size="14" />
                        Add Partner
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="af-surface overflow-hidden">
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2)">
                            <th class="text-left px-4 py-2.5 font-medium" style="color: var(--text-muted); width: 35%">Partner Name</th>
                            <th class="text-right px-4 py-2.5 font-medium" style="color: var(--text-muted)">Links</th>
                            <th class="text-right px-4 py-2.5 font-medium" style="color: var(--text-muted)">Clicks</th>
                            <th class="text-right px-4 py-2.5 font-medium" style="color: var(--text-muted)">Orders</th>
                            <th class="text-right px-4 py-2.5 font-medium" style="color: var(--text-muted)">Commission</th>
                            <th class="px-4 py-2.5" style="color: var(--text-muted); width: 60px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Empty state -->
                        <tr v-if="!partners.data?.length">
                            <td colspan="6" class="text-center py-12" style="color: var(--text-muted)">
                                <div class="flex flex-col items-center gap-3">
                                    <Users :size="32" style="color: var(--text-muted)" />
                                    <p>Chưa có partners nào.</p>
                                    <button @click="showAdd = true" class="af-btn-primary text-sm h-8 px-3">Thêm Partner đầu tiên</button>
                                </div>
                            </td>
                        </tr>

                        <tr v-for="p in partners.data" :key="p.id"
                            style="border-bottom: 1px solid var(--border)"
                            class="hover:bg-[var(--surface-2)] transition-colors cursor-pointer">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <!-- Avatar initials -->
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                                         :style="{ background: 'var(--color-primary-500)' }">
                                        {{ initials(p.name) }}
                                    </div>
                                    <div>
                                        <p class="font-medium text-sm" style="color: var(--text-primary)">{{ p.name }}</p>
                                        <p class="text-xs" style="color: var(--text-muted)">{{ p.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right" style="color: var(--text-primary)">{{ p.tracking_links_count ?? 0 }}</td>
                            <td class="px-4 py-3 text-right" style="color: var(--text-primary)">{{ fmtNum(p.total_clicks) }}</td>
                            <td class="px-4 py-3 text-right" style="color: var(--text-primary)">{{ fmtNum(p.total_orders) }}</td>
                            <td class="px-4 py-3 text-right font-semibold" style="color: var(--text-primary)">
                                đ{{ fmtNum(p.total_commission) }}
                            </td>
                            <td class="px-4 py-3">
                                <button class="w-7 h-7 rounded flex items-center justify-center hover:bg-[var(--surface-2)]">
                                    <ChevronRight :size="14" style="color: var(--text-muted)" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="partners.last_page > 1" class="flex items-center justify-between text-xs" style="color: var(--text-muted)">
                <span>Showing {{ partners.from }}–{{ partners.to }} of {{ partners.total }}</span>
                <div class="flex gap-1">
                    <button v-for="pg in partners.last_page" :key="pg"
                        class="w-7 h-7 rounded"
                        :style="pg === partners.current_page
                            ? { background: 'var(--color-primary-500)', color: '#fff' }
                            : { color: 'var(--text-secondary)' }"
                    >{{ pg }}</button>
                </div>
            </div>
        </div>

        <!-- Add Partner Modal -->
        <Teleport to="body">
            <div v-if="showAdd" class="fixed inset-0 z-50 flex items-center justify-center" style="background: rgba(0,0,0,0.4)">
                <div class="af-surface w-full max-w-md" style="padding: 24px; margin: 16px">
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-base font-semibold" style="color: var(--text-primary)">Thêm Partner / CTV</h2>
                        <button @click="showAdd = false"><X :size="16" style="color: var(--text-muted)" /></button>
                    </div>
                    <form @submit.prevent="submitAdd" class="flex flex-col gap-4">
                        <div>
                            <label class="af-label">Email <span style="color: var(--color-danger)">*</span></label>
                            <input v-model="addForm.email" type="email" class="af-input" required placeholder="ctv@gmail.com" />
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" @click="showAdd = false" class="af-btn-outline text-sm h-9 px-4">Huỷ</button>
                            <button type="submit" class="af-btn-primary text-sm h-9 px-4" :disabled="adding">
                                {{ adding ? 'Đang thêm…' : 'Thêm Partner' }}
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
import { usePage, router } from '@inertiajs/vue3';
import { UserPlus, Search, Users, ChevronRight, X, Link as LinkIcon } from 'lucide-vue-next';
import AppShell from '@/Layouts/AppShell.vue';

const page = usePage();

defineProps({
    partners: { type: Object, default: () => ({ data: [], total: 0, current_page: 1, last_page: 1 }) },
    filters:  { type: Object, default: () => ({}) },
});

const showAdd = ref(false);
const adding  = ref(false);
const addForm = ref({ email: '' });

function fmtNum(n) { return Number(n || 0).toLocaleString('vi-VN'); }
function initials(name) {
    return (name || '?').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
}
async function submitAdd() {
    adding.value = true;
    try {
        const res = await fetch(route('api.partners.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ ...addForm.value, role: 'ctv' }),
        });
        const json = await res.json();
        if (json.ok) { 
            showAdd.value = false; 
            addForm.value = { email: '' };
            alert('Đã gửi email mời thành công!');
            router.reload({ only: ['partners'] }); 
        } else {
            alert(json.message || 'Có lỗi xảy ra.');
        }
    } finally { adding.value = false; }
}

async function copyReferralLink() {
    try {
        const userId = page.props.auth.user.id;
        const url = `${window.location.origin}/register?ref=${userId}`;
        await navigator.clipboard.writeText(url);
        alert('Đã copy Referral Link vào Clipboard!');
    } catch (err) {
        console.error('Failed to copy: ', err);
        alert('Trình duyệt không hỗ trợ copy tự động.');
    }
}
</script>
