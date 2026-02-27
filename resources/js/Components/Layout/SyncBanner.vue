<template>
    <!-- SyncBanner: 4 trạng thái sync (fresh/delayed/failed/manual) -->
    <Teleport to="body">
        <div v-if="visible && status !== 'fresh'"
             class="fixed top-14 left-0 right-0 z-40 flex justify-center px-4 py-2"
             style="pointer-events: none">
            <div class="rounded-lg px-4 py-2 flex items-center gap-3 shadow-sm"
                 style="pointer-events: auto; min-width: 300px"
                 :style="bannerStyle">
                <!-- Icon -->
                <component :is="bannerIcon" :size="15" :style="{ color: bannerColor }" />
                <!-- Text -->
                <span class="text-xs font-medium flex-1" :style="{ color: bannerColor }">
                    {{ bannerText }}
                </span>
                <!-- Action button -->
                <button v-if="status === 'delayed' || status === 'manual'"
                        @click="$emit('sync')"
                        class="text-xs font-semibold px-3 py-1 rounded-md"
                        :style="actionStyle">
                    {{ status === 'manual' ? 'Sync Now' : 'Sync' }}
                </button>
                <button v-if="status === 'failed'"
                        @click="$emit('retry')"
                        class="text-xs font-semibold px-3 py-1 rounded-md border"
                        style="border-color: #EF4444; color: #EF4444">
                    Retry
                </button>
                <!-- Dismiss -->
                <button @click="visible = false" class="ml-1">
                    <X :size="13" :style="{ color: bannerColor, opacity: 0.6 }" />
                </button>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, computed } from 'vue';
import { CheckCircle2, Clock, AlertCircle, CirclePause, X } from 'lucide-vue-next';

const props = defineProps({
    status:      { type: String, default: 'fresh' },   // fresh | delayed | failed | manual
    lastSyncAt:  { type: String, default: null },
    nextSyncIn:  { type: String, default: null },
});

defineEmits(['sync', 'retry']);

const visible = ref(true);

const bannerIcon = computed(() => ({
    fresh:   CheckCircle2,
    delayed: Clock,
    failed:  AlertCircle,
    manual:  CirclePause,
}[props.status] ?? Clock));

const bannerColor = computed(() => ({
    fresh:   '#065F46',
    delayed: '#92400E',
    failed:  '#9F1239',
    manual:  'var(--color-primary-500)',
}[props.status]));

const bannerStyle = computed(() => ({
    fresh:   { background: '#ECFDF5', border: '1px solid #BBF7D0' },
    delayed: { background: '#FFFBEB', border: '1px solid #FDE68A' },
    failed:  { background: '#FFF1F2', border: '1px solid #FECDD3' },
    manual:  { background: 'var(--surface-2)', border: '1px solid var(--border)' },
}[props.status]));

const actionStyle = computed(() => ({
    fresh:   {},
    delayed: { background: '#F59E0B', color: '#fff' },
    failed:  {},
    manual:  { background: 'var(--color-primary-500)', color: '#fff' },
}[props.status]));

const bannerText = computed(() => ({
    fresh:   `Synced · ${props.lastSyncAt ?? 'just now'}`,
    delayed: `Data delayed · ${props.nextSyncIn ?? 'sync recommended'}`,
    failed:  'Sync failed · Platform connection error',
    manual:  'Manual sync mode · auto-sync disabled',
}[props.status]));
</script>
