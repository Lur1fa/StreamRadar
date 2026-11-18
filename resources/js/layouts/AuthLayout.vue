<script setup lang="ts">
import { Radar } from 'lucide-vue-next';

/** The centred page shell of the screens shown before sign-in. */
withDefaults(defineProps<{ title: string; subtitle: string; wide?: boolean }>(), { wide: false });
</script>

<template>
    <div :class="['min-h-screen bg-gray-50 dark:bg-zinc-950 flex items-center justify-center px-4', wide && 'py-10']">
        <div :class="['w-full', wide ? 'max-w-md' : 'max-w-sm']">
            <div class="text-center mb-8">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center mx-auto mb-4">
                    <Radar class="w-7 h-7 text-white" :stroke-width="2" />
                </div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ title }}</h1>
                <p class="text-sm text-gray-500 dark:text-zinc-500 mt-1">{{ subtitle }}</p>
            </div>

            <slot />
        </div>
    </div>
</template>
