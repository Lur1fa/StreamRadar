<script setup lang="ts">
/**
 * Under a secret field: what is stored (as a mask) with "remove", or that it
 * will be removed on save with "undo". Secrets never come back from the
 * server; an empty field keeps the stored value.
 */
defineProps<{ stored: boolean; removing: boolean; mask?: string | null }>();
const emit = defineEmits<{ remove: []; undo: [] }>();
</script>

<template>
    <p v-if="stored" class="text-[11px] text-gray-400 dark:text-zinc-600 mt-1">
        Stored: <span class="font-mono">{{ mask }}</span> · leave empty to keep ·
        <button type="button" class="text-red-500 hover:underline" @click="emit('remove')">remove</button>
    </p>
    <p v-else-if="removing" class="text-[11px] text-amber-600 dark:text-amber-400 mt-1">
        Will be removed on save · <button type="button" class="hover:underline" @click="emit('undo')">undo</button>
    </p>
</template>
