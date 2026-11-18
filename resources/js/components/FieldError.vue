<script setup lang="ts">
defineProps<{ message?: string | null }>();
</script>

<template>
    <p v-if="message" class="text-[11px] text-red-600 dark:text-red-400 mt-1">{{ message }}</p>
</template>
