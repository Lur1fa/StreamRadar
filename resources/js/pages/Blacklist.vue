<script setup lang="ts">
import { ref, watch, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { ShieldBan, User, Type, Tag, Trash2, Plus } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BlacklistRule } from '@/types';

const props = defineProps<{
    channels: BlacklistRule[];
    keywords: BlacklistRule[];
    tags: BlacklistRule[];
    tab: string;
}>();

const activeTab = ref(props.tab || 'channels');
const inputValue = ref('');
const inputEl = ref<HTMLInputElement | null>(null);

// Focus input when tab changes or on mount
watch(activeTab, () => {
    inputValue.value = '';
    setTimeout(() => inputEl.value?.focus(), 50);
});
onMounted(() => setTimeout(() => inputEl.value?.focus(), 100));

const tabConfig = [
    { key: 'channels', label: 'Channels', icon: User, placeholder: 'Channel login...', type: 'channel' },
    { key: 'keywords', label: 'Keywords', icon: Type, placeholder: 'Keyword to block...', type: 'keyword' },
    { key: 'tags', label: 'Tags', icon: Tag, placeholder: 'Tag to block...', type: 'tag' },
];

function currentType(): string {
    return tabConfig.find(t => t.key === activeTab.value)?.type || 'channel';
}

function currentPlaceholder(): string {
    return tabConfig.find(t => t.key === activeTab.value)?.placeholder || '';
}

function itemsFor(key: string): BlacklistRule[] {
    if (key === 'channels') return props.channels;
    if (key === 'keywords') return props.keywords;
    return props.tags;
}

function addRule() {
    const value = inputValue.value.trim();
    if (!value) return;
    const type = currentType();
    router.post('/blacklist', { type, value }, {
        preserveScroll: true,
        onSuccess: () => { inputValue.value = ''; },
    });
}

function removeRule(rule: BlacklistRule) {
    router.delete(`/blacklist/${rule.id}`, { preserveScroll: true });
}

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
</script>

<template>
    <AppLayout>
        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-6">Blacklist</h2>

        <!-- Tabs -->
        <div class="flex gap-2 mb-6">
            <button
                v-for="t in tabConfig" :key="t.key"
                @click="activeTab = t.key"
                :class="['flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg transition-colors',
                    activeTab === t.key
                        ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400'
                        : 'text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800']"
            >
                <component :is="t.icon" class="w-4 h-4" />
                {{ t.label }}
                <span v-if="itemsFor(t.key).length > 0"
                    class="px-1.5 py-0.5 text-[10px] font-semibold rounded-full bg-gray-100 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400">
                    {{ itemsFor(t.key).length }}
                </span>
            </button>
        </div>

        <!-- Single form, changes placeholder per tab -->
        <form @submit.prevent="addRule" class="flex gap-2 mb-6">
            <input
                ref="inputEl"
                v-model="inputValue"
                type="text"
                :placeholder="currentPlaceholder()"
                class="flex-1 px-3 py-2 text-sm rounded-lg bg-white dark:bg-zinc-900 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-zinc-500 outline-none focus:ring-2 focus:ring-purple-500"
            />
            <button type="submit" :disabled="!inputValue.trim()"
                class="px-4 py-2 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors flex items-center gap-1.5">
                <Plus class="w-4 h-4" /> Block
            </button>
        </form>

        <!-- Empty -->
        <div v-if="itemsFor(activeTab).length === 0" class="text-center py-16">
            <ShieldBan class="w-16 h-16 mx-auto text-gray-300 dark:text-zinc-700" :stroke-width="1" />
            <h3 class="mt-4 text-lg font-semibold text-gray-700 dark:text-zinc-300">No {{ activeTab }} blocked</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-zinc-500">
                {{ activeTab === 'channels' ? 'Blocked channels won\'t appear in stream results.' :
                   activeTab === 'keywords' ? 'Streams with blocked keywords in title will be hidden.' :
                   'Streams with blocked tags will be hidden.' }}
            </p>
        </div>

        <!-- Items list -->
        <TransitionGroup v-else name="list" tag="div" class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm dark:shadow-none overflow-hidden divide-y divide-gray-100/60 dark:divide-zinc-800/60">
            <div v-for="item in itemsFor(activeTab)" :key="item.id"
                class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-zinc-800/60 transition-colors">
                <img v-if="item.type === 'channel' && item.profile_image_url" :src="item.profile_image_url" :alt="item.value" class="w-8 h-8 rounded-full shrink-0" />
                <div v-else-if="item.type === 'channel'" class="w-8 h-8 rounded-full bg-gray-200 dark:bg-zinc-700 shrink-0"></div>
                <div v-else class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-zinc-800 flex items-center justify-center shrink-0">
                    <Type v-if="item.type === 'keyword'" class="w-4 h-4 text-gray-400" />
                    <Tag v-else class="w-4 h-4 text-gray-400" />
                </div>

                <div class="flex-1 min-w-0">
                    <a v-if="item.type === 'channel'" :href="`https://www.twitch.tv/${item.value}`" target="_blank" class="text-sm font-medium text-gray-900 dark:text-white hover:text-purple-600 dark:hover:text-purple-400 transition-colors">{{ item.value }}</a>
                    <p v-else class="text-sm font-medium text-gray-900 dark:text-white">{{ item.value }}</p>
                </div>
                <span class="text-xs text-gray-400 dark:text-zinc-600 shrink-0 hidden sm:block">{{ formatDate(item.created_at) }}</span>
                <button @click="removeRule(item)" class="shrink-0 p-1.5 text-gray-400 hover:text-red-500 dark:hover:text-red-400 rounded-lg hover:bg-gray-100 dark:hover:bg-zinc-800 transition-colors">
                    <Trash2 class="w-4 h-4" />
                </button>
            </div>
        </TransitionGroup>
    </AppLayout>
</template>
