<script setup lang="ts">
import { AlertTriangle } from 'lucide-vue-next';
import { useDocumentKeydown } from '@/composables/useDocumentKeydown';

const props = withDefaults(defineProps<{
    show: boolean;
    title?: string;
    message?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    destructive?: boolean;
}>(), {
    title: 'Are you sure?',
    message: 'This action cannot be undone.',
    confirmLabel: 'Confirm',
    cancelLabel: 'Cancel',
    destructive: true,
});

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();

useDocumentKeydown(() => props.show, (e) => {
    if (e.key === 'Escape') emit('cancel');
    if (e.key === 'Enter') emit('confirm');
});
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-150" enter-from-class="opacity-0" enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <!-- Backdrop -->
                <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="emit('cancel')" />

                <!-- Modal -->
                <Transition enter-active-class="transition ease-out duration-150" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
                    <div v-if="show" class="relative bg-white dark:bg-zinc-900 rounded-2xl shadow-xl dark:shadow-none max-w-sm w-full overflow-hidden">
                        <div class="p-6 text-center">
                            <div :class="['w-12 h-12 mx-auto rounded-full flex items-center justify-center mb-4',
                                destructive ? 'bg-red-50 dark:bg-red-500/10' : 'bg-purple-50 dark:bg-purple-500/10']">
                                <AlertTriangle :class="['w-6 h-6', destructive ? 'text-red-500' : 'text-purple-500']" />
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ title }}</h3>
                            <p class="mt-2 text-sm text-gray-500 dark:text-zinc-400">{{ message }}</p>
                        </div>
                        <div class="flex border-t border-gray-100 dark:border-zinc-800">
                            <button @click="emit('cancel')"
                                class="flex-1 px-4 py-3 text-sm font-medium text-gray-600 dark:text-zinc-400 hover:bg-gray-50 dark:hover:bg-zinc-800 transition-colors">
                                {{ cancelLabel }}
                            </button>
                            <button @click="emit('confirm')"
                                :class="['flex-1 px-4 py-3 text-sm font-semibold border-l border-gray-100 dark:border-zinc-800 transition-colors',
                                    destructive
                                        ? 'text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10'
                                        : 'text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-500/10']">
                                {{ confirmLabel }}
                            </button>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
