<template>
    <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center">
        <!-- Backdrop -->
        <transition enter-active-class="transition-opacity ease-linear duration-300"
                    enter-from-class="opacity-0" enter-to-class="opacity-100"
                    leave-active-class="transition-opacity ease-linear duration-300"
                    leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="close"></div>
        </transition>

        <!-- Modal Panel -->
        <transition enter-active-class="transform transition ease-in-out duration-300 sm:duration-500 delay-100"
                    enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-active-class="transform transition ease-in-out duration-300 sm:duration-500"
                    leave-from-class="opacity-100 translate-y-0 sm:scale-100" leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
            <div class="relative z-10 m-4 w-full max-w-md overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-5 dark:border-zinc-800">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Upload Portal Export</h2>
                        <p class="mt-0.5 text-[13px] text-zinc-500">Tải lên file báo cáo CSV/Excel từ Shopee</p>
                    </div>
                    <button @click="close" class="flex h-8 w-8 flex-col items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 transition-colors hover:text-zinc-700 dark:bg-zinc-800 dark:hover:text-zinc-300">
                        <X :size="14" />
                    </button>
                </div>

                <div class="p-6">
                    <form id="uploadForm" class="flex flex-col gap-5" @submit.prevent="submit">
                        <div class="flex flex-col gap-2">
                            <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">Loại Báo Cáo <span class="text-red-500">*</span></label>
                            <select v-model="form.type" required class="h-11 w-full rounded-lg border border-zinc-200 bg-white px-3 text-sm focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900">
                                <option value="conversion">Conversion Report (Đơn hàng / AffiliateCommissionReport)</option>
                                <option value="click">Click Report (Lượt click / AffiliateClickReport)</option>
                                <option value="offer">Offer Report</option>
                            </select>
                            <span v-if="errors.type" class="text-xs text-red-500">{{ errors.type }}</span>
                        </div>

                        <div class="flex flex-col gap-2">
                            <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">File tải lên <span class="text-red-500">*</span></label>
                            <input
                                ref="fileInput"
                                type="file"
                                accept=".csv, .xlsx, .xls"
                                required
                                class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-4 file:py-2.5 file:text-[13px] file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100 dark:text-zinc-400 dark:file:bg-indigo-900/30 dark:file:text-indigo-300"
                                @change="handleFileChange"
                            />
                            <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                Shopee thường xuất file đơn hàng với tên dạng <span class="font-medium">AffiliateCommissionReport*.csv</span>.
                            </p>
                            <span v-if="errors.file" class="text-xs text-red-500">{{ errors.file }}</span>
                        </div>
                    </form>
                </div>

                <div class="flex justify-end gap-3 border-t border-zinc-100 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-900/50">
                    <button type="button" @click="close" class="h-9 rounded-lg border border-zinc-200 px-4 text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">Hủy</button>
                    <button form="uploadForm" type="submit" :disabled="isSubmitting || !form.file" class="flex h-9 items-center gap-2 rounded-lg bg-indigo-600 px-5 text-[13px] font-medium text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:opacity-50">
                        <Loader2 v-if="isSubmitting" :size="14" class="animate-spin" />
                        {{ isSubmitting ? 'Đang tải lên...' : 'Tải lên báo cáo' }}
                    </button>
                </div>
            </div>
        </transition>
    </div>
</template>

<script setup>
import axios from 'axios';
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { X, Loader2 } from 'lucide-vue-next';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
    isOpen: Boolean,
    connection: Object,
});

const emit = defineEmits(['close']);
const toast = useToast();

const fileInput = ref(null);
const isSubmitting = ref(false);

const form = reactive({
    type: 'conversion',
    file: null,
});

const errors = reactive({
    type: null,
    file: null,
});

function resetForm() {
    form.type = 'conversion';
    form.file = null;
    errors.type = null;
    errors.file = null;
    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

watch(() => props.isOpen, (val) => {
    if (val) {
        resetForm();
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
});

function handleFileChange(event) {
    const files = event.target?.files || [];
    form.file = files.length ? files[0] : null;
    errors.file = null;
}

async function submit() {
    if (!props.connection || isSubmitting.value) return;

    errors.type = null;
    errors.file = null;

    const payload = new FormData();
    payload.append('type', form.type);
    if (form.file) {
        payload.append('file', form.file);
    }

    isSubmitting.value = true;

    try {
        const response = await axios.post(
            route('api.integrations.portal-export.upload', props.connection.id),
            payload,
            {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            },
        );

        toast.success(response.data?.message || 'Tải lên thành công. Dữ liệu sẽ được xử lý sớm.');
        close();
        router.reload({ only: ['connections'], preserveScroll: true, preserveState: true });
    } catch (error) {
        if (error.response?.status === 422) {
            const validationErrors = error.response.data?.errors || {};
            errors.type = validationErrors.type?.[0] || null;
            errors.file = validationErrors.file?.[0] || null;
            toast.warning(error.response.data?.message || 'Vui lòng kiểm tra lại thông tin file upload.');
        } else {
            toast.error(error.response?.data?.message || 'Không thể tải lên file. Vui lòng thử lại.');
        }
    } finally {
        isSubmitting.value = false;
    }
}

function close() {
    emit('close');
}
</script>
