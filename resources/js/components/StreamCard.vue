<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ExternalLink, ShieldBan, UserMinus, UserPlus, Eye, Clock, Pin, PinOff } from 'lucide-vue-next';
import LanguageFlag from '@/components/LanguageFlag.vue';
import type { Stream } from '@/types';
import { twitchCategoryUrl } from '@/composables/useTwitch';

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

function thumbnailUrl(stream: Stream): string {
    if (!stream.thumbnail_url) return '';
    return stream.thumbnail_url.replace('{width}', '440').replace('{height}', '248');
}

function twitchUrl(stream: Stream): string {
    return `https://www.twitch.tv/${stream.user_login}`;
}

function timeSince(dateStr: string | null): string {
    if (!dateStr) return '';
    const start = new Date(dateStr);
    const diff = Math.floor(((props.now || Date.now()) - start.getTime()) / 1000);
    const h = Math.floor(diff / 3600);
    const m = Math.floor((diff % 3600) / 60);
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

function blacklistChannel() {
    router.post('/blacklist', { type: 'channel', value: props.stream.user_login }, { preserveScroll: true });
}

function untrackChannel() {
    router.delete(`/tracking/channels/by-login/${props.stream.user_login}`, { preserveScroll: true });
}

function trackChannel() {
    router.post('/tracking/channels', { user_login: props.stream.user_login }, { preserveScroll: true });
}
</script>

<template>
    <!-- Compact row -->
    <div v-if="compact" :class="['flex items-center gap-3 px-4 py-2.5 bg-white dark:bg-zinc-900 hover:bg-gray-50 dark:hover:bg-zinc-800/60 transition-colors group', isPinned && 'bg-amber-50/50 dark:bg-amber-500/[0.03]']">
        <a :href="twitchUrl(stream)" target="_blank" class="shrink-0">
            <img v-if="stream.profile_image_url" :src="stream.profile_image_url" :alt="stream.user_name" class="w-8 h-8 rounded-full hover:ring-2 hover:ring-purple-400 transition-all" />
            <div v-else class="w-8 h-8 rounded-full bg-purple-100 dark:bg-purple-500/10"></div>
        </a>

        <a :href="twitchUrl(stream)" target="_blank" class="min-w-0 flex-1 hover:opacity-80 transition-opacity">
            <div class="flex items-center gap-2">
                <Pin v-if="isPinned" class="w-3 h-3 text-amber-500 shrink-0" />
                <span v-if="isNew" class="px-1.5 py-0.5 text-[9px] font-bold bg-green-500 text-white rounded uppercase leading-none">new</span>
                <span v-if="stream.is_mature" class="px-1.5 py-0.5 text-[9px] font-bold bg-red-900 text-red-200 rounded uppercase leading-none">Mature</span>
                <span class="font-medium text-sm text-gray-900 dark:text-white truncate">{{ stream.user_name }}</span>
                <LanguageFlag v-if="stream.language" :code="stream.language" class="hidden md:inline-block" />
                <a v-if="stream.game_name || stream.category" :href="twitchCategoryUrl(stream.game_name || stream.category?.name || '')" target="_blank" class="text-xs text-purple-600 dark:text-purple-400 truncate hover:underline">{{ stream.game_name || stream.category?.name }}</a>
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

            <div class="flex items-center gap-1 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity">
                <button @click="emit('togglePin', stream.user_login)" class="p-1 text-gray-400 hover:text-amber-500" v-tooltip="isPinned ? 'Unpin' : 'Pin'">
                    <PinOff v-if="isPinned" class="w-4 h-4" />
                    <Pin v-else class="w-4 h-4" />
                </button>
                <a :href="twitchUrl(stream)" target="_blank" class="p-1 text-gray-400 hover:text-purple-500 dark:hover:text-purple-400" v-tooltip="'Open on Twitch'">
                    <ExternalLink class="w-4 h-4" />
                </a>
                <button v-if="isTrackedChannel" @click="untrackChannel" class="p-1 text-gray-400 hover:text-orange-500" v-tooltip="'Untrack channel'">
                    <UserMinus class="w-4 h-4" />
                </button>
                <template v-else>
                    <button @click="trackChannel" class="p-1 text-gray-400 hover:text-green-500" v-tooltip="'Track channel'">
                        <UserPlus class="w-4 h-4" />
                    </button>
                    <button @click="blacklistChannel" class="p-1 text-gray-400 hover:text-red-500" v-tooltip="'Blacklist'">
                        <ShieldBan class="w-4 h-4" />
                    </button>
                </template>
            </div>
        </div>
    </div>

    <!-- Card view -->
    <div v-else :class="['bg-white dark:bg-zinc-900 rounded-xl overflow-hidden hover:shadow-lg shadow-sm dark:shadow-none transition-all group', isPinned && 'ring-2 ring-amber-400/50']">
        <a :href="twitchUrl(stream)" target="_blank" class="block relative aspect-video bg-gray-100 dark:bg-zinc-800 overflow-hidden">
            <img v-if="thumbnailUrl(stream)" :src="thumbnailUrl(stream)" :alt="stream.title" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-[1.03]" loading="lazy" />
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
                <a :href="twitchUrl(stream)" target="_blank" class="shrink-0 mt-0.5">
                    <img v-if="stream.profile_image_url" :src="stream.profile_image_url" :alt="stream.user_name" class="w-9 h-9 rounded-full hover:ring-2 hover:ring-purple-400 transition-all" />
                    <div v-else class="w-9 h-9 rounded-full bg-purple-100 dark:bg-purple-500/10"></div>
                </a>

                <div class="min-w-0 flex-1">
                    <a :href="twitchUrl(stream)" target="_blank" class="block hover:opacity-80 transition-opacity">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white truncate" :title="stream.title">{{ stream.title }}</h3>
                    </a>
                    <div class="flex items-center gap-1.5">
                        <span class="text-sm text-gray-600 dark:text-zinc-400">{{ stream.user_name }}</span>
                        <LanguageFlag v-if="stream.language" :code="stream.language" />
                    </div>
                    <div v-if="stream.game_name || stream.category" class="mt-0.5">
                        <a :href="twitchCategoryUrl(stream.game_name || stream.category?.name || '')" target="_blank" class="text-xs text-purple-600 dark:text-purple-400 hover:underline">{{ stream.game_name || stream.category?.name }}</a>
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

            <div class="flex items-center gap-1 mt-2 pt-2 border-t border-gray-100/60 dark:border-zinc-800/60 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity">
                <button @click="emit('togglePin', stream.user_login)" :class="['flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-md transition-colors', isPinned ? 'text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-500/10' : 'text-gray-500 hover:bg-gray-50 dark:text-zinc-400 dark:hover:bg-zinc-800']">
                    <PinOff v-if="isPinned" class="w-3.5 h-3.5" />
                    <Pin v-else class="w-3.5 h-3.5" />
                    {{ isPinned ? 'Unpin' : 'Pin' }}
                </button>
                <a :href="twitchUrl(stream)" target="_blank" class="flex items-center gap-1 px-2 py-1 text-xs font-medium text-purple-600 hover:bg-purple-50 dark:text-purple-400 dark:hover:bg-purple-500/10 rounded-md transition-colors">
                    <ExternalLink class="w-3.5 h-3.5" />
                    Watch
                </a>
                <button v-if="isTrackedChannel" @click="untrackChannel" class="flex items-center gap-1 px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-50 hover:text-orange-600 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-orange-400 rounded-md transition-colors">
                    <UserMinus class="w-3.5 h-3.5" />
                    Untrack
                </button>
                <template v-else>
                    <button @click="trackChannel" class="flex items-center gap-1 px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-50 hover:text-green-600 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-green-400 rounded-md transition-colors">
                        <UserPlus class="w-3.5 h-3.5" />
                        Track
                    </button>
                    <button @click="blacklistChannel" class="flex items-center gap-1 px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-50 hover:text-red-600 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-red-400 rounded-md transition-colors">
                        <ShieldBan class="w-3.5 h-3.5" />
                        Block
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>
