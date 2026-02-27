<template>
    <div class="min-h-screen flex" :class="{ dark: isDark }">
        <slot />
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';

const isDark = ref(false);

onMounted(() => {
    isDark.value = document.documentElement.classList.contains('dark');
});
</script>
