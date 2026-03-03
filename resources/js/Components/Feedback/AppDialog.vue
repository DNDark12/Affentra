<template>
    <transition
        enter-active-class="transition-opacity duration-200"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div v-if="dialog.isOpen" class="fixed inset-0 z-[150]">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-[1px]" @click="cancelDialog"></div>
            <div class="absolute inset-0 flex items-center justify-center p-4">
                <div class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-start gap-3 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
                        <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl" :class="badgeClass(dialog.variant)">
                            <component :is="iconComponent(dialog.variant)" :size="18" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[15px] font-semibold text-zinc-900 dark:text-zinc-100">{{ dialog.title }}</p>
                            <p v-if="dialog.description" class="mt-1 text-[13px] leading-5 text-zinc-500 dark:text-zinc-400">
                                {{ dialog.description }}
                            </p>
                        </div>
                    </div>

                    <div v-if="dialog.mode === 'prompt'" class="px-5 py-4">
                        <label class="mb-2 block text-[13px] font-medium text-zinc-700 dark:text-zinc-200">
                            {{ dialog.inputLabel || 'Nội dung' }}
                        </label>
                        <textarea
                            v-model="dialog.inputValue"
                            rows="3"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                            :placeholder="dialog.inputPlaceholder || ''"
                        ></textarea>
                        <p v-if="dialog.inputError" class="mt-2 text-xs text-rose-500">{{ dialog.inputError }}</p>
                    </div>

                    <div class="flex items-center justify-end gap-2 px-5 py-4">
                        <button
                            type="button"
                            class="h-9 rounded-lg border border-zinc-200 px-4 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                            @click="cancelDialog"
                        >
                            {{ dialog.cancelText }}
                        </button>
                        <button
                            type="button"
                            class="h-9 rounded-lg px-4 text-sm font-semibold text-white transition"
                            :class="confirmButtonClass(dialog.variant)"
                            @click="submitDialog"
                        >
                            {{ dialog.confirmText }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </transition>
</template>

<script setup>
import { OctagonAlert, AlertTriangle, CheckCircle, HelpCircle } from 'lucide-vue-next';
import { useDialog } from '@/Composables/useDialog';

const { dialog, submitDialog, cancelDialog } = useDialog();

function badgeClass(variant) {
    if (variant === 'danger') return 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200';
    if (variant === 'warning') return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200';
    if (variant === 'success') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200';
    return 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-200';
}

function iconComponent(variant) {
    if (variant === 'danger')  return OctagonAlert;
    if (variant === 'warning') return AlertTriangle;
    if (variant === 'success') return CheckCircle;
    return HelpCircle;
}

function confirmButtonClass(variant) {
    if (variant === 'danger') return 'bg-rose-600 hover:bg-rose-700';
    if (variant === 'warning') return 'bg-amber-600 hover:bg-amber-700';
    if (variant === 'success') return 'bg-emerald-600 hover:bg-emerald-700';
    return 'bg-indigo-600 hover:bg-indigo-700';
}
</script>
