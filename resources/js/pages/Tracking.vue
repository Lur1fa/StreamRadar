<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Search, Pencil, Trash2, X, Package, Loader2, Plus, Check, ArrowUpDown, RefreshCw, LayoutGrid, User } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import type { Category, TagFilter } from '@/types';
import { twitchCategoryUrl } from '@/composables/useTwitch';

interface TrackedChannel {
    id: number;
    twitch_user_id: string;
    user_login: string;
    user_name: string;
    profile_image_url: string | null;
    is_active: boolean;
}

const props = defineProps<{
    categories: Category[];
    channels: TrackedChannel[];
    sort: string;
    globalFilters: { min_viewers: number; languages: string[]; keywords: string[] };
    tagFilters: TagFilter[];
    tab: string;
}>();

const activeTab = ref(props.tab || 'categories');

// ---- Categories ----
const searchQuery = ref('');
const searchResults = ref<any[]>([]);
const searching = ref(false);
const searchError = ref('');
const highlightedIndex = ref(-1);

let searchTimeout: ReturnType<typeof setTimeout>;
function onSearch() {
    clearTimeout(searchTimeout);
    searchError.value = '';
    highlightedIndex.value = -1;
    if (searchQuery.value.length < 2) { searchResults.value = []; return; }
    searchTimeout = setTimeout(async () => {
        searching.value = true;
        try {
            const { data } = await window.axios.get('/tracking/search', { params: { query: searchQuery.value } });
            searchResults.value = Array.isArray(data) ? data : [];
        } catch (e: any) { searchError.value = e.response?.data?.error || e.message || 'Search failed'; }
        finally { searching.value = false; }
    }, 400);
}

function onKeydown(e: KeyboardEvent) {
    if (searchResults.value.length === 0) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); highlightedIndex.value = Math.min(highlightedIndex.value + 1, searchResults.value.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlightedIndex.value = Math.max(highlightedIndex.value - 1, 0); }
    else if (e.key === 'Enter') {
        e.preventDefault();
        const idx = highlightedIndex.value >= 0 ? highlightedIndex.value : 0;
        const result = searchResults.value[idx];
        if (result && !result.is_tracked) trackCategory(result);
    }
}

const tracking = ref<string | null>(null);
function trackCategory(cat: any) {
    tracking.value = cat.id;
    router.post('/tracking/categories', { twitch_id: cat.id, name: cat.name, box_art_url: cat.box_art_url || null }, {
        preserveScroll: true,
        onSuccess: () => { searchResults.value = searchResults.value.map(r => r.id === cat.id ? { ...r, is_tracked: true } : r); },
        onFinish: () => { tracking.value = null; },
    });
}

const totalViewers = (cat: any) => cat.streams_sum_viewer_count ?? 0;

const catSort = ref(localStorage.getItem('sr_tracking_sort') || 'name');
function changeSort(sort: string) { catSort.value = sort; localStorage.setItem('sr_tracking_sort', sort); }

const sortedCategories = computed(() => {
    const cats = [...props.categories];
    switch (catSort.value) {
        case 'streams': return cats.sort((a, b) => (b.streams_count ?? 0) - (a.streams_count ?? 0));
        case 'viewers': return cats.sort((a, b) => totalViewers(b) - totalViewers(a));
        default: return cats.sort((a, b) => a.name.localeCompare(b.name));
    }
});

const editingId = ref<number | null>(null);
const editForm = useForm({ is_active: true, notifications_enabled: true, filter_source: 'global', min_viewers: null as number | null, min_avg_viewers: null as number | null, languages: [] as string[], keywords: [] as string[], tags: [] as string[] });
const tagInput = ref('');
function addTag() { const v = tagInput.value.trim().toLowerCase(); if (v && !editForm.tags.includes(v)) editForm.tags.push(v); tagInput.value = ''; }
function removeTag(tag: string) { editForm.tags = editForm.tags.filter(t => t !== tag); }
function startEdit(cat: Category) { editingId.value = cat.id; editForm.is_active = cat.is_active; editForm.notifications_enabled = cat.notifications_enabled; editForm.filter_source = cat.filter_source || (cat.use_global_filters ? 'global' : 'custom'); editForm.min_viewers = cat.min_viewers; editForm.min_avg_viewers = cat.min_avg_viewers; editForm.languages = cat.languages || []; editForm.keywords = cat.keywords || []; editForm.tags = cat.tags || []; tagInput.value = ''; }
function saveEdit(cat: Category) { editForm.put(`/tracking/categories/${cat.id}`, { preserveScroll: true, onSuccess: () => { editingId.value = null; } }); }
const deletingCategory = ref<Category | null>(null);
function deleteCategory() { if (!deletingCategory.value) return; router.delete(`/tracking/categories/${deletingCategory.value.id}`, { preserveScroll: true, onSuccess: () => { deletingCategory.value = null; } }); }

const syncingCat = ref<number | null>(null);
function syncCat(cat: Category) { syncingCat.value = cat.id; router.post(`/tracking/categories/${cat.id}/sync`, {}, { preserveScroll: true, onFinish: () => { syncingCat.value = null; } }); }

const langInput = ref(''); const kwInput = ref('');
function addLang() { const v = langInput.value.trim().toLowerCase(); if (v && !editForm.languages.includes(v)) editForm.languages.push(v); langInput.value = ''; }
function removeLang(l: string) { editForm.languages = editForm.languages.filter(x => x !== l); }
function addKw() { const v = kwInput.value.trim(); if (v && !editForm.keywords.includes(v)) editForm.keywords.push(v); kwInput.value = ''; }
function removeKw(k: string) { editForm.keywords = editForm.keywords.filter(x => x !== k); }

function boxArt(url: string | null, w = 80, h = 107): string { if (!url) return ''; return url.replace('{width}', String(w)).replace('{height}', String(h)); }

function tagFilterTooltip(tag: string): string {
    const tf = props.tagFilters.find(t => t.tag === tag);
    if (!tf) return 'No filter configured (uses global)';
    const parts: string[] = [];
    if (tf.min_viewers) parts.push(`≥${tf.min_viewers} viewers`);
    if (tf.min_avg_viewers) parts.push(`≥${tf.min_avg_viewers} avg`);
    if (tf.languages?.length) parts.push(tf.languages.join(', '));
    if (tf.keywords?.length) parts.push(tf.keywords.join(', '));
    return parts.length ? parts.join(' · ') : 'No filters (uses global)';
}

function scrollToTagFilters() {
    document.getElementById('tag-filters-section')?.scrollIntoView({ behavior: 'smooth' });
}

function createAndScrollTagFilter(tag: string) {
    showNewTagForm.value = true;
    newTagFilterForm.tag = tag;
    setTimeout(() => document.getElementById('tag-filters-section')?.scrollIntoView({ behavior: 'smooth' }), 100);
}

// ---- Tag Filters ----
const newTagFilterForm = useForm({ tag: '', min_viewers: null as number | null, min_avg_viewers: null as number | null, languages: [] as string[], keywords: [] as string[] });
const newTagLangInput = ref('');
const newTagKwInput = ref('');
function addNewTagLang() { const v = newTagLangInput.value.trim().toLowerCase(); if (v && !newTagFilterForm.languages.includes(v)) newTagFilterForm.languages.push(v); newTagLangInput.value = ''; }
function removeNewTagLang(l: string) { newTagFilterForm.languages = newTagFilterForm.languages.filter(x => x !== l); }
function addNewTagKw() { const v = newTagKwInput.value.trim(); if (v && !newTagFilterForm.keywords.includes(v)) newTagFilterForm.keywords.push(v); newTagKwInput.value = ''; }
function removeNewTagKw(k: string) { newTagFilterForm.keywords = newTagFilterForm.keywords.filter(x => x !== k); }
const showNewTagForm = ref(false);
function submitTagFilter() { newTagFilterForm.post('/tracking/tag-filters', { preserveScroll: true, onSuccess: () => { newTagFilterForm.reset(); showNewTagForm.value = false; } }); }
function deleteTagFilter(tf: TagFilter) { router.delete(`/tracking/tag-filters/${tf.id}`, { preserveScroll: true }); }

// Edit tag filter
const editingTagId = ref<number | null>(null);
const editTagForm = useForm({ min_viewers: null as number | null, min_avg_viewers: null as number | null, languages: [] as string[], keywords: [] as string[] });
const editTagLangInput = ref('');
function addEditTagLang() { const v = editTagLangInput.value.trim().toLowerCase(); if (v && !editTagForm.languages.includes(v)) editTagForm.languages.push(v); editTagLangInput.value = ''; }
function removeEditTagLang(l: string) { editTagForm.languages = editTagForm.languages.filter(x => x !== l); }
function startEditTag(tf: TagFilter) { editingTagId.value = tf.id; editTagForm.min_viewers = tf.min_viewers; editTagForm.min_avg_viewers = tf.min_avg_viewers; editTagForm.languages = tf.languages || []; editTagLangInput.value = ''; }
function saveEditTag(tf: TagFilter) { editTagForm.put(`/tracking/tag-filters/${tf.id}`, { preserveScroll: true, onSuccess: () => { editingTagId.value = null; } }); }

// Available tags from categories (for creating tag filters)
const availableTags = computed(() => {
    const tags = new Set<string>();
    props.categories.forEach(c => c.tags?.forEach(t => tags.add(t)));
    return Array.from(tags).sort();
});

// Tags that have filter options in the edit dropdown — all unique tags from categories
const filterSourceOptions = computed(() => {
    const options: { value: string; label: string }[] = [{ value: 'global', label: 'Global' }];
    const allTags = new Set<string>();
    props.categories.forEach(c => c.tags?.forEach(t => allTags.add(t)));
    Array.from(allTags).sort().forEach(tag => {
        const hasFilter = props.tagFilters.some(tf => tf.tag === tag);
        options.push({ value: `tag:${tag}`, label: `Tag: ${tag}${hasFilter ? '' : ' (no filter)'}` });
    });
    options.push({ value: 'custom', label: 'Custom' });
    return options;
});

const globalFiltersLabel = computed(() => {
    const parts: string[] = [];
    const g = props.globalFilters;
    if (g.min_viewers) parts.push(`≥${g.min_viewers} viewers`);
    if (g.min_avg_viewers) parts.push(`≥${g.min_avg_viewers} avg`);
    if (g.languages.length) parts.push(g.languages.join(', '));
    if (g.keywords.length) parts.push(g.keywords.join(', '));
    return parts.length ? parts.join(' · ') : 'No filters set';
});

// ---- Channels ----
const channelInput = ref('');
const addingChannel = ref(false);

function trackChannel() {
    const login = channelInput.value.trim();
    if (!login) return;
    addingChannel.value = true;
    router.post('/tracking/channels', { user_login: login }, {
        preserveScroll: true,
        onSuccess: () => { channelInput.value = ''; },
        onFinish: () => { addingChannel.value = false; },
    });
}

function deleteChannel(ch: TrackedChannel) { router.delete(`/tracking/channels/${ch.id}`, { preserveScroll: true }); }
</script>

<template>
    <AppLayout>
        <!-- Tab switcher -->
        <div class="flex items-center gap-2 mb-6">
            <button @click="activeTab = 'categories'" :class="['flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg transition-colors', activeTab === 'categories' ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400' : 'text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800']">
                <LayoutGrid class="w-4 h-4" /> Categories
                <span v-if="categories.length" class="px-1.5 py-0.5 text-[10px] font-semibold rounded-full bg-gray-100 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400">{{ categories.length }}</span>
            </button>
            <button @click="activeTab = 'channels'" :class="['flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg transition-colors', activeTab === 'channels' ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400' : 'text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800']">
                <User class="w-4 h-4" /> Channels
                <span v-if="channels.length" class="px-1.5 py-0.5 text-[10px] font-semibold rounded-full bg-gray-100 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400">{{ channels.length }}</span>
            </button>
        </div>

        <!-- ==================== CATEGORIES TAB ==================== -->
        <div v-if="activeTab === 'categories'">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Search Panel -->
                <div class="lg:sticky lg:top-20 lg:self-start">
                    <div class="bg-white dark:bg-zinc-900 rounded-xl p-5 shadow-sm dark:shadow-none">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Track Category</h2>
                        <div class="relative">
                            <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input v-model="searchQuery" @input="onSearch" @keydown="onKeydown" autocomplete="off" type="text" placeholder="Search Twitch categories..."
                                class="w-full pl-9 pr-10 py-2.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-zinc-500 focus:ring-2 focus:ring-purple-500 outline-none" />
                            <Loader2 v-if="searching" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-purple-500 animate-spin" />
                        </div>
                        <div v-if="searchError" class="mt-4 text-sm text-red-500">{{ searchError }}</div>
                        <div v-if="searchResults.length > 0" class="mt-3 space-y-1 max-h-96 overflow-y-auto">
                            <div v-for="(result, idx) in searchResults" :key="result.id"
                                :class="['flex items-center gap-3 p-2.5 rounded-lg transition-colors', idx === highlightedIndex ? 'bg-purple-50 dark:bg-purple-500/10' : 'hover:bg-gray-50 dark:hover:bg-zinc-800/60']"
                                @mouseenter="highlightedIndex = idx">
                                <img v-if="result.box_art_url" :src="boxArt(result.box_art_url, 40, 54)" :alt="result.name" class="w-8 h-10 rounded object-cover shrink-0" />
                                <div v-else class="w-8 h-10 bg-gray-200 dark:bg-zinc-700 rounded shrink-0"></div>
                                <span class="flex-1 text-sm font-medium text-gray-900 dark:text-white truncate">{{ result.name }}</span>
                                <button v-if="!result.is_tracked" @click="trackCategory(result)" :disabled="tracking === result.id"
                                    class="shrink-0 px-3 py-1 text-xs font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors flex items-center gap-1 disabled:opacity-50">
                                    <Loader2 v-if="tracking === result.id" class="w-3 h-3 animate-spin" />
                                    <Plus v-else class="w-3 h-3" /> Track
                                </button>
                                <span v-else class="shrink-0 px-3 py-1 text-xs font-medium text-green-600 dark:text-green-400 flex items-center gap-1"><Check class="w-3 h-3" /> Tracked</span>
                            </div>
                        </div>
                        <div v-else-if="searchQuery.length >= 2 && !searching && !searchError" class="mt-4 text-center text-sm text-gray-400 dark:text-zinc-500">No categories found.</div>
                        <p v-if="searchResults.length > 0" class="mt-2 text-[11px] text-gray-400 dark:text-zinc-600">
                            <kbd class="px-1 py-0.5 rounded bg-gray-100 dark:bg-zinc-800 text-[10px]">↑↓</kbd> navigate, <kbd class="px-1 py-0.5 rounded bg-gray-100 dark:bg-zinc-800 text-[10px]">Enter</kbd> track
                        </p>
                    </div>

                    <!-- Tag Filters -->
                    <div v-if="tagFilters.length || availableTags.length" id="tag-filters-section" class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm dark:shadow-none p-4 mt-4">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-xs font-semibold text-gray-700 dark:text-zinc-300 uppercase tracking-wide">Tag Filters</h3>
                            <button v-if="availableTags.length && !showNewTagForm" @click="showNewTagForm = true" class="text-[10px] text-purple-600 dark:text-purple-400 hover:underline flex items-center gap-0.5">
                                <Plus class="w-3 h-3" /> New
                            </button>
                        </div>

                        <!-- New tag filter form -->
                        <div v-if="showNewTagForm" class="space-y-2 mb-3 pb-3 border-b border-gray-100 dark:border-zinc-800">
                            <select v-model="newTagFilterForm.tag" class="w-full px-2.5 py-1.5 text-xs rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500">
                                <option value="" disabled>Select tag</option>
                                <option v-for="tag in availableTags.filter(t => !tagFilters.find(tf => tf.tag === t))" :key="tag" :value="tag">{{ tag }}</option>
                            </select>
                            <input type="number" v-model.number="newTagFilterForm.min_viewers" min="0" placeholder="Min viewers" class="w-full px-2.5 py-1.5 text-xs rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none" />
                            <input type="number" v-model.number="newTagFilterForm.min_avg_viewers" min="0" placeholder="Min avg viewers (TwitchTracker)" class="w-full px-2.5 py-1.5 text-xs rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none" />
                            <div>
                                <div v-if="newTagFilterForm.languages.length" class="flex flex-wrap gap-1 mb-1">
                                    <span v-for="l in newTagFilterForm.languages" :key="l" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 text-[10px] bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 rounded-full">{{ l }} <button @click="removeNewTagLang(l)" class="hover:text-red-500"><X class="w-2.5 h-2.5" /></button></span>
                                </div>
                                <div class="flex gap-1">
                                    <input v-model="newTagLangInput" @keydown.enter.prevent="addNewTagLang" placeholder="Language" class="flex-1 px-2.5 py-1.5 text-xs rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white outline-none" />
                                    <button @click="addNewTagLang" class="px-2 py-1.5 text-[10px] font-medium bg-gray-100 dark:bg-zinc-700 text-gray-600 dark:text-zinc-300 rounded-lg">Add</button>
                                </div>
                            </div>
                            <div class="flex gap-1.5">
                                <button @click="submitTagFilter" :disabled="!newTagFilterForm.tag" class="px-3 py-1.5 text-xs font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors">Create</button>
                                <button @click="showNewTagForm = false; newTagFilterForm.reset()" class="px-3 py-1.5 text-xs font-medium text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800 rounded-lg transition-colors">Cancel</button>
                            </div>
                        </div>

                        <!-- Existing tag filters -->
                        <div v-if="tagFilters.length" class="space-y-2">
                            <div v-for="tf in tagFilters" :key="tf.id" class="group">
                                <!-- View mode -->
                                <div v-if="editingTagId !== tf.id" class="flex items-start gap-2">
                                    <div class="flex-1 min-w-0 cursor-pointer rounded-lg px-2 py-1.5 -mx-2 -my-1 hover:bg-gray-50 dark:hover:bg-zinc-800 transition-colors" @click="startEditTag(tf)">
                                        <div class="flex items-center gap-1.5">
                                            <span class="px-1.5 py-0.5 text-[10px] font-medium bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400 rounded">{{ tf.tag }}</span>
                                            <Pencil class="w-2.5 h-2.5 text-gray-300 dark:text-zinc-700" />
                                        </div>
                                        <div class="text-[10px] text-gray-400 dark:text-zinc-600 mt-0.5">
                                            <span v-if="tf.min_viewers">&ge;{{ tf.min_viewers }}</span>
                                            <span v-if="tf.languages?.length">{{ tf.min_viewers ? ' · ' : '' }}{{ tf.languages.join(', ') }}</span>
                                            <span v-if="!tf.min_viewers && !tf.languages?.length">No filters</span>
                                        </div>
                                    </div>
                                    <button @click="deleteTagFilter(tf)" class="p-1 text-gray-300 dark:text-zinc-700 hover:text-red-500 dark:hover:text-red-400 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                                        <Trash2 class="w-3 h-3" />
                                    </button>
                                </div>
                                <!-- Edit mode -->
                                <div v-else class="space-y-2 p-2 -mx-2 rounded-lg bg-gray-50 dark:bg-zinc-800/50">
                                    <span class="px-1.5 py-0.5 text-[10px] font-medium bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400 rounded">{{ tf.tag }}</span>
                                    <input type="number" v-model.number="editTagForm.min_viewers" min="0" placeholder="Min viewers" class="w-full px-2.5 py-1.5 text-xs rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-white outline-none" />
                                    <input type="number" v-model.number="editTagForm.min_avg_viewers" min="0" placeholder="Min avg (TwitchTracker)" class="w-full px-2.5 py-1.5 text-xs rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-white outline-none" />
                                    <div>
                                        <div v-if="editTagForm.languages.length" class="flex flex-wrap gap-1 mb-1">
                                            <span v-for="l in editTagForm.languages" :key="l" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 text-[10px] bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 rounded-full">{{ l }} <button @click="removeEditTagLang(l)" class="hover:text-red-500"><X class="w-2.5 h-2.5" /></button></span>
                                        </div>
                                        <div class="flex gap-1">
                                            <input v-model="editTagLangInput" @keydown.enter.prevent="addEditTagLang" placeholder="Language" class="flex-1 px-2.5 py-1.5 text-xs rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-white outline-none" />
                                            <button @click="addEditTagLang" class="px-2 py-1.5 text-[10px] font-medium bg-gray-100 dark:bg-zinc-700 text-gray-600 dark:text-zinc-300 rounded-lg">Add</button>
                                        </div>
                                    </div>
                                    <div class="flex gap-1.5">
                                        <button @click="saveEditTag(tf)" class="px-2.5 py-1 text-[10px] font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">Save</button>
                                        <button @click="editingTagId = null" class="px-2.5 py-1 text-[10px] font-medium text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800 rounded-lg transition-colors">Cancel</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p v-else-if="!showNewTagForm" class="text-[10px] text-gray-400 dark:text-zinc-600">Add tags to categories, then create filters here.</p>
                    </div>
                </div>

                <!-- Categories List -->
                <div class="lg:col-span-2">
                    <div v-if="categories.length > 0" class="flex items-center gap-2 mb-4">
                        <ArrowUpDown class="w-4 h-4 text-gray-400" />
                        <span class="text-xs text-gray-500 dark:text-zinc-500 mr-1">Sort:</span>
                        <button v-for="s in [{ key: 'name', label: 'Name' }, { key: 'streams', label: 'Streams' }, { key: 'viewers', label: 'Viewers' }]"
                            :key="s.key" @click="changeSort(s.key)"
                            :class="['px-2.5 py-1 text-xs font-medium rounded-lg transition-colors', catSort === s.key ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400' : 'text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800']">
                            {{ s.label }}
                        </button>
                    </div>
                    <div v-if="categories.length === 0" class="text-center py-16">
                        <Package class="w-16 h-16 mx-auto text-gray-300 dark:text-zinc-700" :stroke-width="1" />
                        <h3 class="mt-4 text-lg font-semibold text-gray-700 dark:text-zinc-300">No categories tracked</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-zinc-500">Search for a Twitch category to start tracking.</p>
                    </div>
                    <TransitionGroup v-else name="list" tag="div" class="space-y-3">
                        <div v-for="cat in sortedCategories" :key="cat.id" class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm dark:shadow-none overflow-hidden">
                            <div class="flex items-center gap-4 p-4">
                                <img v-if="cat.box_art_url" :src="boxArt(cat.box_art_url)" :alt="cat.name" class="w-14 h-18 rounded-lg object-cover shrink-0" />
                                <div v-else class="w-14 h-18 bg-gray-200 dark:bg-zinc-700 rounded-lg shrink-0"></div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <a :href="twitchCategoryUrl(cat.name)" target="_blank" class="font-semibold text-gray-900 dark:text-white truncate hover:text-purple-600 dark:hover:text-purple-400 transition-colors">{{ cat.name }}</a>
                                        <span v-if="!cat.is_active" class="px-1.5 py-0.5 text-[10px] font-medium bg-gray-100 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400 rounded">PAUSED</span>
                                    </div>
                                    <div class="flex items-center gap-3 mt-1 text-xs text-gray-500 dark:text-zinc-500">
                                        <span>{{ cat.streams_count ?? 0 }} streams</span>
                                        <span v-if="totalViewers(cat) > 0">{{ totalViewers(cat).toLocaleString() }} viewers</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-1.5 mt-1 text-[11px] text-gray-400 dark:text-zinc-600">
                                        <span v-if="cat.filter_source?.startsWith('tag:')" class="text-purple-500 dark:text-purple-400 cursor-help border-b border-dotted border-purple-300 dark:border-purple-600" :title="tagFilterTooltip(cat.filter_source.substring(4))">Tag filters: {{ cat.filter_source.substring(4) }}</span>
                                        <span v-else-if="!cat.filter_source || cat.filter_source === 'global'" class="cursor-help border-b border-dotted border-gray-300 dark:border-zinc-600" :title="'Global: ' + globalFiltersLabel">Global filters</span>
                                        <template v-else>
                                            <span v-if="cat.min_viewers">&ge;{{ cat.min_viewers }} viewers</span>
                                            <span v-if="cat.languages?.length">{{ cat.languages.join(', ') }}</span>
                                            <span v-if="cat.keywords?.length" class="truncate max-w-48">{{ cat.keywords.join(', ') }}</span>
                                                <span v-if="!cat.min_viewers && !cat.languages?.length && !cat.keywords?.length">No filters</span>
                                        </template>
                                        <template v-if="cat.tags?.length">
                                            <span v-for="tag in cat.tags" :key="tag" class="px-1.5 py-0.5 bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400 rounded text-[10px]">{{ tag }}</span>
                                        </template>
                                        <span v-if="!cat.notifications_enabled" class="text-red-400 dark:text-red-500">Notifications off</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <button @click="syncCat(cat)" :disabled="syncingCat === cat.id" class="p-2 text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition-colors rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800 disabled:opacity-50" v-tooltip="'Scan'">
                                        <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': syncingCat === cat.id }" />
                                    </button>
                                    <button @click="editingId === cat.id ? editingId = null : startEdit(cat)" class="p-2 text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition-colors rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800">
                                        <Pencil class="w-4 h-4" />
                                    </button>
                                    <button @click="deletingCategory = cat" class="p-2 text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-colors rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800">
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                            <!-- Edit panel -->
                            <div v-if="editingId === cat.id" class="px-4 py-3 bg-gray-50 dark:bg-zinc-950/50 border-t border-gray-100 dark:border-zinc-800 space-y-3">
                                <!-- Row 1: toggles + filter source -->
                                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                                    <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" v-model="editForm.is_active" class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800" /><span class="text-xs text-gray-600 dark:text-zinc-400">Active</span></label>
                                    <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" v-model="editForm.notifications_enabled" class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800" /><span class="text-xs text-gray-600 dark:text-zinc-400">Notifications</span></label>
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs text-gray-600 dark:text-zinc-400">Filters:</span>
                                        <select v-model="editForm.filter_source" class="px-2.5 py-1.5 text-xs rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500">
                                            <option v-for="opt in filterSourceOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Row 2: tag filter info (when tag selected) -->
                                <div v-if="editForm.filter_source.startsWith('tag:')" class="text-xs text-gray-500 dark:text-zinc-500">
                                    <template v-if="tagFilters.find(tf => tf.tag === editForm.filter_source.substring(4))">
                                        <span v-if="tagFilters.find(tf => tf.tag === editForm.filter_source.substring(4))!.min_viewers">&ge;{{ tagFilters.find(tf => tf.tag === editForm.filter_source.substring(4))!.min_viewers }} viewers</span>
                                        <span v-if="tagFilters.find(tf => tf.tag === editForm.filter_source.substring(4))!.languages?.length"> · {{ tagFilters.find(tf => tf.tag === editForm.filter_source.substring(4))!.languages!.join(', ') }}</span>
                                        <span v-if="tagFilters.find(tf => tf.tag === editForm.filter_source.substring(4))!.keywords?.length"> · {{ tagFilters.find(tf => tf.tag === editForm.filter_source.substring(4))!.keywords!.join(', ') }}</span>
                                        <span v-if="!tagFilters.find(tf => tf.tag === editForm.filter_source.substring(4))!.min_viewers && !tagFilters.find(tf => tf.tag === editForm.filter_source.substring(4))!.languages?.length && !tagFilters.find(tf => tf.tag === editForm.filter_source.substring(4))!.keywords?.length">No filters (inherits global)</span>
                                        <span class="ml-1">·</span>
                                        <button @click="scrollToTagFilters" class="text-purple-500 dark:text-purple-400 hover:underline ml-0.5">edit tag filter</button>
                                    </template>
                                    <template v-else>
                                        Uses global filters. <button @click="createAndScrollTagFilter(editForm.filter_source.substring(4))" class="text-purple-500 dark:text-purple-400 hover:underline">Create filter for "{{ editForm.filter_source.substring(4) }}"</button>
                                    </template>
                                </div>

                                <!-- Row 2b: custom filters (only if custom) -->
                                <div v-if="editForm.filter_source === 'custom'" class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-[10px] font-medium text-gray-500 dark:text-zinc-500 uppercase tracking-wide">Min Viewers</label>
                                        <input autocomplete="off" type="number" v-model.number="editForm.min_viewers" min="0" placeholder="0" class="mt-0.5 w-full px-2.5 py-1.5 text-sm rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-medium text-gray-500 dark:text-zinc-500 uppercase tracking-wide">Min Avg <span class="normal-case tracking-normal font-normal text-gray-400 dark:text-zinc-600">TwitchTracker</span></label>
                                        <input autocomplete="off" type="number" v-model.number="editForm.min_avg_viewers" min="0" placeholder="0" class="mt-0.5 w-full px-2.5 py-1.5 text-sm rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-medium text-gray-500 dark:text-zinc-500 uppercase tracking-wide">Languages <span class="normal-case tracking-normal font-normal text-gray-400 dark:text-zinc-600">any if empty</span></label>
                                        <div v-if="editForm.languages.length" class="flex flex-wrap gap-1 mt-0.5">
                                            <span v-for="lang in editForm.languages" :key="lang" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 text-[10px] bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 rounded-full">{{ lang }} <button @click="removeLang(lang)" class="hover:text-red-500"><X class="w-2.5 h-2.5" /></button></span>
                                        </div>
                                        <div class="flex gap-1 mt-0.5">
                                            <input v-model="langInput" @keydown.enter.prevent="addLang" autocomplete="off" placeholder="en" class="flex-1 px-2.5 py-1.5 text-sm rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                                            <button @click="addLang" class="px-2 py-1.5 text-[10px] font-medium bg-gray-100 dark:bg-zinc-700 text-gray-600 dark:text-zinc-300 rounded-lg">Add</button>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-medium text-gray-500 dark:text-zinc-500 uppercase tracking-wide">Keywords <span class="normal-case tracking-normal font-normal text-gray-400 dark:text-zinc-600">any if empty</span></label>
                                        <div v-if="editForm.keywords.length" class="flex flex-wrap gap-1 mt-0.5">
                                            <span v-for="kw in editForm.keywords" :key="kw" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 text-[10px] bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-300 rounded-full">{{ kw }} <button @click="removeKw(kw)" class="hover:text-red-500"><X class="w-2.5 h-2.5" /></button></span>
                                        </div>
                                        <div class="flex gap-1 mt-0.5">
                                            <input v-model="kwInput" @keydown.enter.prevent="addKw" autocomplete="off" placeholder="keyword" class="flex-1 px-2.5 py-1.5 text-sm rounded-lg bg-white dark:bg-zinc-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-purple-500" />
                                            <button @click="addKw" class="px-2 py-1.5 text-[10px] font-medium bg-gray-100 dark:bg-zinc-700 text-gray-600 dark:text-zinc-300 rounded-lg">Add</button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Row 3: tags -->
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="text-xs text-gray-500 dark:text-zinc-500">Tags:</span>
                                    <span v-for="tag in editForm.tags" :key="tag" class="inline-flex items-center gap-0.5 px-2 py-0.5 text-[10px] bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-300 rounded-full">{{ tag }} <button @click="removeTag(tag)" class="hover:text-red-500"><X class="w-2.5 h-2.5" /></button></span>
                                    <input v-model="tagInput" @keydown.enter.prevent="addTag" autocomplete="off" placeholder="add tag" class="w-20 px-2 py-0.5 text-xs rounded bg-transparent text-gray-600 dark:text-zinc-400 outline-none placeholder:text-gray-300 dark:placeholder:text-zinc-700 border-b border-gray-200 dark:border-zinc-700 focus:border-purple-500" />
                                </div>

                                <!-- Row 4: actions -->
                                <div class="flex gap-2 pt-1">
                                    <button @click="saveEdit(cat)" :disabled="editForm.processing" class="px-3 py-1.5 text-xs font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors">Save</button>
                                    <button @click="editingId = null" class="px-3 py-1.5 text-xs font-medium text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800 rounded-lg transition-colors">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </TransitionGroup>
                </div>

            </div>
        </div>

        <!-- ==================== CHANNELS TAB ==================== -->
        <div v-if="activeTab === 'channels'">
            <!-- Add channel -->
            <form @submit.prevent="trackChannel" class="flex gap-2 mb-6">
                <input v-model="channelInput" autocomplete="off" type="text" placeholder="Channel login (e.g. shroud)..."
                    class="flex-1 max-w-sm px-3 py-2 text-sm rounded-lg bg-white dark:bg-zinc-900 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-zinc-500 outline-none focus:ring-2 focus:ring-purple-500" />
                <button type="submit" :disabled="addingChannel || !channelInput.trim()"
                    class="px-4 py-2 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors flex items-center gap-1.5">
                    <Loader2 v-if="addingChannel" class="w-4 h-4 animate-spin" />
                    <Plus v-else class="w-4 h-4" />
                    Track
                </button>
            </form>

            <div v-if="channels.length === 0" class="text-center py-16">
                <User class="w-16 h-16 mx-auto text-gray-300 dark:text-zinc-700" :stroke-width="1" />
                <h3 class="mt-4 text-lg font-semibold text-gray-700 dark:text-zinc-300">No channels tracked</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-zinc-500">Add a Twitch channel login to track their streams.</p>
            </div>

            <TransitionGroup v-else name="list" tag="div" class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm dark:shadow-none overflow-hidden divide-y divide-gray-100/60 dark:divide-zinc-800/60">
                <div v-for="ch in channels" :key="ch.id" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-zinc-800/60 transition-colors">
                    <img v-if="ch.profile_image_url" :src="ch.profile_image_url" :alt="ch.user_name" class="w-9 h-9 rounded-full shrink-0" />
                    <div v-else class="w-9 h-9 rounded-full bg-gray-200 dark:bg-zinc-700 shrink-0"></div>
                    <div class="flex-1 min-w-0">
                        <a :href="`https://www.twitch.tv/${ch.user_login}`" target="_blank" class="text-sm font-medium text-gray-900 dark:text-white hover:text-purple-600 dark:hover:text-purple-400 transition-colors">{{ ch.user_name }}</a>
                        <p class="text-xs text-gray-500 dark:text-zinc-500">@{{ ch.user_login }}</p>
                    </div>
                    <button @click="deleteChannel(ch)" class="shrink-0 p-1.5 text-gray-400 hover:text-red-500 dark:hover:text-red-400 rounded-lg hover:bg-gray-100 dark:hover:bg-zinc-800 transition-colors">
                        <Trash2 class="w-4 h-4" />
                    </button>
                </div>
            </TransitionGroup>
        </div>
        <ConfirmModal :show="!!deletingCategory" :title="`Remove &quot;${deletingCategory?.name}&quot;?`" message="This category and all its streams will be removed." confirm-label="Remove" @confirm="deleteCategory" @cancel="deletingCategory = null" />
    </AppLayout>
</template>
