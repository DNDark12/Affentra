<template>
    <AppShell>
        <div class="flex h-full" style="height: calc(100vh - 57px)">

            <!-- ═══════════════════════════════════════════
                 LEFT PANEL — Link selector
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

                    <div
                        v-for="link in filteredLinks"
                        :key="link.id"
                        @click="selectLink(link)"
                        class="w-full cursor-pointer text-left px-4 py-3 border-b border-zinc-100 dark:border-zinc-800 transition-colors relative group"
                        :class="selectedLink?.id === link.id
                            ? 'bg-indigo-50 dark:bg-indigo-500/10 border-l-2 border-l-indigo-500'
                            : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/60'"
                    >
                        <div class="flex items-start justify-between">
                            <span class="text-xs font-mono font-semibold text-indigo-600 dark:text-indigo-400 block truncate max-w-[190px]">
                                {{ link.short_code }}
                            </span>
                            <button
                                @click.stop="copyText(link.track_url || link.tracking_url, 'Đã copy Tracking Link')"
                                class="opacity-0 group-hover:opacity-100 p-1 rounded bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-zinc-500 hover:text-indigo-600 hover:border-indigo-300 transition-all shadow-sm"
                                title="Copy tracking link"
                            >
                                <Copy :size="10" />
                            </button>
                        </div>
                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400 truncate leading-tight mt-0.5">
                            {{ link.destination_url }}
                        </p>
                        <p class="text-[10px] font-mono text-zinc-400 dark:text-zinc-500 truncate mt-0.5 opacity-70">
                            {{ link.track_url || link.tracking_url }}
                        </p>
                        <p v-if="link.campaign_name" class="text-[10px] text-zinc-500 dark:text-zinc-400 mt-1 flex items-center gap-1">
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700">
                                <Pin :size="10" class="text-zinc-400" />
                                {{ link.campaign_name }}
                            </span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════
                 CENTER PANEL — Editor / History Viewer
            ═══════════════════════════════════════════ -->
            <div class="flex-1 flex flex-col overflow-hidden bg-zinc-50 dark:bg-zinc-950">

                <!-- No link selected -->
                <div v-if="!selectedLink" class="flex-1 overflow-y-auto px-6 py-5">
                    <div class="af-surface p-4 flex flex-col gap-3">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-500/10 flex items-center justify-center">
                                <Sparkles :size="16" class="text-indigo-500" />
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Chọn một tracking link để bắt đầu tạo content</p>
                                <p class="text-xs text-zinc-400">Thống kê theo tài khoản vẫn luôn hiển thị bên dưới.</p>
                            </div>
                        </div>

                        <div v-if="accountStatsCards.length > 0" class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-900/40 p-2.5">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                    Thống kê theo tài khoản
                                </p>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-600 dark:text-zinc-300"
                                    @click="accountStatsExpanded = !accountStatsExpanded"
                                >
                                    <span>{{ accountStatsExpanded ? 'Thu gọn' : 'Chi tiết' }}</span>
                                    <ChevronUp v-if="accountStatsExpanded" :size="12" />
                                    <ChevronDown v-else :size="12" />
                                </button>
                            </div>

                            <div v-if="!accountStatsExpanded" class="mt-2 flex flex-wrap gap-1.5">
                                <div
                                    v-for="statCard in accountStatsCards"
                                    :key="'summary-no-link-' + statCard.key"
                                    class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white/80 dark:bg-zinc-800/60"
                                >
                                    <span class="text-[10px]">{{ statCard.icon }}</span>
                                    <span class="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400">{{ statCard.label }}</span>
                                    <span class="text-[10px] font-mono font-semibold" :class="statCard.color">
                                        {{ statCard.stats?.tokens?.toLocaleString() || 0 }} tk
                                    </span>
                                    <span class="text-[10px] text-zinc-400">· {{ statCard.stats?.requests_total?.toLocaleString() || 0 }} req</span>
                                </div>
                            </div>

                            <div v-if="accountStatsExpanded" class="mt-2 grid gap-2 md:grid-cols-2">
                                <div
                                    v-for="statCard in accountStatsCards"
                                    :key="'detail-no-link-' + statCard.key"
                                    class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white/80 dark:bg-zinc-800/60 p-2"
                                >
                                    <div class="flex items-center gap-1.5 mb-1.5">
                                        <span class="text-[10px]">{{ statCard.icon }}</span>
                                        <span class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                            {{ statCard.label }}
                                        </span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-x-2 gap-y-1 text-[11px]">
                                        <div class="flex items-center justify-between">
                                            <span class="text-zinc-400">Tokens</span>
                                            <span class="font-mono font-semibold" :class="statCard.color">{{ statCard.stats?.tokens?.toLocaleString() || 0 }}</span>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <span class="text-zinc-400">Ảnh/Video</span>
                                            <span class="font-mono text-zinc-700 dark:text-zinc-300">
                                                {{ statCard.stats?.images?.toLocaleString() || 0 }}/{{ statCard.stats?.videos?.toLocaleString() || 0 }}
                                            </span>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <span class="text-zinc-400">Req</span>
                                            <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ statCard.stats?.requests_total?.toLocaleString() || 0 }}</span>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <span class="text-zinc-400">Cache</span>
                                            <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ statCard.stats?.cache_hits?.toLocaleString() || 0 }}</span>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <span class="text-zinc-400">Success/Fail</span>
                                            <span class="font-mono text-zinc-700 dark:text-zinc-300">
                                                {{ statCard.stats?.requests_succeeded?.toLocaleString() || 0 }}/{{ statCard.stats?.requests_failed?.toLocaleString() || 0 }}
                                            </span>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <span class="text-zinc-400">Cost</span>
                                            <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ fmtCost(statCard.stats?.cost_amount || 0) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p v-else-if="accountStatisticsLoading" class="text-xs text-zinc-400">Đang tải thống kê tài khoản...</p>
                        <p v-else class="text-xs text-zinc-400">Chưa có dữ liệu thống kê tài khoản.</p>
                    </div>
                </div>

                <!-- History viewer area -->
                <div v-else-if="centerViewMode === 'history' && selectedHistoryDetail" class="flex-1 flex flex-col overflow-hidden">
                    <div class="px-6 pt-5 pb-4 border-b border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shrink-0">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs text-zinc-400">Đang xem lịch sử</p>
                                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ selectedLink.short_code }} · {{ formatRelative(selectedHistoryDetail.created_at) }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button class="af-btn-outline text-xs h-8 px-3" @click="openEditorView">
                                    Quay lại tạo content
                                </button>
                                <button class="af-btn-primary text-xs h-8 px-3" @click="applyHistoryToEditor()">
                                    Áp dụng vào editor
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto px-6 py-5 flex flex-col gap-4">
                        <div class="af-surface p-4">
                            <h3 class="text-xs font-semibold text-zinc-500 uppercase tracking-wide mb-3">Thông tin bản ghi</h3>
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 text-sm">
                                <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 p-3 bg-zinc-50 dark:bg-zinc-900/40">
                                    <p class="text-xs text-zinc-500">Trạng thái</p>
                                    <p class="mt-1 font-semibold" :class="selectedHistoryDetail.status === 'succeeded' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                                        {{ selectedHistoryDetail.status || '—' }}
                                    </p>
                                </div>
                                <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 p-3 bg-zinc-50 dark:bg-zinc-900/40">
                                    <p class="text-xs text-zinc-500">Preset</p>
                                    <p class="mt-1 font-semibold text-zinc-800 dark:text-zinc-200">{{ selectedHistoryDetail.preset_id || '—' }}</p>
                                </div>
                                <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 p-3 bg-zinc-50 dark:bg-zinc-900/40">
                                    <p class="text-xs text-zinc-500">Model</p>
                                    <p class="mt-1 font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ selectedHistoryDetail.model_used || selectedHistoryDetail.ai_model || '—' }}</p>
                                </div>
                                <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 p-3 bg-zinc-50 dark:bg-zinc-900/40">
                                    <p class="text-[10px] text-zinc-500 uppercase tracking-widest font-semibold">Tokens used</p>
                                    <p class="mt-1 font-mono text-xs text-zinc-700 dark:text-zinc-300 font-semibold" v-if="selectedHistoryDetail.usage">
                                        {{ (selectedHistoryDetail.usage.tokens_prompt || 0) + (selectedHistoryDetail.usage.tokens_completion || 0) }}
                                    </p>
                                    <p class="mt-1 font-mono text-xs text-zinc-700 dark:text-zinc-300" v-else>—</p>
                                </div>
                            </div>
                        </div>

                        <div v-if="historyOutputVariants.length > 0" class="flex flex-col gap-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ historyOutputVariants.length }} phiên bản
                                </h3>
                            </div>
                            <div
                                v-for="(variant, idx) in historyOutputVariants"
                                :key="'history-variant-' + idx"
                                class="af-surface rounded-xl overflow-hidden"
                            >
                                <div class="flex items-center justify-between px-4 py-2.5 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/60">
                                    <span class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">
                                        Phiên bản {{ idx + 1 }}
                                        <span v-if="variant.kind" class="ml-1.5 text-zinc-400 font-normal">{{ variant.kind }}</span>
                                    </span>
                                    <div class="flex items-center gap-2">
                                        <button
                                            @click="copyHistoryVariantWithLink(variant.text)"
                                            class="h-6 px-2.5 rounded-md text-[11px] font-semibold flex items-center gap-1.5 transition-colors border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-500/20"
                                        >
                                            <Link2 :size="10" />
                                            Copy + Link
                                        </button>
                                        <button
                                            @click="copyText(variant.text, `Đã copy phiên bản ${idx + 1}`)"
                                            class="h-6 px-2 rounded-md text-[11px] font-semibold flex items-center gap-1 transition-colors bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-600"
                                        >
                                            <Copy :size="10" />
                                            Copy
                                        </button>
                                    </div>
                                </div>
                                <div class="px-4 py-3">
                                    <pre class="text-sm text-zinc-800 dark:text-zinc-200 whitespace-pre-wrap leading-relaxed font-sans">{{ variant.text || '—' }}</pre>
                                </div>
                            </div>
                        </div>

                        <div v-if="historyOutputMedia.length > 0" class="flex flex-col gap-3">
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Media</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <template v-for="(item, idx) in historyOutputMedia" :key="'history-media-' + idx">
                                    <div class="group relative bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden shadow-sm hover:shadow-md transition-all duration-300">
                                        <div class="bg-zinc-100 dark:bg-zinc-900 flex items-center justify-center overflow-hidden" :class="item.type === 'video' ? 'aspect-video' : 'aspect-square'">
                                            <video
                                                v-if="item.type === 'video' && item.url"
                                                :key="item.url"
                                                :src="item.url"
                                                controls
                                                class="w-full h-full object-contain"
                                                preload="metadata"
                                            />
                                            <img
                                                v-else-if="item.url || item.base64"
                                                :src="item.url || `data:image/png;base64,${item.base64}`"
                                                class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                                                alt="History generated media"
                                            />
                                            <div v-else class="text-zinc-400">Không có media</div>
                                        </div>
                                        <div class="p-3 border-t border-zinc-100 dark:border-zinc-700 flex items-center justify-between">
                                            <span class="text-[10px] text-zinc-400 uppercase tracking-wider font-bold">
                                                {{ item.type === 'video' ? 'AI Video' : 'AI Image' }} #{{ idx + 1 }}
                                            </span>
                                            <a v-if="item.url" :href="item.url" target="_blank" download class="text-indigo-600 dark:text-indigo-400 text-[10px] font-bold hover:underline">Download</a>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Editor area -->
                <div v-else class="flex-1 flex flex-col overflow-hidden">

                    <!-- Toolbar / Preset tabs & Stats -->
                    <div class="px-6 pt-5 pb-4 border-b border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shrink-0">
                        <div class="flex flex-col gap-4 mb-4">
                            <!-- Selected Link info -->
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs text-zinc-400">Generating for</p>
                                    <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 font-mono">{{ selectedLink.short_code }}</p>
                                    <p v-if="selectedLink.shop_label" class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                        {{ selectedLink.shop_label }}
                                    </p>
                                </div>
                                <div v-if="lastGeneration" class="text-right">
                                    <p class="text-[10px] text-zinc-400">Tokens used</p>
                                    <p class="text-xs font-mono text-zinc-600 dark:text-zinc-300">
                                        {{ (lastGeneration.usage?.tokens_prompt || 0) + (lastGeneration.usage?.tokens_completion || 0) }}
                                        <span v-if="lastGeneration.from_cache" class="ml-1 text-amber-500">⚡ cache</span>
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Statistics Widget -->
                            <div class="grid gap-2">
                                <div v-if="accountStatsCards.length > 0" class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-900/40 p-2.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                            Thống kê theo tài khoản
                                        </p>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-600 dark:text-zinc-300"
                                            @click="accountStatsExpanded = !accountStatsExpanded"
                                        >
                                            <span>{{ accountStatsExpanded ? 'Thu gọn' : 'Chi tiết' }}</span>
                                            <ChevronUp v-if="accountStatsExpanded" :size="12" />
                                            <ChevronDown v-else :size="12" />
                                        </button>
                                    </div>

                                    <div v-if="!accountStatsExpanded" class="mt-2 flex flex-wrap gap-1.5">
                                        <div
                                            v-for="statCard in accountStatsCards"
                                            :key="'summary-account-' + statCard.key"
                                            class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white/80 dark:bg-zinc-800/60"
                                        >
                                            <span class="text-[10px]">{{ statCard.icon }}</span>
                                            <span class="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400">{{ statCard.label }}</span>
                                            <span class="text-[10px] font-mono font-semibold" :class="statCard.color">
                                                {{ statCard.stats?.tokens?.toLocaleString() || 0 }} tk
                                            </span>
                                            <span class="text-[10px] text-zinc-400">· {{ statCard.stats?.requests_total?.toLocaleString() || 0 }} req</span>
                                        </div>
                                    </div>

                                    <div v-if="accountStatsExpanded" class="mt-2 grid gap-2 md:grid-cols-2">
                                        <div
                                            v-for="statCard in accountStatsCards"
                                            :key="'detail-account-' + statCard.key"
                                            class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white/80 dark:bg-zinc-800/60 p-2"
                                        >
                                            <div class="flex items-center gap-1.5 mb-1.5">
                                                <span class="text-[10px]">{{ statCard.icon }}</span>
                                                <span class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                                    {{ statCard.label }}
                                                </span>
                                            </div>
                                            <div class="grid grid-cols-2 gap-x-2 gap-y-1 text-[11px]">
                                                <div class="flex items-center justify-between">
                                                    <span class="text-zinc-400">Tokens</span>
                                                    <span class="font-mono font-semibold" :class="statCard.color">{{ statCard.stats?.tokens?.toLocaleString() || 0 }}</span>
                                                </div>
                                                <div class="flex items-center justify-between">
                                                    <span class="text-zinc-400">Ảnh/Video</span>
                                                    <span class="font-mono text-zinc-700 dark:text-zinc-300">
                                                        {{ statCard.stats?.images?.toLocaleString() || 0 }}/{{ statCard.stats?.videos?.toLocaleString() || 0 }}
                                                    </span>
                                                </div>
                                                <div class="flex items-center justify-between">
                                                    <span class="text-zinc-400">Req</span>
                                                    <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ statCard.stats?.requests_total?.toLocaleString() || 0 }}</span>
                                                </div>
                                                <div class="flex items-center justify-between">
                                                    <span class="text-zinc-400">Cache</span>
                                                    <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ statCard.stats?.cache_hits?.toLocaleString() || 0 }}</span>
                                                </div>
                                                <div class="flex items-center justify-between">
                                                    <span class="text-zinc-400">Success/Fail</span>
                                                    <span class="font-mono text-zinc-700 dark:text-zinc-300">
                                                        {{ statCard.stats?.requests_succeeded?.toLocaleString() || 0 }}/{{ statCard.stats?.requests_failed?.toLocaleString() || 0 }}
                                                    </span>
                                                </div>
                                                <div class="flex items-center justify-between">
                                                    <span class="text-zinc-400">Cost</span>
                                                    <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ fmtCost(statCard.stats?.cost_amount || 0) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="linkStatsCards.length > 0" class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-900/40 p-2.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                            Thống kê theo link hiện tại
                                        </p>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-600 dark:text-zinc-300"
                                            @click="linkStatsExpanded = !linkStatsExpanded"
                                        >
                                            <span>{{ linkStatsExpanded ? 'Thu gọn' : 'Chi tiết' }}</span>
                                            <ChevronUp v-if="linkStatsExpanded" :size="12" />
                                            <ChevronDown v-else :size="12" />
                                        </button>
                                    </div>

                                    <div v-if="!linkStatsExpanded" class="mt-2 flex flex-wrap gap-1.5">
                                        <div
                                            v-for="statCard in linkStatsCards"
                                            :key="'summary-link-' + statCard.key"
                                            class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white/80 dark:bg-zinc-800/60"
                                        >
                                            <span class="text-[10px]">{{ statCard.icon }}</span>
                                            <span class="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400">{{ statCard.label }}</span>
                                            <span class="text-[10px] font-mono font-semibold" :class="statCard.color">
                                                {{ statCard.stats?.tokens?.toLocaleString() || 0 }} tk
                                            </span>
                                            <span class="text-[10px] text-zinc-400">· {{ statCard.stats?.requests_total?.toLocaleString() || 0 }} req</span>
                                        </div>
                                    </div>

                                    <div v-if="linkStatsExpanded" class="mt-2 grid gap-2 md:grid-cols-2">
                                        <div
                                            v-for="statCard in linkStatsCards"
                                            :key="'detail-link-' + statCard.key"
                                            class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white/80 dark:bg-zinc-800/60 p-2"
                                        >
                                            <div class="flex items-center gap-1.5 mb-1.5">
                                                <span class="text-[10px]">{{ statCard.icon }}</span>
                                                <span class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                                    {{ statCard.label }}
                                                </span>
                                            </div>
                                            <div class="grid grid-cols-2 gap-x-2 gap-y-1 text-[11px]">
                                                <div class="flex items-center justify-between">
                                                    <span class="text-zinc-400">Tokens</span>
                                                    <span class="font-mono font-semibold" :class="statCard.color">{{ statCard.stats?.tokens?.toLocaleString() || 0 }}</span>
                                                </div>
                                                <div class="flex items-center justify-between">
                                                    <span class="text-zinc-400">Ảnh/Video</span>
                                                    <span class="font-mono text-zinc-700 dark:text-zinc-300">
                                                        {{ statCard.stats?.images?.toLocaleString() || 0 }}/{{ statCard.stats?.videos?.toLocaleString() || 0 }}
                                                    </span>
                                                </div>
                                                <div class="flex items-center justify-between">
                                                    <span class="text-zinc-400">Req</span>
                                                    <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ statCard.stats?.requests_total?.toLocaleString() || 0 }}</span>
                                                </div>
                                                <div class="flex items-center justify-between">
                                                    <span class="text-zinc-400">Cache</span>
                                                    <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ statCard.stats?.cache_hits?.toLocaleString() || 0 }}</span>
                                                </div>
                                                <div class="flex items-center justify-between">
                                                    <span class="text-zinc-400">Success/Fail</span>
                                                    <span class="font-mono text-zinc-700 dark:text-zinc-300">
                                                        {{ statCard.stats?.requests_succeeded?.toLocaleString() || 0 }}/{{ statCard.stats?.requests_failed?.toLocaleString() || 0 }}
                                                    </span>
                                                </div>
                                                <div class="flex items-center justify-between">
                                                    <span class="text-zinc-400">Cost</span>
                                                    <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ fmtCost(statCard.stats?.cost_amount || 0) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <p v-else-if="linkStatisticsLoading" class="text-xs text-zinc-400 px-1">
                                    Đang tải thống kê link hiện tại...
                                </p>
                            </div>
                        </div>

                        <!-- Preset tabs -->
                        <div class="flex gap-1 p-1 bg-zinc-100 dark:bg-zinc-800 rounded-lg overflow-x-auto">
                            <button
                                v-for="preset in presets"
                                :key="preset.id"
                                @click="selectPreset(preset)"
                                :disabled="generating"
                                class="shrink-0 h-8 px-3 rounded-md text-xs font-medium whitespace-nowrap transition-all disabled:opacity-50 disabled:cursor-not-allowed"
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
                                    <select v-model="form.provider_key" @change="onProviderChange" :disabled="generating" class="af-input h-9 text-sm disabled:opacity-50">
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
                                    
                                    <!-- Dropdown for registered models -->
                                    <select 
                                        v-if="selectedProvider && selectedProvider.models && selectedProvider.models.length > 0"
                                        v-model="form.model" 
                                        :disabled="generating"
                                        class="af-input h-9 text-sm disabled:opacity-50"
                                    >
                                        <option value="" disabled>-- Chọn model --</option>
                                        <option 
                                            v-for="m in selectedProvider.models" 
                                            :key="m.id" 
                                            :value="m.id"
                                        >
                                            {{ m.name }}
                                        </option>
                                    </select>

                                    <!-- Text input fallback for unknown/custom -->
                                    <input
                                        v-else
                                        v-model="form.model"
                                        type="text"
                                        :disabled="generating"
                                        class="af-input h-9 text-sm font-mono disabled:opacity-50"
                                        :placeholder="selectedProviderDefaultModel || 'gemini-1.5-flash'"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- ── Options form ── -->
                        <div class="af-surface p-5 flex flex-col gap-4 relative">
                            <!-- Header -->
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Tuỳ chọn nội dung</h3>
                            </div>

                            <!-- Basic Form Grid -->
                            <div class="grid grid-cols-2 gap-4 mt-1">
                                <!-- Tone — hidden for hashtags -->
                                <div v-if="selectedPreset?.id !== 'hashtags_pack'" class="flex flex-col gap-1.5">
                                    <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Giọng văn</label>
                                    <select v-model="form.tone" :disabled="generating" class="af-input h-9 text-sm disabled:opacity-50">
                                        <option value="friendly">Thân thiện</option>
                                        <option value="hype">Hype / Cảm xúc</option>
                                        <option value="professional">Chuyên nghiệp</option>
                                        <option value="minimalist">Minimalist</option>
                                    </select>
                                </div>

                                <!-- Output type multi-select (based on provider capabilities) -->
                                <div v-if="form.provider_key && hasAnyCapability" class="flex flex-col gap-1.5">
                                    <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Output</label>
                                    <div class="flex flex-col gap-1.5 h-auto px-3 py-2 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                        <label v-if="providerCapsArray.includes('text')" class="flex items-center gap-2 cursor-pointer group">
                                            <input type="checkbox" v-model="form.generate_text" :disabled="generating" class="w-3.5 h-3.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-600 dark:border-zinc-700 dark:bg-zinc-800 disabled:opacity-50" />
                                            <span class="text-xs text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 group-hover:dark:text-zinc-200">✍️ Text</span>
                                        </label>
                                        <label v-if="providerCapsArray.includes('image')" class="flex items-center gap-2 cursor-pointer group">
                                            <input type="checkbox" v-model="form.generate_image" :disabled="generating" class="w-3.5 h-3.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-600 dark:border-zinc-700 dark:bg-zinc-800 disabled:opacity-50" />
                                            <span class="text-xs text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 group-hover:dark:text-zinc-200">🖼️ Image</span>
                                        </label>
                                        <label v-if="providerCapsArray.includes('video')" class="flex items-center gap-2 cursor-pointer group">
                                            <input type="checkbox" v-model="form.generate_video" :disabled="generating" class="w-3.5 h-3.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-600 dark:border-zinc-700 dark:bg-zinc-800 disabled:opacity-50" />
                                            <span class="text-xs text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 group-hover:dark:text-zinc-200">🎬 Video</span>
                                        </label>

                                        <!-- Video Duration Selector -->
                                        <div v-if="form.generate_video && form.provider_key === 'seedance'" class="mt-1 ml-5.5 flex flex-col gap-1">
                                            <div class="flex items-center gap-2">
                                                <span class="text-[10px] text-zinc-500">Thời lượng:</span>
                                                <select v-model="form.video_duration" :disabled="generating" class="text-[10px] h-6 px-1.5 py-0 border-zinc-200 rounded-md bg-white dark:bg-zinc-800 dark:border-zinc-700">
                                                    <option v-for="s in [4,5,6,7,8,9,10,11,12]" :key="s" :value="s">{{ s }} giây</option>
                                                </select>
                                            </div>
                                            <p class="text-[9px]" :class="form.video_duration > 5 ? 'text-amber-600 dark:text-amber-400 font-medium' : 'text-zinc-400'">
                                                Tiêu tốn {{ form.video_duration * 5 }} credits
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Goal — only FB Post -->
                                <div v-if="requiresGoal" class="flex flex-col gap-1.5">
                                    <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Mục tiêu</label>
                                    <select v-model="form.goal" :disabled="generating" class="af-input h-9 text-sm disabled:opacity-50">
                                        <option value="traffic">Traffic (click)</option>
                                        <option value="conversion">Chuyển đổi / Bán hàng</option>
                                        <option value="remarketing">Remarketing</option>
                                    </select>
                                </div>

                                <!-- Product Title -->
                                <div class="flex flex-col gap-1.5 col-span-2 sm:col-span-1">
                                    <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Tên sản phẩm *</label>
                                    <input v-model="form.product_title" type="text" :disabled="generating" class="af-input h-9 text-sm disabled:opacity-50" placeholder="VD: Son môi Dior 999" />
                                </div>
                                
                                <!-- Product Price -->
                                <div class="flex flex-col gap-1.5 col-span-2 sm:col-span-1">
                                    <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Giá sản phẩm</label>
                                    <input v-model="form.product_price" type="text" :disabled="generating" class="af-input h-9 text-sm disabled:opacity-50" placeholder="VD: 450.000đ" />
                                </div>
                            </div>

                            <!-- Audience — only FB Post -->
                            <div v-if="requiresAudience" class="flex flex-col gap-1.5">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Đối tượng mục tiêu</label>
                                <input
                                    v-model="form.audience"
                                    type="text"
                                    :disabled="generating"
                                    class="af-input h-9 text-sm disabled:opacity-50"
                                    placeholder="VD: phụ nữ 25-35 tuổi quan tâm làm đẹp"
                                />
                            </div>

                            <!-- Advanced Accordion Toggle -->
                            <button
                                @click="isAdvancedOpen = !isAdvancedOpen"
                                class="flex items-center gap-2 text-xs font-semibold text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 mt-2 transition-colors w-fit focus:outline-none"
                            >
                                <ChevronDown v-if="!isAdvancedOpen" :size="14" />
                                <ChevronUp v-else :size="14" />
                                Lựa chọn nâng cao
                            </button>

                            <!-- Advanced Options Body -->
                            <div v-show="isAdvancedOpen" class="flex flex-col gap-4 pt-3 pb-2 border-t border-zinc-100 dark:border-zinc-800 animate-in fade-in slide-in-from-top-2 duration-200">
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="flex flex-col gap-1.5 col-span-2">
                                        <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">USP / Điểm nổi bật</label>
                                        <textarea v-model="form.usp" :disabled="generating" class="af-input h-20 py-2 text-sm disabled:opacity-50 resize-none" placeholder="VD: Chất son lỳ, lâu trôi 24h, không bám cốc"></textarea>
                                    </div>
                                    <div class="flex flex-col gap-1.5 col-span-2 sm:col-span-1">
                                        <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Ưu đãi / Flash Sale</label>
                                        <input v-model="form.offers" type="text" :disabled="generating" class="af-input h-9 text-sm disabled:opacity-50" placeholder="VD: Mua 1 tặng 1, Free ship đơn từ 50k" />
                                    </div>
                                    <div class="flex flex-col gap-1.5 col-span-2 sm:col-span-1">
                                        <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Hạn dùng ưu đãi</label>
                                        <input v-model="form.expiration" type="text" :disabled="generating" class="af-input h-9 text-sm disabled:opacity-50" placeholder="VD: Chỉ còn 2 ngày, Duy nhất dịp 11/11" />
                                    </div>
                                    <div class="flex flex-col gap-1.5 col-span-2">
                                        <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Lưu ý / Chính sách</label>
                                        <textarea v-model="form.policy" :disabled="generating" class="af-input h-20 py-2 text-sm disabled:opacity-50 resize-none" placeholder="VD: Bảo hành 12 tháng, Đổi trả 7 ngày"></textarea>
                                    </div>
                                </div>

                                <!-- Custom prompt -->
                                <div class="flex flex-col gap-1.5">
                                    <div class="flex items-center justify-between">
                                        <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Prompt tuỳ chỉnh (ghi đè)</label>
                                    </div>
                                    <textarea
                                        v-model="form.custom_prompt"
                                        :disabled="generating"
                                        class="af-input text-sm resize-y leading-relaxed min-h-[80px] disabled:opacity-50"
                                        rows="3"
                                        placeholder="Nhập prompt tuỳ chỉnh... Nếu để trống, hệ thống dùng template mặc định."
                                    ></textarea>
                                </div>
                                
                                <!-- Safety constraints -->
                                <div class="flex flex-col gap-2 p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg border border-zinc-100 dark:border-zinc-800">
                                    <h4 class="text-[11px] font-semibold text-zinc-500 uppercase">Ràng buộc an toàn</h4>
                                    <label class="flex items-center gap-2 cursor-pointer group w-fit">
                                        <input type="checkbox" v-model="form.safety_no_absolute" :disabled="generating" class="w-3.5 h-3.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-600 dark:border-zinc-700 dark:bg-zinc-800 disabled:opacity-50" />
                                        <span class="text-xs text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 group-hover:dark:text-zinc-200">Không dùng cam kết tuyệt đối (nhất, 100%, trị dứt điểm)</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer group w-fit">
                                        <input type="checkbox" v-model="form.safety_no_medical" :disabled="generating" class="w-3.5 h-3.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-600 dark:border-zinc-700 dark:bg-zinc-800 disabled:opacity-50" />
                                        <span class="text-xs text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 group-hover:dark:text-zinc-200">Không vi phạm từ khoá Y Tế / Dược (Facebook strict)</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Variant count -->
                            <div class="flex flex-col gap-1.5 mt-2">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                    Số lượng phiên bản
                                    <span class="text-zinc-400 font-normal ml-1">({{ form.variant_count }} bản)</span>
                                </label>
                                <div class="flex items-center gap-3">
                                    <input
                                        v-model.number="form.variant_count"
                                        :disabled="generating"
                                        type="range" min="1" max="5" step="1"
                                        class="flex-1 h-1.5 rounded accent-indigo-500 cursor-pointer disabled:opacity-50"
                                    />
                                    <span class="text-xs font-mono w-3 text-zinc-600 dark:text-zinc-300">{{ form.variant_count }}</span>
                                </div>
                                <p class="text-[10px] text-zinc-400 leading-tight">Mỗi phiên bản sẽ có một cách viết khác nhau để bạn chọn mẫu ưng ý nhất.</p>
                            </div>

                            <!-- Generate button -->
                            <button
                                @click="generate(false)"
                                :disabled="generating || !form.product_title"
                                class="af-btn-primary h-10 flex items-center justify-center gap-2 text-sm font-semibold mt-1"
                            >
                                <Loader2 v-if="generating" :size="16" class="animate-spin" />
                                <template v-else>
                                    <Wand2 v-if="selectedPreset?.type === 'image'" :size="16" />
                                    <Sparkles v-else :size="16" />
                                </template>
                                {{ 
                                    generating 
                                    ? 'Đang phân tích & tạo nội dung...' 
                                    : 'Bắt đầu tạo nội dung'
                                }}
                            </button>
                        </div>

                        <!-- ── Product Image picker ── -->
                        <div class="af-surface p-4 flex flex-col gap-3">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Ảnh sản phẩm</h3>
                                <span class="text-[10px] text-zinc-400">Đính kèm vào content (tùy chọn)</span>
                            </div>

                            <!-- URL input & File upload -->
                            <div class="flex gap-2 items-center">
                                <label class="cursor-pointer shrink-0">
                                    <div class="af-btn-outline h-9 px-3 flex items-center gap-1.5" :class="{'opacity-50 pointer-events-none': isUploadingImage || generating}">
                                        <Loader2 v-if="isUploadingImage" :size="13" class="animate-spin" />
                                        <Upload v-else :size="13" />
                                        <span class="text-xs font-medium">{{ isUploadingImage ? 'Đang tải...' : 'Upload ảnh' }}</span>
                                    </div>
                                    <input type="file" accept="image/*" class="hidden" @change="handleImageUpload" :disabled="isUploadingImage || generating" />
                                </label>
                                <span class="text-[10px] text-zinc-400 font-medium tracking-wide uppercase">hoặc</span>
                                <input
                                    v-model="imageUrlInput"
                                    type="url"
                                    :disabled="generating"
                                    class="af-input h-9 text-sm flex-1 min-w-0 disabled:opacity-50"
                                    placeholder="Paste URL ảnh sản phẩm..."
                                    @keydown.enter="addImageUrl"
                                />
                                <button @click="addImageUrl" :disabled="generating" class="af-btn-outline h-9 px-3 text-xs font-medium flex items-center gap-1 shrink-0 disabled:opacity-50 disabled:cursor-not-allowed">
                                    <Plus :size="13" />
                                    Thêm URL
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
                                        :disabled="generating"
                                        class="absolute top-1 right-1 w-5 h-5 rounded-full bg-zinc-900/70 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity disabled:opacity-0"
                                    >
                                        <X :size="10" />
                                    </button>
                                </div>
                            </div>

                            <!-- Auto-extract hint -->
                            <p class="text-[10px] text-zinc-400 leading-relaxed">
                                💡 Dán URL ảnh sản phẩm, ảnh sẽ được đính kèm vào prompt để AI tham khảo phần trình bày sản phẩm.
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

                        <!-- ── Async Video Progress Banner ── -->
                        <div
                            v-if="asyncStatus && (asyncStatus === 'queued' || asyncStatus === 'processing')"
                            class="relative overflow-hidden rounded-lg border border-indigo-200 dark:border-indigo-500/30 bg-gradient-to-r from-indigo-50 via-purple-50 to-indigo-50 dark:from-indigo-500/10 dark:via-purple-500/10 dark:to-indigo-500/10 px-4 py-3"
                        >
                            <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/40 to-transparent dark:via-white/5 animate-[shimmer_2s_infinite]" style="animation: shimmer 2s infinite; background-size: 200% 100%;"></div>
                            <div class="relative flex items-center gap-3">
                                <div class="flex items-center justify-center w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-500/20">
                                    <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-indigo-800 dark:text-indigo-300 truncate">
                                        {{ asyncStatus === 'queued' ? 'Đang chờ xử lý video...' : 'Đang tạo video...' }}
                                    </p>
                                    <p class="text-xs text-indigo-600/70 dark:text-indigo-400/70 mt-0.5">
                                        Poll #{{ asyncPollCount }} • Tối đa {{ asyncPollMax }} lần (~5 phút)
                                    </p>
                                </div>
                                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full"
                                      :class="asyncStatus === 'processing'
                                          ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'
                                          : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300'">
                                    {{ asyncStatus }}
                                </span>
                            </div>
                        </div>

                        <!-- Retry CTA after timeout -->
                        <div v-if="errorMsg && errorMsg.includes('Quá thời gian')" class="flex items-center gap-2">
                            <button @click="retryAsyncPolling" class="text-xs text-indigo-600 dark:text-indigo-400 font-medium hover:underline">
                                🔄 Thử lại
                            </button>
                        </div>

                        <!-- Generation output is now handled by auto-switching to the History Detail view. -->
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════
                 RIGHT PANEL — History list
            ═══════════════════════════════════════════ -->
            <div class="w-[360px] shrink-0 border-l border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 flex flex-col overflow-hidden">
                <div class="px-4 py-4 border-b border-zinc-200 dark:border-zinc-800">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Lịch sử AI</h2>
                        <Loader2 v-if="historyLoading" :size="12" class="animate-spin text-zinc-400" />
                    </div>
                    <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">
                        Chọn bản ghi để hiển thị nội dung.
                    </p>
                </div>

                <div v-if="!selectedLink" class="flex-1 flex items-center justify-center px-4 text-center text-xs text-zinc-400">
                    Chọn một tracking link để xem lịch sử tạo nội dung.
                </div>

                <template v-else>
                    <div class="flex-1 overflow-y-auto">
                        <p v-if="!historyLoading && history.length === 0" class="text-xs text-zinc-400 px-4 py-4 text-center">
                            Chưa có lịch sử cho link này
                        </p>
                        <button
                            v-for="item in history"
                            :key="item.id"
                            @click="selectHistoryItem(item)"
                            class="w-full text-left px-4 py-2.5 border-b border-zinc-100 dark:border-zinc-800 transition-colors"
                            :class="selectedHistoryItem?.id === item.id
                                ? 'bg-indigo-50 dark:bg-indigo-500/10'
                                : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/60'"
                        >
                            <div class="flex items-center gap-1.5 mb-0.5">
                                <span class="text-[9px] font-bold px-1.5 py-0.5 rounded uppercase tracking-wide" :class="platformBadgeClass(item.platform)">{{ item.platform }}</span>
                                <span class="text-[9px] px-1 py-0.5 rounded" :class="item.status === 'succeeded' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400' : 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400'">{{ item.status }}</span>
                            </div>
                            <p class="text-[11px] text-zinc-600 dark:text-zinc-300 line-clamp-1">{{ item.preview || '—' }}</p>
                            <p v-if="item.shop_label" class="text-[10px] text-zinc-500 dark:text-zinc-400 line-clamp-1 mt-0.5">
                                {{ item.shop_label }}
                            </p>
                            <p class="text-[10px] text-zinc-400 mt-0.5">{{ formatRelative(item.created_at) }}</p>
                        </button>
                    </div>

                    <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/40">
                        <div v-if="historyDetailLoading" class="text-xs text-zinc-400">Đang tải nội dung lịch sử...</div>
                        <div v-else-if="selectedHistoryDetail" class="flex items-center justify-between gap-2">
                            <p class="text-xs text-zinc-500 truncate">Đang xem: {{ formatRelative(selectedHistoryDetail.created_at) }}</p>
                            <button
                                @click="applyHistoryToEditor()"
                                class="af-btn-outline h-7 px-2.5 text-[11px] font-semibold shrink-0"
                            >
                                Áp dụng vào editor
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </AppShell>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import {
    Sparkles, Search, Link2, Loader2,
    AlertTriangle, CheckCircle2, RefreshCw,
    Copy, Check, Plus, X, ChevronDown, ChevronUp, Wand2, Upload, Pin
} from 'lucide-vue-next';
import AppShell from '@/Layouts/AppShell.vue';
import { useToast } from '@/Composables/useToast';

// ── Props ────────────────────────────────────────────────────────────────────
const props = defineProps({
    trackingLinks:       { type: Array, default: () => [] },
    presets:             { type: Array, default: () => [] },
    configuredProviders: { type: Array, default: () => [] },
});

const GOAL_PRESET_IDS = new Set(['fb_post', 'carousel_ad_copy', 'short_video_ad', 'product_story_video', 'ugc_review_video']);
const AUDIENCE_PRESET_IDS = new Set(['fb_post', 'carousel_ad_copy', 'short_video_ad', 'product_story_video', 'ugc_review_video', 'seo_description']);

const toast = useToast();

// ── State ────────────────────────────────────────────────────────────────────
const linkSearch     = ref('');
const selectedLink   = ref(null);
const selectedPreset = ref(props.presets[0] ?? null);

const history        = ref([]);
const historyLoading = ref(false);
const historyDetailLoading = ref(false);
const selectedHistoryItem = ref(null);
const selectedHistoryDetail = ref(null);
const centerViewMode = ref('editor');
const isAdvancedOpen = ref(false);

const accountStatistics = ref(null);
const linkStatistics = ref(null);
const accountStatisticsLoading = ref(false);
const linkStatisticsLoading = ref(false);
const accountStatsExpanded = ref(false);
const linkStatsExpanded = ref(false);

const generating     = ref(false);
const outputVariants = ref([]);
const outputMedia    = ref([]);

// ─── Async Polling State (Seedance video generation) ──────────────────────────
const asyncPollingInterval = ref(null);
const asyncGenId           = ref(null);
const asyncStatus          = ref(null);   // 'queued' | 'processing' | 'succeeded' | 'failed'
const asyncPollCount       = ref(0);
const asyncPollMax         = 60; // 5 min at 5s intervals
const lastGeneration = ref(null);
const errorMsg       = ref('');
const errorHint      = ref('');
const copiedIndex    = ref(-1);
const isRestoring    = ref(false);

const imageUrlInput    = ref('');
const isUploadingImage = ref(false);

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
    usp:           '',
    offers:        '',
    expiration:    '',
    policy:        '',
    safety_no_absolute: false,
    safety_no_medical: false,
    safety_no_sensitive: false,
    generate_text: true,
    generate_image: false,
    generate_video: false,
    video_duration: 5,
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

const selectedProvider = computed(() => {
    return props.configuredProviders.find(p => p.key === form.value.provider_key) || null;
});

const selectedProviderDefaultModel = computed(() => {
    return selectedProvider.value?.default_model ?? '';
});

const selectedProviderCapabilities = computed(() => {
    return selectedProvider.value?.capabilities ?? {};
});

const selectedModelInfo = computed(() => {
    if (!selectedProvider.value || !form.value.model) return null;
    return selectedProvider.value.models.find(m => m.id === form.value.model) || null;
});

/** Normalized array of caps: ['text', 'image', 'video'] */
const providerCapsArray = computed(() => {
    // If a specific model is selected, use ITS capabilities
    if (selectedModelInfo.value) {
        return selectedModelInfo.value.capabilities || [];
    }
    // Fallback to provider-level capabilities
    const caps = selectedProvider.value?.capabilities ?? {};
    if (!caps || Object.keys(caps).length === 0) return [];
    return Array.isArray(caps) ? caps : Object.keys(caps).filter(k => caps[k]);
});

/** True if provider has at least one capability */
const hasAnyCapability = computed(() => providerCapsArray.value.length > 0);
const requiresGoal = computed(() => GOAL_PRESET_IDS.has(selectedPreset.value?.id || ''));
const requiresAudience = computed(() => AUDIENCE_PRESET_IDS.has(selectedPreset.value?.id || ''));
const accountStatsCards = computed(() => {
    if (!accountStatistics.value) return [];

    const stats = accountStatistics.value;
    const meta = stats.meta || {};
    const partnerCount = meta.partner_count ?? 0;
    const partnerStats = stats.partner || {
        requests_total: 0,
        requests_succeeded: 0,
        requests_failed: 0,
        cache_hits: 0,
        tokens: 0,
        images: 0,
        videos: 0,
        cost_amount: 0,
    };
    const cards = [
        {
            key: 'account',
            label: 'Tài khoản hiện tại',
            icon: '👤',
            color: 'text-indigo-600 dark:text-indigo-400',
            stats: stats.account || {},
        },
    ];

    cards.push({
        key: 'partner',
        label: `Partner (${partnerCount})`,
        icon: '🤝',
        color: 'text-cyan-600 dark:text-cyan-400',
        stats: partnerStats,
    });

    cards.push({
        key: 'total_shop',
        label: `Tổng shop (${meta.total_shop_count || 0})`,
        icon: '🛒',
        color: 'text-orange-600 dark:text-orange-400',
        stats: stats.total_shop || {},
    });

    cards.push({
        key: 'unknown_shop',
        label: 'Unknown Shop',
        icon: '❓',
        color: 'text-zinc-600 dark:text-zinc-300',
        stats: stats.unknown_shop || {},
    });

    return cards;
});
const linkStatsCards = computed(() => {
    if (!linkStatistics.value) return [];

    const stats = linkStatistics.value;
    const meta = stats.meta || {};

    return [
        {
            key: 'shop',
            label: meta.shop_label ? `Shop: ${meta.shop_label}` : 'Shop hiện tại',
            icon: '🛒',
            color: 'text-orange-600 dark:text-orange-400',
            stats: stats.shop || {},
        },
        {
            key: 'link',
            label: 'Tracking Link hiện tại',
            icon: '🔗',
            color: 'text-emerald-600 dark:text-emerald-400',
            stats: stats.link || {},
        },
    ];
});
const historyOutputVariants = computed(() => {
    const output = selectedHistoryDetail.value?.output ?? selectedHistoryDetail.value?.output_payload ?? {};
    return Array.isArray(output?.variants) ? output.variants : [];
});
const historyOutputMedia = computed(() => {
    const output = selectedHistoryDetail.value?.output ?? selectedHistoryDetail.value?.output_payload ?? {};
    return Array.isArray(output?.media) ? output.media : [];
});

// ── Watchers ─────────────────────────────────────────────────────────────────
watch([selectedLink, selectedPreset], () => {
    if (isRestoring.value) return;
    
    idempotencyKey = crypto.randomUUID();
    outputVariants.value = [];
    lastGeneration.value = null;
    errorMsg.value = '';
    errorHint.value = '';
});

watch(selectedLink, (newLink) => {
    accountStatsExpanded.value = false;
    linkStatsExpanded.value = false;
    if (newLink) {
        if (newLink.product_name) {
            form.value.product_title = newLink.product_name;
        }
        if (newLink.product_price) {
            form.value.product_price = newLink.product_price;
        }
        if (Array.isArray(newLink.product_image_urls) && newLink.product_image_urls.length > 0) {
            form.value.image_urls = [...newLink.product_image_urls];
        } else {
            form.value.image_urls = [];
        }
    }
});

watch(() => form.value.provider_key, () => {
    onProviderChange();
});

watch(form, () => {
    idempotencyKey = crypto.randomUUID();
}, { deep: true });

onMounted(() => {
    fetchAccountStatistics();
    applyPresetOutputDefaults();

    const urlParams = new URLSearchParams(window.location.search);
    const linkId = urlParams.get('link_id');
    
    if (linkId && props.trackingLinks.length > 0) {
        const link = props.trackingLinks.find(l => String(l.id) === String(linkId));
        if (link) {
            selectLink(link);
        }
    }
});

onUnmounted(() => {
    stopAsyncPolling();
});

// ── Methods ───────────────────────────────────────────────────────────────────
function selectedPresetType() {
    const type = selectedPreset.value?.type;
    return ['text', 'image', 'video'].includes(type) ? type : 'text';
}

function setOutputFlagsByType(type) {
    form.value.generate_text = type === 'text';
    form.value.generate_image = type === 'image';
    form.value.generate_video = type === 'video';
}

function enforceProviderCapabilities() {
    const caps = providerCapsArray.value;
    if (caps.length === 0) return;
    const knownCaps = caps.filter((cap) => ['text', 'image', 'video'].includes(cap));
    if (knownCaps.length === 0) return;

    if (!knownCaps.includes('text')) form.value.generate_text = false;
    if (!knownCaps.includes('image')) form.value.generate_image = false;
    if (!knownCaps.includes('video')) form.value.generate_video = false;

    if (!form.value.generate_text && !form.value.generate_image && !form.value.generate_video) {
        const preferred = selectedPresetType();
        const fallbackOrder = Array.from(new Set([preferred, 'video', 'image', 'text']));
        const picked = fallbackOrder.find((cap) => knownCaps.includes(cap)) ?? knownCaps[0];
        setOutputFlagsByType(picked);
    }
}

function applyPresetOutputDefaults() {
    setOutputFlagsByType(selectedPresetType());
    enforceProviderCapabilities();
}

function selectLink(link) {
    selectedLink.value = link;
    linkStatistics.value = null;
    accountStatsExpanded.value = false;
    linkStatsExpanded.value = false;
    selectedHistoryItem.value = null;
    selectedHistoryDetail.value = null;
    centerViewMode.value = 'editor';
    fetchHistory();
}

function selectPreset(preset) {
    selectedPreset.value = preset;
    applyPresetOutputDefaults();
}

function onProviderChange() {
    const p = props.configuredProviders.find(p => p.key === form.value.provider_key);
    
    if (p) {
        const models = p.models || [];
        const isCurrentModelValid = models.some(m => m.id === form.value.model);
        
        // If current model is NOT in the new provider's model list, sync to default
        if (!isCurrentModelValid) {
            form.value.model = p.default_model || (models.length > 0 ? models[0].id : '');
        }
    } else {
        // "Auto" mode
        form.value.model = '';
    }

    applyPresetOutputDefaults();
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

async function handleImageUpload(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    // Reset input
    event.target.value = '';

    if (file.size > 5 * 1024 * 1024) {
        toast.error('Ảnh quá lớn. Vui lòng chọn ảnh < 5MB.');
        return;
    }

    if (form.value.image_urls.length >= 5) {
        toast.warning('Tối đa 5 ảnh.');
        return;
    }

    const formData = new FormData();
    formData.append('image', file);

    isUploadingImage.value = true;
    try {
        const res = await axios.post(route('api.images.upload'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (res.data?.ok && res.data.data?.url) {
            form.value.image_urls.push(res.data.data.url);
            toast.success('Đã tải ảnh lên.');
        } else {
            throw new Error('Upload failed');
        }
    } catch (e) {
        const msg = e.response?.data?.message || 'Lỗi khi tải ảnh lên.';
        toast.error(msg);
    } finally {
        isUploadingImage.value = false;
    }
}

async function fetchHistory() {
    if (!selectedLink.value) return;
    historyLoading.value = true;
    try {
        const res = await axios.get(
            route('api.content.history', { trackingLink: selectedLink.value.id }),
            { params: { _ts: Date.now() } }
        );
        if (res.data?.ok) {
            const items = res.data.data?.data ?? res.data.data ?? [];
            history.value = items.map(item => ({
                ...item,
                platform: item.platform || inferHistoryPlatform(item.preset_id),
                platform_connection_id: item.platform_connection_id ?? null,
                shop_label: item.shop_label || null,
                preview: item.preview_text || '',
            }));
            // Keep current detail view stable, only clear stale selection.
            if (selectedHistoryItem.value) {
                const stillExists = history.value.some((item) => String(item.id) === String(selectedHistoryItem.value.id));
                if (!stillExists) {
                    selectedHistoryItem.value = null;
                    selectedHistoryDetail.value = null;
                }
            }
        }
    } catch {
        toast.warning('Không thể làm mới lịch sử AI ngay lúc này.');
    } finally {
        historyLoading.value = false;
        await Promise.all([
            fetchLinkStatistics(),
            fetchAccountStatistics(),
        ]);
    }
}

async function fetchAccountStatistics() {
    accountStatisticsLoading.value = true;
    try {
        const res = await axios.get(route('api.content.statistics.account'));
        if (res.data?.ok) {
            accountStatistics.value = res.data.data;
        }
    } catch {
        accountStatistics.value = null;
    } finally {
        accountStatisticsLoading.value = false;
    }
}

async function fetchLinkStatistics() {
    if (!selectedLink.value) {
        linkStatistics.value = null;
        return;
    }

    linkStatisticsLoading.value = true;
    try {
        const res = await axios.get(route('api.content.statistics', { trackingLink: selectedLink.value.id }));
        if (res.data?.ok) {
            linkStatistics.value = res.data?.data || null;
        }
    } catch {
        linkStatistics.value = null;
    } finally {
        linkStatisticsLoading.value = false;
    }
}

function buildHistoryPreview(output) {
    const raw = output?.variants?.[0]?.text ?? '';
    if (!raw) return '—';
    const compact = String(raw).replace(/\s+/g, ' ').trim();
    return compact.length > 100 ? `${compact.slice(0, 100)}...` : compact;
}

function inferHistoryPlatform(presetId) {
    const value = String(presetId ?? '');
    if (value.includes('fb')) return 'facebook';
    if (value.includes('tiktok')) return 'tiktok';
    if (value.includes('shopee')) return 'shopee';
    return 'generic';
}

function upsertHistoryFromGeneration(data, statusOverride = null) {
    const generationId = data?.generation_id || data?.id;
    if (!generationId) return;

    const incomingStatus = statusOverride || data?.status || 'processing';
    const incomingPreset = data?.preset_id || selectedPreset.value?.id || null;
    const incomingPreview = buildHistoryPreview(data?.output);
    const index = history.value.findIndex((item) => String(item.id) === String(generationId));

    if (index >= 0) {
        const current = history.value[index];
        history.value[index] = {
            ...current,
            status: incomingStatus,
            preset_id: incomingPreset ?? current.preset_id,
            platform: inferHistoryPlatform(incomingPreset ?? current.preset_id),
            preview: incomingPreview !== '—' ? incomingPreview : current.preview,
            preview_text: incomingPreview !== '—' ? incomingPreview : current.preview_text,
        };
        return;
    }

    history.value.unshift({
        id: generationId,
        status: incomingStatus,
        from_cache: Boolean(data?.from_cache),
        preset_id: incomingPreset,
        preview: incomingPreview,
        preview_text: incomingPreview,
        error_code: null,
        error_message_short: null,
        created_at: new Date().toISOString(),
        platform: inferHistoryPlatform(incomingPreset),
    });
}

async function selectHistoryItem(item) {
    if (!item?.id) return;
    selectedHistoryItem.value = item;
    historyDetailLoading.value = true;
    try {
        const res = await axios.get(route('api.content-generations.show', { id: item.id }));
        if (res.data?.ok) {
            selectedHistoryDetail.value = res.data.data;
            centerViewMode.value = 'history';
        }
    } catch {
        selectedHistoryDetail.value = null;
        toast.error('Không thể tải chi tiết lịch sử này.');
    } finally {
        historyDetailLoading.value = false;
    }
}

async function generate(forceNewSeed = false) {
    if (!selectedLink.value || !selectedPreset.value || generating.value) return;
    idempotencyKey = crypto.randomUUID();

    generating.value = true;
    centerViewMode.value = 'editor';
    errorMsg.value = '';
    errorHint.value = '';
    outputVariants.value = [];
    lastGeneration.value = null;

    // Build options object
    const options = { variant_count: form.value.variant_count };
    
    if (selectedPreset.value.id !== 'hashtags_pack') options.tone = form.value.tone;
    if (requiresGoal.value) options.goal = form.value.goal;
    if (requiresAudience.value) options.audience = form.value.audience;
    
    if (form.value.product_title) options.product_title = form.value.product_title;
    if (form.value.product_price) options.product_price = form.value.product_price;
    if (form.value.usp) options.usp = form.value.usp;
    if (form.value.offers) options.offers = form.value.offers;
    if (form.value.expiration) options.expiration = form.value.expiration;
    if (form.value.policy) options.policy = form.value.policy;
    if (selectedPreset.value.type === 'image') options.aspect_ratio = '4:5';
    if (selectedPreset.value.type === 'video') options.aspect_ratio = '9:16';
    if (form.value.custom_prompt) options.custom_prompt = form.value.custom_prompt;
    if (form.value.generate_video) options.duration = form.value.video_duration;

    options.safety_no_absolute = form.value.safety_no_absolute;
    options.safety_no_medical = form.value.safety_no_medical;
    options.safety_no_sensitive = form.value.safety_no_sensitive;

    const payload = {
        preset_id:      selectedPreset.value.id,
        variant_count:  form.value.variant_count,
        options,
        force_new_seed: true, // Always generate fresh content
    };

    // Optional overrides
    if (form.value.provider_key) payload.provider_key  = form.value.provider_key;
    if (form.value.model)        payload.model          = form.value.model;
    if (form.value.image_urls.length) payload.image_urls = form.value.image_urls;
    if (form.value.generate_text)  payload.generate_text  = true;
    if (form.value.generate_image) payload.generate_image = true;
    if (form.value.generate_video) payload.generate_video = true;

    try {
        const res = await axios.post(
            route('api.content.generate', { trackingLink: selectedLink.value.id }),
            payload,
            { headers: { 'Idempotency-Key': idempotencyKey } }
        );

        const data = res.data?.data ?? res.data;
        if (data?.generation_id) {
            upsertHistoryFromGeneration(data, data.status);
        }

        // ── Async path: video generation returns 'queued' ─────────────────
        if (data?.status === 'queued' && data?.generation_id) {
            startAsyncPolling(data.generation_id);
            return;
        }

        lastGeneration.value = {
            from_cache: data?.from_cache ?? false,
            usage:      data?.usage ?? {},
        };

        await fetchHistory();

        // ── Auto-open in history tab ──
        if (data?.generation_id) {
            const newItem = history.value.find(h => String(h.id) === String(data.generation_id));
            if (newItem) {
                await selectHistoryItem(newItem);
            }
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
        await fetchHistory();
    } finally {
        generating.value = false;
    }
}

// ─── Async Polling Functions ──────────────────────────────────────────────────

function startAsyncPolling(generationId) {
    stopAsyncPolling();
    asyncGenId.value    = generationId;
    asyncStatus.value   = 'queued';
    asyncPollCount.value = 0;
    generating.value     = true;
    upsertHistoryFromGeneration({ generation_id: generationId, status: 'queued', preset_id: selectedPreset.value?.id }, 'queued');

    asyncPollingInterval.value = setInterval(async () => {
        asyncPollCount.value++;

        // Timeout: 60 polls × 5s = 5 minutes
        if (asyncPollCount.value >= asyncPollMax) {
            stopAsyncPolling();
            errorMsg.value = 'Quá thời gian chờ. Video có thể vẫn đang xử lý.';
            errorHint.value = 'Kiểm tra lại ở Lịch sử hoặc bấm "Thử lại" để tiếp tục theo dõi.';
            return;
        }

        try {
            const res = await axios.get(
                route('api.content-generations.status', { id: generationId })
            );
            const data = res.data?.data;
            if (!data) return;

            asyncStatus.value = data.status;
            upsertHistoryFromGeneration(
                { ...data, generation_id: generationId, preset_id: selectedPreset.value?.id },
                data.status
            );

            if (data.status === 'succeeded') {
                stopAsyncPolling();
                outputMedia.value = data.output?.media ?? [];
                lastGeneration.value = { from_cache: false, usage: {} };
                await fetchHistory();
                toast.success('Video đã tạo xong!');

                // Automatic transition to detail view
                const newItem = history.value.find(h => String(h.id) === String(generationId));
                if (newItem) {
                    await selectHistoryItem(newItem);
                }
            } else if (data.status === 'failed') {
                stopAsyncPolling();
                errorMsg.value = data.error_message || 'Tạo video thất bại.';
            }
        } catch (err) {
            // Network error — don't stop polling, just skip this tick
            console.warn('Async poll error:', err.message);
        }
    }, 5000);
}

function stopAsyncPolling() {
    if (asyncPollingInterval.value) {
        clearInterval(asyncPollingInterval.value);
        asyncPollingInterval.value = null;
    }
    asyncGenId.value  = null;
    asyncStatus.value = null;
    generating.value  = false;
}

function retryAsyncPolling() {
    if (asyncGenId.value) {
        errorMsg.value  = '';
        errorHint.value = '';
        startAsyncPolling(asyncGenId.value);
    }
}

function applyHistoryToEditor() {
    const data = selectedHistoryDetail.value;
    if (!data?.id && !data?.generation_id) return;
    isRestoring.value = true;
    toast.success('Đang khôi phục...');
    // Restore Output — backend returns "output" not "output_payload"
    const output = data.output ?? data.output_payload ?? {};
    outputVariants.value = output.variants || [];
    outputMedia.value    = output.media || [];

    lastGeneration.value = {
        from_cache: true,
        usage: data.usage ?? {
            tokens_prompt:     data.tokens_prompt ?? 0,
            tokens_completion: data.tokens_completion ?? 0,
            model:             data.model_used ?? data.ai_model ?? '',
        },
    };
    
    // Restore Form configuration — backend returns "prompt_attributes" not "input_payload"
    const attrs = data.prompt_attributes ?? data.input_payload ?? {};
    if (data.preset_id) {
        const preset = props.presets.find(p => p.id === data.preset_id);
        if (preset) selectedPreset.value = preset;
    }
    if (attrs.provider_key) form.value.provider_key = attrs.provider_key;
    if (attrs.model) form.value.model = attrs.model;
    if (attrs.image_urls) form.value.image_urls = [...attrs.image_urls];
    
    form.value.variant_count = attrs.variant_count || 3;
    if (attrs.tone) form.value.tone = attrs.tone;
    if (attrs.goal) form.value.goal = attrs.goal;
    if (attrs.audience) form.value.audience = attrs.audience;
    if (attrs.product_title) form.value.product_title = attrs.product_title;
    if (attrs.product_price) form.value.product_price = attrs.product_price;
    if (attrs.usp) form.value.usp = attrs.usp;
    if (attrs.offers) form.value.offers = attrs.offers;
    if (attrs.expiration) form.value.expiration = attrs.expiration;
    if (attrs.policy) form.value.policy = attrs.policy;
    if (attrs.custom_prompt) form.value.custom_prompt = attrs.custom_prompt;
    if (attrs.duration) form.value.video_duration = Number(attrs.duration);
    
    form.value.safety_no_absolute = !!attrs.safety_no_absolute;
    form.value.safety_no_medical = !!attrs.safety_no_medical;
    form.value.safety_no_sensitive = !!attrs.safety_no_sensitive;
    applyPresetOutputDefaults();

    errorMsg.value = '';
    centerViewMode.value = 'editor';
    toast.success('Đã tải lại preset và nội dung.');
    
    // End restoration after all state updates are done (tick later)
    setTimeout(() => { isRestoring.value = false; }, 50);
}

function openEditorView() {
    centerViewMode.value = 'editor';
}

function copyHistoryVariantWithLink(text) {
    if (!text) return;
    if (!selectedLink.value) return;
    const trackingUrl = selectedLink.value.track_url || selectedLink.value.tracking_url || '';
    if (!trackingUrl) {
        toast.error('Tracking link không tồn tại hoặc bị lỗi.');
        return;
    }

    const combinedText = `${text}\n👉 Đặt mua ngay tại đây: ${trackingUrl}`;
    copyText(combinedText, 'Đã copy nội dung lịch sử + link tracking.');
}

function copyText(text, successMsg = 'Đã copy') {
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        toast.success(successMsg);
    }).catch(() => toast.error('Không thể copy'));
}

function copyVariantWithLink(text, index) {
    if (!selectedLink.value) return;
    const trackingUrl = selectedLink.value.track_url || selectedLink.value.tracking_url || '';
    if (!trackingUrl) {
        toast.error('Tracking link không tồn tại hoặc bị lỗi.');
        return;
    }
    
    // Nối link vào text: "\n👉 Mua ngay tại: [link]"
    const isTikTokLike = ['tiktok_caption', 'ugc_review_video'].includes(selectedPreset.value?.id || '');
    const separator = isTikTokLike ? '\n🛒 Xem giỏ hàng/thêm vào giỏ:' : '\n👉 Đặt mua ngay tại đây:';
    const combinedText = `${text}\n${separator} ${trackingUrl}`;
    
    navigator.clipboard.writeText(combinedText).then(() => {
        toast.success('Đã copy nội dung + link tracking.');
    }).catch(() => toast.error('Không thể copy'));
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
        shopee:   'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300',
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

function fmtCost(value) {
    const number = Number(value || 0);
    return number.toLocaleString('en-US', { maximumFractionDigits: 6 });
}
</script>
