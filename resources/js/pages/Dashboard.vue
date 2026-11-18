<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { Search, List, LayoutGrid as GridIcon, Video, ChevronDown, ChevronsDownUp, ChevronsUpDown, SlidersHorizontal, X } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import StreamCard from '@/components/StreamCard.vue';
import LanguageFlag from '@/components/LanguageFlag.vue';
import { useNow } from '@/composables/useNow';
import { useDebouncedFn } from '@/composables/useDebouncedFn';
import { readStored, sizedImage, twitchCategoryUrl, writeStored } from '@/utils';
import type { Stream } from '@/types';

interface StreamGroup {
    name: string;
    boxArtUrl: string | null;
    streams: Stream[];
    tracked: boolean;
}

const props = defineProps<{
    streams: Stream[];
    trackedLogins: string[];
    languages: string[];
    filters: { sort: string; search: string | null; lang: string | null; min_viewers: string | null; hide_mature: string | null };
}>();

const now = useNow();
const BATCH = 20;
const density = ref(localStorage.getItem('sp_density') || 'comfortable');
const groupMode = ref(localStorage.getItem('sp_group') || 'flat');
const searchInput = ref(props.filters.search || '');
const visibleCount = ref(BATCH);
const showFilters = ref(false);

// Pinned streams and collapsed groups are remembered in this browser.
const pinnedLogins = ref<string[]>(readStored('sp_pinned', []));
const collapsed = ref<Record<string, boolean>>(readStored('sp_collapsed', {}));
watch(pinnedLogins, (value) => writeStored('sp_pinned', value), { deep: true });
watch(collapsed, (value) => writeStored('sp_collapsed', value), { deep: true });

function togglePin(login: string) {
    const idx = pinnedLogins.value.indexOf(login);
    if (idx >= 0) pinnedLogins.value.splice(idx, 1);
    else pinnedLogins.value.push(login);
}

function toggleGroup(key: string) {
    collapsed.value[key] = !collapsed.value[key];
}


const groupLimits = ref<Record<string, number>>({});
function groupVisible(group: StreamGroup): Stream[] {
    return group.streams.slice(0, groupLimits.value[group.name] || BATCH);
}
function groupHasMore(group: StreamGroup): boolean {
    return group.streams.length > (groupLimits.value[group.name] || BATCH);
}
function showMoreGroup(name: string) {
    groupLimits.value[name] = (groupLimits.value[name] || BATCH) + BATCH;
}

const allCollapsed = computed(() => groupedStreams.value.every(g => collapsed.value[g.name]));
function toggleAll() {
    const s = !allCollapsed.value;
    for (const g of groupedStreams.value) collapsed.value[g.name] = s;
}

function isNewStream(stream: Stream): boolean {
    if (!stream.created_at) return false;
    return (Date.now() - new Date(stream.created_at).getTime()) < 10 * 60 * 1000;
}

// Server-side filters
function applyFilter(key: string, value: any) {
    const params: Record<string, any> = { ...props.filters, [key]: value };
    Object.keys(params).forEach(k => { if (!params[k]) delete params[k]; });
    visibleCount.value = BATCH;
    router.get('/', params, { preserveState: true, preserveScroll: true });
}

function toggleDensity() {
    density.value = density.value === 'comfortable' ? 'compact' : 'comfortable';
    localStorage.setItem('sp_density', density.value);
}

function toggleGroupMode() {
    groupMode.value = groupMode.value === 'category' ? 'flat' : 'category';
    localStorage.setItem('sp_group', groupMode.value);
}

const search = useDebouncedFn((value: string) => applyFilter('search', value || null), 300);
function onSearch(e: Event) {
    searchInput.value = (e.target as HTMLInputElement).value;
    search(searchInput.value);
}

/** The same bindings for every card, in every view. */
function cardProps(stream: Stream) {
    return {
        stream,
        now: now.value,
        isNew: isNewStream(stream),
        isTrackedChannel: props.trackedLogins.includes(stream.user_login),
        isPinned: pinnedLogins.value.includes(stream.user_login),
    };
}

// Sort streams with pinned on top
const sortedStreams = computed(() => {
    const pinned = props.streams.filter(s => pinnedLogins.value.includes(s.user_login));
    const rest = props.streams.filter(s => !pinnedLogins.value.includes(s.user_login));
    return [...pinned, ...rest];
});

const isGrouped = computed(() => groupMode.value === 'category');
const isEmpty = computed(() => props.streams.length === 0);
const visibleStreams = computed(() => sortedStreams.value.slice(0, visibleCount.value));
const hasMore = computed(() => visibleCount.value < sortedStreams.value.length);
function showMore() { visibleCount.value += BATCH; }

const groupedStreams = computed<StreamGroup[]>(() => {
    const map = new Map<string, StreamGroup>();
    for (const stream of sortedStreams.value) {
        const name = stream.game_name || stream.category?.name || 'No category';
        if (!map.has(name)) map.set(name, { name, boxArtUrl: stream.game_box_art_url || stream.category?.box_art_url || null, streams: [], tracked: false });
        const group = map.get(name)!;
        group.streams.push(stream);
        if (stream.category?.is_active) group.tracked = true;
    }
    const groups = Array.from(map.values());
    const sort = props.filters.sort;
    groups.sort((a, b) => {
        const aT = a.streams.reduce((s, x) => s + x.viewer_count, 0);
        const bT = b.streams.reduce((s, x) => s + x.viewer_count, 0);
        if (sort === 'viewers_asc') return aT - bT;
        if (sort === 'name') return a.name.localeCompare(b.name);
        return bT - aT;
    });
    return groups;
});

const hasActiveFilters = computed(() => !!(props.filters.lang || props.filters.min_viewers || props.filters.hide_mature));

const sortOptions = [
    { value: 'viewers_desc', label: 'Viewers (high)' },
    { value: 'viewers_asc', label: 'Viewers (low)' },
    { value: 'name', label: 'Name (A-Z)' },
    { value: 'started_at', label: 'Recently started' },
];
</script>

<template>
    <AppLayout>
        <!-- Toolbar -->
        <div class="flex flex-col sm:flex-row gap-3 mb-4">
            <div class="relative flex-1">
                <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                <input autocomplete="off" type="text" :value="searchInput" @input="onSearch" placeholder="Search streams, tags..."
                    class="w-full pl-9 pr-4 py-2 text-sm rounded-lg bg-white dark:bg-zinc-900 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-zinc-500 focus:ring-2 focus:ring-purple-500 outline-none" />
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <select :value="filters.sort" @change="applyFilter('sort', ($event.target as HTMLSelectElement).value)"
                    class="px-3 py-2 text-sm rounded-lg bg-white dark:bg-zinc-900 text-gray-700 dark:text-zinc-300 outline-none focus:ring-2 focus:ring-purple-500">
                    <option v-for="opt in sortOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
                <button @click="toggleGroupMode"
                    :class="['px-3 py-2 text-sm rounded-lg transition-colors', groupMode === 'category' ? 'bg-purple-50 text-purple-700 dark:bg-purple-500/10 dark:text-purple-300' : 'bg-white dark:bg-zinc-900 text-gray-600 dark:text-zinc-400 hover:bg-gray-50 dark:hover:bg-zinc-800']">
                    Group
                </button>
                <button v-if="isGrouped" @click="toggleAll"
                    class="p-2 text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800 rounded-lg bg-white dark:bg-zinc-900 transition-colors"
                    v-tooltip="allCollapsed ? 'Expand all' : 'Collapse all'">
                    <ChevronsUpDown v-if="allCollapsed" class="w-4 h-4" />
                    <ChevronsDownUp v-else class="w-4 h-4" />
                </button>
                <button @click="toggleDensity" class="p-2 text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800 rounded-lg bg-white dark:bg-zinc-900 transition-colors"
                    v-tooltip="density === 'comfortable' ? 'Compact view' : 'Card view'">
                    <List v-if="density === 'comfortable'" class="w-4 h-4" />
                    <GridIcon v-else class="w-4 h-4" />
                </button>
                <button @click="showFilters = !showFilters"
                    :class="['p-2 rounded-lg transition-colors', showFilters || hasActiveFilters ? 'bg-purple-50 text-purple-600 dark:bg-purple-500/10 dark:text-purple-400' : 'bg-white dark:bg-zinc-900 text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800']">
                    <SlidersHorizontal class="w-4 h-4" />
                </button>
            </div>
        </div>

        <!-- Quick Filters -->
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100" leave-to-class="opacity-0 -translate-y-2">
            <div v-if="showFilters" class="flex flex-wrap items-center gap-3 mb-4 p-3 bg-white dark:bg-zinc-900 rounded-xl shadow-sm dark:shadow-none">
                <!-- Language -->
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-500 dark:text-zinc-500">Language:</span>
                    <div class="flex gap-1 flex-wrap">
                        <button @click="applyFilter('lang', null)"
                            :class="['px-2 py-1 text-xs rounded-md transition-colors', !filters.lang ? 'accent-soft' : 'text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800']">
                            All
                        </button>
                        <button v-for="lang in languages" :key="lang" @click="applyFilter('lang', filters.lang === lang ? null : lang)"
                            :class="['px-2 py-1 text-xs rounded-md transition-colors flex items-center gap-1', filters.lang === lang ? 'accent-soft' : 'text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800']">
                            <LanguageFlag :code="lang" :size="12" />
                            {{ lang }}
                        </button>
                    </div>
                </div>

                <div class="w-px h-6 bg-gray-200 dark:bg-zinc-800 hidden sm:block"></div>

                <!-- Min viewers -->
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-500 dark:text-zinc-500">Min viewers:</span>
                    <input autocomplete="off" type="number" :value="filters.min_viewers || ''" @change="applyFilter('min_viewers', ($event.target as HTMLInputElement).value || null)"
                        placeholder="0" min="0"
                        class="w-20 px-2 py-1 text-xs rounded-md bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                </div>

                <div class="w-px h-6 bg-gray-200 dark:bg-zinc-800 hidden sm:block"></div>

                <!-- Hide mature -->
                <label class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-zinc-400">
                    <input type="checkbox" :checked="filters.hide_mature === '1'" @change="applyFilter('hide_mature', ($event.target as HTMLInputElement).checked ? '1' : null)"
                        class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800 w-3.5 h-3.5" />
                    Hide mature
                </label>

                <!-- Clear all -->
                <button v-if="hasActiveFilters" @click="applyFilter('lang', null); applyFilter('min_viewers', null); applyFilter('hide_mature', null)"
                    class="ml-auto px-2 py-1 text-xs text-gray-400 hover:text-red-500 flex items-center gap-1">
                    <X class="w-3 h-3" /> Clear filters
                </button>
            </div>
        </Transition>

        <!-- Empty -->
        <div v-if="isEmpty" class="text-center py-20">
            <Video class="w-16 h-16 mx-auto text-gray-300 dark:text-zinc-700" :stroke-width="1" />
            <h3 class="mt-4 text-lg font-semibold text-gray-700 dark:text-zinc-300">No streams found</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-zinc-500">Add categories or channels to start tracking streams, then scan.</p>
        </div>

        <!-- Grouped View -->
        <template v-else-if="isGrouped && groupedStreams.length">
            <div v-for="group in groupedStreams" :key="group.name" class="mb-4">
                <div class="w-full flex items-center gap-3 p-3 rounded-xl bg-white dark:bg-zinc-900 shadow-sm dark:shadow-none mb-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-zinc-800/60 transition-colors" @click="toggleGroup(group.name)">
                    <img v-if="group.boxArtUrl" :src="sizedImage(group.boxArtUrl, 40, 54)" :alt="group.name" class="w-8 h-10 rounded object-cover" />
                    <a :href="twitchCategoryUrl(group.name)" target="_blank" @click.stop class="text-base font-bold text-gray-900 dark:text-white hover:text-purple-600 dark:hover:text-purple-400 transition-colors truncate">{{ group.name }}</a>
                    <span v-if="!group.tracked" class="px-1.5 py-0.5 text-[10px] font-medium bg-gray-100 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400 rounded shrink-0">Not tracked</span>
                    <span class="flex-1"></span>
                    <span class="text-sm text-gray-400 dark:text-zinc-500 shrink-0">
                        {{ group.streams.length }} streams &middot; {{ group.streams.reduce((s, x) => s + x.viewer_count, 0).toLocaleString() }} viewers
                    </span>
                    <ChevronDown class="w-4 h-4 text-gray-400 transition-transform duration-200 shrink-0" :class="{ 'rotate-180': collapsed[group.name] }" />
                </div>
                <div v-show="!collapsed[group.name]">
                    <div v-if="density === 'compact'" class="rounded-xl overflow-hidden bg-white dark:bg-zinc-900 shadow-sm dark:shadow-none divide-y divide-gray-100/60 dark:divide-zinc-800/60">
                        <StreamCard v-for="stream in groupVisible(group)" :key="stream.id" v-bind="cardProps(stream)" @toggle-pin="togglePin" compact />
                    </div>
                    <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        <StreamCard v-for="stream in groupVisible(group)" :key="stream.id" v-bind="cardProps(stream)" @toggle-pin="togglePin" />
                    </div>
                    <div v-if="groupHasMore(group)" class="flex justify-center mt-3">
                        <button @click="showMoreGroup(group.name)" class="px-5 py-1.5 text-xs font-medium text-gray-500 dark:text-zinc-400 bg-white dark:bg-zinc-900 rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800 shadow-sm dark:shadow-none transition-colors">
                            Show more ({{ group.streams.length - (groupLimits[group.name] || BATCH) }} remaining)
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- Flat View -->
        <template v-else>
            <div v-if="density === 'compact'" class="rounded-xl overflow-hidden bg-white dark:bg-zinc-900 shadow-sm dark:shadow-none divide-y divide-gray-100/60 dark:divide-zinc-800/60">
                <StreamCard v-for="stream in visibleStreams" :key="stream.id" v-bind="cardProps(stream)" @toggle-pin="togglePin" compact />
            </div>
            <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <StreamCard v-for="stream in visibleStreams" :key="stream.id" v-bind="cardProps(stream)" @toggle-pin="togglePin" />
            </div>
            <div v-if="hasMore" class="flex justify-center mt-6">
                <button @click="showMore" class="px-6 py-2 text-sm font-medium text-gray-600 dark:text-zinc-400 bg-white dark:bg-zinc-900 rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800 shadow-sm dark:shadow-none transition-colors">
                    Show more ({{ sortedStreams.length - visibleCount }} remaining)
                </button>
            </div>
        </template>
    </AppLayout>
</template>
