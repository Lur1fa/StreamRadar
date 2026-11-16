<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Search, Clock, Radio, WifiOff, Bell, RefreshCw, Trash2, Eye } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import { useNow } from '@/composables/useNow';
import { twitchCategoryUrl } from '@/composables/useTwitch';
import type { HistoryEvent, PaginatedData } from '@/types';

const props = defineProps<{ events: PaginatedData<HistoryEvent>; filters: { type: string | null; search: string | null } }>();
const searchInput = ref(props.filters.search || '');

let searchTimeout: ReturnType<typeof setTimeout>;
function onSearch(e: Event) {
    const value = (e.target as HTMLInputElement).value;
    searchInput.value = value;
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        const params: Record<string, any> = {};
        if (value) params.search = value;
        if (props.filters.type) params.type = props.filters.type;
        router.get('/history', params, { preserveState: true, preserveScroll: true });
    }, 300);
}

function filterByType(type: string | null) {
    const params: Record<string, any> = {};
    if (type) params.type = type;
    if (searchInput.value) params.search = searchInput.value;
    router.get('/history', params, { preserveState: true, preserveScroll: true });
}

const showClearConfirm = ref(false);
function clearHistory() { router.delete('/history', { preserveScroll: true, onSuccess: () => { showClearConfirm.value = false; } }); }

const now = useNow();

function formatTime(dateStr: string): string {
    const d = new Date(dateStr);
    const diffMin = Math.floor((now.value - d.getTime()) / 60000);
    if (diffMin < 1) return 'Just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    const diffH = Math.floor(diffMin / 60);
    if (diffH < 24) return `${diffH}h ago`;
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function timeKey(dateStr: string): string {
    const d = new Date(dateStr);
    d.setSeconds(0, 0);
    return d.toISOString();
}

function shouldShowTime(idx: number): boolean {
    const event = props.events.data[idx];
    if (event.type === 'sync_completed') return false;
    if (idx === 0) return true;
    return timeKey(event.created_at) !== timeKey(props.events.data[idx - 1].created_at);
}

const typeConfig: Record<string, { label: string; icon: any; color: string }> = {
    stream_online: { label: 'Stream Online', icon: Radio, color: 'text-green-500 bg-green-50 dark:bg-green-500/10' },
    stream_offline: { label: 'Stream Offline', icon: WifiOff, color: 'text-gray-500 bg-gray-100 dark:bg-zinc-800' },
    alert_triggered: { label: 'Alert', icon: Bell, color: 'text-amber-500 bg-amber-50 dark:bg-amber-500/10' },
    sync_completed: { label: 'Scan', icon: RefreshCw, color: 'text-blue-500 bg-blue-50 dark:bg-blue-500/10' },
};
const types = Object.keys(typeConfig);
</script>

<template>
    <AppLayout>
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">History</h2>
            <button v-if="events.total > 0" @click="showClearConfirm = true"
                class="px-3 py-1.5 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg transition-colors flex items-center gap-1.5">
                <Trash2 class="w-4 h-4" /> Clear All
            </button>
        </div>

        <div class="flex flex-col sm:flex-row gap-3 mb-6">
            <div class="relative flex-1">
                <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                <input :value="searchInput" @input="onSearch" autocomplete="off" type="text" placeholder="Search history..."
                    class="w-full pl-9 pr-4 py-2 text-sm rounded-lg bg-white dark:bg-zinc-900 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-zinc-500 focus:ring-2 focus:ring-purple-500 outline-none" />
            </div>
            <div class="flex gap-1 flex-wrap">
                <button @click="filterByType(null)"
                    :class="['px-3 py-1.5 text-xs font-medium rounded-lg transition-colors', !filters.type ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-300' : 'text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800']">
                    All
                </button>
                <button v-for="t in types" :key="t" @click="filterByType(t)"
                    :class="['px-3 py-1.5 text-xs font-medium rounded-lg transition-colors', filters.type === t ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-300' : 'text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800']">
                    {{ typeConfig[t]?.label || t }}
                </button>
            </div>
        </div>

        <div v-if="events.data.length === 0" class="text-center py-16">
            <Clock class="w-16 h-16 mx-auto text-gray-300 dark:text-zinc-700" :stroke-width="1" />
            <h3 class="mt-4 text-lg font-semibold text-gray-700 dark:text-zinc-300">No events yet</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-zinc-500">Events will appear here after syncing streams.</p>
        </div>

        <div v-else class="space-y-0">
            <template v-for="(event, idx) in events.data" :key="event.id">
                <!-- Time separator -->
                <div v-if="shouldShowTime(idx)" :class="['flex items-center gap-3 mb-1.5', idx > 0 ? 'mt-3' : '']">
                    <div class="h-px flex-1 bg-gray-200 dark:bg-zinc-800"></div>
                    <span class="text-[10px] font-medium text-gray-400 dark:text-zinc-600 shrink-0">{{ formatTime(event.created_at) }}</span>
                    <div class="h-px flex-1 bg-gray-200 dark:bg-zinc-800"></div>
                </div>

                <!-- Event row -->
                <div class="flex items-center gap-3 px-4 py-2.5 bg-white dark:bg-zinc-900 first:rounded-t-xl last:rounded-b-xl border-b border-gray-100/60 dark:border-zinc-800/60 last:border-b-0"
                    :class="{ 'rounded-t-xl': shouldShowTime(idx), 'rounded-b-xl': idx === events.data.length - 1 || (idx < events.data.length - 1 && shouldShowTime(idx + 1)) }">
                    <div :class="['w-7 h-7 rounded-lg flex items-center justify-center shrink-0', typeConfig[event.type]?.color || 'bg-gray-100 dark:bg-zinc-800 text-gray-500']">
                        <component :is="typeConfig[event.type]?.icon || Clock" class="w-3.5 h-3.5" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <a v-if="event.streamer_login" :href="`https://www.twitch.tv/${event.streamer_login}`" target="_blank" class="text-sm font-medium text-gray-900 dark:text-white truncate hover:text-purple-600 dark:hover:text-purple-400 transition-colors">{{ event.streamer_name || event.streamer_login }}</a>
                            <span class="text-[10px] font-bold uppercase text-gray-400 dark:text-zinc-500">{{ typeConfig[event.type]?.label || event.type }}</span>
                        </div>
                        <p v-if="event.type === 'sync_completed' && event.metadata" class="text-xs text-gray-500 dark:text-zinc-500 mt-0.5">
                            <span class="text-gray-400 dark:text-zinc-600 mr-1.5">{{ formatTime(event.created_at) }}</span>
                            <span class="text-green-600 dark:text-green-400">{{ event.metadata.new }} new</span>
                            <span class="mx-1">&middot;</span>
                            <span>{{ event.metadata.updated }} updated</span>
                            <span class="mx-1">&middot;</span>
                            <span class="text-red-500 dark:text-red-400">{{ event.metadata.ended }} ended</span>
                            <span v-if="event.metadata.alerts" class="mx-1">&middot;</span>
                            <span v-if="event.metadata.alerts" class="text-amber-500 dark:text-amber-400">{{ event.metadata.alerts }} alerts</span>
                            <span class="mx-1">&middot;</span>
                            <span class="text-gray-400 dark:text-zinc-600">{{ event.metadata.duration }}s</span>
                        </p>
                        <p v-else-if="event.title" class="text-xs text-gray-500 dark:text-zinc-500 truncate mt-0.5">
                            {{ event.title }}
                             <span v-if="event.category_name" class="text-gray-400 dark:text-zinc-600">—</span> <a v-if="event.category_name" :href="twitchCategoryUrl(event.category_name)" target="_blank" class="text-purple-500 dark:text-purple-400 hover:underline">{{ event.category_name }}</a>
                            <span v-if="event.metadata?.rule_names" class="text-amber-500 dark:text-amber-400"> ({{ event.metadata.rule_names.join(', ') }})</span>
                            <span v-else-if="event.metadata?.rule_name" class="text-amber-500 dark:text-amber-400"> ({{ event.metadata.rule_name }})</span>
                        </p>
                    </div>
                    <div v-if="event.viewer_count" class="flex items-center gap-1 text-sm font-semibold text-gray-600 dark:text-zinc-300 shrink-0">
                        <Eye class="w-3.5 h-3.5" />
                        {{ event.viewer_count.toLocaleString() }}
                    </div>
                </div>
            </template>
        </div>

        <div v-if="events.last_page > 1" class="flex justify-center gap-1 mt-6">
            <template v-for="link in events.links" :key="link.label">
                <component :is="link.url ? 'a' : 'span'" :href="link.url || undefined"
                    @click.prevent="link.url && router.get(link.url, {}, { preserveState: true })"
                    :class="['px-3 py-1 text-sm rounded-lg transition-colors cursor-pointer',
                        link.active ? 'bg-purple-600 text-white' : link.url ? 'text-gray-600 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800' : 'text-gray-300 dark:text-zinc-700 cursor-default']"
                    v-html="link.label" />
            </template>
        </div>
        <ConfirmModal :show="showClearConfirm" title="Clear all history?" message="All events will be permanently deleted." confirm-label="Clear All" @confirm="clearHistory" @cancel="showClearConfirm = false" />
    </AppLayout>
</template>
