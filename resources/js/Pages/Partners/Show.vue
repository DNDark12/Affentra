<template>
    <AppShell>
        <div class="flex flex-col gap-4" style="padding: 16px 24px;">
            <!-- Header Section -->
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs mb-0.5" style="color: var(--text-muted)">Trang / Đối tác / Partner / Chi tiết</p>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-bold" style="color: var(--text-primary)">{{ partner.name }}</h1>
                        <span class="text-xs px-2 py-0.5 rounded-full" :style="statusPillStyle(partner.status)">
                            {{ partnerStatusLabel(partner.status) }}
                        </span>
                        
                        <span class="text-xs px-2 py-0.5 rounded-full border bg-[var(--surface-2)] shadow-sm">
                            <span class="font-medium text-[var(--text-muted)] tracking-wide uppercase text-[10px]">Review:</span>
                            {{ reviewStatusLabel(partner.payout_review_status) }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2 mt-2">
                        <p class="text-sm font-medium mr-2" style="color: var(--text-secondary)">{{ partner.email }}</p>
                        
                        <!-- Connections Badges -->
                        <template v-if="connectionsData">
                            <span v-for="conn in connectionsData" :key="conn.id" title="Connection Sync Status"
                                  class="text-xs px-2 py-0.5 rounded-full border border-[var(--border)] bg-[var(--surface)] flex items-center gap-1.5 shadow-sm">
                                <span class="capitalize font-medium">{{ conn.label }}</span>
                                <span :class="[
                                    'w-2 h-2 rounded-full',
                                    conn.sync_health === 'healthy' ? 'bg-emerald-500' :
                                    conn.sync_health === 'inactive' ? 'bg-gray-400' :
                                    conn.sync_health === 'warning' ? 'bg-amber-500 shadow-[0_0_8px_rgba(245,158,11,0.6)] animate-pulse' :
                                    'bg-rose-500 shadow-[0_0_8px_rgba(225,29,72,0.6)] animate-pulse'
                                ]"></span>
                            </span>
                        </template>
                        <span v-else-if="!isLoadingConnections" class="text-xs italic" style="color: var(--text-muted)">Chưa kết nối platform nào</span>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <select v-model="daysFilter" @change="fetchAnalyticsData" class="af-input text-sm h-9 px-3 rounded-lg border border-[var(--border)] bg-[var(--surface)] text-[var(--text-primary)]">
                        <option :value="7">7 ngày qua</option>
                        <option :value="30">30 ngày qua</option>
                        <option :value="90">90 ngày qua</option>
                        <option :value="null">Toàn thời gian</option>
                    </select>
                    <button class="af-btn-outline text-sm h-9 px-3 flex items-center gap-1.5" @click="backToList">
                        <ArrowLeft :size="14" />
                        Quay lại
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                <div class="af-surface p-4 rounded-xl border border-[var(--border)] flex flex-col justify-between hover:border-[var(--color-primary-300)] transition-colors">
                    <p class="text-[11px] font-medium uppercase tracking-wider tooltip-trigger" style="color: var(--text-secondary)" title="Tổng lượt click link">Total Clicks</p>
                    <p class="text-xl font-bold mt-1" style="color: var(--text-primary)">
                        <span v-if="isLoadingSummary" class="animate-pulse">...</span>
                        <span v-else>{{ fmtNum(summaryTotals.clicks) }}</span>
                    </p>
                    <div v-if="!isLoadingSummary" class="mt-2 flex flex-wrap gap-1">
                        <span v-for="p in summaryPlatforms" :key="p.platform" class="text-[9px] px-1.5 py-0.5 bg-[var(--surface-2)] text-[var(--text-secondary)] rounded shadow-sm border border-[var(--border)]">{{ p.platform }}: {{ fmtNum(p.clicks) }}</span>
                    </div>
                </div>

                <div class="af-surface p-4 rounded-xl border border-[var(--border)] flex flex-col justify-between hover:border-[var(--color-primary-300)] transition-colors">
                    <p class="text-[11px] font-medium uppercase tracking-wider tooltip-trigger" style="color: var(--text-secondary)" title="Tổng lượt tạo đơn (tất cả trạng thái)">Orders</p>
                    <p class="text-xl font-bold mt-1" style="color: var(--text-primary)">
                        <span v-if="isLoadingSummary" class="animate-pulse">...</span>
                        <span v-else>{{ fmtNum(summaryTotals.orders) }}</span>
                    </p>
                    <div v-if="!isLoadingSummary" class="mt-2 flex flex-wrap gap-1">
                        <span v-for="p in summaryPlatforms" :key="p.platform" class="text-[9px] px-1.5 py-0.5 bg-[var(--surface-2)] text-[var(--text-secondary)] rounded shadow-sm border border-[var(--border)]">{{ p.platform }}: {{ fmtNum(p.orders) }}</span>
                    </div>
                </div>

                <div class="af-surface p-4 rounded-xl border border-[var(--border)] flex flex-col justify-between hover:border-[var(--color-primary-300)] transition-colors">
                    <p class="text-[11px] font-medium uppercase tracking-wider tooltip-trigger" style="color: var(--text-secondary)" title="Tỷ lệ chuyển đổi Order/Click">CR %</p>
                    <p class="text-xl font-bold mt-1" style="color: var(--text-primary)">
                        <span v-if="isLoadingSummary" class="animate-pulse">...</span>
                        <span v-else>{{ summaryTotals.clicks > 0 ? (summaryTotals.conversion_rate * 100).toFixed(2) + '%' : '—' }}</span>
                    </p>
                    <div v-if="!isLoadingSummary" class="mt-2 flex flex-wrap gap-1">
                        <span v-for="p in summaryPlatforms" :key="p.platform" class="text-[9px] px-1.5 py-0.5 bg-[var(--surface-2)] text-[var(--text-secondary)] rounded shadow-sm border border-[var(--border)]" :title="'CR: ' + (p.conversion_rate * 100).toFixed(1) + '%'">
                            {{ p.platform }}: {{ p.clicks > 0 ? (p.conversion_rate * 100).toFixed(1) + '%' : '—' }}
                        </span>
                    </div>
                </div>

                <div class="af-surface p-4 rounded-xl border border-[var(--border)] flex flex-col justify-between hover:border-[var(--color-primary-300)] transition-colors">
                    <p class="text-[11px] font-medium uppercase tracking-wider tooltip-trigger" style="color: var(--text-secondary)" title="Đơn đã duyệt thành công">Approved</p>
                    <p class="text-xl font-bold mt-1 text-emerald-600 dark:text-emerald-500">
                        <span v-if="isLoadingSummary" class="animate-pulse">...</span>
                        <span v-else>{{ fmtNum(summaryTotals.approved_orders) }} <span class="text-xs font-normal text-emerald-700/60 ml-1">({{ (summaryTotals.approved_rate * 100).toFixed(1) }}%)</span></span>
                    </p>
                    <div v-if="!isLoadingSummary" class="mt-2 flex flex-wrap gap-1">
                        <span v-for="p in summaryPlatforms" :key="p.platform" class="text-[9px] px-1.5 py-0.5 bg-[var(--surface-2)] text-[var(--text-secondary)] rounded shadow-sm border border-[var(--border)]">{{ p.platform }}: {{ fmtNum(p.approved_orders) }}</span>
                    </div>
                </div>

                <div class="af-surface p-4 rounded-xl border border-[var(--border)] flex flex-col justify-between hover:border-[var(--color-primary-300)] transition-colors">
                    <p class="text-[11px] font-medium uppercase tracking-wider tooltip-trigger" style="color: var(--text-secondary)" title="Tổng hoa hồng (đã duyệt)">Total Commission</p>
                    <p class="text-xl font-bold mt-1" style="color: var(--color-primary-600)">
                        <span v-if="isLoadingSummary" class="animate-pulse">...</span>
                        <span v-else>{{ fmtMoney(summaryTotals.commission) }}</span>
                    </p>
                    <div v-if="!isLoadingSummary" class="mt-2 flex flex-wrap gap-1">
                        <span v-for="p in summaryPlatforms" :key="p.platform" class="text-[9px] px-1.5 py-0.5 bg-[var(--surface-2)] text-[var(--text-secondary)] rounded shadow-sm border border-[var(--border)] whitespace-nowrap overflow-hidden text-ellipsis">{{ p.platform }}: {{ fmtMoney(p.commission) }}</span>
                    </div>
                </div>

                <div class="af-surface p-4 rounded-xl border border-rose-500/20 bg-rose-50/30 dark:bg-rose-950/20 flex flex-col justify-between">
                    <p class="text-[11px] font-medium uppercase tracking-wider tooltip-trigger text-rose-800 dark:text-rose-400" title="Tỷ lệ đơn bị từ chối/hủy">Rejected %</p>
                    <p class="text-xl font-bold mt-1" :class="(summaryTotals.rejected_rate || 0) > 0.2 ? 'text-rose-600 font-extrabold' : 'text-rose-700 dark:text-rose-300'">
                        <span v-if="isLoadingSummary" class="animate-pulse">...</span>
                        <span v-else>{{ (summaryTotals.rejected_rate * 100).toFixed(1) }}%</span>
                    </p>
                </div>
            </div>

            <!-- Fraud Alert -->
            <div v-if="!isLoadingSummary && (summaryTotals.rejected_rate > 0.3)" class="flex items-center gap-2 p-3 rounded-lg border bg-rose-50 border-rose-500/30 dark:bg-rose-950/20 mt-1">
                <AlertTriangle :size="16" class="text-rose-600 shrink-0" />
                <span class="text-[13px] text-rose-900 dark:text-rose-200">
                    <strong>Tỷ lệ từ chối cao bất thường ({{ (summaryTotals.rejected_rate * 100).toFixed(1) }}%).</strong> 
                    Gợi ý: Cần rà soát các đơn hàng gần đây từ Partner này để phòng chống gian lận.
                </span>
                <button class="ml-auto bg-rose-600 hover:bg-rose-700 text-white text-xs font-medium px-4 py-1.5 rounded-md transition-colors" @click="activeTab = 'orders'">Kiểm tra Đơn Hàng</button>
            </div>

            <!-- Chart -->
            <div class="af-surface p-4 rounded-xl border border-[var(--border)]">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary)">
                        Biểu đồ Hoa Hồng 
                        <span class="text-xs font-normal text-[var(--text-muted)] ml-1">({{ daysFilter ? daysFilter + ' ngày qua' : 'Toàn thời gian' }})</span>
                    </h2>
                    <div class="flex items-center gap-2">
                         <span v-if="isLoadingTrend" class="text-xs text-[var(--text-muted)] animate-pulse">Đang tải data...</span>
                    </div>
                </div>
                <!-- Custom styling for ApexCharts to support light/dark -->
                <div class="w-full relative min-h-[300px]">
                    <div v-if="isLoadingTrend" class="absolute inset-0 flex items-center justify-center bg-[var(--surface)]/50 backdrop-blur-sm z-10 transition-opacity">
                        <div class="w-6 h-6 border-2 border-[var(--color-primary-500)] border-t-transparent rounded-full animate-spin"></div>
                    </div>
                    <ApexCharts 
                        v-if="chartSeries.length > 0" 
                        type="area" 
                        height="300" 
                        :options="chartOptions" 
                        :series="chartSeries" 
                    />
                    <div v-else-if="!isLoadingTrend" class="absolute inset-0 flex flex-col items-center justify-center text-sm border-2 border-dashed border-[var(--border)] rounded-lg">
                        <p style="color: var(--text-muted)" class="font-medium">Chưa có dữ liệu thống kê cho bộ lọc này</p>
                        <p class="text-xs mt-1 opacity-70" style="color: var(--text-muted)">Partner chưa phát sinh đơn hàng hoặc chưa đồng bộ.</p>
                    </div>
                </div>
            </div>

            <!-- Content Tabs Navigation -->
            <div class="mt-2">
                <div class="flex items-center gap-2 md:gap-6 border-b border-[var(--border)] overflow-x-auto pb-px">
                    <button v-for="tab in tabs" :key="tab.id" @click="activeTab = tab.id"
                        class="text-[13px] md:text-sm font-semibold py-3 px-1 relative whitespace-nowrap transition-colors"
                        :class="activeTab === tab.id ? 'text-[var(--color-primary-600)] dark:text-[var(--color-primary-400)]' : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'">
                        {{ tab.label }}
                        <div v-if="activeTab === tab.id" class="absolute left-0 right-0 bottom-0 h-0.5 bg-[var(--color-primary-500)] rounded-t"></div>
                    </button>
                </div>

                <!-- Tab: Overview -->
                <div v-show="activeTab === 'overview'" class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="af-surface p-5 rounded-xl border border-[var(--border)]">
                        <h2 class="text-sm font-semibold mb-4 uppercase tracking-wider text-[var(--text-secondary)] border-b border-[var(--border)] pb-2">Thông tin Cá Nhân</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm mt-4">
                            <div>
                                <p class="text-xs mb-1" style="color: var(--text-muted)">Họ và tên</p>
                                <p class="font-medium" style="color: var(--text-primary)">{{ partner.name }}</p>
                            </div>
                            <div>
                                <p class="text-xs mb-1" style="color: var(--text-muted)">Địa chỉ Email</p>
                                <p class="font-medium" style="color: var(--text-primary)">{{ partner.email }}</p>
                            </div>
                            <div>
                                <p class="text-xs mb-1" style="color: var(--text-muted)">Quản lý trực tiếp (Leader)</p>
                                <p class="font-medium" style="color: var(--text-primary)">{{ partner.parent?.name || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs mb-1" style="color: var(--text-muted)">Ngày tham gia</p>
                                <p class="font-medium" style="color: var(--text-primary)">{{ fmtDateTime(partner.created_at) }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="af-surface p-5 rounded-xl border border-[var(--border)]">
                        <h2 class="text-sm font-semibold mb-4 uppercase tracking-wider text-[var(--text-secondary)] border-b border-[var(--border)] pb-2">Tài Khoản & Thanh Toán</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm mt-4">
                            <div>
                                <p class="text-xs mb-1" style="color: var(--text-muted)">Trạng thái kết nối</p>
                                <p class="font-medium" style="color: var(--text-primary)">
                                    {{ connectionsMeta.active_count }} active / {{ connectionsMeta.total_count }} total
                                </p>
                            </div>
                            <div>
                                <p class="text-xs mb-1" style="color: var(--text-muted)">Tài khoản ngân hàng</p>
                                <p v-if="partner.has_banking_info" class="font-medium font-mono text-sm" style="color: var(--text-primary)">
                                    {{ partner.masked_bank_name }} <br/>
                                    <span class="text-xs text-[var(--text-muted)]">{{ partner.masked_bank_account }}</span>
                                </p>
                                <p v-else class="text-[var(--text-muted)] italic text-sm">Chưa cập nhật</p>
                            </div>
                            <div class="md:col-span-2 mt-2 pt-3 border-t border-[var(--border)] flex justify-between items-center">
                                <span class="text-xs text-[var(--text-muted)]">Bạn có thể tạo batch thanh toán cho đối tác này tại phân hệ Tài Chính.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Tracking Links -->
                <div v-show="activeTab === 'tracking_links'" class="mt-4 af-surface rounded-xl border border-[var(--border)] overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm border-collapse whitespace-nowrap">
                            <thead>
                                <tr class="bg-[var(--surface-2)] border-b border-[var(--border)] uppercase text-[10px] tracking-wider text-[var(--text-muted)] font-semibold">
                                    <th class="text-left px-4 py-3">Platform</th>
                                    <th class="text-left px-4 py-3">Sub ID / Tracking ID</th>
                                    <th class="text-left px-4 py-3">Item ID</th>
                                    <th class="text-right px-4 py-3">Clicks</th>
                                    <th class="text-right px-4 py-3">Orders</th>
                                    <th class="text-right px-4 py-3">CR%</th>
                                    <th class="text-right px-4 py-3">Approved</th>
                                    <th class="text-right px-4 py-3">Commission (Appr.)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="trackingLinks.loading && trackingLinks.data.length === 0">
                                    <td colspan="8" class="text-center py-10" style="color: var(--text-muted)">Đang tải...</td>
                                </tr>
                                <tr v-else-if="trackingLinks.data.length === 0">
                                    <td colspan="8" class="text-center py-16">
                                        <div class="flex flex-col items-center justify-center opacity-70">
                                            <p class="font-medium text-[var(--text-muted)]">Chưa có tracking link nào</p>
                                            <p class="text-xs text-[var(--text-muted)] mt-1">Partner chưa phát sinh clicks hoặc đơn hàng thông qua Tracking Link.</p>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-for="link in trackingLinks.data" :key="link.id + link.platform" class="border-b border-[var(--border)] hover:bg-[var(--surface-2)] transition-colors">
                                    <td class="px-4 py-3 text-xs font-medium uppercase text-[var(--text-secondary)]">{{ link.platform }}</td>
                                    <td class="px-4 py-3 font-medium cursor-pointer hover:underline text-[var(--color-primary-600)]" :title="link.sub_id">{{ strLimit(link.sub_id, 25) }}</td>
                                    <td class="px-4 py-3 text-[var(--text-secondary)] text-xs font-mono">{{ link.item_id || '—' }}</td>
                                    <td class="px-4 py-3 text-right">{{ fmtNum(link.clicks) }}</td>
                                    <td class="px-4 py-3 text-right">{{ fmtNum(link.orders) }}</td>
                                    <td class="px-4 py-3 text-right">{{ link.clicks > 0 ? (link.orders / link.clicks * 100).toFixed(1) + '%' : '—' }}</td>
                                    <td class="px-4 py-3 text-right font-medium text-emerald-600">{{ fmtNum(link.approved_orders) }}</td>
                                    <td class="px-4 py-3 text-right font-medium font-mono text-[var(--color-primary-700)]">{{ fmtMoney(link.approved_commission) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="trackingLinks.hasMore" class="p-3 border-t border-[var(--border)] text-center bg-[var(--surface-2)]">
                        <button :disabled="trackingLinks.loading" @click="loadData('tracking_links', true)" class="af-btn-outline h-8 px-4 text-xs font-medium w-full md:w-auto mx-auto rounded-md bg-white dark:bg-zinc-800">
                            {{ trackingLinks.loading ? 'Đang tải...' : 'Tải thêm (Load More)' }}
                        </button>
                    </div>
                </div>

                <!-- Tab: Orders -->
                <div v-show="activeTab === 'orders'" class="mt-4 af-surface rounded-xl border border-[var(--border)] overflow-hidden">
                    <div class="px-4 py-3 border-b border-[var(--border)] bg-[var(--surface-2)] flex flex-wrap gap-2 justify-between items-center">
                        <p class="text-xs text-[var(--text-muted)] font-medium">Lưu ý: Danh sách này chịu ảnh hưởng của bộ lọc thời gian bên trên.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm border-collapse whitespace-nowrap">
                            <thead>
                                <tr class="bg-[var(--surface-2)] border-b border-[var(--border)] uppercase text-[10px] tracking-wider text-[var(--text-muted)] font-semibold">
                                    <th class="text-left px-4 py-3">Mã đơn</th>
                                    <th class="text-left px-4 py-3">Trạng thái Nền tảng</th>
                                    <th class="text-left px-4 py-3">Tình trạng Thanh toán</th>
                                    <th class="text-right px-4 py-3">Giá trị đơn</th>
                                    <th class="text-right px-4 py-3">Hoa hồng dự kiến</th>
                                    <th class="text-right px-4 py-3">Thời gian tạo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="orders.loading && orders.data.length === 0">
                                    <td colspan="6" class="text-center py-10" style="color: var(--text-muted)">Đang tải...</td>
                                </tr>
                                <tr v-else-if="orders.data.length === 0">
                                    <td colspan="6" class="text-center py-16">
                                        <div class="flex flex-col items-center justify-center opacity-70">
                                            <p class="font-medium text-[var(--text-muted)]">Chưa có đơn hàng nào</p>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-for="order in orders.data" :key="order.id" class="border-b border-[var(--border)] hover:bg-[var(--surface-2)] transition-colors">
                                    <td class="px-4 py-3 font-medium font-mono text-[var(--text-primary)] cursor-pointer hover:underline text-xs">{{ order.order_code }}</td>
                                    <td class="px-4 py-3">
                                        <span class="text-[11px] px-2 py-0.5 rounded-full font-medium" :style="orderStatusStyle(order.status)">
                                            {{ orderStatusLabel(order.status) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-[11px] px-2 py-0.5 rounded-full font-medium" :style="financeStatusStyle(order.payout_status)">
                                            {{ financeStatusLabel(order.payout_status) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium text-[var(--text-primary)]">{{ fmtMoney(order.order_amount) }}</td>
                                    <td class="px-4 py-3 text-right font-medium text-emerald-600 dark:text-emerald-500">{{ fmtMoney(order.commission) }}</td>
                                    <td class="px-4 py-3 text-right text-xs text-[var(--text-muted)]">{{ fmtDateTime(order.ordered_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="orders.hasMore" class="p-3 border-t border-[var(--border)] text-center bg-[var(--surface-2)]">
                        <button :disabled="orders.loading" @click="loadData('orders', true)" class="af-btn-outline h-8 px-4 text-xs font-medium w-full md:w-auto mx-auto rounded-md bg-white dark:bg-zinc-800">
                            {{ orders.loading ? 'Đang tải...' : 'Tải thêm (Load More) ' + '(Trang ' + (orders.page + 1) + ')' }}
                        </button>
                    </div>
                </div>

                <!-- Tab: Billings & Payouts remain structurally similar for MVP Phase 12.2 -->
                <div v-show="activeTab === 'finance'" class="mt-4 af-surface rounded-xl border border-[var(--border)] overflow-hidden p-8 text-center placeholder-state flex flex-col items-center justify-center min-h-[300px]">
                    <p class="text-[var(--text-muted)] font-medium">Bảng kê & Lịch sử rút tiền (Đang xây dựng tải trang AJAX cho tab này)</p>
                    <button class="af-btn-outline mt-3" @click="activeTab = 'overview'">Quay lại Tổng Quan</button>
                </div>

            </div>
        </div>
    </AppShell>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowLeft, AlertTriangle } from 'lucide-vue-next';
import AppShell from '@/Layouts/AppShell.vue';
import axios from 'axios';
import VueApexCharts from 'vue3-apexcharts';

const ApexCharts = VueApexCharts;

const props = defineProps({
    partner: { type: Object, required: true },
    connections_meta: { type: Object, default: () => ({ total_count: 0, active_count: 0 }) },
});

// Analytics State
const daysFilter = ref(30);
const summaryTotals = ref({ clicks: 0, orders: 0, approved_orders: 0, commission: 0, conversion_rate: 0, approved_rate: 0, rejected_rate: 0 });
const summaryPlatforms = ref([]);
const chartSeries = ref([]);

const isLoadingSummary = ref(false);
const isLoadingTrend = ref(false);

const connectionsData = ref(null);
const isLoadingConnections = ref(false);

// Tabs State & Pagination structures
const tabs = [
    { id: 'overview', label: '1. Tổng quan Profile' },
    { id: 'tracking_links', label: '2. Tracking Links (Hiệu suất)' },
    { id: 'orders', label: '3. Lịch sử Đơn hàng (Quy đổi)' },
    { id: 'finance', label: '4. Bảng kê & Payout' },
];
const activeTab = ref('overview');

const trackingLinks = ref({ data: [], page: 1, hasMore: true, loading: false });
const orders = ref({ data: [], page: 1, hasMore: true, loading: false });
// Billings & Payouts omitted from full AJAX in this snippet to keep code size manageable but structure is there.

// Fetch overview metrics
async function fetchAnalyticsData() {
    isLoadingSummary.value = true;
    isLoadingTrend.value = true;

    // Reset paginated lists so they reload properly with new date filter if active tab requires it.
    resetPagination(orders.value);
    // tracking links are generally not date-filtered in this iteration (or we could pass the days), but based on plan it's general.

    try {
        const params = {};
        if (daysFilter.value !== null) params.days = daysFilter.value;

        // 1. Summary
        axios.get(`/api/partners/${props.partner.id}/summary`, { params }).then(res => {
            summaryTotals.value = res.data.data.totals;
            summaryPlatforms.value = res.data.data.platforms;
            isLoadingSummary.value = false;
        });

        // 2. Trend
        axios.get(`/api/partners/${props.partner.id}/trend`, { params }).then(res => {
            const raw = res.data.data;
            const platforms = [...new Set(raw.map(item => item.platform))];
            
            // Re-shape for ApexCharts [ { name: 'Shopee', data: [ {x, y}, ... ] } ]
            const builtSeries = platforms.map(plat => {
                const platData = raw.filter(i => i.platform === plat);
                return {
                    name: plat.toUpperCase(),
                    data: platData.map(i => ({ x: i.date, y: parseFloat(i.commission) }))
                };
            });
            chartSeries.value = builtSeries;
            isLoadingTrend.value = false;
        });

        // Automatically reload active tab if it's data-driven
        if (['orders', 'tracking_links'].includes(activeTab.value)) {
             loadData(activeTab.value);
        }

    } catch (e) {
        console.error("Failed to fetch analytics", e);
        isLoadingSummary.value = false;
        isLoadingTrend.value = false;
    }
}

async function fetchConnections() {
    isLoadingConnections.value = true;
    try {
        const res = await axios.get(`/api/partners/${props.partner.id}/connections`);
        connectionsData.value = res.data.data;
    } catch (e) {
        console.error("Failed connections", e);
    } finally {
        isLoadingConnections.value = false;
    }
}

// Load More Pattern for Tabs
function resetPagination(stateObj) {
    stateObj.data = [];
    stateObj.page = 1;
    stateObj.hasMore = true;
}

async function loadData(tabKey, isLoadMore = false) {
    const listState = tabKey === 'tracking_links' ? trackingLinks.value :
                      tabKey === 'orders' ? orders.value : null;
                      
    if (!listState || !listState.hasMore) return;
    
    if (isLoadMore) {
        listState.page++;
    } else if (listState.data.length > 0) {
        return; // Already loaded initial, and this isn't a forced refresh.
    }
    
    listState.loading = true;
    const params = { page: listState.page, per_page: 15 };
    
    // Inject date filters for transactional tabs
    if (tabKey === 'orders') {
        if (daysFilter.value) {
            const dateFrom = new Date();
            dateFrom.setDate(dateFrom.getDate() - daysFilter.value);
            params.date_from = dateFrom.toISOString().split('T')[0];
        }
    }

    // Determine URL
    const url = tabKey === 'tracking_links' ? 'tracking-links' : tabKey;

    try {
        const res = await axios.get(`/api/partners/${props.partner.id}/${url}`, { params });
        const items = tabKey === 'tracking_links' ? res.data.data : res.data.data; // res.data for basic Laravel paginate, res.data.data if wrapped in Resource
        
        listState.data = isLoadMore ? [...listState.data, ...items] : items;
        
        // Checking hasMore. If it's a Laravel Paginator (no API Resource wrapper), meta info is at root
        const metaObj = res.data.meta || res.data; 
        listState.hasMore = listState.page < (metaObj.last_page || 1);

    } catch (e) {
        console.error(`Failed to load ${tabKey}`, e);
    } finally {
        listState.loading = false;
    }
}

watch(activeTab, (newVal) => {
    if (['orders', 'tracking_links'].includes(newVal)) {
        // Load initial data for tab if it hasn't been loaded
        loadData(newVal, false);
    }
});

onMounted(() => {
    fetchAnalyticsData();
    fetchConnections();
});

// Chart Configuration
const chartTheme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
const chartOptions = computed(() => ({
    chart: {
        type: 'area',
        fontFamily: 'inherit',
        toolbar: { show: false },
        zoom: { enabled: false },
        background: 'transparent',
    },
    theme: { mode: chartTheme },
    colors: ['#0ea5e9', '#ec4899', '#f59e0b', '#10b981'],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05, stops: [20, 100] } },
    xaxis: { type: 'datetime', axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { 
        labels: { formatter: (val) => Number(val).toLocaleString('vi-VN') + ' đ' } 
    },
    tooltip: { x: { format: 'dd MMM yyyy' } },
    legend: { position: 'top', horizontalAlign: 'right' }
}));

// Helpers
function backToList() { router.visit(route('partners.index')); }
function fmtNum(v) { return Number(v || 0).toLocaleString('vi-VN'); }
function fmtMoney(v) { return Number(v || 0).toLocaleString('vi-VN') + ' đ'; }
function fmtDateTime(v) { return v ? new Date(v).toLocaleString('vi-VN') : '—'; }
function strLimit(str, limit) { return str.length > limit ? str.substring(0, limit) + '...' : str; }

function reviewStatusLabel(status) {
    if (status === 'approved') return 'Đã duyệt hồ sơ';
    if (status === 'rejected') return 'Cảnh báo - Từ chối';
    return 'Chưa duyệt';
}

function statusPillStyle(status) {
    const key = String(status || '').toLowerCase();
    if (key === 'active') return { background: 'var(--success-bg)', color: 'var(--success-text)' };
    if (key === 'pending') return { background: 'var(--warning-bg)', color: 'var(--warning-text)' };
    if (key === 'suspended' || key === 'banned') return { background: 'var(--danger-bg)', color: 'var(--danger-text)' };
    return { background: 'var(--surface-2)', color: 'var(--text-muted)' };
}

function partnerStatusLabel(status) {
    const key = String(status || '').toLowerCase();
    if (key === 'active') return 'Hoạt động';
    if (key === 'pending') return 'Chờ duyệt';
    if (key === 'suspended') return 'Tạm ngưng';
    if (key === 'banned') return 'Đã khóa';
    return status || '—';
}

function orderStatusStyle(status) {
    const key = String(status || '').toLowerCase();
    if (['approved', 'completed', 'done', '3', '2'].includes(key)) return { background: 'var(--success-bg)', color: 'var(--success-text)' };
    if (['pending', 'processing', 'review', '0', '1'].includes(key)) return { background: 'var(--warning-bg)', color: 'var(--warning-text)' };
    if (['rejected', 'cancelled', 'canceled', 'failed', '-1', '4', '5'].includes(key)) return { background: 'var(--danger-bg)', color: 'var(--danger-text)' };
    return { background: 'var(--surface-2)', color: 'var(--text-muted)' };
}

function orderStatusLabel(status) {
   // ... existing mapper
   return status;
}

function financeStatusStyle(status) {
    const key = String(status || '').toLowerCase();
    if (['paid', 'settled', 'completed', 'success', '2', '3', '6'].includes(key)) return { background: 'var(--success-bg)', color: 'var(--success-text)' };
    if (['pending', 'processing', 'review', 'created', '0', '1'].includes(key)) return { background: 'var(--warning-bg)', color: 'var(--warning-text)' };
    if (['failed', 'rejected', 'cancelled', 'canceled', 'closed', '-1', '4', '5'].includes(key)) return { background: 'var(--danger-bg)', color: 'var(--danger-text)' };
    return { background: 'var(--surface-2)', color: 'var(--text-muted)' };
}

function financeStatusLabel(status) {
    return status;
}
</script>
