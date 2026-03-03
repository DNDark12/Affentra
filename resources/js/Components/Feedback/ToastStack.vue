<template>
    <div class="fixed right-4 top-4 z-[140] flex w-[min(92vw,400px)] flex-col gap-2 pointer-events-none">
        <transition-group name="toast-slide">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto rounded-xl border px-4 py-3 shadow-lg backdrop-blur-sm"
                :class="toastClass(toast.type)"
            >
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 shrink-0" :class="iconClass(toast.type)">
                        <component :is="iconComponent(toast.type)" :size="16" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p v-if="toast.title" class="text-[13px] font-semibold leading-5">{{ toast.title }}</p>
                        <p class="text-[13px] leading-5 break-words">{{ toast.message }}</p>
                        <button
                            v-if="toast.actionLabel"
                            type="button"
                            class="mt-2 text-xs font-semibold underline underline-offset-2"
                            @click="handleAction(toast)"
                        >
                            {{ toast.actionLabel }}
                        </button>
                    </div>
                    <button
                        type="button"
                        class="rounded-md p-1 opacity-70 transition hover:opacity-100"
                        @click="dismiss(toast.id)"
                        aria-label="Đóng thông báo"
                    >
                        <X :size="12" />
                    </button>
                </div>
            </div>
        </transition-group>
    </div>
</template>

<script setup>
import { X, CheckCircle, AlertTriangle, OctagonAlert, Info } from 'lucide-vue-next';
import { useToast } from '@/Composables/useToast';

const { toasts, dismiss } = useToast();

function toastClass(type) {
    if (type === 'success') return 'border-emerald-200 bg-emerald-50/95 text-emerald-800 dark:border-emerald-500/35 dark:bg-emerald-500/15 dark:text-emerald-200';
    if (type === 'warning') return 'border-amber-200 bg-amber-50/95 text-amber-900 dark:border-amber-500/35 dark:bg-amber-500/15 dark:text-amber-200';
    if (type === 'error') return 'border-rose-200 bg-rose-50/95 text-rose-800 dark:border-rose-500/35 dark:bg-rose-500/15 dark:text-rose-200';
    return 'border-indigo-200 bg-indigo-50/95 text-indigo-800 dark:border-indigo-500/35 dark:bg-indigo-500/15 dark:text-indigo-200';
}

function iconClass(type) {
    if (type === 'success') return 'text-emerald-600 dark:text-emerald-300';
    if (type === 'warning') return 'text-amber-600 dark:text-amber-300';
    if (type === 'error') return 'text-rose-600 dark:text-rose-300';
    return 'text-indigo-600 dark:text-indigo-300';
}

function iconComponent(type) {
    if (type === 'success') return CheckCircle;
    if (type === 'warning') return AlertTriangle;
    if (type === 'error') return OctagonAlert;
    return Info;
}

function handleAction(toast) {
    if (typeof toast.onAction === 'function') {
        toast.onAction();
    }
    dismiss(toast.id);
}
</script>

<style scoped>
.toast-slide-enter-active,
.toast-slide-leave-active {
    transition: all 0.22s ease;
}

.toast-slide-enter-from,
.toast-slide-leave-to {
    opacity: 0;
    transform: translateY(-10px) translateX(8px);
}
</style>
