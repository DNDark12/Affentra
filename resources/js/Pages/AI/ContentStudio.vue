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
                                v-for="preset in presets.filter(p => p.type !== 'image')"
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
                        <div class="af-surface p-5 flex flex-col gap-4 relative">
                            <!-- Header -->
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Tuỳ chọn nội dung</h3>
                            </div>

                            <!-- Basic Form Grid -->
                            <div class="grid grid-cols-2 gap-4 mt-1">
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

                                <!-- AI Media Toggle -->
                                <div v-if="selectedProviderCapabilities.image || selectedProviderCapabilities.video" class="flex flex-col gap-1.5">
                                    <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Tạo media AI</label>
                                    <div class="flex flex-col gap-1.5 h-auto px-3 py-2 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                        <label v-if="selectedProviderCapabilities.image" class="flex items-center gap-2 cursor-pointer group">
                                            <input type="checkbox" v-model="form.generate_image" class="w-3.5 h-3.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-600 dark:border-zinc-700 dark:bg-zinc-800 dark:checked:bg-indigo-500" />
                                            <span class="text-xs text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 group-hover:dark:text-zinc-200">🖼️ Tạo ảnh minh hoạ</span>
                                        </label>
                                        <label v-if="selectedProviderCapabilities.video" class="flex items-center gap-2 cursor-pointer group">
                                            <input type="checkbox" v-model="form.generate_video" class="w-3.5 h-3.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-600 dark:border-zinc-700 dark:bg-zinc-800 dark:checked:bg-indigo-500" />
                                            <span class="text-xs text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 group-hover:dark:text-zinc-200">🎬 Tạo video</span>
                                        </label>
                                    </div>
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

                                <!-- Product Title -->
                                <div class="flex flex-col gap-1.5 col-span-2 sm:col-span-1">
                                    <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Tên sản phẩm *</label>
                                    <input v-model="form.product_title" type="text" class="af-input h-9 text-sm" placeholder="VD: Son môi Dior 999" />
                                </div>
                                
                                <!-- Product Price -->
                                <div class="flex flex-col gap-1.5 col-span-2 sm:col-span-1">
                                    <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Giá sản phẩm</label>
                                    <input v-model="form.product_price" type="text" class="af-input h-9 text-sm" placeholder="VD: 450.000đ" />
                                </div>
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
                                        <input v-model="form.usp" type="text" class="af-input h-9 text-sm" placeholder="VD: Chất son lỳ, lâu trôi 24h, không bám cốc" />
                                    </div>
                                    <div class="flex flex-col gap-1.5 col-span-2 sm:col-span-1">
                                        <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Ưu đãi / Flash Sale</label>
                                        <input v-model="form.offers" type="text" class="af-input h-9 text-sm" placeholder="VD: Mua 1 tặng 1, Free ship đơn từ 50k" />
                                    </div>
                                    <div class="flex flex-col gap-1.5 col-span-2 sm:col-span-1">
                                        <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Hạn dùng ưu đãi</label>
                                        <input v-model="form.expiration" type="text" class="af-input h-9 text-sm" placeholder="VD: Chỉ còn 2 ngày, Duy nhất dịp 11/11" />
                                    </div>
                                    <div class="flex flex-col gap-1.5 col-span-2">
                                        <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Lưu ý / Chính sách</label>
                                        <input v-model="form.policy" type="text" class="af-input h-9 text-sm" placeholder="VD: Bảo hành 12 tháng, Đổi trả 7 ngày" />
                                    </div>
                                </div>

                                <!-- Custom prompt -->
                                <div class="flex flex-col gap-1.5">
                                    <div class="flex items-center justify-between">
                                        <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Prompt tuỳ chỉnh (ghi đè)</label>
                                    </div>
                                    <textarea
                                        v-model="form.custom_prompt"
                                        class="af-input text-sm resize-y leading-relaxed min-h-[80px]"
                                        rows="3"
                                        placeholder="Nhập prompt tuỳ chỉnh... Nếu để trống, hệ thống dùng template mặc định."
                                    ></textarea>
                                </div>
                                
                                <!-- Safety constraints -->
                                <div class="flex flex-col gap-2 p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg border border-zinc-100 dark:border-zinc-800">
                                    <h4 class="text-[11px] font-semibold text-zinc-500 uppercase">Ràng buộc an toàn</h4>
                                    <label class="flex items-center gap-2 cursor-pointer group w-fit">
                                        <input type="checkbox" v-model="form.safety_no_absolute" class="w-3.5 h-3.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-600 dark:border-zinc-700 dark:bg-zinc-800 dark:checked:bg-indigo-500" />
                                        <span class="text-xs text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 group-hover:dark:text-zinc-200">Không dùng cam kết tuyệt đối (nhất, 100%, trị dứt điểm)</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer group w-fit">
                                        <input type="checkbox" v-model="form.safety_no_medical" class="w-3.5 h-3.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-600 dark:border-zinc-700 dark:bg-zinc-800 dark:checked:bg-indigo-500" />
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
                                        type="range" min="1" max="5" step="1"
                                        class="flex-1 h-1.5 rounded accent-indigo-500 cursor-pointer"
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
                                    <div class="af-btn-outline h-9 px-3 flex items-center gap-1.5" :class="{'opacity-50 pointer-events-none': isUploadingImage}">
                                        <Loader2 v-if="isUploadingImage" :size="13" class="animate-spin" />
                                        <Upload v-else :size="13" />
                                        <span class="text-xs font-medium">{{ isUploadingImage ? 'Đang tải...' : 'Upload ảnh' }}</span>
                                    </div>
                                    <input type="file" accept="image/*" class="hidden" @change="handleImageUpload" :disabled="isUploadingImage" />
                                </label>
                                <span class="text-[10px] text-zinc-400 font-medium tracking-wide uppercase">hoặc</span>
                                <input
                                    v-model="imageUrlInput"
                                    type="url"
                                    class="af-input h-9 text-sm flex-1 min-w-0"
                                    placeholder="Paste URL ảnh sản phẩm..."
                                    @keydown.enter="addImageUrl"
                                />
                                <button @click="addImageUrl" class="af-btn-outline h-9 px-3 text-xs font-medium flex items-center gap-1 shrink-0">
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
                                        class="absolute top-1 right-1 w-5 h-5 rounded-full bg-zinc-900/70 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
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

                        <!-- ── Output section ── -->
                        <div v-if="outputVariants.length > 0 || outputMedia.length > 0" class="flex flex-col gap-4">
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
                                            @click="copyVariantWithLink(variant.text, index)"
                                            class="h-6 px-2.5 rounded-md text-[11px] font-semibold flex items-center gap-1.5 transition-colors border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-500/20"
                                            title="Copy nội dung & nối tracking link vào cuối"
                                        >
                                            <Link2 :size="10" />
                                            Copy + Link
                                        </button>
                                        <button
                                            @click="copyVariant(variant.text, index)"
                                            class="h-6 px-2 rounded-md text-[11px] font-semibold flex items-center gap-1 transition-colors"
                                            :class="copiedIndex === index
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400'
                                                : 'bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-600'"
                                            title="Chỉ copy nội dung"
                                        >
                                            <Check v-if="copiedIndex === index" :size="10" />
                                            <Copy v-else :size="10" />
                                            {{ copiedIndex === index ? 'Copied!' : 'Copy' }}
                                        </button>
                                    </div>
                                </div>
                                <div v-if="variant.text" class="px-4 py-3">
                                    <pre class="text-sm text-zinc-800 dark:text-zinc-200 whitespace-pre-wrap leading-relaxed font-sans">{{ variant.text }}</pre>
                                </div>
                            </div>

                            <!-- Media Display (Images/Videos) -->
                            <div v-if="outputMedia.length > 0" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <template v-for="(item, idx) in outputMedia" :key="idx">
                                    <div class="group relative bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden shadow-sm hover:shadow-md transition-all duration-300">
                                        <div class="aspect-square bg-zinc-100 dark:bg-zinc-900 flex items-center justify-center overflow-hidden">
                                            <img v-if="item.url || item.base64" 
                                                 :src="item.url || `data:image/png;base64,${item.base64}`" 
                                                 class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105" 
                                                 alt="Generated AI" />
                                            <div v-else class="text-zinc-400">Không có ảnh</div>
                                        </div>
                                        <div class="p-3 border-t border-zinc-100 dark:border-zinc-700 flex items-center justify-between">
                                            <span class="text-[10px] text-zinc-400 uppercase tracking-wider font-bold">Generated AI Image #{{ idx + 1 }}</span>
                                            <a v-if="item.url" :href="item.url" target="_blank" class="text-indigo-600 dark:text-indigo-400 text-[10px] font-bold hover:underline">Download</a>
                                        </div>
                                    </div>
                                </template>
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
import { ref, computed, watch, onMounted } from 'vue';
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

const toast = useToast();

// ── State ────────────────────────────────────────────────────────────────────
const linkSearch     = ref('');
const selectedLink   = ref(null);
const selectedPreset = ref(props.presets[0] ?? null);

const history        = ref([]);
const historyLoading = ref(false);
const isAdvancedOpen = ref(false);

const generating     = ref(false);
const outputVariants = ref([]);
const outputMedia    = ref([]);
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
    generate_image: false,
    generate_video: false,
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

const selectedProviderCapabilities = computed(() => {
    const p = props.configuredProviders.find(p => p.key === form.value.provider_key);
    return p?.capabilities ?? {};
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

watch(form, () => {
    idempotencyKey = crypto.randomUUID();
}, { deep: true });

onMounted(() => {
    const urlParams = new URLSearchParams(window.location.search);
    const linkId = urlParams.get('link_id');
    
    if (linkId && props.trackingLinks.length > 0) {
        const link = props.trackingLinks.find(l => String(l.id) === String(linkId));
        if (link) {
            selectLink(link);
        }
    }
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
        const res = await axios.get(route('api.content.history', { trackingLink: selectedLink.value.id }));
        if (res.data?.ok) {
            const items = res.data.data?.data ?? res.data.data ?? [];
            history.value = items.map(item => ({
                ...item,
                platform: item.preset_id?.includes('fb') ? 'facebook' : (item.preset_id?.includes('tiktok') ? 'tiktok' : 'generic'),
                preview: item.preview_text || '',
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
    if (form.value.usp) options.usp = form.value.usp;
    if (form.value.offers) options.offers = form.value.offers;
    if (form.value.expiration) options.expiration = form.value.expiration;
    if (form.value.policy) options.policy = form.value.policy;
    if (form.value.custom_prompt) options.custom_prompt = form.value.custom_prompt;

    options.safety_no_absolute = form.value.safety_no_absolute;
    options.safety_no_medical = form.value.safety_no_medical;
    options.safety_no_sensitive = form.value.safety_no_sensitive;

    const payload = {
        preset_id:      selectedPreset.value.id,
        variant_count:  form.value.variant_count,
        options,
        force_new_seed: forceNewSeed,
    };

    // Optional overrides
    if (form.value.provider_key) payload.provider_key  = form.value.provider_key;
    if (form.value.model)        payload.model          = form.value.model;
    if (form.value.image_urls.length) payload.image_urls = form.value.image_urls;
    if (form.value.generate_image) payload.generate_image = true;
    if (form.value.generate_video) payload.generate_video = true;

    try {
        const res = await axios.post(
            route('api.content.generate', { trackingLink: selectedLink.value.id }),
            payload,
            { headers: { 'Idempotency-Key': idempotencyKey } }
        );

        const data = res.data?.data ?? res.data;
        outputVariants.value = data?.output?.variants ?? [];
        outputMedia.value    = data?.output?.media ?? [];
        
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
    if (!item?.id) return;
    isRestoring.value = true;
    toast.success('Đang khôi phục...');
    axios.get(route('api.content-generations.show', { id: item.id }))
        .then(res => {
            if (res.data?.ok) {
                const data = res.data.data;
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
                
                form.value.safety_no_absolute = !!attrs.safety_no_absolute;
                form.value.safety_no_medical = !!attrs.safety_no_medical;
                form.value.safety_no_sensitive = !!attrs.safety_no_sensitive;

                errorMsg.value = '';
                toast.success('Đã tải lại preset và nội dung từ lịch sử.');
                
                // End restoration after all state updates are done (tick later)
                setTimeout(() => { isRestoring.value = false; }, 50);
            }
        })
        .catch(e => {
            isRestoring.value = false;
            toast.error('Không thể tải chi tiết lịch sử này.');
        });
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
    const separator = selectedPreset.value?.id === 'tiktok_caption_v1' ? '\n🛒 Xem giỏ hàng/thêm vào giỏ:' : '\n👉 Đặt mua ngay tại đây:';
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
