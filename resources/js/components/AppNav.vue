<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, ref, onMounted, nextTick } from 'vue';
import { Radio, Bell, LayoutGrid, ShieldBan, Clock, Settings, Users } from 'lucide-vue-next';
import { useAuth } from '@/composables/useAuth';
import type { Stats } from '@/types';

const props = defineProps<{
    mobile?: boolean;
    stats: Stats | null;
}>();

const page = usePage();
const { isAdmin } = useAuth();
const currentPath = computed(() => page.url.split('?')[0]);

const tabs = computed(() => [
    { name: 'Streams', href: '/', match: '/', icon: Radio, count: props.stats?.streams_count ?? null },
    { name: 'Tracking', href: '/tracking', match: '/tracking', icon: LayoutGrid, count: props.stats?.categories_count ?? null },
    { name: 'Alerts', href: '/alerts', match: '/alerts', icon: Bell, count: props.stats?.alerts_count ?? null },
    { name: 'Blacklist', href: '/blacklist', match: '/blacklist', icon: ShieldBan, count: props.stats?.blacklist_count ?? null },
    { name: 'History', href: '/history', match: '/history', icon: Clock, count: null },
    ...(isAdmin.value ? [
        { name: 'Users', href: '/users', match: '/users', icon: Users, count: null },
    ] : []),
    { name: 'Settings', href: '/settings', match: '/settings', icon: Settings, count: null },
]);

function isActive(match: string): boolean {
    if (match === '/') return currentPath.value === '/';
    return currentPath.value.startsWith(match);
}

// Sliding underline
const navContainer = ref<HTMLElement | null>(null);
const indicatorEl = ref<HTMLElement | null>(null);

function updateIndicator(animate: boolean) {
    if (!navContainer.value || !indicatorEl.value) return;
    const activeEl = navContainer.value.querySelector('[data-active="true"]') as HTMLElement | null;
    if (!activeEl) return;
    const containerRect = navContainer.value.getBoundingClientRect();
    const elRect = activeEl.getBoundingClientRect();
    const el = indicatorEl.value;

    if (!animate) {
        el.style.transition = 'none';
    } else {
        el.style.transition = 'left 300ms ease-out, width 300ms ease-out';
    }

    el.style.left = `${elRect.left - containerRect.left}px`;
    el.style.width = `${elRect.width}px`;
    el.style.opacity = '1';

    if (!animate) {
        // Force reflow then re-enable transition
        el.offsetHeight;
        el.style.transition = 'left 300ms ease-out, width 300ms ease-out';
    }
}

onMounted(() => {
    nextTick(() => updateIndicator(false));
});

router.on('navigate', () => {
    nextTick(() => nextTick(() => updateIndicator(true)));
});
</script>

<template>
    <!-- Mobile vertical nav -->
    <nav v-if="mobile" class="space-y-1">
        <Link
            v-for="tab in tabs"
            :key="tab.href"
            :href="tab.href"
            :class="[
                'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors',
                isActive(tab.match)
                    ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400'
                    : 'text-gray-600 hover:bg-gray-50 dark:text-zinc-400 dark:hover:bg-zinc-800'
            ]"
        >
            <component :is="tab.icon" class="w-4 h-4" />
            <span class="flex-1">{{ tab.name }}</span>
            <span
                v-if="tab.count != null && tab.count > 0"
                :class="[
                    'px-2 py-0.5 text-[11px] font-semibold rounded-full',
                    isActive(tab.match)
                        ? 'bg-purple-100 dark:bg-purple-500/20 text-purple-700 dark:text-purple-300'
                        : 'bg-gray-100 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400'
                ]"
            >
                {{ tab.count }}
            </span>
        </Link>
    </nav>

    <!-- Desktop horizontal nav -->
    <nav v-else class="bg-white dark:bg-zinc-900">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div ref="navContainer" class="relative flex gap-1 overflow-x-auto scrollbar-hide">
                <!-- Sliding indicator -->
                <div
                    ref="indicatorEl"
                    class="absolute bottom-0 h-0.5 bg-purple-500 rounded-full"
                    style="opacity: 0; left: 0; width: 0;"
                ></div>

                <Link
                    v-for="tab in tabs"
                    :key="tab.href"
                    :href="tab.href"
                    :data-active="isActive(tab.match)"
                    :class="[
                        'flex items-center gap-2 px-4 py-3 text-sm font-medium whitespace-nowrap transition-colors',
                        isActive(tab.match)
                            ? 'text-purple-600 dark:text-purple-400'
                            : 'text-gray-500 hover:text-gray-700 dark:text-zinc-400 dark:hover:text-zinc-200'
                    ]"
                >
                    <component :is="tab.icon" class="w-4 h-4" />
                    {{ tab.name }}
                    <span
                        v-if="tab.count != null && tab.count > 0"
                        :class="[
                            'px-1.5 py-0.5 text-[10px] font-semibold rounded-full leading-none',
                            isActive(tab.match)
                                ? 'bg-purple-100 dark:bg-purple-500/20 text-purple-700 dark:text-purple-300'
                                : 'bg-gray-100 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400'
                        ]"
                    >
                        {{ tab.count }}
                    </span>
                </Link>
            </div>
        </div>
    </nav>
</template>
