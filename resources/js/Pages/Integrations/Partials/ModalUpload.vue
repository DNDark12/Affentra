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
            <div class="relative w-full max-w-md bg-white dark:bg-zinc-900 rounded-xl shadow-2xl border border-zinc-200 dark:border-zinc-800 z-10 m-4 overflow-hidden">
                <div class="px-6 py-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Upload Portal Export</h2>
                        <p class="text-[13px] mt-0.5 text-zinc-500">Tải lên file báo cáo CSV/Excel từ Shopee</p>
                    </div>
                    <button @click="close" class="h-8 w-8 flex flex-col items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800 text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 transition-colors">
                        <i class="ph ph-x"></i>
                    </button>
                </div>

                <div class="p-6">
                    <form @submit.prevent="submit" id="uploadForm" class="flex flex-col gap-5">
                        <div class="flex flex-col gap-2">
                            <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">Loại Báo Cáo <span class="text-red-500">*</span></label>
                            <select v-model="form.type" required class="h-11 px-3 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-sm focus:ring-1 focus:ring-indigo-500 w-full">
                                <option value="conversion">Conversion Report (Đơn hàng)</option>
                                <option value="click">Click Report (Lượt click)</option>
                                <option value="offer">Offer Link</option>
                            </select>
                            <span v-if="form.errors.type" class="text-xs text-red-500">{{ form.errors.type }}</span>
                        </div>

                        <div class="flex flex-col gap-2">
                            <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">File tải lên <span class="text-red-500">*</span></label>
                            <input type="file" @change="handleFileChange" accept=".csv, .xlsx, .xls" required class="block w-full text-sm text-zinc-500 dark:text-zinc-400 file:mr-4 file:py-2.5 file:px-4 file:rounded-lg file:border-0 file:text-[13px] file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-indigo-900/30 dark:file:text-indigo-300" />
                            <span v-if="form.errors.file" class="text-xs text-red-500">{{ form.errors.file }}</span>
                        </div>
                    </form>
                </div>

                <div class="px-6 py-4 border-t border-zinc-100 dark:border-zinc-800 flex justify-end gap-3 bg-zinc-50 dark:bg-zinc-900/50">
                    <button type="button" @click="close" class="h-9 px-4 rounded-lg border border-zinc-200 dark:border-zinc-700 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors">Hủy</button>
                    <button form="uploadForm" type="submit" :disabled="form.processing || !form.file" class="h-9 px-5 rounded-lg bg-indigo-600 text-white text-[13px] font-medium hover:bg-indigo-700 shadow-sm transition-colors disabled:opacity-50 flex items-center gap-2">
                        <i v-if="form.processing" class="ph ph-spinner animate-spin"></i>
                        {{ form.processing ? 'Đang tải lên...' : 'Tải lên báo cáo' }}
                    </button>
                </div>
            </div>
        </transition>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps({
    isOpen: Boolean,
    connection: Object,
});

const emit = defineEmits(['close']);

const form = useForm({
    type: 'conversion',
    file: null,
});

watch(() => props.isOpen, (val) => {
    if (val) {
        form.reset();
        form.clearErrors();
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
});

function handleFileChange(e) {
    if (e.target.files.length) {
        form.file = e.target.files[0];
    } else {
        form.file = null;
    }
}

function submit() {
    if (!props.connection) return;

    form.post(route('api.integrations.portal-export.upload', props.connection.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            alert('Tải lên thành công! Dữ liệu sẽ được xử lý sớm.');
            close();
        },
    });
}

function close() {
    emit('close');
}
</script>
