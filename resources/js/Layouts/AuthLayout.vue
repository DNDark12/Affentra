<template>
    <div class="min-h-screen flex" :class="{ dark: isDark }">
        <slot />
        <ToastStack />
        <AppDialog />
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import ToastStack from '@/Components/Feedback/ToastStack.vue';
import AppDialog from '@/Components/Feedback/AppDialog.vue';

const isDark = ref(false);

onMounted(() => {
    isDark.value = document.documentElement.classList.contains('dark');
});
</script>
