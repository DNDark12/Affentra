<template>
    <AppShell>
        <div class="flex h-full" style="height: calc(100vh - 57px)">

            <!-- ═══════════════════════════════════════════
                 LEFT PANEL — Link selector + History
            ═══════════════════════════════════════════ -->
            <div class="flex flex-col w-[300px] shrink-0 border-r border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">

                <!-- Header -->
                <div class="px-4 py-4 border-b border-zinc-200 dark:border-zinc-800">
                    <div class="flex items-center gap-2 mb-3">
                        <div class="w-7 h-7 rounded-lg bg-indigo-100 dark:bg-indigo-500/20 flex items-center justify-center">
                            <Sparkles :size="14" class="text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <h1 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">AI Content Studio</h1>
                    </div>

                    <!-- Link search -->
                    <div class="relative">
                        <Search :size="13" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-zinc-400 pointer-events-none" />
                        <input
                            v-model="linkSearch"
                            type="text"
                            placeholder="Tìm tracking link..."
                            class="w-full h-8 pl-8 pr-3 text-xs rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                        />
                    </div>
                </div>

                <!-- Link list -->
                <div class="flex-1 overflow-y-auto">
                    <div v-if="filteredLinks.length === 0" class="flex flex-col items-center justify-center h-32 gap-2 text-zinc-400">
                        <Link2 :size="20" class="opacity-40" />
                        <p class="text-xs">Không tìm thấy link</p>
                    </div>

                    <button
                        v-for="link in filteredLinks"
                        :key="link.id"
                        @click="selectLink(link)"
                        class="w-full text-left px-4 py-3 border-b border-zinc-100 dark:border-zinc-800 transition-colors"
                        :class="selectedLink?.id === link.id
                            ? 'bg-indigo-50 dark:bg-indigo-500/10 border-l-2 border-l-indigo-500'
                            : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/60'"
                    >
                        <span class="text-xs font-mono font-semibold text-indigo-600 dark:text-indigo-400 block truncate">
                            {{ link.short_code }}
                        </span>
                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400 truncate leading-tight mt-0.5">
                            {{ link.destination_url }}
                        </p>
                        <p v-if="link.campaign_name" class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">
                            📌 {{ link.campaign_name }}
                        </p>
                    </button>
                </div>

                <!-- History panel -->
                <div v-if="selectedLink" class="border-t border-zinc-200 dark:border-zinc-800">
                    <div class="px-4 py-2 flex items-center justify-between">
                        <span class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider">Lịch sử</span>
                        <Loader2 v-if="historyLoading" :size="11" class="animate-spin text-zinc-400" />
                    </div>
                    <div class="max-h-[220px] overflow-y-auto">
                        <p v-if="!historyLoading && history.length === 0" class="text-xs text-zinc-400 px-4 py-3 text-center">
                            Chưa có lịch sử
                        </p>
                        <button
                            v-for="item in history"
                            :key="item.id"
                            @click="loadFromHistory(item)"
                            class="w-full text-left px-4 py-2.5 border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-800/60 transition-colors"
                        >
                            <div class="flex items-center gap-1.5 mb-0.5">
                                <span class="text-[9px] font-bold px-1.5 py-0.5 rounded uppercase tracking-wide" :class="platformBadgeClass(item.platform)">{{ item.platform }}</span>
                                <span class="text-[9px] px-1 py-0.5 rounded" :class="item.status === 'succeeded' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400' : 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400'">{{ item.status }}</span>
                            </div>
                            <p class="text-[11px] text-zinc-600 dark:text-zinc-300 line-clamp-1">{{ item.preview || '—' }}</p>
                            <p class="text-[10px] text-zinc-400 mt-0.5">{{ formatRelative(item.created_at) }}</p>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════
                 RIGHT PANEL — Form + Output
            ═══════════════════════════════════════════ -->
            <div class="flex-1 flex flex-col overflow-hidden bg-zinc-50 dark:bg-zinc-950">

                <!-- No link selected -->
                <div v-if="!selectedLink" class="flex-1 flex flex-col items-center justify-center gap-4 text-zinc-400">
                    <div class="w-16 h-16 rounded-2xl bg-indigo-100 dark:bg-indigo-500/10 flex items-center justify-center">
                        <Sparkles :size="28" class="text-indigo-400" />
                    </div>
                    <div class="text-center">
                        <p class="font-semibold text-zinc-600 dark:text-zinc-300 text-sm">Chọn một tracking link</p>
                        <p class="text-xs mt-1 text-zinc-400">để bắt đầu tạo content AI</p>
                    </div>
                </div>

                <!-- Form area -->
                <div v-else class="flex-1 flex flex-col overflow-hidden">

                    <!-- Toolbar / Preset tabs -->
                    <div class="px-6 pt-5 pb-4 border-b border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shrink-0">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-xs text-zinc-400">Generating for</p>
                                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 font-mono">{{ selectedLink.short_code }}</p>
                            </div>
                            <div v-if="lastGeneration" class="text-right">
                                <p class="text-[10px] text-zinc-400">Tokens used</p>
                                <p class="text-xs font-mono text-zinc-600 dark:text-zinc-300">
                                    {{ (lastGeneration.usage?.tokens_prompt || 0) + (lastGeneration.usage?.tokens_completion || 0) }}
                                    <span v-if="lastGeneration.from_cache" class="ml-1 text-amber-500">⚡ cache</span>
                                </p>
                            </div>
                        </div>

                        <!-- Preset tabs -->
                        <div class="flex gap-1 p-1 bg-zinc-100 dark:bg-zinc-800 rounded-lg">
                            <button
                                v-for="preset in presets"
                                :key="preset.id"
                                @click="selectPreset(preset)"
                                class="flex-1 h-8 rounded-md text-xs font-medium transition-all"
                                :class="selectedPreset?.id === preset.id
                                    ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 shadow-sm'
                                    : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300'"
                            >
                                {{ preset.label }}
                            </button>
                        </div>
                    </div>

                    <!-- Scrollable body -->
                    <div class="flex-1 overflow-y-auto px-6 py-5 flex flex-col gap-5">

                        <!-- ── AI Provider & Model selector ── -->
                        <div class="af-surface p-4 flex flex-col gap-3">
                            <h3 class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">AI Provider</h3>
                            <div class="grid grid-cols-2 gap-3">
                                <!-- Provider -->
                                <div class="flex flex-col gap-1.5">
                                    <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Provider</label>
                                    <select v-model="form.provider_key" @change="onProviderChange" class="af-input h-9 text-sm">
                                        <option value="">— Auto (mặc định) —</option>
                                        <option v-for="p in configuredProviders" :key="p.key" :value="p.key">
                                            {{ p.label }}
                                        </option>
                                    </select>
                                    <p v-if="configuredProviders.length === 0" class="text-[10px] text-amber-600 dark:text-amber-400">
                                        Chưa cấu hình provider. <a :href="route('settings.ai')" class="underline">Cài đặt →</a>
                                    </p>
                                </div>

                                <!-- Model -->
                                <div class="flex flex-col gap-1.5">
                                    <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Model</label>
                                    <input
                                        v-model="form.model"
                                        type="text"
                                        class="af-input h-9 text-sm font-mono"
                                        :placeholder="selectedProviderDefaultModel || 'gemini-1.5-flash'"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- ── Options form ── -->
                        <div class="af-surface p-5 flex flex-col gap-4">
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Tuỳ chọn nội dung</h3>

                            <!-- Tone — hidden for hashtags -->
                            <div v-if="selectedPreset?.id !== 'hashtags_pack_v1'" class="flex flex-col gap-1.5">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Giọng văn</label>
                                <select v-model="form.tone" class="af-input h-9 text-sm">
                                    <option value="friendly">Thân thiện</option>
                                    <option value="hype">Hype / Cảm xúc</option>
                                    <option value="professional">Chuyên nghiệp</option>
                                    <option value="minimalist">Minimalist</option>
                                </select>
                            </div>

                            <!-- Goal — only FB Post -->
                            <div v-if="selectedPreset?.id === 'fb_post_v1'" class="flex flex-col gap-1.5">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Mục tiêu</label>
                                <select v-model="form.goal" class="af-input h-9 text-sm">
                                    <option value="traffic">Traffic (click)</option>
                                    <option value="conversion">Chuyển đổi / Bán hàng</option>
                                    <option value="remarketing">Remarketing</option>
                                </select>
                            </div>

                            <!-- Audience — only FB Post -->
                            <div v-if="selectedPreset?.id === 'fb_post_v1'" class="flex flex-col gap-1.5">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Đối tượng mục tiêu</label>
                                <input
                                    v-model="form.audience"
                                    type="text"
                                    class="af-input h-9 text-sm"
                                    placeholder="VD: phụ nữ 25-35 tuổi quan tâm làm đẹp"
                                />
                            </div>

                            <!-- Product Title & Price -->
                            <div class="grid grid-cols-2 gap-3">
                                <div class="flex flex-col gap-1.5">
                                    <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Tên sản phẩm</label>
                                    <input v-model="form.product_title" type="text" class="af-input h-9 text-sm" placeholder="VD: Son môi Dior 999" />
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Giá sản phẩm</label>
                                    <input v-model="form.product_price" type="text" class="af-input h-9 text-sm" placeholder="VD: 450.000đ" />
                                </div>
                            </div>

                            <!-- Custom prompt -->
                            <div class="flex flex-col gap-1.5">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Prompt tuỳ chỉnh</label>
                                    <span class="text-[10px] text-zinc-400">Tùy chọn — ghi đè template mặc định</span>
                                </div>
                                <textarea
                                    v-model="form.custom_prompt"
                                    class="af-input text-sm resize-none leading-relaxed"
                                    rows="4"
                                    placeholder="Nhập prompt tuỳ chỉnh... Nếu để trống, hệ thống dùng template mặc định theo preset."
                                />
                            </div>

                            <!-- Variant count -->
                            <div class="flex flex-col gap-1.5">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                    Số phiên bản
                                    <span class="text-zinc-400 font-normal ml-1">({{ form.variant_count }})</span>
                                </label>
                                <div class="flex items-center gap-3">
                                    <input
                                        v-model.number="form.variant_count"
                                        type="range" min="1" max="5" step="1"
                                        class="flex-1 h-1.5 rounded accent-indigo-500 cursor-pointer"
                                    />
                                    <span class="text-xs font-mono w-3 text-zinc-600 dark:text-zinc-300">{{ form.variant_count }}</span>
                                </div>
                            </div>

                            <!-- Generate button -->
                            <button
                                @click="generate(false)"
                                :disabled="generating"
                                class="af-btn-primary h-10 flex items-center justify-center gap-2 text-sm font-semibold mt-1"
                            >
                                <Loader2 v-if="generating" :size="16" class="animate-spin" />
                                <Sparkles v-else :size="16" />
                                {{ generating ? 'Đang tạo nội dung...' : 'Tạo content' }}
                            </button>
                        </div>

                        <!-- ── Product Image picker ── -->
                        <div class="af-surface p-4 flex flex-col gap-3">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Ảnh sản phẩm</h3>
                                <span class="text-[10px] text-zinc-400">Đính kèm vào content (tùy chọn)</span>
                            </div>

                            <!-- URL input -->
                            <div class="flex gap-2">
                                <input
                                    v-model="imageUrlInput"
                                    type="url"
                                    class="af-input h-9 text-sm flex-1"
                                    placeholder="https://... hoặc paste URL ảnh sản phẩm"
                                    @keydown.enter="addImageUrl"
                                />
                                <button @click="addImageUrl" class="af-btn-outline h-9 px-3 text-xs font-medium flex items-center gap-1">
                                    <Plus :size="13" />
                                    Thêm
                                </button>
                            </div>

                            <!-- Selected images -->
                            <div v-if="form.image_urls.length > 0" class="flex flex-wrap gap-2">
                                <div
                                    v-for="(url, idx) in form.image_urls"
                                    :key="idx"
                                    class="relative group w-20 h-20 rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 bg-zinc-100 dark:bg-zinc-800"
                                >
                                    <img
                                        :src="url"
                                        :alt="`Product image ${idx + 1}`"
                                        class="w-full h-full object-cover"
                                        @error="(e) => e.target.style.display = 'none'"
                                    />
                                    <button
                                        @click="removeImage(idx)"
                                        class="absolute top-1 right-1 w-5 h-5 rounded-full bg-zinc-900/70 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                                    >
                                        <X :size="10" />
                                    </button>
                                </div>
                            </div>

                            <!-- Auto-extract hint -->
                            <p class="text-[10px] text-zinc-400 leading-relaxed">
                                💡 Dán URL landing page sản phẩm, ảnh sẽ được đính kèm vào prompt để AI tham khảo phần trình bày sản phẩm.
                            </p>
                        </div>

                        <!-- ── Error state ── -->
                        <div
                            v-if="errorMsg"
                            class="flex items-start gap-2.5 px-4 py-3 rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 text-red-700 dark:text-red-400 text-sm"
                        >
                            <AlertTriangle :size="15" class="shrink-0 mt-0.5" />
                            <div>
                                <p class="font-medium">{{ errorMsg }}</p>
                                <p v-if="errorHint" class="text-xs mt-0.5 opacity-80">{{ errorHint }}</p>
                            </div>
                        </div>

                        <!-- ── Output variants ── -->
                        <div v-if="outputVariants.length > 0" class="flex flex-col gap-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                                    <CheckCircle2 :size="15" class="text-emerald-500" />
                                    {{ outputVariants.length }} phiên bản được tạo
                                </h3>
                                <button
                                    @click="generate(true)"
                                    :disabled="generating"
                                    class="text-xs flex items-center gap-1.5 text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 font-medium transition-colors disabled:opacity-50"
                                >
                                    <RefreshCw :size="12" />
                                    Tạo lại (mới)
                                </button>
                            </div>

                            <div
                                v-for="(variant, index) in outputVariants"
                                :key="index"
                                class="af-surface rounded-xl overflow-hidden"
                            >
                                <div class="flex items-center justify-between px-4 py-2.5 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/60">
                                    <span class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">
                                        Phiên bản {{ index + 1 }}
                                        <span v-if="variant.kind" class="ml-1.5 text-zinc-400 font-normal">{{ variant.kind }}</span>
                                    </span>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[10px] text-zinc-400">{{ variant.text?.length || 0 }} ký tự</span>
                                        <button
                                            @click="copyVariant(variant.text, index)"
                                            class="h-6 px-2 rounded-md text-[11px] font-semibold flex items-center gap-1 transition-colors"
                                            :class="copiedIndex === index
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400'
                                                : 'bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-600'"
                                        >
                                            <Check v-if="copiedIndex === index" :size="10" />
                                            <Copy v-else :size="10" />
                                            {{ copiedIndex === index ? 'Copied!' : 'Copy' }}
                                        </button>
                                    </div>
                                </div>
                                <div class="px-4 py-3">
                                    <pre class="text-sm text-zinc-800 dark:text-zinc-200 whitespace-pre-wrap leading-relaxed font-sans">{{ variant.text }}</pre>
                                </div>
                            </div>

                            <!-- Usage footer -->
                            <div v-if="lastGeneration" class="flex items-center gap-4 text-[11px] text-zinc-400 py-1">
                                <span v-if="lastGeneration.usage?.model">Model: <span class="font-mono">{{ lastGeneration.usage.model }}</span></span>
                                <span>Prompt: <span class="font-mono">{{ lastGeneration.usage?.tokens_prompt || 0 }}</span> tokens</span>
                                <span>Output: <span class="font-mono">{{ lastGeneration.usage?.tokens_completion || 0 }}</span> tokens</span>
                                <span v-if="lastGeneration.from_cache" class="text-amber-500 font-medium">⚡ từ cache</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import {
    Sparkles, Search, Link2, Loader2,
    AlertTriangle, CheckCircle2, RefreshCw,
    Copy, Check, Plus, X,
} from 'lucide-vue-next';
import AppShell from '@/Layouts/AppShell.vue';
import { useToast } from '@/Composables/useToast';

// ── Props ────────────────────────────────────────────────────────────────────
const props = defineProps({
    trackingLinks:       { type: Array, default: () => [] },
    presets:             { type: Array, default: () => [] },
    configuredProviders: { type: Array, default: () => [] },
});

const toast = useToast();

// ── State ────────────────────────────────────────────────────────────────────
const linkSearch     = ref('');
const selectedLink   = ref(null);
const selectedPreset = ref(props.presets[0] ?? null);

const history        = ref([]);
const historyLoading = ref(false);

const generating     = ref(false);
const outputVariants = ref([]);
const lastGeneration = ref(null);
const errorMsg       = ref('');
const errorHint      = ref('');
const copiedIndex    = ref(-1);

const imageUrlInput  = ref('');

let idempotencyKey = crypto.randomUUID();

// ── Form ─────────────────────────────────────────────────────────────────────
const form = ref({
    provider_key:  '',
    model:         '',
    tone:          'friendly',
    goal:          'traffic',
    audience:      '',
    product_title: '',
    product_price: '',
    custom_prompt: '',
    variant_count: 3,
    image_urls:    [],
});

// ── Computed ─────────────────────────────────────────────────────────────────
const filteredLinks = computed(() => {
    if (!linkSearch.value.trim()) return props.trackingLinks;
    const q = linkSearch.value.toLowerCase();
    return props.trackingLinks.filter(l =>
        l.short_code.toLowerCase().includes(q) ||
        l.destination_url.toLowerCase().includes(q) ||
        (l.campaign_name ?? '').toLowerCase().includes(q)
    );
});

const selectedProviderDefaultModel = computed(() => {
    const p = props.configuredProviders.find(p => p.key === form.value.provider_key);
    return p?.model ?? '';
});

// ── Watchers ─────────────────────────────────────────────────────────────────
watch([selectedLink, selectedPreset], () => {
    idempotencyKey = crypto.randomUUID();
    outputVariants.value = [];
    lastGeneration.value = null;
    errorMsg.value = '';
    errorHint.value = '';
});

// ── Methods ───────────────────────────────────────────────────────────────────
function selectLink(link) {
    selectedLink.value = link;
    fetchHistory();
}

function selectPreset(preset) {
    selectedPreset.value = preset;
}

function onProviderChange() {
    // Auto-fill model from provider default
    const p = props.configuredProviders.find(p => p.key === form.value.provider_key);
    if (p) form.value.model = p.model ?? '';
}

function addImageUrl() {
    const url = imageUrlInput.value.trim();
    if (!url) return;
    if (form.value.image_urls.includes(url)) { toast.warning('URL này đã được thêm.'); return; }
    if (form.value.image_urls.length >= 5) { toast.warning('Tối đa 5 ảnh.'); return; }
    form.value.image_urls.push(url);
    imageUrlInput.value = '';
}

function removeImage(idx) {
    form.value.image_urls.splice(idx, 1);
}

async function fetchHistory() {
    if (!selectedLink.value) return;
    historyLoading.value = true;
    try {
        const res = await axios.get(route('api.content.history', { trackingLink: selectedLink.value.id }));
        if (res.data?.ok) {
            const items = res.data.data?.data ?? res.data.data ?? [];
            history.value = items.map(item => ({
                ...item,
                preview: item.output_payload?.variants?.[0]?.text?.substring(0, 80) ?? '',
            }));
        }
    } catch {
        // silent fail — history is non-critical
    } finally {
        historyLoading.value = false;
    }
}

async function generate(forceNewSeed = false) {
    if (!selectedLink.value || !selectedPreset.value || generating.value) return;
    if (forceNewSeed) idempotencyKey = crypto.randomUUID();

    generating.value = true;
    errorMsg.value = '';
    errorHint.value = '';
    outputVariants.value = [];
    lastGeneration.value = null;

    // Build options object
    const options = { variant_count: form.value.variant_count };
    if (selectedPreset.value.id !== 'hashtags_pack_v1') options.tone = form.value.tone;
    if (selectedPreset.value.id === 'fb_post_v1') {
        options.goal     = form.value.goal;
        options.audience = form.value.audience;
    }
    if (form.value.product_title) options.product_title = form.value.product_title;
    if (form.value.product_price) options.product_price = form.value.product_price;

    const payload = {
        type:           'text',
        platform:       selectedPreset.value.platform,
        preset:         selectedPreset.value.id,
        options,
        force_new_seed: forceNewSeed,
    };

    // Optional overrides
    if (form.value.provider_key) payload.provider_key  = form.value.provider_key;
    if (form.value.model)        payload.model          = form.value.model;
    if (form.value.custom_prompt) payload.custom_prompt = form.value.custom_prompt;
    if (form.value.image_urls.length) payload.image_urls = form.value.image_urls;

    try {
        const res = await axios.post(
            route('api.content.generate', { trackingLink: selectedLink.value.id }),
            payload,
            { headers: { 'Idempotency-Key': idempotencyKey } }
        );

        const data = res.data?.data ?? res.data;
        outputVariants.value = data?.output?.variants ?? [];
        lastGeneration.value = {
            from_cache: data?.from_cache ?? false,
            usage:      data?.usage ?? {},
        };

        await fetchHistory();

        if (outputVariants.value.length === 0) {
            errorMsg.value = 'Không nhận được dữ liệu từ AI provider.';
        }
    } catch (e) {
        const status = e.response?.status;
        const msg    = e.response?.data?.message ?? e.message;

        if (status === 429) {
            errorMsg.value = msg || 'Bạn đã dùng hết quota tokens hôm nay.';
            errorHint.value = 'Quota reset tự động lúc 00:00.';
        } else if (status === 422) {
            const errors = e.response?.data?.errors ?? {};
            const first  = Object.values(errors).flat()[0];
            errorMsg.value = first || msg || 'Dữ liệu không hợp lệ.';
        } else if (status === 503 || status === 500) {
            errorMsg.value = 'AI provider không phản hồi hoặc gặp lỗi.';
            errorHint.value = 'Kiểm tra cấu hình tại Cài đặt → AI Provider.';
        } else if (status === 401 || status === 403) {
            errorMsg.value = msg || 'API key chưa được cấu hình. Vào Cài đặt → AI để thêm.';
        } else {
            errorMsg.value = msg || 'Có lỗi xảy ra khi kết nối.';
        }
    } finally {
        generating.value = false;
    }
}

function loadFromHistory(item) {
    if (!item?.output_payload?.variants?.length) {
        toast.warning('Phiên bản này không có output để tải lại.');
        return;
    }
    outputVariants.value = item.output_payload.variants;
    lastGeneration.value = {
        from_cache: true,
        usage: {
            tokens_prompt:     item.tokens_prompt ?? 0,
            tokens_completion: item.tokens_completion ?? 0,
            model:             item.ai_model ?? '',
        },
    };
    errorMsg.value = '';
}

function copyVariant(text, index) {
    navigator.clipboard.writeText(text).then(() => {
        copiedIndex.value = index;
        setTimeout(() => { copiedIndex.value = -1; }, 2000);
    }).catch(() => toast.error('Không thể copy'));
}

function platformBadgeClass(platform) {
    const map = {
        facebook: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
        tiktok:   'bg-zinc-900 text-white dark:bg-zinc-700 dark:text-zinc-100',
        generic:  'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300',
    };
    return map[platform] ?? map.generic;
}

function formatRelative(dateString) {
    if (!dateString) return '';
    const diff = Math.round((Date.now() - new Date(dateString).getTime()) / 1000);
    if (diff < 60)    return `${diff}s trước`;
    if (diff < 3600)  return `${Math.floor(diff / 60)}m trước`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h trước`;
    return new Date(dateString).toLocaleDateString('vi-VN');
}
</script>
