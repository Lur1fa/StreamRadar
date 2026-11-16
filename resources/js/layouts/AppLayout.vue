<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { RefreshCw, Menu, X, Radar, LogOut, UserCircle } from 'lucide-vue-next';
import AppNav from '@/components/AppNav.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import FlashMessage from '@/components/FlashMessage.vue';
import { useNow } from '@/composables/useNow';
import { useAuth } from '@/composables/useAuth';
import type { PageProps } from '@/types';

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);
const appSettings = computed(() => page.props.appSettings);
const stats = computed(() => page.props.stats);
const syncing = ref(false);
const mobileMenuOpen = ref(false);
const { user } = useAuth();

function triggerSync() {
    syncing.value = true;
    router.post('/sync', {}, {
        preserveScroll: true,
        onFinish: () => { syncing.value = false; },
    });
}

// Auto-reload data every 60 seconds
let autoReloadInterval: ReturnType<typeof setInterval>;
onMounted(() => {
    autoReloadInterval = setInterval(() => {
        router.reload({ only: ['stats', 'appSettings', 'pendingAlerts'], preserveScroll: true });
    }, 60000);
});
onUnmounted(() => clearInterval(autoReloadInterval));

// Close mobile menu on navigation
router.on('navigate', () => { mobileMenuOpen.value = false; });

const now = useNow();

function formatLastSync(iso: string | null): string {
    if (!iso) return 'Never';
    const date = new Date(iso);
    const diffMs = now.value - date.getTime();
    const diffMin = Math.floor(diffMs / 60000);
    if (diffMin < 1) return 'Just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    const diffH = Math.floor(diffMin / 60);
    if (diffH < 24) return `${diffH}h ago`;
    return date.toLocaleDateString();
}
</script>

<template>
    <div class="min-h-screen flex flex-col bg-gray-50 dark:bg-zinc-950 transition-colors duration-200">
        <!-- Header -->
        <header class="sticky top-0 z-40 bg-white/80 dark:bg-zinc-900/80 backdrop-blur-md">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <div class="flex items-center gap-3">
                        <!-- Hamburger (mobile) -->
                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="sm:hidden p-1.5 -ml-1.5 text-gray-500 dark:text-zinc-400 hover:text-gray-700 dark:hover:text-zinc-200">
                            <Menu v-if="!mobileMenuOpen" class="w-5 h-5" />
                            <X v-else class="w-5 h-5" />
                        </button>

                        <!-- Logo (clickable) -->
                        <Link href="/" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center">
                                <Radar class="w-4.5 h-4.5 text-white" :stroke-width="2" />
                            </div>
                            <h1 class="text-xl font-bold text-gray-900 dark:text-white hidden sm:block">StreamRadar</h1>
                        </Link>
                    </div>

                    <div class="flex items-center gap-3">
                        <span class="text-xs text-gray-400 dark:text-zinc-500 hidden sm:block">
                            {{ formatLastSync(appSettings?.last_sync_at ?? null) }}
                        </span>
                        <button
                            @click="triggerSync"
                            :disabled="syncing"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg transition-all
                                   bg-purple-50 text-purple-700 hover:bg-purple-100
                                   dark:bg-purple-500/10 dark:text-purple-400 dark:hover:bg-purple-500/20
                                   disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <RefreshCw class="w-3.5 h-3.5" :class="{ 'animate-spin': syncing }" />
                            <span class="hidden sm:inline">Scan</span>
                        </button>
                        <ThemeToggle />
                        <Link href="/account" class="hidden sm:inline-flex items-center gap-1.5 px-2 py-1.5 text-xs font-medium text-gray-500 hover:text-gray-700 hover:bg-gray-100 dark:text-zinc-400 dark:hover:text-zinc-200 dark:hover:bg-zinc-800 rounded-lg transition-colors" v-tooltip="'Account'">
                            <UserCircle class="w-4 h-4" />
                            <span class="max-w-[10rem] truncate">{{ user?.name }}</span>
                        </Link>
                        <button @click="router.post('/logout')"
                            class="p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 dark:text-zinc-400 dark:hover:text-zinc-200 dark:hover:bg-zinc-800 transition-colors" v-tooltip="'Sign out'">
                            <LogOut class="w-5 h-5" />
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Mobile Menu Overlay -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="mobileMenuOpen" class="fixed inset-0 z-30 bg-black/40 sm:hidden" @click="mobileMenuOpen = false"></div>
        </Transition>

        <!-- Mobile Menu Drawer -->
        <Transition
            enter-active-class="transition ease-out duration-200 transform"
            enter-from-class="-translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition ease-in duration-150 transform"
            leave-from-class="translate-x-0"
            leave-to-class="-translate-x-full"
        >
            <div v-if="mobileMenuOpen" class="fixed inset-y-0 left-0 z-30 w-64 bg-white dark:bg-zinc-900 shadow-xl sm:hidden overflow-y-auto">
                <div class="p-4 pt-20">
                    <AppNav :mobile="true" :stats="stats" />
                    <Link href="/account" class="mt-3 flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-zinc-400 hover:bg-gray-50 dark:hover:bg-zinc-800"><UserCircle class="w-4 h-4" /> {{ user?.name }}</Link>
                </div>
            </div>
        </Transition>

        <!-- Desktop nav -->
        <div class="hidden sm:block">
            <AppNav :stats="stats" />
        </div>

        <FlashMessage :flash="flash" />

        <main class="flex-1 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 w-full">
            <slot />
        </main>

        <footer class="py-4 text-center text-[11px] text-gray-300 dark:text-zinc-700">
            <a href="https://github.com/stmn/StreamRadar" target="_blank" class="hover:text-gray-500 dark:hover:text-zinc-500 transition-colors">StreamRadar</a>
        </footer>
    </div>
</template>
