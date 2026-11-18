<script setup lang="ts">
import { computed } from 'vue';
import { Eye, Clock, Pin } from 'lucide-vue-next';
import LanguageFlag from '@/components/LanguageFlag.vue';
import StreamActions from '@/components/StreamActions.vue';
import { sizedImage, twitchCategoryUrl, twitchChannelUrl } from '@/utils';
import type { Stream } from '@/types';

const props = defineProps<{
    stream: Stream;
    compact?: boolean;
    isNew?: boolean;
    isTrackedChannel?: boolean;
    isPinned?: boolean;
    now?: number;
}>();

const emit = defineEmits<{
    togglePin: [login: string];
}>();

const channelUrl = computed(() => twitchChannelUrl(props.stream.user_login));
const thumbnail = computed(() => sizedImage(props.stream.thumbnail_url, 440, 248));
const categoryName = computed(() => props.stream.game_name || props.stream.category?.name || '');

function timeSince(dateStr: string | null): string {
    if (!dateStr) return '';
    const start = new Date(dateStr);
    const diff = Math.floor(((props.now || Date.now()) - start.getTime()) / 1000);
    const h = Math.floor(diff / 3600);
    const m = Math.floor((diff % 3600) / 60);
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}
</script>

<template>
    <!-- Compact row -->
    <div v-if="compact" :class="['flex items-center gap-3 px-4 py-2.5 bg-white dark:bg-zinc-900 hover:bg-gray-50 dark:hover:bg-zinc-800/60 transition-colors group', isPinned && 'bg-amber-50/50 dark:bg-amber-500/[0.03]']">
        <a :href="channelUrl" target="_blank" class="shrink-0">
            <img v-if="stream.profile_image_url" :src="stream.profile_image_url" :alt="stream.user_name" class="w-8 h-8 rounded-full hover:ring-2 hover:ring-purple-400 transition-all" />
            <div v-else class="w-8 h-8 rounded-full bg-purple-100 dark:bg-purple-500/10"></div>
        </a>

        <a :href="channelUrl" target="_blank" class="min-w-0 flex-1 hover:opacity-80 transition-opacity">
            <div class="flex items-center gap-2">
                <Pin v-if="isPinned" class="w-3 h-3 text-amber-500 shrink-0" />
                <span v-if="isNew" class="px-1.5 py-0.5 text-[9px] font-bold bg-green-500 text-white rounded uppercase leading-none">new</span>
                <span v-if="stream.is_mature" class="px-1.5 py-0.5 text-[9px] font-bold bg-red-900 text-red-200 rounded uppercase leading-none">Mature</span>
                <span class="font-medium text-sm text-gray-900 dark:text-white truncate">{{ stream.user_name }}</span>
                <LanguageFlag v-if="stream.language" :code="stream.language" class="hidden md:inline-block" />
                <a v-if="categoryName" :href="twitchCategoryUrl(categoryName)" target="_blank" class="text-xs text-purple-600 dark:text-purple-400 truncate hover:underline">{{ categoryName }}</a>
            </div>
            <p class="text-xs text-gray-500 dark:text-zinc-500 truncate">{{ stream.title }}</p>
        </a>

        <div class="flex items-center gap-3 shrink-0">
            <div class="flex items-center gap-1 text-sm font-semibold text-red-500">
                <Eye class="w-3.5 h-3.5" />
                {{ stream.viewer_count.toLocaleString() }}
            </div>
            <div class="flex items-center gap-1 text-xs text-gray-400 dark:text-zinc-500 w-16 justify-end hidden md:flex">
                <Clock class="w-3 h-3" />
                {{ timeSince(stream.started_at) }}
            </div>

            <StreamActions :login="stream.user_login" :is-pinned="isPinned" :is-tracked-channel="isTrackedChannel" @toggle-pin="emit('togglePin', $event)" />
        </div>
    </div>

    <!-- Card view -->
    <div v-else :class="['bg-white dark:bg-zinc-900 rounded-xl overflow-hidden hover:shadow-lg shadow-sm dark:shadow-none transition-all group', isPinned && 'ring-2 ring-amber-400/50']">
        <a :href="channelUrl" target="_blank" class="block relative aspect-video bg-gray-100 dark:bg-zinc-800 overflow-hidden">
            <img v-if="thumbnail" :src="thumbnail" :alt="stream.title" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-[1.03]" loading="lazy" />
            <div class="absolute top-2 left-2 flex gap-1.5">
                <span class="px-2 py-0.5 text-xs font-bold bg-red-600 text-white rounded inline-flex items-center gap-1">
                    <span class="relative flex h-1.5 w-1.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-300 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-white"></span>
                    </span>
                    LIVE
                </span>
                <span v-if="isNew" class="px-2 py-0.5 text-xs font-bold bg-green-500 text-white rounded">NEW</span>
                <span v-if="stream.is_mature" class="px-2 py-0.5 text-xs font-bold bg-red-900 text-red-200 rounded">Mature</span>
                <span v-if="isPinned" class="px-2 py-0.5 text-xs font-bold bg-amber-500 text-white rounded flex items-center gap-0.5"><Pin class="w-3 h-3" /></span>
                <span class="px-2 py-0.5 text-xs font-semibold bg-black/60 text-white rounded backdrop-blur-sm flex items-center gap-1">
                    <Eye class="w-3 h-3" />
                    {{ stream.viewer_count.toLocaleString() }}
                </span>
            </div>
            <span class="absolute bottom-2 right-2 px-1.5 py-0.5 text-xs bg-black/60 text-white rounded backdrop-blur-sm flex items-center gap-1">
                <Clock class="w-3 h-3" />
                {{ timeSince(stream.started_at) }}
            </span>
        </a>

        <div class="p-3">
            <div class="flex items-start gap-2.5">
                <a :href="channelUrl" target="_blank" class="shrink-0 mt-0.5">
                    <img v-if="stream.profile_image_url" :src="stream.profile_image_url" :alt="stream.user_name" class="w-9 h-9 rounded-full hover:ring-2 hover:ring-purple-400 transition-all" />
                    <div v-else class="w-9 h-9 rounded-full bg-purple-100 dark:bg-purple-500/10"></div>
                </a>

                <div class="min-w-0 flex-1">
                    <a :href="channelUrl" target="_blank" class="block hover:opacity-80 transition-opacity">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white truncate" :title="stream.title">{{ stream.title }}</h3>
                    </a>
                    <div class="flex items-center gap-1.5">
                        <span class="text-sm text-gray-600 dark:text-zinc-400">{{ stream.user_name }}</span>
                        <LanguageFlag v-if="stream.language" :code="stream.language" />
                    </div>
                    <div v-if="categoryName" class="mt-0.5">
                        <a :href="twitchCategoryUrl(categoryName)" target="_blank" class="text-xs text-purple-600 dark:text-purple-400 hover:underline">{{ categoryName }}</a>
                    </div>
                </div>
            </div>

            <div v-if="stream.tags?.length" class="flex flex-wrap gap-1 mt-2">
                <span v-for="tag in stream.tags.slice(0, 3)" :key="tag" class="px-1.5 py-0.5 text-[10px] font-medium bg-gray-100 dark:bg-zinc-800 text-gray-600 dark:text-zinc-400 rounded">
                    {{ tag }}
                </span>
                <span v-if="stream.tags.length > 3" class="px-1.5 py-0.5 text-[10px] font-medium text-gray-400 dark:text-zinc-500" v-tooltip="stream.tags.slice(3).join(', ')">
                    +{{ stream.tags.length - 3 }} more
                </span>
            </div>

            <StreamActions :login="stream.user_login" :is-pinned="isPinned" :is-tracked-channel="isTrackedChannel" labelled @toggle-pin="emit('togglePin', $event)" />
        </div>
    </div>
</template>
