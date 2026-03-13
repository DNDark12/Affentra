<template>
    <div v-if="isOpen" class="fixed inset-0 z-50 flex justify-end">
        <!-- Backdrop -->
        <transition enter-active-class="transition-opacity ease-linear duration-300"
                    enter-from-class="opacity-0" enter-to-class="opacity-100"
                    leave-active-class="transition-opacity ease-linear duration-300"
                    leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="close"></div>
        </transition>

        <!-- Slide Slide-over panel -->
        <transition enter-active-class="transform transition ease-in-out duration-300"
                    enter-from-class="translate-x-full" enter-to-class="translate-x-0"
                    leave-active-class="transform transition ease-in-out duration-300"
                    leave-from-class="translate-x-0" leave-to-class="translate-x-full">
            <div class="relative w-[440px] max-w-full flex shadow-2xl h-full bg-white dark:bg-zinc-900 border-l border-zinc-200 dark:border-zinc-800">
                <div class="flex flex-col h-full w-full">
                    
                    <!-- Header -->
                    <div class="px-6 py-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                        <div class="flex flex-col gap-1">
                            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Tạo Link Động</h2>
                            <p class="text-[13px] text-zinc-500 dark:text-zinc-400">Tuỳ chỉnh thông số theo dõi chiến dịch</p>
                        </div>
                        <button @click="close" class="h-8 w-8 flex items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800 text-zinc-500 hover:text-zinc-700 transition-colors">
                        <X :size="14" />
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 overflow-y-auto p-6 flex flex-col gap-6">
                        
                        <!-- Product Preview -->
                        <div v-if="offer" class="flex items-start gap-4 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700">
                            <!-- Image -->
                            <div class="w-16 h-16 rounded-md overflow-hidden bg-white shrink-0 border border-zinc-100 dark:border-zinc-700">
                                <img :src="offer.image_url" alt="Product Image" class="w-full h-full object-cover">
                            </div>
                            <!-- Title & Price -->
                            <div class="flex flex-col gap-1 min-w-0">
                                <h4 class="text-[13px] font-semibold text-zinc-900 dark:text-zinc-100 line-clamp-2 leading-tight">
                                    {{ offer.item_name }}
                                </h4>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-[13px] font-bold text-red-600 dark:text-red-500">{{ formatCurrency(offer.price) }}</span>
                                    <span class="text-[11px] text-zinc-500 bg-zinc-200/50 dark:bg-zinc-700 px-1.5 py-0.5 rounded">Hoa hồng: {{ offer.commission_rate }}%</span>
                                </div>
                            </div>
                        </div>

                        <!-- SubID Form -->
                        <form id="linkForm" @submit.prevent="generateLink" class="flex flex-col gap-4">
                            
                            <div class="flex flex-col gap-2">
                                <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">Chiến dịch (Campaign)</label>
                                <select v-model="form.campaign_id" class="h-10 px-3 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-[var(--surface-1)] text-[var(--text-primary)] text-sm focus:ring-1 focus:ring-indigo-500 w-full">
                                    <option value="">-- Tiền xử lý (Mặc định) --</option>
                                    <option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">SubID 1 (Nhóm/Vị trí)</label>
                                <input type="text" v-model="form.sub1" placeholder="VD: tiktok_ads_q1" class="h-10 px-3 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-transparent text-sm focus:ring-1 focus:ring-indigo-500 w-full" />
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">SubID 2 (Nguồn)</label>
                                <input type="text" v-model="form.sub2" placeholder="VD: fb_group_review" class="h-10 px-3 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-transparent text-sm focus:ring-1 focus:ring-indigo-500 w-full" />
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">SubID 3 (KOL/KOC)</label>
                                <input type="text" v-model="form.sub3" placeholder="VD: reviewer_nam" class="h-10 px-3 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-transparent text-sm focus:ring-1 focus:ring-indigo-500 w-full" />
                            </div>

                            <!-- Advanced toggle or the rest can be omitted for brevity, keeping 4 & 5 simple -->
                            <div class="grid grid-cols-2 gap-4">
                                <div class="flex flex-col gap-2">
                                    <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">SubID 4</label>
                                    <input type="text" v-model="form.sub4" class="h-10 px-3 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-transparent text-sm focus:ring-1 focus:ring-indigo-500 w-full" />
                                </div>
                                <div class="flex flex-col gap-2">
                                    <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">SubID 5</label>
                                    <input type="text" v-model="form.sub5" class="h-10 px-3 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-transparent text-sm focus:ring-1 focus:ring-indigo-500 w-full" />
                                </div>
                            </div>
                            
                            <div v-if="errorMsg" class="text-sm text-red-500 bg-red-50 dark:bg-red-500/10 px-3 py-2 rounded-lg border border-red-200 dark:border-red-500/20">
                                {{ errorMsg }}
                            </div>
                        </form>

                        <!-- Generated Link Result -->
                        <div v-if="generatedLink" class="mt-2 p-4 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 border border-indigo-100 dark:border-indigo-500/20 flex flex-col gap-3">
                            <div class="flex items-center gap-2 text-indigo-700 dark:text-indigo-400 font-semibold text-sm">
                                <CheckCircle :size="15" /> Link đã sẵn sàng!
                            </div>
                            <div class="relative">
                                <input type="text" readonly :value="generatedLink" class="w-full h-10 pl-3 pr-12 rounded-lg border border-indigo-200 dark:border-indigo-500/30 bg-white dark:bg-zinc-900 text-sm text-zinc-700 dark:text-zinc-300 outline-none" />
                                <button @click="copyLink" class="absolute right-1 top-1 h-8 px-3 rounded-md bg-indigo-100 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-400 hover:bg-indigo-200 dark:hover:bg-indigo-500/30 text-[12px] font-bold uppercase transition-colors">
                                    {{ copied ? 'Copied' : 'Copy' }}
                                </button>
                            </div>
                        </div>

                    </div>
                    
                    <!-- Footer -->
                    <div class="px-6 py-4 border-t border-zinc-200 dark:border-zinc-800 flex items-center justify-end gap-3 bg-zinc-50 dark:bg-zinc-900/50">
                        <button @click="close" type="button" class="h-9 px-4 rounded-lg border border-zinc-200 dark:border-zinc-700 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors">
                            Hủy
                        </button>
                        <button form="linkForm" type="submit" :disabled="loading" class="h-9 px-6 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium flex justify-center items-center transition-colors disabled:opacity-50">
                            <Loader2 v-if="loading" :size="14" class="animate-spin mr-2" />
                            {{ loading ? 'Đang tạo...' : 'Tạo Link Động' }}
                        </button>
                    </div>

                </div>
            </div>
        </transition>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import { X, CheckCircle, Loader2 } from 'lucide-vue-next';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
    isOpen: Boolean,
    offer: Object,        // The selected API offer data returned from search
    connectionId: Number, // The ID of the PlatformConnection chosen
    campaigns: Array,     // Available campaigns to select
});

const emit = defineEmits(['close']);
const toast = useToast();

const form = ref({
    campaign_id: '', sub1: '', sub2: '', sub3: '', sub4: '', sub5: ''
});

const loading = ref(false);
const errorMsg = ref('');
const generatedLink = ref('');
const copied = ref(false);

watch(() => props.isOpen, (val) => {
    if (val) {
        document.body.style.overflow = 'hidden';
        form.value = { campaign_id: '', sub1: '', sub2: '', sub3: '', sub4: '', sub5: '' };
        generatedLink.value = '';
        errorMsg.value = '';
        copied.value = false;
    } else {
        document.body.style.overflow = '';
    }
});

function close() {
    emit('close');
}

function formatCurrency(amount) {
    if (!amount && amount !== 0) return '';
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(amount);
}

async function generateLink() {
    if (!props.offer || !props.connectionId) {
        errorMsg.value = 'Missing offer or connection context.';
        return;
    }

    loading.value = true;
    errorMsg.value = '';
    generatedLink.value = '';
    copied.value = false;

    try {
        const payload = {
            connection_id: props.connectionId,
            campaign_id: form.value.campaign_id || undefined,
            sub1: form.value.sub1 || undefined,
            sub2: form.value.sub2 || undefined,
            sub3: form.value.sub3 || undefined,
            sub4: form.value.sub4 || undefined,
            sub5: form.value.sub5 || undefined,
            offer_link: props.offer.item_url,
            item_id: props.offer.item_id,
            product_name: props.offer.item_name,
            shop_id: props.offer.shop_id ?? null,
            commission_rate: props.offer.commission_rate ?? null,
            image_url: props.offer.image_url ?? null,
        };

        const res = await axios.post(route('api.offers.getLink'), payload);
        if (res.data?.ok) {
            generatedLink.value = res.data?.data?.track_url || res.data?.data?.destination_url || '';
            if (!generatedLink.value) {
                errorMsg.value = 'API trả về dữ liệu link không hợp lệ.';
            }
        } else {
            errorMsg.value = res.data?.message || 'Có lỗi khi tạo link từ API.';
        }
    } catch (e) {
        errorMsg.value = e.response?.data?.message || 'Không kết nối được tới máy chủ.';
    } finally {
        loading.value = false;
    }
}

function copyLink() {
    if (!generatedLink.value) return;
    navigator.clipboard.writeText(generatedLink.value)
        .then(() => {
            copied.value = true;
            toast.success('Đã copy link tracking.');
            setTimeout(() => copied.value = false, 2000);
        })
        .catch(() => {
            toast.error('Không thể copy link tracking.');
        });
}
</script>
