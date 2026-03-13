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
        <transition enter-active-class="transform transition ease-in-out duration-300 sm:duration-500"
                    enter-from-class="translate-x-full" enter-to-class="translate-x-0"
                    leave-active-class="transform transition ease-in-out duration-300 sm:duration-500"
                    leave-from-class="translate-x-0" leave-to-class="translate-x-full">
            <div class="relative w-[480px] max-w-full flex shadow-2xl h-screen bg-white dark:bg-zinc-900 border-l border-zinc-200 dark:border-zinc-800">
                <div class="flex flex-col h-full w-full overflow-hidden">
                    
                    <!-- Header -->
                    <div class="px-6 py-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                        <div class="flex flex-col gap-1">
                            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ editConnection ? 'Cấu hình' : 'Thêm kết nối mới' }}
                            </h2>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">Kết nối Affiliate API để tự động đồng bộ số liệu</p>
                        </div>
                        <button @click="close" class="h-8 w-8 flex items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800 text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 transition-colors">
                            <X :size="14" />
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 overflow-y-auto p-6">
                        <form id="configForm" @submit.prevent="submit" class="flex flex-col gap-6">
                            
                            <!-- Label / Account Name -->
                            <div class="flex flex-col gap-2">
                                <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">Tên gợi nhớ (Label)</label>
                                <input type="text" v-model="form.label" placeholder="VD: Shop Mỹ Phẩm, Account 1..." class="h-11 px-4 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-transparent text-sm focus:ring-1 focus:ring-indigo-500 w-full" />
                                <span v-if="form.errors.label" class="text-xs text-red-500">{{ form.errors.label }}</span>
                            </div>

                            <!-- Platform Select -->
                            <div class="flex flex-col gap-2">
                                <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">Chọn Nền Tảng <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <select v-model="form.platform" class="w-full h-11 pl-4 pr-10 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-transparent text-sm appearance-none focus:ring-1 focus:ring-indigo-500 z-10" :disabled="!!editConnection">
                                        <option value="" disabled>--- Chọn nền tảng ---</option>
                                        <option v-for="plat in platforms" :key="plat.id" :value="plat.id">{{ plat.name }}</option>
                                    </select>
                                    <ChevronDown :size="14" class="absolute right-3 top-3.5 text-zinc-400 pointer-events-none" />
                                </div>
                            </div>

                            <!-- Connection Method Tabs -->
                            <div class="flex flex-col gap-2">
                                <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">Phương thức kết nối</label>
                                <div class="flex p-1 bg-[#F4F4F5] dark:bg-zinc-800 rounded-lg">
                                    <button
                                        v-for="option in methodOptions"
                                        :key="option.value"
                                        type="button"
                                        @click="form.method = option.value"
                                        :class="form.method === option.value
                                            ? 'bg-white dark:bg-zinc-900 shadow-sm border-zinc-200 dark:border-zinc-700 text-zinc-900 dark:text-zinc-100'
                                            : 'text-zinc-500 hover:text-zinc-700 border-transparent'"
                                        class="border flex-1 h-8 rounded-md text-[13px] font-medium transition-colors flex items-center justify-center gap-1.5"
                                    >
                                        {{ option.label }}
                                        <CheckCircle2 v-if="editConnection && isConfigured(option.value)" :size="10" class="text-emerald-500" />
                                    </button>
                                </div>
                            </div>

                            <!-- OPEN API Flow -->
                            <template v-if="form.method === 'open_api'">
                                <div class="flex flex-col gap-2">
                                    <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">
                                        App ID <span v-if="!editConnection" class="text-zinc-900 dark:text-white">*</span>
                                        <span v-else class="text-xs opacity-70 font-normal ml-1">(Bỏ trống nếu không đổi)</span>
                                    </label>
                                    <input type="text" v-model="form.app_id" :required="!editConnection && form.method === 'open_api'" class="h-11 px-4 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-transparent text-sm focus:ring-1 focus:ring-indigo-500 w-full" />
                                    <span v-if="form.errors.app_id" class="text-xs text-red-500">{{ form.errors.app_id }}</span>
                                </div>
                                <div class="flex flex-col gap-2">
                                    <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">
                                        App Secret <span v-if="!editConnection" class="text-zinc-900 dark:text-white">*</span>
                                        <span v-else class="text-xs opacity-70 font-normal ml-1">(Bỏ trống nếu không đổi)</span>
                                    </label>
                                    <input type="password" v-model="form.app_secret" :required="!editConnection && form.method === 'open_api'" class="h-11 px-4 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-transparent text-sm focus:ring-1 focus:ring-indigo-500 w-full" />
                                    <span v-if="form.errors.app_secret" class="text-xs text-red-500">{{ form.errors.app_secret }}</span>
                                </div>
                            </template>

                            <!-- PORTAL EXPORT Flow -->
                            <template v-else-if="form.method === 'portal_export'">
                                <div class="p-4 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-300 rounded-lg text-sm border border-indigo-100 dark:border-indigo-800/50">
                                    <p class="font-medium mb-1">Upload CSV thủ công</p>
                                    <p class="opacity-90">Phương thức này cho phép bạn tải dữ liệu thủ công từ Shopee Affiliate Portal (Click Report, Conversion Report).</p>
                                    <p class="opacity-90 mt-2"><b>Lưu ý:</b> Lưu kết nối này trước, sau đó hệ thống sẽ hiển thị chức năng Tải lên (Upload) trên danh sách.</p>
                                </div>
                            </template>

                            <!-- COOKIE Flow -->
                            <template v-else-if="form.method === 'cookie'">
                                <div class="p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/50 rounded-lg flex items-start gap-3">
                                    <AlertTriangle :size="18" class="text-amber-600 dark:text-amber-400 mt-0.5 shrink-0" />
                                    <div class="text-sm text-amber-800 dark:text-amber-300">
                                        <b>Cảnh báo rủi ro:</b> Cookie có thể hết hạn bất ngờ, vướng captcha. Mọi thiết lập cookie có thể bị reset hoặc lỗi sync không báo trước.
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2">
                                    <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">Cách nhập Cookie</label>
                                    <div class="flex gap-4">
                                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                                            <input type="radio" v-model="cookieInputMode" value="curl" class="text-indigo-600 bg-transparent border-zinc-300 dark:border-zinc-600 focus:ring-indigo-500" />
                                            <span>Dán cURL (Khuyên dùng)</span>
                                        </label>
                                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                                            <input type="radio" v-model="cookieInputMode" value="manual" class="text-indigo-600 bg-transparent border-zinc-300 dark:border-zinc-600 focus:ring-indigo-500" />
                                            <span>Nhập Cookie Header</span>
                                        </label>
                                    </div>
                                </div>

                                <div v-if="cookieInputMode === 'curl'" class="flex flex-col gap-2">
                                    <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">
                                        Lệnh cURL (Copy as cURL - bash)
                                        <span v-if="editConnection" class="text-xs opacity-70 font-normal ml-1">(Chỉ cần dán nếu muốn đổi Cookie)</span>
                                    </label>
                                    <textarea v-model="form.curl_command" :placeholder="curlPlaceholder" rows="6" class="p-3 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-transparent text-sm focus:ring-1 focus:ring-indigo-500 w-full font-mono text-xs"></textarea>
                                    <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                        <template v-if="form.platform === 'lazada'">
                                            Mở <b>Lazada Affiliate Dashboard → Report</b>, bật DevTools (F12) → Network tab → lọc XHR/Fetch → chuột phải vào request → <b>Copy as cURL (bash)</b>.
                                        </template>
                                        <template v-else>
                                            Để đồng bộ Finance đầy đủ, hãy dán cURL từ cả 3 trang:
                                            <code>/payment/billing</code>,
                                            <code>/payment/payout_record</code>,
                                            <code>/payment/service_fee_invoice</code>.
                                            Để làm mới thông tin sản phẩm ổn định, dán thêm cURL từ:
                                            <code>/offer/product_offer/{item_id}</code>.
                                        </template>
                                    </p>
                                    <span v-if="form.errors.curl_command" class="text-xs text-red-500">{{ form.errors.curl_command }}</span>
                                </div>

                                <div v-if="cookieInputMode === 'manual'" class="flex flex-col gap-2">
                                    <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">
                                        Cookie String
                                        <span v-if="editConnection" class="text-xs opacity-70 font-normal ml-1">(Bỏ trống nếu không đổi)</span>
                                    </label>
                                    <textarea v-model="form.cookie_header" :placeholder="cookiePlaceholder" rows="4" class="p-3 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-transparent text-sm focus:ring-1 focus:ring-indigo-500 w-full font-mono text-xs"></textarea>
                                    <span v-if="form.errors.cookie_header" class="text-xs text-red-500">{{ form.errors.cookie_header }}</span>
                                </div>

                                <label class="flex items-start gap-2 text-sm cursor-pointer bg-zinc-50 dark:bg-zinc-800/50 p-3 rounded-md border border-zinc-200 dark:border-zinc-800">
                                    <input type="checkbox" v-model="form.consent_acknowledged" class="mt-0.5 text-indigo-600 bg-transparent border-zinc-300 dark:border-zinc-600 focus:ring-indigo-500 rounded" />
                                    <span class="text-zinc-600 dark:text-zinc-400 text-xs">Tôi hiểu rủi ro bảo mật và sự thiếu ổn định của phương thức này, và xác nhận tiếp tục sử dụng.</span>
                                </label>
                                <span v-if="form.errors.consent_acknowledged" class="text-xs text-red-500">{{ form.errors.consent_acknowledged }}</span>
                            </template>

                            <!-- Sync Mode & Status (Advanced) -->
                            <div v-if="editConnection" class="flex flex-col gap-4 p-4 mt-2 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg border border-zinc-200 dark:border-zinc-700 border-dashed">
                                <!-- Mode -->
                                <div class="flex flex-col gap-2">
                                    <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">Chế độ đồng bộ</label>
                                    <select v-model="form.sync_mode" class="h-10 pl-3 pr-8 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-sm">
                                        <option value="scheduled">Scheduled (Tự động)</option>
                                        <option value="manual">Manual (Kích hoạt thủ công)</option>
                                    </select>
                                </div>

                                <!-- Interval -->
                                <div v-if="form.sync_mode === 'scheduled'" class="flex flex-col gap-2">
                                    <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">Chu kỳ đồng bộ</label>
                                    <div class="flex items-center gap-2">
                                        <select v-model="form.sync_interval" class="flex-1 h-10 pl-3 pr-8 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-sm">
                                            <option value="15m">15 phút</option>
                                            <option value="1h">1 tiếng</option>
                                            <option value="3h">3 tiếng</option>
                                            <option value="8h">8 tiếng</option>
                                            <option value="daily">Hàng ngày</option>
                                        </select>
                                        <div v-show="form.sync_interval === 'daily'" class="relative w-32 shrink-0">
                                            <input
                                                ref="timeInputRef"
                                                type="text"
                                                readonly
                                                class="w-full h-10 pl-9 pr-3 cursor-pointer rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                                                placeholder="00:00"
                                            />
                                            <Clock :size="14" class="absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 pointer-events-none" />
                                        </div>
                                    </div>
                                    <span v-if="form.errors.sync_time" class="text-xs text-red-500">{{ form.errors.sync_time }}</span>
                                </div>
                                
                                <!-- Status Force -->
                                <div class="flex flex-col gap-2">
                                    <label class="text-[13px] font-medium text-zinc-900 dark:text-zinc-100">Trạng thái kết nối</label>
                                    <select v-model="form.status" class="h-10 pl-3 pr-8 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-sm">
                                        <option value="active">Đang hoạt động</option>
                                        <option value="inactive">Tạm dừng</option>
                                        <option value="error">Báo Lỗi</option>
                                        <option value="disabled">Đã vô hiệu</option>
                                        <option value="expired">Hết hạn</option>
                                    </select>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Footer -->
                    <div class="px-6 py-4 flex items-center gap-3 border-t border-zinc-200 dark:border-zinc-800 shrink-0">
                        <button type="button" @click="close" class="h-10 px-5 rounded-lg border border-zinc-200 dark:border-zinc-700 text-sm font-medium text-zinc-900 dark:text-zinc-300 bg-white hover:bg-zinc-50 dark:bg-zinc-900 dark:hover:bg-zinc-800 transition-colors">
                            Hủy bỏ
                        </button>
                        <button form="configForm" type="submit" :disabled="form.processing" class="h-10 px-6 rounded-lg bg-[#6366F1] hover:bg-indigo-600 text-white text-sm font-medium flex justify-center items-center transition-colors disabled:opacity-50">
                            {{ form.processing ? '...' : (editConnection ? 'Lưu cấu hình' : 'Lưu kết nối') }}
                        </button>

                        <div class="flex-1"></div>

                        <button v-if="editConnection" @click.prevent="emit('delete', editConnection)" type="button" class="text-[14px] font-medium text-red-600 hover:text-red-700 flex items-center gap-1.5 focus:outline-none shrink-0">
                            <Trash2 :size="14" />
                            <span class="hidden sm:inline">Xóa kết nối</span>
                        </button>
                    </div>
                </div>
            </div>
        </transition>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import axios from 'axios';
import { X, ChevronDown, CheckCircle2, AlertTriangle, Clock, Trash2 } from 'lucide-vue-next';
import { useToast } from '@/Composables/useToast';
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';

const props = defineProps({
    isOpen: Boolean,
    platforms: Array,
    editConnection: Object,
    allowedMethods: Array,
});

const emit = defineEmits(['close', 'delete']);
const toast = useToast();

const cookieInputMode = ref('curl');
const timeInputRef = ref(null);
let fpInstance = null;

function initFlatpickr() {
    if (!timeInputRef.value) return;
    if (fpInstance) fpInstance.destroy();
    
    fpInstance = flatpickr(timeInputRef.value, {
        enableTime: true,
        noCalendar: true,
        dateFormat: "H:i",
        time_24hr: true,
        defaultDate: form.sync_time || '00:00',
        allowInput: true,
        onChange: (selectedDates, dateStr) => {
            form.sync_time = dateStr;
        }
    });
}

const methodLabelMap = {
    open_api: 'Open API',
    portal_export: 'Portal Export',
    cookie: 'Cookie (Beta)',
};
const methodOrder = ['open_api', 'portal_export', 'cookie'];

const availableMethods = computed(() => {
    const methods = Array.isArray(props.allowedMethods) ? props.allowedMethods : [];
    return methods.length ? methods : ['open_api', 'portal_export'];
});

const methodOptions = computed(() => {
    return methodOrder
        .filter((method) => availableMethods.value.includes(method))
        .map((method) => ({
            value: method,
            label: methodLabelMap[method] || method,
        }));
});

const curlPlaceholder = computed(() => {
    if (form.platform === 'lazada') {
        return 'Dán cURL từ Lazada Affiliate Dashboard → Report page (DevTools → Network → Copy as cURL bash).';
    }
    return 'Có thể dán nhiều block cURL. Khuyến nghị: billing + payout_record + service_fee_invoice + offer/product_offer.';
});

const cookiePlaceholder = computed(() => {
    if (form.platform === 'lazada') {
        return '_lzd_=...; hng=VN...; t_uid=...;';
    }
    return 'SPC_EC=...; SPC_F=...;';
});

function isConfigured(method) {
    if (!props.editConnection) return false;
    if (method === 'open_api') return !!props.editConnection.app_id;
    if (method === 'cookie') return !!props.editConnection.has_cookie;
    if (method === 'portal_export') return true;
    return false;
}

const form = useForm({
    platform: '',
    label: '',
    method: 'open_api',
    app_id: '',
    app_secret: '',
    cookie_header: '',
    curl_command: '',
    consent_acknowledged: false,
    sync_mode: 'scheduled',
    sync_interval: '15m',
    sync_time: '00:00',
    status: 'active',
});

watch(() => props.isOpen, (val) => {
    if (val) {
        document.body.style.overflow = 'hidden';
        const mainEl = document.querySelector('main');
        if (mainEl) mainEl.style.overflow = 'hidden';

        form.reset();
        form.clearErrors();
        cookieInputMode.value = 'curl';
        const defaultMethod = availableMethods.value[0] || 'open_api';
        
        if (props.editConnection) {
            form.platform = props.editConnection.platform;
            form.label = props.editConnection.label || '';
            const existingMethod = props.editConnection.method || defaultMethod;
            form.method = availableMethods.value.includes(existingMethod) ? existingMethod : defaultMethod;
            form.app_id = props.editConnection.app_id || '';
            form.app_secret = '';
            form.cookie_header = '';
            form.curl_command = '';
            form.consent_acknowledged = false;
            form.sync_mode = props.editConnection.sync_mode || 'scheduled';
            form.sync_interval = props.editConnection.sync_interval || '15m';
            form.sync_time = props.editConnection.sync_time || '00:00';
            form.status = props.editConnection.status || 'active';
        } else if (props.platforms?.length) {
            form.platform = props.platforms[0].id;
            form.method = defaultMethod;
        }

        setTimeout(() => {
            initFlatpickr();
        }, 50);
    } else {
        document.body.style.overflow = '';
        const mainEl = document.querySelector('main');
        if (mainEl) mainEl.style.overflow = '';
        
        if (fpInstance) {
            fpInstance.destroy();
            fpInstance = null;
        }
    }
});

watch(() => form.sync_interval, (newVal) => {
    if (newVal === 'daily') {
        setTimeout(initFlatpickr, 50);
    } else {
        if (fpInstance) {
            fpInstance.destroy();
            fpInstance = null;
        }
    }
});

watch(availableMethods, (methods) => {
    if (methods.length && !methods.includes(form.method)) {
        form.method = methods[0];
    }
});

function close() {
    emit('close');
}

async function submit() {
    form.clearErrors();
    form.processing = true;

    try {
        const payload = { ...form.data() };

        if (payload.sync_mode !== 'scheduled') {
            delete payload.sync_interval;
            delete payload.sync_time;
        } else if (payload.sync_interval !== 'daily') {
            delete payload.sync_time;
        }

        // Prevent silent method switch if an existing legacy method is not selectable in current policy.
        if (props.editConnection?.method && !availableMethods.value.includes(props.editConnection.method)) {
            payload.method = props.editConnection.method;
        }

        if (props.editConnection) {
            await axios.patch(route('api.integrations.update', props.editConnection.id), payload);
            toast.success('Cập nhật cấu hình thành công.');
        } else {
            await axios.post(route('api.integrations.store'), payload);
            toast.success('Thêm kết nối thành công.');
        }
        
        close();
        router.reload({ only: ['connections'] });
    } catch (error) {
        if (error.response?.status === 422) {
            const errors = error.response.data.errors || {};
            for (const key in errors) {
                form.setError(key, errors[key][0]);
            }
        } else {
            toast.error(error.response?.data?.message || 'Có lỗi xảy ra khi lưu kết nối.');
        }
    } finally {
        form.processing = false;
    }
}
</script>
